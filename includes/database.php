<?php
defined('ABSPATH') || exit;

final class CSM_Database {
    public static function inventory(): array {
        global $wpdb;
        $sql = $wpdb->prepare('SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = %s ORDER BY TABLE_NAME', DB_NAME, 'BASE TABLE');
        $result = [];
        foreach ($wpdb->get_results($sql, ARRAY_A) as $row) {
            if (!str_starts_with($row['TABLE_NAME'], $wpdb->prefix) || str_starts_with($row['TABLE_NAME'], '_csm_')) { continue; }
            $result[] = ['id' => $row['TABLE_NAME'], 'name' => $row['TABLE_NAME'], 'engine' => $row['ENGINE'], 'rows' => (int)$row['TABLE_ROWS'], 'bytes' => (int)$row['bytes']];
        }
        return $result;
    }

    public static function validate(array $tables): array {
        $allowed = array_column(self::inventory(), null, 'id');
        $output = [];
        foreach (array_unique($tables) as $table) {
            if (!isset($allowed[$table])) { throw new RuntimeException('Unknown database table.'); }
            if (strtoupper($allowed[$table]['engine']) !== 'INNODB') {
                throw new RuntimeException($table . ' is not InnoDB; a consistent live snapshot cannot be guaranteed.');
            }
            $output[] = $table;
        }
        return $output;
    }

    public static function identity(): array {
        return ['url' => site_url(), 'home' => home_url(), 'root' => ABSPATH, 'content' => WP_CONTENT_DIR];
    }

    public static function replacement_defaults(array $source, array $destination): array {
        $rules = [];
        foreach (['url' => 'WordPress URL', 'home' => 'Home URL', 'root' => 'WordPress directory', 'content' => 'Content directory'] as $id => $label) {
            if (!is_string($source[$id] ?? null) || !is_string($destination[$id] ?? null)) { throw new RuntimeException('Update Trickle on both sites to preview all default replacements.'); }
            $find = $source[$id];
            if ($find === '') { continue; }
            if (isset($rules[$find])) {
                $rules[$find]['label'] .= ' / ' . $label;
                // Preserve the legacy mapping when site/home URLs share the same search string.
                $rules[$find]['replace'] = $destination[$id];
            } else {
                $rules[$find] = ['kind' => 'default', 'id' => $id, 'label' => $label, 'find' => $find, 'replace' => $destination[$id], 'enabled' => true];
            }
        }
        foreach (['url', 'home'] as $id) {
            $from = preg_replace('#^https?://#i', '//', $source[$id]);
            $to = preg_replace('#^https?://#i', '//', $destination[$id]);
            if (!str_starts_with($from, '//') || !str_starts_with($to, '//')) { continue; }
            foreach (['http' => 'http:' . $from, 'https' => 'https:' . $from, 'relative' => $from] as $variant => $find) {
                $replace = $variant === 'relative' ? $to : $destination[$id];
                if (!isset($rules[$find])) { $rules[$find] = ['kind' => 'default', 'id' => $id . '_' . $variant, 'label' => ($id === 'url' ? 'WordPress URL' : 'Home URL') . ' (' . $variant . ')', 'find' => $find, 'replace' => $replace, 'enabled' => true]; }
            }
        }
        return array_values($rules);
    }

