<?php
// Real SQL read failures and resumable backup copying; idle QA-C fixtures only.
if (realpath(ABSPATH) !== realpath(getenv('HOME').'/Cove/Sites/trickle-qa-c.localhost/public') || get_option('_csm_control') || get_option('_csm_active_job') || get_option('_csm_backup_restore')) { throw new RuntimeException('Idle QA-C required.'); }
global $wpdb;
$targets = [$wpdb->prefix.'csm_read_fixture', $wpdb->prefix.'csm_read_keyless_fixture'];
$backups = ['_csm_read_backup0', '_csm_read_backup1'];
$stages = ['_csm_read_stage0', '_csm_read_stage1'];
$tables = array_merge($targets, $backups, $stages);
foreach ($tables as $table) { if (CSM_Database::table_exists($table)) { throw new RuntimeException('Fixture already exists.'); } }
$old_backups = get_option('_csm_backups', []); $id = null; $checks = 0;
$check = static function($ok, $label) use (&$checks) { if (!$ok) { throw new RuntimeException($label); } $checks++; echo 'PASS '.$label."\n"; };
$fail = static function(callable $operation, callable $matches, string $label) use ($check, $wpdb) {
    $reject = static fn($sql) => $matches($sql) ? 'INVALID TRICKLE READ TEST QUERY' : $sql;
    $errors = $wpdb->suppress_errors(true); add_filter('query', $reject); $caught = false;
    try { $operation(); }
    catch (RuntimeException $e) { $caught = str_contains($e->getMessage(), 'Database read failed'); }
    finally { remove_filter('query', $reject); $wpdb->suppress_errors($errors); }
    $check($caught, $label);
};
CSM_Support::lock();
try {
    foreach ([0, 1] as $i) {
        $schema = '(a int NOT NULL,b int NOT NULL,v text,doubled int AS (a*2) STORED'.($i === 0 ? ',PRIMARY KEY (b,a)' : '').') ENGINE=InnoDB';
        foreach ([$targets[$i], $backups[$i]] as $table) { CSM_Support::query('CREATE TABLE '.CSM_Support::ident($table).' '.$schema); }
        CSM_Support::query('INSERT INTO '.CSM_Support::ident($targets[$i])." (a,b,v) VALUES (99999,0,'current destination')");
        $values = [];
        for ($n = 599; $n >= 0; $n--) { $values[] = '('.$n.','.($n%7).",'row-".$n."')"; }
        if ($i === 1) { $values[] = $values[0]; $values[] = $values[0]; }
        CSM_Support::query('INSERT INTO '.CSM_Support::ident($backups[$i]).' (a,b,v) VALUES '.implode(',', $values));
    }
    $fail(static fn() => CSM_Database::inventory(), static fn($sql) => str_contains($sql, 'information_schema.TABLES'), 'Inventory SQL failures cannot look like an empty site');
    $check(CSM_Database::table_exists($targets[0]) && !CSM_Database::table_exists($stages[0]), 'Successful table lookups still distinguish present and absent tables');
    $caught = false;
    try { CSM_Database::page_sql(['b','a'], ['b'=>1], 250, ['a','b']); }
    catch (RuntimeException $e) { $caught = str_contains($e->getMessage(), 'checkpoint is incomplete'); }
    $check($caught, 'Incomplete compound-key cursors stop before constructing the next page');
    foreach (['SHOW CREATE TABLE', 'SHOW COLUMNS FROM', 'SHOW INDEX FROM', 'SELECT `a`'] as $prefix) {
        $fail(static function() use ($targets) { foreach (CSM_Database::stream(['tables'=>[$targets[0]]]) as $line) {} },
            static fn($sql) => str_starts_with($sql, $prefix) && str_contains($sql, '`'.$targets[0].'`'), 'Export stops on failed '.$prefix.' reads');
    }
    $restore = ['database'=>[['target'=>$targets[0], 'stage'=>$stages[0], 'backup'=>$stages[1], 'existed'=>false]]];
    $fail(static fn() => CSM_Database::restore_tables($restore), static fn($sql) => str_starts_with($sql, 'SHOW TABLES LIKE'), 'Failed existence lookup stops restoration before any table drop');
    $check(CSM_Database::rows('SELECT v FROM '.CSM_Support::ident($targets[0]))[0]['v'] === 'current destination', 'Lookup failure leaves the current destination table intact');

    $summary = CSM_Plugin::prepare(['tables'=>$targets, 'plugins'=>[], 'themes'=>[], 'media'=>'none', 'sync_active_plugins'=>false]); $id = $summary['id'];
    $job = CSM_Jobs::get($id); $job['status'] = 'applied'; $job['applied'] = time();
    foreach ([0, 1] as $i) { $job['database'][] = ['target'=>$targets[$i], 'stage'=>$stages[$i], 'backup'=>$backups[$i], 'existed'=>true]; }
    CSM_Jobs::save($job); update_option('_csm_backups', [$id], false); CSM_Backups::retain($job);
    $cursor_read = static fn($sql) => str_starts_with($sql, 'SELECT ') && str_contains($sql, '_csm_') && str_contains($sql, ' DESC');
    $fail(static fn() => CSM_Backups::restore($id), $cursor_read, 'Failed backup cursor read aborts its copy transaction');
    $state = get_option('_csm_backup_restore'); $copy_job = CSM_Jobs::get($state['job_id']);
    $copied = CSM_Database::rows('SELECT COUNT(*) AS n FROM '.CSM_Support::ident($copy_job['database'][0]['backup']))[0]['n'];
    $check((int)$copied === 0 && $copy_job['backup_copy']['offset'] === 0, 'Rolled-back rows and their saved cursor remain at the same acknowledged offset');
    foreach (['SHOW COLUMNS FROM', 'SHOW INDEX FROM'] as $prefix) {
        $fail(static fn() => CSM_Backups::restore($id), static fn($sql) => str_starts_with($sql, $prefix) && str_contains($sql, '`'.$backups[0].'`'), 'Backup copying stops on failed '.$prefix.' reads');
    }
    $empty_cursor = static fn($sql) => $cursor_read($sql) ? 'SELECT 1 WHERE 1=0' : $sql;
    add_filter('query', $empty_cursor); $caught = false;
    try { CSM_Backups::restore($id); }
    catch (RuntimeException $e) { $caught = str_contains($e->getMessage(), 'row checkpoint is missing'); }
    finally { remove_filter('query', $empty_cursor); }
    $check($caught, 'An unexpectedly empty copy cursor also rolls back instead of repeating a page');
    $check(CSM_Database::rows('SELECT v FROM '.CSM_Support::ident($targets[0]))[0]['v'] === 'current destination', 'Copy failures never replace the live destination table');
    $short_steps = static fn() => .001; add_filter('csm_step_seconds', $short_steps);
    try { for ($steps=0; $steps<50; $steps++) { $result=CSM_Backups::restore($id); if (empty($result['continue'])) { break; } } }
    finally { remove_filter('csm_step_seconds', $short_steps); }
    $check($result['status'] === 'restored' && $steps > 0 && $steps < 50, 'Backup restoration resumes successfully across bounded copy steps');
    foreach ([0, 1] as $i) {
        $read = static fn($table) => CSM_Database::rows('SELECT * FROM '.CSM_Support::ident($table).' ORDER BY a,b,v');
        $check($read($targets[$i]) === $read($backups[$i]), $i === 0 ? 'Compound-key backup restores every row and generated column exactly' : 'Keyless backup preserves duplicate rows and generated columns exactly');
    }
    echo $checks." checked database-read / backup checks passed.\n";
} finally {
    $restore = get_option('_csm_backup_restore');
    if ($id && $restore && $restore['backup_id'] === $id) {
        for ($i=0; $i<50; $i++) { if (empty(CSM_Backups::restore($id)['continue'])) { break; } }
    }
    if ($id && get_option('_csm_job_'.$id)) { CSM_Plugin::discard(CSM_Jobs::get($id)); }
    foreach ($tables as $table) { CSM_Support::query('DROP TABLE IF EXISTS '.CSM_Support::ident($table)); }
    update_option('_csm_backups', $old_backups, false); CSM_Support::unlock();
}
