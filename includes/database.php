<?php
defined('ABSPATH') || exit;

final class CSM_Database {
    public static function rows(string $sql, string $format = ARRAY_A, ?wpdb $reader = null): array {
        global $wpdb;
        $reader ??= $wpdb;
        $rows = $reader->get_results($sql, $format);
        if ($reader->last_error !== '' || !is_array($rows)) { throw new RuntimeException('Database read failed: ' . $reader->last_error); }
        return $rows;
    }

    public static function table_exists(string $table): bool {
        global $wpdb;
        $rows = self::rows($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)), ARRAY_N);
        return ($rows[0][0] ?? null) === $table;
    }

    public static function primary_keys(string $table, ?wpdb $reader = null): array {
        $keys = self::rows('SHOW INDEX FROM ' . CSM_Support::ident($table) . " WHERE Key_name = 'PRIMARY'", ARRAY_A, $reader);
        usort($keys, static fn($a, $b) => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        return array_column($keys, 'Column_name');
    }

    public static function page_sql(array $keys, ?array $last, int $offset, array $columns): string {
        global $wpdb;
        $where = '';
        if ($keys && $last !== null) {
            $clauses = [];
            foreach ($keys as $index => $key) {
                $terms = [];
                for ($j = 0; $j <= $index; $j++) {
                    if (!array_key_exists($keys[$j], $last)) { throw new RuntimeException('Database row checkpoint is incomplete.'); }
                    $terms[] = CSM_Support::ident($keys[$j]) . ($j === $index ? ' > ' : ' = ') . $wpdb->prepare('%s', $last[$keys[$j]]);
                }
                $clauses[] = '(' . implode(' AND ', $terms) . ')';
            }
            $where = ' WHERE ' . implode(' OR ', $clauses);
        }
        $order = $keys ? ' ORDER BY ' . implode(',', array_map([CSM_Support::class, 'ident'], $keys)) : self::keyless_order($columns);
        return $where . $order . ($keys ? ' LIMIT 250' : ' LIMIT ' . $offset . ',250');
    }

    public static function inventory(): array {
        global $wpdb;
        $sql = $wpdb->prepare('SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = %s ORDER BY TABLE_NAME', DB_NAME, 'BASE TABLE');
        $result = [];
        foreach (self::rows($sql) as $row) {
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
            if (!in_array(strtoupper($allowed[$table]['engine']), ['INNODB', 'MYISAM', 'MEMORY'], true)) {
                throw new RuntimeException($table . ' uses an unsupported database engine: ' . $allowed[$table]['engine']);
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

    public static function keyless_order(array $columns): string {
        // Equal rows may tie; distinct row contents must have stable paging even without an index.
        $values=array_map(static fn($column)=>"IFNULL(HEX(CAST(".CSM_Support::ident($column)." AS BINARY)),'NULL')",$columns);
        return " ORDER BY SHA2(CONCAT_WS(',',".implode(',',$values)."),256)";
    }

    public static function stream(array $selection, bool $chain = false): Generator {
        global $wpdb;
        $tables = self::validate($selection['tables'] ?? []);
        yield CSM_Support::json(array_merge(['type' => 'header', 'version' => $chain ? 2 : 1, 'kind' => 'database', 'prefix' => $wpdb->prefix, 'tables' => $tables, 'active_plugins' => CSM_Activation::current(), 'trickle_plugin' => plugin_basename(CSM_FILE)], self::identity()));
        $engines = array_column(self::inventory(), 'engine', 'id');
        $locked = array_values(array_filter($tables, static fn($table) => strtoupper($engines[$table]) !== 'INNODB'));
        $reader = $locked ? new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST) : $wpdb;
        $hash = $chain ? '' : hash_init('sha256');
        $rows = 0;
        try {
            if ($locked) {
                // Lock only nontransactional tables; InnoDB writes continue during its snapshot.
                $reader->suppress_errors(true);
                $reader->query('SET SESSION lock_wait_timeout=5');
                if ($reader->query('LOCK TABLES ' . implode(', ', array_map(static fn($table) => CSM_Support::ident($table) . ' READ', $locked))) === false) {
                    throw new RuntimeException('Cannot safely snapshot non-InnoDB tables. Check source LOCK TABLES permission and whether a table is busy.');
                }
            }
            CSM_Support::query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            CSM_Support::query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $transaction_connection = mysqli_thread_id($wpdb->dbh);
            $locked_connection = mysqli_thread_id($reader->dbh);
            foreach ($tables as $table) {
                $table_reader = in_array($table, $locked, true) ? $reader : $wpdb;
                CSM_Support::check_source_budget();
                CSM_Progress::update(['phase' => 'Exporting table', 'component' => $table, 'count' => $rows, 'total' => null, 'unit' => 'rows', 'bytes' => 0]);
                $quoted = CSM_Support::ident($table);
                $ddl = self::rows('SHOW CREATE TABLE ' . $quoted, ARRAY_N, $table_reader)[0] ?? null;
                if (!$ddl) { throw new RuntimeException('Cannot read table schema.'); }
                if (self::schema_engine($ddl[1]) !== strtoupper($engines[$table])) { throw new RuntimeException('Source table engine changed during preparation. Prepare again.'); }
                $columns = self::rows('SHOW COLUMNS FROM ' . $quoted, ARRAY_A, $table_reader);
                $columns = array_values(array_filter($columns, fn($column) => !str_contains($column['Extra'], 'GENERATED')));
                $names = array_column($columns, 'Field');
                $text_columns = array_column(array_filter($columns, fn($column) => (bool)preg_match('/^(char|varchar|tinytext|text|mediumtext|longtext|enum|set|json)\b/i', $column['Type'])), 'Field');
                $line = CSM_Support::json(['type' => 'table', 'name' => $table, 'schema' => $ddl[1], 'columns' => $names, 'text_columns' => $text_columns]);
                if ($chain) { $hash = CSM_Package::chain($hash,$line); } else { hash_update($hash,$line); } yield $line;
                $keys = self::primary_keys($table, $table_reader);
                $last = null; $offset = 0;
                do {
                    $batch = self::rows('SELECT ' . implode(',', array_map([CSM_Support::class, 'ident'], $names)) . ' FROM ' . $quoted . self::page_sql($keys, $last, $offset, $names), ARRAY_A, $table_reader);
                    if (mysqli_thread_id($table_reader->dbh) !== ($table_reader === $wpdb ? $transaction_connection : $locked_connection)) { throw new RuntimeException('Source database reconnected during snapshot. Prepare again.'); }
                    foreach ($batch as $row) {
                        CSM_Support::check_source_budget();
                        $last = $row;
                        if ($table === $wpdb->options && isset($row['option_name']) && str_starts_with($row['option_name'], '_csm_')) { continue; }
                        $encoded = [];
                        foreach ($row as $name => $value) { $encoded[] = $value === null ? null : base64_encode((string)$value); }
                        $line = CSM_Support::json(['type' => 'row', 'values' => $encoded]);
                        if (strlen($line) >= 32 * 1024 * 1024) { throw new RuntimeException('A database row exceeds the 32 MB stream record limit.'); }
                        if ($chain) { $hash = CSM_Package::chain($hash,$line); } else { hash_update($hash,$line); } yield $line; $rows++;
                        CSM_Progress::update(['count' => $rows]);
                    }
                    $offset += count($batch);
                } while (count($batch) === 250);
                $line = CSM_Support::json(['type' => 'table-end']);
                if ($chain) { $hash = CSM_Package::chain($hash,$line); } else { hash_update($hash,$line); } yield $line;
            }
            yield CSM_Support::json(['type' => 'end', 'rows' => $rows, 'sha256' => ($chain ? $hash : hash_final($hash))]);
        } finally {
            try { CSM_Support::query('ROLLBACK'); }
            finally { if ($locked) { $reader->query('UNLOCK TABLES'); $reader->close(); } }
        }
    }

    public static function schema_engine(string $ddl): string {
        if (!preg_match('/\bENGINE=(InnoDB|MyISAM|MEMORY)\b/i', $ddl, $match)) { throw new RuntimeException('Unsupported database engine.'); }
        return strtoupper($match[1]);
    }

    /** Rebind only SHOW CREATE TABLE's ordinary, same-database foreign keys. */
    public static function foreign_schema(string $ddl, array $mapping, string $salt): array {
        $keys = []; $lines = [];
        foreach (explode("\n", $ddl) as $line) {
            if (!preg_match('/^\s*CONSTRAINT\s+`([^`]+)`\s+FOREIGN KEY\b/', $line)) { $lines[] = $line; continue; }
            $columns = '`[a-zA-Z0-9_$-]{1,64}`(?:,\s*`[a-zA-Z0-9_$-]{1,64}`)*';
            $action = '(?:RESTRICT|CASCADE|SET NULL|NO ACTION)';
            if (!preg_match('/^\s*CONSTRAINT `([^`]+)` FOREIGN KEY \(('.$columns.')\) REFERENCES `([a-zA-Z0-9_$-]{1,64})` \(('.$columns.')\)((?: ON (?:DELETE|UPDATE) '.$action.')*)[,]?$/D', $line, $match)) {
                throw new RuntimeException('Unsupported foreign-key definition.');
            }
            if (!isset($mapping[$match[3]])) { throw new RuntimeException('Include the related table in this transfer: '.$match[3]); }
            $name = '_csm_fk_'.substr(hash('sha256', $salt.$match[1]), 0, 40);
            $keys[] = 'CONSTRAINT '.CSM_Support::ident($name).' FOREIGN KEY ('.$match[2].') REFERENCES '.CSM_Support::ident($mapping[$match[3]]).' ('.$match[4].')'.$match[5];
        }
        $ddl = preg_replace('/,(\s*\) ENGINE=)/', '$1', implode("\n", $lines));
        return [$ddl, $keys];
    }

    private static function stage_mapping(array $tables, string $id): array {
        $mapping = [];
        foreach ($tables as $index=>$name) { $mapping[$name] = '_csm_'.substr($id,0,10).'_'.$index; }
        return $mapping;
    }

    public static function attach_keys(string $table, array $keys): void {
        foreach ($keys as $key) {
            $schema = self::rows('SHOW CREATE TABLE '.CSM_Support::ident($table), ARRAY_N)[0][1];
            $name = substr($key, 0, strpos($key, ' FOREIGN KEY'));
            if (!str_contains($schema, $name)) { CSM_Support::query('ALTER TABLE '.CSM_Support::ident($table).' ADD '.$key); }
        }
    }

    public static function drop_private_tables(array $tables): void {
        global $wpdb;
        foreach ($tables as $table) { if (!preg_match('/^_csm_[a-zA-Z0-9_$-]+$/D', $table)) { throw new RuntimeException('Invalid private database table.'); } }
        $checks = (int)$wpdb->get_var('SELECT @@SESSION.foreign_key_checks');
        CSM_Support::query('SET SESSION FOREIGN_KEY_CHECKS=0');
        try { foreach ($tables as $table) { CSM_Support::query('DROP TABLE IF EXISTS '.CSM_Support::ident($table)); } }
        finally { CSM_Support::query('SET SESSION FOREIGN_KEY_CHECKS='.$checks); }
    }

    public static function stage($fp, array &$job): void {
        global $wpdb;
        $header = CSM_Support::line($fp);
        if (($header['type'] ?? '') !== 'header' || ($header['version'] ?? 0) !== 1 || ($header['kind'] ?? '') !== 'database') { throw new RuntimeException('Invalid database stream header.'); }
        CSM_Activation::source($job, $header);
        $prefix = $header['prefix'];
        if (!$prefix || !is_array($header['tables']) || $header['tables'] !== $job['selection']['tables']) { throw new RuntimeException('Database stream does not match the selected tables.'); }
        $job['database'] = []; CSM_Jobs::save($job);
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
                [$ddl, $foreign_keys] = self::foreign_schema($record['schema'], self::stage_mapping($header['tables'], $job['id']), $job['id'].$record['name']);
                // A signed peer can send data, not arbitrary executable SQL.
                if (preg_match('/;|\b(FOREIGN KEY|DATA DIRECTORY|INDEX DIRECTORY|TABLESPACE|CONNECTION|SELECT|INSERT|DROP)\b/i', $ddl) || !preg_match('/\bENGINE=(?:InnoDB|MyISAM|MEMORY)\b/i', $ddl) || !str_starts_with($ddl, 'CREATE TABLE ' . CSM_Support::ident($record['name']) . ' (')) {
                    throw new RuntimeException('Unsupported or unsafe table schema.');
                }
                $columns = $record['columns']; $text_columns = $record['text_columns'] ?? [];
                foreach ($columns as $column) { CSM_Support::ident($column); }
                $job['database'][] = ['target' => $target, 'stage' => $current, 'backup' => $backup, 'engine' => self::schema_engine($ddl), 'foreign_keys' => $foreign_keys];
                CSM_Jobs::save($job);
                $ddl = preg_replace('/^CREATE TABLE `[^`]+`/', 'CREATE TABLE ' . CSM_Support::ident($current), $ddl, 1);
                CSM_Support::query(preg_replace('/\bENGINE=(?:InnoDB|MyISAM|MEMORY)\b/i', 'ENGINE=InnoDB', $ddl, 1));
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

    public static function stage_step($fp, array &$job, bool $continuing = false): bool {
        global $wpdb;
        $state=$job['imports']['database'] ?? null;
        if (!$state) {
            $header=CSM_Support::line($fp);
            if (($header['type'] ?? '')!=='header' || ($header['version'] ?? 0)!==2 || ($header['kind'] ?? '')!=='database' || empty($header['prefix']) || ($header['tables'] ?? null)!==$job['selection']['tables']) { throw new RuntimeException('Invalid database stream header.'); }
            CSM_Activation::source($job,$header);
            $state=['header'=>$header,'offset'=>ftell($fp),'hash'=>'','rows'=>0,'current'=>null,'columns'=>[],'text_columns'=>[],'seen'=>[]];
            $job['database']=[];
        }
        $prefix=$state['header']['prefix']; $pairs=self::replacement_pairs($state['header'],self::identity(),$job['selection']);
        if (fseek($fp,$state['offset'])!==0) { throw new RuntimeException('Cannot resume table import.'); }
        CSM_Support::step(8, $continuing); $transaction=false;
        $checkpoint=static function() use (&$job,&$state,&$transaction,$fp):void {
            $state['offset']=ftell($fp); $job['imports']['database']=$state; $job['rows']=$state['rows']; if ($transaction) { CSM_Jobs::save_transactional($job); CSM_Support::query('COMMIT'); wp_cache_delete('_csm_job_'.$job['id'],'options'); $transaction=false; } else { CSM_Jobs::save($job); }
        };
        try {
            do {
                CSM_Support::check_cancel(); $record=CSM_Support::line($fp); $type=$record['type'] ?? '';
                if ($type==='end') {
                    if ($state['current'] || $state['rows']!==$record['rows'] || !hash_equals($state['hash'],$record['sha256']) || $state['seen']!==$state['header']['tables'] || fread($fp,1)!=='') { throw new RuntimeException('Database stream is incomplete or checksum failed.'); }
                    $checkpoint(); unset($job['imports']['database']); $job['rows']=$state['rows']; CSM_Jobs::save($job); return true;
                }
                $state['hash']=CSM_Package::chain($state['hash'],CSM_Support::json($record));
                if ($type==='table') {
                    if ($state['current'] || !in_array($record['name'],$state['header']['tables'],true) || in_array($record['name'],$state['seen'],true) || !str_starts_with($record['name'],$prefix)) { throw new RuntimeException('Unexpected table.'); }
                    if ($transaction) { throw new RuntimeException('Unexpected table transaction.'); }
                    $target=$wpdb->prefix.substr($record['name'],strlen($prefix)); CSM_Support::ident($target);
                    $index=count($state['seen']); $current='_csm_'.substr($job['id'],0,10).'_'.$index; $backup=$current.'_old';
                    [$ddl,$foreign_keys]=self::foreign_schema($record['schema'],self::stage_mapping($state['header']['tables'],$job['id']),$job['id'].$record['name']);
                    if (preg_match('/;|\b(FOREIGN KEY|DATA DIRECTORY|INDEX DIRECTORY|TABLESPACE|CONNECTION|SELECT|INSERT|DROP)\b/i',$ddl) || !preg_match('/\bENGINE=(?:InnoDB|MyISAM|MEMORY)\b/i',$ddl) || !str_starts_with($ddl,'CREATE TABLE '.CSM_Support::ident($record['name']).' (')) { throw new RuntimeException('Unsupported or unsafe table schema.'); }
                    foreach ($record['columns'] as $column) { CSM_Support::ident($column); }
                    // Register orphan cleanup before DDL; replaying this record safely recreates only the private stage table.
                    $job['database'][$index]=['target'=>$target,'stage'=>$current,'backup'=>$backup,'engine'=>self::schema_engine($ddl),'foreign_keys'=>$foreign_keys]; CSM_Jobs::save($job);
                    CSM_Support::query('DROP TABLE IF EXISTS '.CSM_Support::ident($current));
                    CSM_Support::query(preg_replace('/^CREATE TABLE `[^`]+`/','CREATE TABLE '.CSM_Support::ident($current),preg_replace('/\bENGINE=(?:InnoDB|MyISAM|MEMORY)\b/i','ENGINE=InnoDB',$ddl,1),1));
                    $state['seen'][]=$record['name']; $state['current']=$current; $state['columns']=$record['columns']; $state['text_columns']=$record['text_columns'] ?? [];
                    $checkpoint();
                } elseif ($type==='row' && $state['current']) {
                    if (count($record['values'])!==count($state['columns'])) { throw new RuntimeException('Invalid database row.'); }
                    if (!$transaction) { CSM_Support::query('START TRANSACTION'); $transaction=true; }
                    $values=[];
                    foreach ($record['values'] as $i=>$value) {
                        if ($value===null) { $values[]='NULL'; continue; }
                        $decoded=base64_decode($value,true); if ($decoded===false) { throw new RuntimeException('Invalid row encoding.'); }
                        if (in_array($state['columns'][$i],$state['text_columns'],true)) { $decoded=CSM_Support::replace($decoded,$pairs); }
                        if ($prefix!==$wpdb->prefix && in_array($state['columns'][$i],['meta_key','option_name'],true) && str_starts_with($decoded,$prefix)) { $decoded=$wpdb->prefix.substr($decoded,strlen($prefix)); }
                        $values[]="X'".bin2hex($decoded)."'";
                    }
                    CSM_Support::query('INSERT INTO '.CSM_Support::ident($state['current']).' ('.implode(',',array_map([CSM_Support::class,'ident'],$state['columns'])).') VALUES ('.implode(',',$values).')');
                    $state['rows']++;
                    CSM_Progress::update(['phase'=>'Staging table','component'=>end($state['seen']),'count'=>$state['rows'],'total'=>null,'unit'=>'rows']);
                    if ($state['rows']%250===0) { $checkpoint(); }
                } elseif ($type==='table-end' && $state['current']) { $state['current']=null; $checkpoint(); }
                else { throw new RuntimeException('Unexpected database record.'); }
            } while (!CSM_Support::due());
            $checkpoint(); return false;
        } catch (Throwable $error) { if ($transaction) { CSM_Support::query('ROLLBACK'); } throw $error; }
    }

    public static function replace_tables(array &$job): void {
        global $wpdb;
        $targets = array_column($job['database'], 'target');
        $relations = self::rows($wpdb->prepare('SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=%s AND REFERENCED_TABLE_SCHEMA=%s AND REFERENCED_TABLE_NAME IS NOT NULL', DB_NAME, DB_NAME));
        foreach ($relations as $relation) {
            if (in_array($relation['TABLE_NAME'],$targets,true) !== in_array($relation['REFERENCED_TABLE_NAME'],$targets,true)) {
                throw new RuntimeException('Include both related destination tables: '.$relation['TABLE_NAME'].' and '.$relation['REFERENCED_TABLE_NAME']);
            }
        }
        foreach ($job['database'] as $table) { self::attach_keys($table['stage'], $table['foreign_keys'] ?? []); }
        $renames = [];
        foreach ($job['database'] as &$table) {
            CSM_Progress::update(['phase' => 'Preparing table replacement', 'component' => $table['target'], 'count' => 0, 'total' => count($job['database']), 'unit' => 'tables']);
            if (isset($table['engine']) && $table['engine'] !== 'INNODB') {
                if (!in_array($table['engine'], ['MYISAM', 'MEMORY'], true)) { throw new RuntimeException('Invalid destination table engine.'); }
                CSM_Support::query('ALTER TABLE ' . CSM_Support::ident($table['stage']) . ' ENGINE=' . $table['engine']);
            }
            $exists = self::table_exists($table['target']);
            $table['existed'] = $exists;
            if ($exists) { $table['backup_engine'] = self::schema_engine(self::rows('SHOW CREATE TABLE ' . CSM_Support::ident($table['target']), ARRAY_N)[0][1]); }
            if ($exists) { $renames[] = CSM_Support::ident($table['target']) . ' TO ' . CSM_Support::ident($table['backup']); }
            $renames[] = CSM_Support::ident($table['stage']) . ' TO ' . CSM_Support::ident($table['target']);
        }
        unset($table);
        CSM_Jobs::save($job);
        self::preserve_options($job);
        if ($renames) { CSM_Progress::update(['phase' => 'Replacing database tables atomically', 'component' => implode(', ', array_column($job['database'], 'target'))], true); }
        if ($renames) {
            CSM_Support::query('RENAME TABLE ' . implode(', ', $renames));
            foreach ($job['database'] as $table) {
                if (!empty($table['existed']) && ($table['backup_engine'] ?? 'INNODB') !== 'INNODB') {
                    CSM_Support::query('ALTER TABLE ' . CSM_Support::ident($table['backup']) . ' ENGINE=InnoDB');
                }
            }
            CSM_Progress::update(['count' => count($job['database'])], true);
        }
        wp_cache_flush();
    }

    public static function restore_tables(array $job): void {
        foreach ($job['database'] as $table) { if (!empty($table['restore_foreign_keys']) && self::table_exists($table['backup'])) { self::attach_keys($table['backup'], $table['restore_foreign_keys']); } }
        $renames = [];
        foreach ($job['database'] as $table) {
            CSM_Progress::update(['phase' => 'Restoring table', 'component' => $table['target']]);
            $backup_exists = self::table_exists($table['backup']);
            $stage_exists = self::table_exists($table['stage']);
            if ($backup_exists) {
                if (isset($table['backup_engine']) && $table['backup_engine'] !== 'INNODB') {
                    if (!in_array($table['backup_engine'], ['MYISAM', 'MEMORY'], true)) { throw new RuntimeException('Invalid backup table engine.'); }
                    CSM_Support::query('ALTER TABLE ' . CSM_Support::ident($table['backup']) . ' ENGINE=' . $table['backup_engine']);
                }
                self::preserve_options(['database' => [['target' => $table['target'], 'stage' => $table['backup']]]], true);
                if (self::table_exists($table['target'])) { $renames[] = CSM_Support::ident($table['target']) . ' TO ' . CSM_Support::ident($table['stage']); }
                $renames[] = CSM_Support::ident($table['backup']) . ' TO ' . CSM_Support::ident($table['target']);
            } elseif (!$stage_exists && isset($table['existed']) && !$table['existed']) {
                CSM_Support::query('DROP TABLE IF EXISTS ' . CSM_Support::ident($table['target']));
            }
        }
        if ($renames) { CSM_Support::query('RENAME TABLE ' . implode(', ', $renames)); }
        wp_cache_flush();
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
            $where='option_name LIKE '.$wpdb->prepare('%s',$wpdb->esc_like('_csm_').'%');
            if ($names) { $where.=' OR option_name IN ('.implode(',',array_map(static fn($name)=>$wpdb->prepare('%s',$name),$names)).')'; }
            CSM_Support::query('INSERT INTO '.$staged.' (option_name,option_value,autoload) SELECT option_name,option_value,autoload FROM '.CSM_Support::ident($wpdb->options).' WHERE '.$where);

        }
    }
}
