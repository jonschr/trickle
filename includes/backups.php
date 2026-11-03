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
        // Each copy batch and its cursor commit together; a killed worker repeats no acknowledged rows.
        CSM_Support::work($job['id'],false); CSM_Support::step();
        if ($job['status']==='backup_copy') {
            $cursor=$job['backup_copy'] ?? ['table'=>0,'offset'=>0,'initialized'=>false];
            while ($cursor['table']<count($original['database'])) {
                $i=$cursor['table']; $table=$original['database'][$i];
                if (empty($table['existed'])) { $cursor=['table'=>$i+1,'offset'=>0,'initialized'=>false]; continue; }
                $copy=CSM_Support::ident($job['database'][$i]['backup']); $source=CSM_Support::ident($table['backup']);
                CSM_Progress::update(['phase'=>'Copying backup table','component'=>$table['target'],'count'=>$cursor['offset'],'total'=>null,'unit'=>'rows']);
                if (!$cursor['initialized']) {
                    CSM_Support::query('DROP TABLE IF EXISTS '.$copy); CSM_Support::query('CREATE TABLE '.$copy.' LIKE '.$source);
                    $cursor['initialized']=true; $job['backup_copy']=$cursor; CSM_Support::save($job);
                }
                $columns=$wpdb->get_results('SHOW COLUMNS FROM '.$source,ARRAY_A);
                $copy_columns=array_column(array_filter($columns,static fn($c)=>!str_contains($c['Extra'],'GENERATED')),'Field');
                $columns=implode(',',array_map([CSM_Support::class,'ident'],$copy_columns));
                $keys=$wpdb->get_results("SHOW INDEX FROM ".$source." WHERE Key_name='PRIMARY'",ARRAY_A);
                usort($keys,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']); $keys=array_column($keys,'Column_name');
                $where='';
                if ($keys && isset($cursor['last'])) {
                    $clauses=[];
                    foreach ($keys as $index=>$key) { $terms=[]; for ($j=0;$j<=$index;$j++) { $terms[]=CSM_Support::ident($keys[$j]).($j===$index?' > ':' = ').$wpdb->prepare('%s',$cursor['last'][$keys[$j]]); } $clauses[]='('.implode(' AND ',$terms).')'; }
                    $where=' WHERE '.implode(' OR ',$clauses);
                }
                $order=$keys?' ORDER BY '.implode(',',array_map([CSM_Support::class,'ident'],$keys)):CSM_Database::keyless_order($copy_columns);
                $limit=$keys?' LIMIT 250':' LIMIT '.$cursor['offset'].',250';
                CSM_Support::query('START TRANSACTION');
                try {
                    $count=CSM_Support::query('INSERT INTO '.$copy.' ('.$columns.') SELECT '.$columns.' FROM '.$source.$where.$order.$limit);
                    $cursor['offset']+=$count;
                    if ($keys && $count) { $cursor['last']=$wpdb->get_row('SELECT '.implode(',',array_map([CSM_Support::class,'ident'],$keys)).' FROM '.$copy.' ORDER BY '.implode(',',array_map(static fn($key)=>CSM_Support::ident($key).' DESC',$keys)).' LIMIT 1',ARRAY_A); }
                    if ($count<250) { $cursor=['table'=>$i+1,'offset'=>0,'initialized'=>false]; }
                    $job['backup_copy']=$cursor; CSM_Support::save_transactional($job); CSM_Support::query('COMMIT'); wp_cache_delete('_csm_job_'.$job['id'],'options');
                } catch (Throwable $error) { CSM_Support::query('ROLLBACK'); throw $error; }
                if (CSM_Support::due()) { return CSM_Plugin::summary($job)+['continue'=>true]; }
            }
            $job['status']='applying'; CSM_Support::save($job);
        }
        update_option('_csm_maintenance', ['id' => $job['id'], 'probe' => hash('sha256', random_bytes(32)), 'until' => time() + 15 * MINUTE_IN_SECONDS], false);
        $result = CSM_Plugin::rollback($job);
        if (!empty($result['continue'])) { return $result; }
        CSM_Plugin::discard(CSM_Support::job($job['id'])); delete_option('_csm_backup_restore');
        return $result + ['restored_backup' => $id, 'destination' => site_url()];
    }
}
