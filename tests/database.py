#!/usr/bin/env python3
"""Real MariaDB primary-key pagination on disposable QA-A; no transfer or apply."""
import json
import subprocess

code = r'''
global $wpdb;
if (home_url() !== 'https://trickle-qa-a.localhost:8453' || get_option('_csm_control') || get_option('_csm_active_job')) { throw new RuntimeException('QA-A must be idle.'); }
$tables = [$wpdb->prefix.'csm_keyset_fixture', $wpdb->prefix.'csm_offset_fixture'];
foreach ($tables as $table) { if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) { throw new RuntimeException('Fixture already exists.'); } }
$queries = [];
$capture = static function($sql) use (&$queries, $tables) {
    if (str_starts_with($sql, 'SELECT ') && str_contains($sql, ' FROM `'.$tables[0].'`')) { $queries[] = $sql; }
    return $sql;
};
try {
    CSM_Support::query('CREATE TABLE `'.$tables[0].'` (a int NOT NULL,b int NOT NULL,payload text,PRIMARY KEY (b,a)) ENGINE=InnoDB');
    CSM_Support::query('CREATE TABLE `'.$tables[1].'` (a int NOT NULL,b int NOT NULL,payload text) ENGINE=InnoDB');
    $values = [];
    for ($i=599;$i>=0;$i--) { $values[] = '('.$i.','.($i%7).',\'row-'.$i.'\')'; }
    foreach ($tables as $table) { CSM_Support::query('INSERT INTO `'.$table.'` VALUES '.implode(',', $values)); }
    add_filter('query', $capture);
    $result = [];
    foreach ($tables as $table) {
        $rows = []; $end = null;
        foreach (CSM_Database::stream(['tables'=>[$table]]) as $line) {
            $record = json_decode($line,true,512,JSON_THROW_ON_ERROR);
            if ($record['type']==='row') { $rows[] = array_map('base64_decode',$record['values']); }
            if ($record['type']==='end') { $end = $record; }
        }
        $ids = array_column($rows,0);
        $result[] = count($rows)===600 && count(array_unique($ids))===600 && $end['rows']===600 && !$wpdb->last_error;
        if ($table===$tables[0]) {
            $sorted = $rows;
            usort($sorted, static fn($a,$b) => (int)$a[1] <=> (int)$b[1] ?: (int)$a[0] <=> (int)$b[0]);
            $result[] = $rows===$sorted;
        }
    }
    $result[] = count($queries)===3 && str_contains($queries[1],' WHERE ') && str_contains($queries[1],'ORDER BY `b`,`a` LIMIT 250') && !str_contains(implode(' ',$queries),'LIMIT 250,');
    echo wp_json_encode($result);
} finally {
    remove_filter('query',$capture);
    foreach ($tables as $table) { CSM_Support::query('DROP TABLE IF EXISTS `'.$table.'`'); }
}
'''
p = subprocess.run(['cove', 'wp', 'trickle-qa-a', 'eval', code], capture_output=True, text=True, timeout=120)
assert p.returncode == 0, p.stdout + p.stderr
checks = json.loads(p.stdout.splitlines()[-1])
assert len(checks) == 4 and all(checks), checks
print('4 database checks passed: compound-key order, keyset pages, complete rows, and tables without keys.')