    public static function replacement_selection(array $selection): array {
        $selection['replace'] = !array_key_exists('replace', $selection) || !empty($selection['replace']);
        if (!array_key_exists('replacements', $selection)) { return $selection; }
        $rules = $selection['replacements'];
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 100 || strlen(CSM_Support::json($rules)) > 65536) {
            throw new RuntimeException('Use a list of at most 100 replacement rules, totaling no more than 64 KB.');
        }
        $selection['replacements'] = [];
        foreach ($rules as $rule) {
            if (!is_array($rule) || !is_string($rule['find'] ?? null) || !is_string($rule['replace'] ?? null) || (array_key_exists('enabled', $rule) && !is_bool($rule['enabled']))) {
                throw new RuntimeException('Each replacement needs find and replace text and an optional boolean enabled value.');
            }
            $kind = $rule['kind'] ?? 'custom';
            if (!in_array($kind, ['default', 'custom'], true)) { throw new RuntimeException('Invalid replacement rule type.'); }
            $selection['replacements'][] = ['kind' => $kind, 'id' => $kind === 'default' ? sanitize_key($rule['id'] ?? '') : '',
                'label' => $kind === 'default' ? sanitize_text_field($rule['label'] ?? 'Default replacement') : '',
                'find' => $rule['find'], 'replace' => $rule['replace'], 'enabled' => $rule['enabled'] ?? true];
        }
        self::rules_to_pairs($selection['replacements'], $selection['replace']);
        return $selection;
    }

    private static function rules_to_pairs(array $rules, bool $enabled): array {
        if (!$enabled) { return []; }
        $pairs = [];
        foreach ($rules as $rule) {
            if (!$rule['enabled']) { continue; }
            if ($rule['find'] === '') { throw new RuntimeException('An enabled replacement cannot have empty find text. Disable or remove the empty row.'); }
            if (array_key_exists($rule['find'], $pairs) && $pairs[$rule['find']] !== $rule['replace']) {
                throw new RuntimeException('Enabled replacement rules search for the same text with different replacements. Disable one of the conflicting rows.');
            }
            $pairs[$rule['find']] = $rule['replace'];
        }
        return $pairs;
    }

    public static function replacement_pairs(array $source, array $destination, array $selection): array {
        $selection = self::replacement_selection($selection);
        if (!$selection['replace']) { return []; }
        return self::rules_to_pairs($selection['replacements'] ?? self::replacement_defaults($source, $destination), $selection['replace']);
    }

    public static function stream(array $selection): Generator {
        global $wpdb;
        $tables = self::validate($selection['tables'] ?? []);
        yield CSM_Support::json(array_merge(['type' => 'header', 'version' => 1, 'kind' => 'database', 'prefix' => $wpdb->prefix, 'tables' => $tables, 'active_plugins' => CSM_Activation::current(), 'trickle_plugin' => plugin_basename(CSM_FILE)], self::identity()));
        CSM_Support::query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        CSM_Support::query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $hash = hash_init('sha256');
        $rows = 0;
        try {
            foreach ($tables as $table) {
                CSM_Progress::update(['phase' => 'Exporting table', 'component' => $table, 'count' => $rows, 'total' => null, 'unit' => 'rows', 'bytes' => 0]);
                $quoted = CSM_Support::ident($table);
                $ddl = $wpdb->get_row('SHOW CREATE TABLE ' . $quoted, ARRAY_N);
                if (!$ddl) { throw new RuntimeException('Cannot read table schema.'); }
                if (preg_match('/\bFOREIGN KEY\b/i', $ddl[1])) {
                    throw new RuntimeException('Foreign-key tables are not supported in this release: ' . $table);
                }
                $columns = $wpdb->get_results('SHOW COLUMNS FROM ' . $quoted, ARRAY_A);
                $columns = array_values(array_filter($columns, fn($column) => !str_contains($column['Extra'], 'GENERATED')));
                $names = array_column($columns, 'Field');
                $text_columns = array_column(array_filter($columns, fn($column) => (bool)preg_match('/^(char|varchar|tinytext|text|mediumtext|longtext|enum|set|json)\b/i', $column['Type'])), 'Field');
                $line = CSM_Support::json(['type' => 'table', 'name' => $table, 'schema' => $ddl[1], 'columns' => $names, 'text_columns' => $text_columns]);
                hash_update($hash, $line); yield $line;
                $keys = $wpdb->get_results('SHOW INDEX FROM ' . $quoted . " WHERE Key_name = 'PRIMARY' ORDER BY Seq_in_index", ARRAY_A);
                $keys = array_column($keys, 'Column_name');
                $last = null; $offset = 0;
                do {
                    $where = '';
                    if ($last && $keys) {
                        $clauses = [];
                        foreach ($keys as $index => $key) {
                            $terms = [];
                            for ($j = 0; $j <= $index; $j++) {
                                $terms[] = CSM_Support::ident($keys[$j]) . ($j === $index ? ' > ' : ' = ') . $wpdb->prepare('%s', $last[$keys[$j]]);
                            }
                            $clauses[] = '(' . implode(' AND ', $terms) . ')';
                        }
                        $where = ' WHERE ' . implode(' OR ', $clauses);
                    }
                    $order = $keys ? ' ORDER BY ' . implode(',', array_map([CSM_Support::class, 'ident'], $keys)) : '';
                    $limit = $keys ? ' LIMIT 250' : ' LIMIT ' . $offset . ',250';
                    $batch = $wpdb->get_results('SELECT ' . implode(',', array_map([CSM_Support::class, 'ident'], $names)) . ' FROM ' . $quoted . $where . $order . $limit, ARRAY_A);
                    if ($wpdb->last_error) { throw new RuntimeException($wpdb->last_error); }
                    foreach ($batch as $row) {
                        $last = $row;
                        if ($table === $wpdb->options && isset($row['option_name']) && str_starts_with($row['option_name'], '_csm_')) { continue; }
                        $encoded = [];
                        foreach ($row as $name => $value) { $encoded[] = $value === null ? null : base64_encode((string)$value); }
                        $line = CSM_Support::json(['type' => 'row', 'values' => $encoded]);
                        if (strlen($line) >= 32 * 1024 * 1024) { throw new RuntimeException('A database row exceeds the 32 MB stream record limit.'); }
                        hash_update($hash, $line); yield $line; $rows++;
                        CSM_Progress::update(['count' => $rows]);
                    }
                    $offset += count($batch);
                } while (count($batch) === 250);
                $line = CSM_Support::json(['type' => 'table-end']);
                hash_update($hash, $line); yield $line;
            }
            yield CSM_Support::json(['type' => 'end', 'rows' => $rows, 'sha256' => hash_final($hash)]);
        } finally {
            CSM_Support::query('ROLLBACK');
        }
    }

    public static function stage($fp, array &$job): void {
        global $wpdb;
        $header = CSM_Support::line($fp);
        if (($header['type'] ?? '') !== 'header' || ($header['version'] ?? 0) !== 1 || ($header['kind'] ?? '') !== 'database') { throw new RuntimeException('Invalid database stream header.'); }
        CSM_Activation::source($job, $header);
        $prefix = $header['prefix'];
        if (!$prefix || !is_array($header['tables']) || $header['tables'] !== $job['selection']['tables']) { throw new RuntimeException('Database stream does not match the selected tables.'); }
        $job['database'] = []; CSM_Support::save($job);
        $hash = hash_init('sha256'); $rows = 0; $current = null; $columns = []; $seen = [];
        $pairs = self::replacement_pairs($header, self::identity(), $job['selection']);
        while (true) {
            $record = CSM_Support::line($fp);
            if (($record['type'] ?? '') === 'end') {
                if ($current || $rows !== $record['rows'] || !hash_equals(hash_final($hash), $record['sha256']) || $seen !== $header['tables'] || fread($fp, 1) !== '') {
                    throw new RuntimeException('Database stream is incomplete or checksum failed.');
                }
                $job['rows'] = $rows; return;
            }
            hash_update($hash, CSM_Support::json($record));
            if ($record['type'] === 'table') {
                if ($current || !in_array($record['name'], $header['tables'], true) || in_array($record['name'], $seen, true) || !str_starts_with($record['name'], $prefix)) { throw new RuntimeException('Unexpected table.'); }
                $seen[] = $record['name'];
                CSM_Progress::update(['phase' => 'Receiving and verifying table', 'component' => $record['name'], 'count' => $rows, 'total' => null, 'unit' => 'rows', 'bytes' => 0]);
                $suffix = substr($record['name'], strlen($prefix));
                $target = $wpdb->prefix . $suffix;
                $current = '_csm_' . substr($job['id'], 0, 10) . '_' . count($job['database']);
                $backup = $current . '_old';
                CSM_Support::ident($target);
                $ddl = $record['schema'];
                // A signed peer can send data, not arbitrary executable SQL.
                if (preg_match('/;|\b(FOREIGN KEY|DATA DIRECTORY|INDEX DIRECTORY|TABLESPACE|CONNECTION|SELECT|INSERT|DROP)\b/i', $ddl) || !preg_match('/\bENGINE=InnoDB\b/i', $ddl) || !str_starts_with($ddl, 'CREATE TABLE ' . CSM_Support::ident($record['name']) . ' (')) {
                    throw new RuntimeException('Unsupported or unsafe table schema.');
                }
                $columns = $record['columns']; $text_columns = $record['text_columns'] ?? [];
                foreach ($columns as $column) { CSM_Support::ident($column); }
                $job['database'][] = ['target' => $target, 'stage' => $current, 'backup' => $backup];
                CSM_Support::save($job);
                $ddl = preg_replace('/^CREATE TABLE `[^`]+`/', 'CREATE TABLE ' . CSM_Support::ident($current), $ddl, 1);
                CSM_Support::query($ddl);
            } elseif ($record['type'] === 'row' && $current) {
                if (count($record['values']) !== count($columns)) { throw new RuntimeException('Invalid database row.'); }
                $values = [];
                foreach ($record['values'] as $index => $value) {
                    if ($value === null) { $values[] = 'NULL'; continue; }
                    $decoded = base64_decode($value, true);
                    if ($decoded === false) { throw new RuntimeException('Invalid row encoding.'); }
                    if (in_array($columns[$index], $text_columns, true)) { $decoded = CSM_Support::replace($decoded, $pairs); }
                    if ($prefix !== $wpdb->prefix && in_array($columns[$index], ['meta_key', 'option_name'], true) && str_starts_with($decoded, $prefix)) {
                        $decoded = $wpdb->prefix . substr($decoded, strlen($prefix));
                    }
                    $values[] = "X'" . bin2hex($decoded) . "'";
                }
                CSM_Support::query('INSERT INTO ' . CSM_Support::ident($current) . ' (' . implode(',', array_map([CSM_Support::class, 'ident'], $columns)) . ') VALUES (' . implode(',', $values) . ')');
                $rows++;
                CSM_Progress::update(['count' => $rows]);
            } elseif ($record['type'] === 'table-end' && $current) {
                $current = null;
            } else { throw new RuntimeException('Unexpected database record.'); }
        }
    }

    public static function preserve_options(array $job, bool $connection_only = false): void {
        global $wpdb;
        foreach ($job['database'] ?? [] as $table) {
            if ($table['target'] !== $wpdb->options) { continue; }
            $staged = CSM_Support::ident($table['stage']);
            // Preserve destination settings during table swap; activation is applied separately before the health check.
            $names = $connection_only ? [] : ['siteurl', 'home', 'active_plugins', 'cron', 'blog_public', 'upload_path', 'upload_url_path'];
            CSM_Support::query($wpdb->prepare('DELETE FROM ' . $staged . ' WHERE option_name LIKE %s', $wpdb->esc_like('_csm_') . '%'));
            foreach ($names as $name) { CSM_Support::query($wpdb->prepare('DELETE FROM ' . $staged . ' WHERE option_name = %s', $name)); }
            $rows = $wpdb->get_results('SELECT option_name, option_value, autoload FROM ' . CSM_Support::ident($wpdb->options), ARRAY_A);
            foreach ($rows as $row) {
                if (!str_starts_with($row['option_name'], '_csm_') && !in_array($row['option_name'], $names, true)) { continue; }
                CSM_Support::query($wpdb->prepare('DELETE FROM ' . $staged . ' WHERE option_name = %s', $row['option_name']));
                CSM_Support::query($wpdb->prepare('INSERT INTO ' . $staged . ' (option_name, option_value, autoload) VALUES (%s,%s,%s)', $row['option_name'], $row['option_value'], $row['autoload']));
            }
        }
    }
}
