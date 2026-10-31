<?php
defined('ABSPATH') || exit;

final class CSM_Backups {
    public static function forget(string $id): void {
        update_option('_csm_backups', array_values(array_diff(get_option('_csm_backups', []), [$id])), false);
    }

    public static function retain(array $job): void {
        $ids = array_values(array_unique(array_merge([$job['id']], get_option('_csm_backups', []))));
        update_option('_csm_backups', $ids, false);
        if (get_option('_csm_active_job') === $job['id']) { delete_option('_csm_active_job'); }
        // Cleanup failure must not turn a successful migration into a rollback.
        try {
            CSM_Segments::cleanup($job['id']);
            CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id']);
        } catch (Throwable $e) { CSM_Log::write('error', 'Completed staging cleanup needs attention', ['transfer_id' => $job['id'], 'error' => $e->getMessage()]); }
        foreach (array_slice($ids, 3) as $id) {
            try { CSM_Plugin::discard(CSM_Support::job($id)); }
            catch (Throwable $e) { CSM_Log::write('error', 'Old backup cleanup needs attention', ['transfer_id' => $id, 'error' => $e->getMessage()]); }
        }
    }

    public static function listing(): array {
        $items = [];
        foreach (get_option('_csm_backups', []) as $id) {
            try { $job = CSM_Support::job($id); } catch (Throwable $e) { continue; }
            try { self::authorize($job); } catch (Throwable $e) { continue; }
            if ($job['status'] !== 'applied') { continue; }
            $items[] = ['id' => $id, 'created' => $job['applied'], 'source' => $job['source'] ?? '',
                'tables' => array_column($job['database'], 'target'), 'plugins' => array_values(array_filter($job['selection']['plugins'], static fn($name) => !CSM_Files::skipped('plugins/' . $name, $job['selection']))), 'themes' => array_values(array_filter($job['selection']['themes'], static fn($name) => !CSM_Files::skipped('themes/' . $name, $job['selection']))),
                'media' => ($job['selection']['media'] ?? 'none') !== 'none', 'temporary' => !isset($job['backup_dir']) || str_starts_with($job['backup_dir'], CSM_Support::private_dir() . '/'), 'plugin_version' => $job['plugin_version'] ?? ''];
        }
        $restore = get_option('_csm_backup_restore');
        if ($restore) { self::authorize(CSM_Support::job($restore['backup_id'])); }
        return ['backups' => $items, 'destination' => site_url(), 'recovery' => $restore ?: null];
    }

    private static function authorize(array $job): void {
        if (isset($GLOBALS['_csm_credential'])) { CSM_Access::assert_owner($job); }
    }

    public static function restore(string $id): array {
        global $wpdb;
        if (!in_array($id, get_option('_csm_backups', []), true)) { throw new RuntimeException('Backup not found. Refresh the backup list.'); }
        $original = CSM_Support::job($id); self::authorize($original);
        $restore = get_option('_csm_backup_restore');
        if ($restore && $restore['backup_id'] !== $id) { throw new RuntimeException('Finish restoring the interrupted backup first.'); }
        if (get_option('_csm_active_job') && get_option('_csm_active_job') !== ($restore['job_id'] ?? '')) { throw new RuntimeException('Finish or cancel the active transfer before restoring a backup.'); }
        if ($restore) { $job = CSM_Support::job($restore['job_id']); }
        else {
            if ($original['status'] !== 'applied') { throw new RuntimeException('This backup is no longer available.'); }
            $ops = CSM_Plugin::recovery_operations($original);
            $selection = $original['selection']; $selection['git_overrides'] = [];
            if ($ops && (!wp_is_file_mod_allowed('trickle') || !CSM_Plugin::loader_ready())) { throw new RuntimeException('File restoration needs writable files and the current Trickle recovery loader.'); }
            $known = [];
            foreach ($ops as $op) {
                CSM_Files::destination($op['path'], $selection); $known[$op['path']] = true;
                if ($op['existed'] && (!is_file($op['backup']) || (isset($op['sha256']) && !hash_equals($op['sha256'], hash_file('sha256', $op['backup']))))) { throw new RuntimeException('A backup file is missing or changed: ' . $op['path']); }
            }
            // A saved code component is a snapshot, including files introduced by later migrations.
            if (!empty($original['code_snapshot'])) {
                foreach (['plugins', 'themes'] as $kind) {
                    foreach ($selection[$kind] as $component) {
                        $relative = $kind . '/' . $component;
                        if (CSM_Files::skipped($relative, $selection)) { continue; }
                        $path = CSM_Files::destination($relative, $selection);
                        foreach (CSM_Files::scan($path, $relative, $selection) as $file) {
                            $name = $kind . '/' . substr($file, strlen(CSM_Files::root($kind, $component)) + 1);
                            CSM_Files::destination($name, $selection);
                            if (!isset($known[$name])) { $ops[] = ['path' => $name, 'existed' => false]; }
                        }
                    }
                }
            }
            foreach ($original['database'] as $table) {
                if (!empty($table['existed']) && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table['backup']))) !== $table['backup']) { throw new RuntimeException('A backup table is missing: ' . $table['target']); }
            }
            $job = $original; $job['id'] = bin2hex(random_bytes(16)); $job['selection'] = $selection; $job['ops'] = $ops; $job['database'] = [];
            unset($job['ops_hash'], $job['error'], $job['backup_dir']);
            foreach ($original['database'] as $i => $table) {
                $copy = '_csm_' . substr($job['id'], 0, 10) . '_' . $i;
                $job['database'][] = array_merge($table, ['stage' => $copy, 'backup' => $copy . '_old']);
            }
            $job['status'] = 'backup_copy'; CSM_Support::save($job);
            update_option('_csm_backup_restore', ['backup_id' => $id, 'job_id' => $job['id']], false);
            update_option('_csm_active_job', $job['id'], false);
            wp_schedule_single_event(time() + 60, 'trickle_recover', [$job['id']]);
        }
        // Journal the copy phase too; a killed copy can restart without touching live tables.
        if ($job['status'] === 'backup_copy') {
            foreach ($original['database'] as $i => $table) {
                if (empty($table['existed'])) { continue; }
                CSM_Progress::update(['phase' => 'Copying backup table', 'component' => $table['target'], 'count' => $i, 'total' => count($original['database']), 'unit' => 'tables']);
                $copy = CSM_Support::ident($job['database'][$i]['backup']);
                CSM_Support::query('DROP TABLE IF EXISTS ' . $copy);
                CSM_Support::query('CREATE TABLE ' . $copy . ' LIKE ' . CSM_Support::ident($table['backup']));
                $columns = $wpdb->get_results('SHOW COLUMNS FROM ' . CSM_Support::ident($table['backup']), ARRAY_A);
                $columns = implode(',', array_map([CSM_Support::class, 'ident'], array_column(array_filter($columns, static fn($column) => !str_contains($column['Extra'], 'GENERATED')), 'Field')));
                CSM_Support::query('INSERT INTO ' . $copy . ' (' . $columns . ') SELECT ' . $columns . ' FROM ' . CSM_Support::ident($table['backup']));
            }
            $job['status'] = 'applying'; CSM_Support::save($job);
        }
        update_option('_csm_maintenance', ['id' => $job['id'], 'probe' => hash('sha256', random_bytes(32)), 'until' => time() + 15 * MINUTE_IN_SECONDS], false);
        $result = CSM_Plugin::rollback($job);
        CSM_Plugin::discard(CSM_Support::job($job['id'])); delete_option('_csm_backup_restore');
        return $result + ['restored_backup' => $id, 'destination' => site_url()];
    }
}
