<?php
// Real verification/preview loops against temporary files; no WordPress or migration writes.
define('ABSPATH', __DIR__);
$base = sys_get_temp_dir() . '/trickle-progress-' . bin2hex(random_bytes(6));
mkdir($base); mkdir($base . '/destination');
class CSM_Progress {
    public static array $events = [], $current = [];
    public static function update(array $detail): void { self::$current = array_replace(self::$current, $detail); self::$events[] = self::$current; }
}
class CSM_Files { public static function assert_writable($target,$relative) {} public static function destination($path, $selection) { return $GLOBALS['base'] . '/destination/' . basename($path); } }
class CSM_Support { public static function hash_file($path) { return hash_file('sha256',$path); } public static function private_dir() { return $GLOBALS['base']; } public static function json($data) { return json_encode($data); } }
require __DIR__ . '/../includes/plugin.php';
$check = static function ($ok, $label) { if (!$ok) throw new RuntimeException($label); };
try {
    $files = [];
    foreach (['a.php' => '<?php $valid = true;', 'b.txt' => 'data'] as $name => $data) {
        file_put_contents($base . '/' . $name, $data);
        $files[] = ['path' => 'uploads/' . $name, 'stage' => $base . '/' . $name, 'size' => strlen($data), 'sha256' => hash('sha256', $data)];
    }
    $job = ['files' => $files, 'selection' => ['plugins' => [], 'themes' => []], 'database' => []];
    $result = CSM_Plugin::preview($job);
    $check(count($result['items']) === 2, 'Preview output unchanged');
    foreach (['Verifying staged file', 'Checking destination file'] as $phase) {
        $events = array_values(array_filter(CSM_Progress::$events, fn($e) => $e['phase'] === $phase));
        $check($events[0]['count'] === 0 && $events[0]['total'] === 2 && $events[0]['unit'] === 'files', 'Known verification denominator');
        $check(end($events)['count'] === 2, 'Completed count reported');
    }
    $check(CSM_Progress::$current['count'] === 2 && CSM_Progress::$current['total'] === 2 && CSM_Progress::$current['unit'] === 'files', 'Compatibility counter completes');
    $check(!file_exists($base . '/destination/a.php'), 'Destination was never mutated');
    file_put_contents($base . '/a.php', 'corrupted');
    try { CSM_Plugin::changes($job); throw new LogicException('Corruption accepted'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(), 'staged file'), 'Checksums still enforced'); }
    file_put_contents($base . '/a.php', '<?php function {');
    $job['files'][0]['sha256'] = hash_file('sha256', $base . '/a.php');
    try { CSM_Plugin::preview($job); throw new LogicException('Invalid PHP accepted'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(), 'PHP parse error'), 'PHP validation still enforced'); }
    echo "Verification telemetry checks passed: real preview/checksums, known file totals, completed counters, no destination mutation.\n";
} finally { foreach (['a.php', 'b.txt'] as $name) @unlink($base . '/' . $name); rmdir($base . '/destination'); rmdir($base); }
