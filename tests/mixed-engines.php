<?php
// wp --path=<idle Trickle Main Dev> --skip-plugins --skip-themes eval-file <this file>
require_once WP_PLUGIN_DIR . '/trickle/trickle.php';
if (realpath(ABSPATH) !== realpath(getenv('HOME').'/Cove/Sites/trickle-main-dev.localhost/public') || get_option('_csm_control') || get_option('_csm_active_job') || get_option('_csm_backup_restore')) { throw new RuntimeException('Idle Trickle Main Dev required.'); }
global $wpdb;
$tables = array_map(fn($n)=>$wpdb->prefix.'csm_engine_fixture_'.$n, ['innodb','memory','myisam']);
foreach ($tables as $table) if (CSM_Database::table_exists($table)) throw new RuntimeException('Fixture already exists.');
$job = null; $checks = 0; $old_backups = get_option('_csm_backups', []);
$check = static function($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException($label); $checks++; echo 'PASS '.$label."\n"; };
CSM_Support::lock();
try {
    foreach (['InnoDB','MEMORY','MyISAM'] as $i=>$engine) {
        CSM_Support::query('CREATE TABLE '.CSM_Support::ident($tables[$i]).' (id int PRIMARY KEY, value varchar(80)) ENGINE='.$engine);
        for ($j=0;$j<260;$j++) CSM_Support::query('INSERT INTO '.CSM_Support::ident($tables[$i])." VALUES ($j,'source-$j')");
    }
    foreach ([false,true] as $framed) {
        $selection=['tables'=>$tables,'plugins'=>[],'themes'=>[],'media'=>'none','replace'=>false,'sync_active_plugins'=>false];
        $fp=tmpfile();
        $concurrent=false;
        foreach (CSM_Database::stream($selection,$framed) as $line) {
            fwrite($fp,$line);
            if (!$framed && !$concurrent && (json_decode($line,true)['type'] ?? '')==='table') {
                $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->suppress_errors(true);$other->query('SET SESSION lock_wait_timeout=1');
                $check($other->query('UPDATE '.CSM_Support::ident($tables[0])." SET value='concurrent' WHERE id=0")!==false,'InnoDB writes continue during mixed snapshot');
                $check($other->query('UPDATE '.CSM_Support::ident($tables[1])." SET value='concurrent' WHERE id=0")===false,'MEMORY writes wait while snapshot read lock is held');
                $other->close();$concurrent=true;
            }
        }
        rewind($fp);
        foreach ($tables as $table) CSM_Support::query('UPDATE '.CSM_Support::ident($table)." SET value='destination'");
        $summary=CSM_Plugin::prepare($selection); $job=CSM_Jobs::get($summary['id']);
        if ($framed) {
            $short = static fn()=>.001; add_filter('csm_step_seconds',$short);$steps=0;
            try { do { $done=CSM_Database::stage_step($fp,$job);$steps++; } while (!$done && $steps<100); }
            finally { remove_filter('csm_step_seconds',$short); }
            $check($done && $steps>1,'Mixed engines resume across bounded import steps');
        }
        else CSM_Database::stage($fp,$job);
        fclose($fp);
        $check($job['rows']===780,'All mixed-engine rows staged (format '.($framed?2:1).')');
        foreach ($job['database'] as $table) $check(CSM_Database::schema_engine(CSM_Database::rows('SHOW CREATE TABLE '.CSM_Support::ident($table['stage']),ARRAY_N)[0][1])==='INNODB','Transactional staging for '.$table['target']);
        CSM_Database::replace_tables($job);
        foreach (['INNODB','MEMORY','MYISAM'] as $i=>$engine) {
            $table=CSM_Support::ident($tables[$i]);
            $check(CSM_Database::schema_engine(CSM_Database::rows('SHOW CREATE TABLE '.$table,ARRAY_N)[0][1])===$engine,'Original engine preserved: '.$engine);
            $check((int)CSM_Database::rows('SELECT COUNT(*) AS n FROM '.$table." WHERE value LIKE 'source-%'")[0]['n']===260,'All source rows replaced: '.$engine);
            $check(CSM_Database::schema_engine(CSM_Database::rows('SHOW CREATE TABLE '.CSM_Support::ident($job['database'][$i]['backup']),ARRAY_N)[0][1])==='INNODB','Durable backup for '.$engine);
        }
        if ($framed) {
            $job['status']='applied';$job['applied']=time();CSM_Jobs::save($job);
            if (get_option('_csm_active_job')===$job['id']) delete_option('_csm_active_job');
            update_option('_csm_backups',array_merge([$job['id']],$old_backups),false);
            do { $restored=CSM_Backups::restore($job['id']); } while (!empty($restored['continue']));
            $check($restored['status']==='restored','Retained mixed-engine backup restores successfully');
        } else CSM_Database::restore_tables($job);
        foreach ($tables as $table) $check((int)CSM_Database::rows('SELECT COUNT(*) AS n FROM '.CSM_Support::ident($table)." WHERE value='destination'")[0]['n']===260,'Rollback restores destination rows');
        CSM_Plugin::discard(CSM_Jobs::get($job['id'])); $job=null;
        foreach ($tables as $table) CSM_Support::query('UPDATE '.CSM_Support::ident($table)." SET value=CONCAT('source-',id)");
    }
    // A source failure must release both read locks and the transaction.
    $reject=static fn($sql)=>str_starts_with($sql,'SHOW COLUMNS FROM') && str_contains($sql,'csm_engine_fixture_memory') ? 'INVALID ENGINE FIXTURE QUERY' : $sql;
    add_filter('query',$reject);$caught=false;
    try { foreach(CSM_Database::stream(['tables'=>$tables]) as $line) {} } catch(RuntimeException $e) { $caught=true; }
    finally { remove_filter('query',$reject); }
    $check($caught,'Source read failures stop export');
    $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->query('SET SESSION lock_wait_timeout=1');
    $check($other->query('UPDATE '.CSM_Support::ident($tables[1])." SET value='unlocked'")!==false,'Source locks released after failure');$other->close();
    echo "$checks mixed-engine checks passed.\n";
} finally {
    if ($job) { CSM_Plugin::discard(CSM_Jobs::get($job['id'])); }
    foreach ($tables as $table) CSM_Support::query('DROP TABLE IF EXISTS '.CSM_Support::ident($table));
    update_option('_csm_backups',$old_backups,false);
    CSM_Support::unlock();
}
