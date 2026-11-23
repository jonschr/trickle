<?php
// New class files must load directly, in fresh processes and in reverse dependency order.
$directory = dirname(__DIR__) . '/includes';
$files = glob($directory . '/class-*.php');
if (!$files) { throw new RuntimeException('No first-party class files found.'); }
function child(array $files, string $assertion): void {
    $code = 'define("ABSPATH", "/fixture/"); foreach (json_decode($argv[1], true) as $file) { require_once $file; } ' . $assertion;
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $code, json_encode($files)], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start class-loading fixture.'); }
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException($out . $error); }
}
foreach ($files as $file) {
    preg_match('/^(?:final )?class (CSM_\w+)/m', file_get_contents($file), $match);
    if (!$match) { throw new RuntimeException('Class declaration missing: ' . basename($file)); }
    child([$file], 'if (!class_exists(' . var_export($match[1], true) . ', false)) { throw new RuntimeException("Class did not load"); }');
}
child(array_reverse($files), '$busy = new CSM_Busy_Error("busy"); $paced = new CSM_Pacing_Error("paced"); if (!$busy instanceof CSM_Network_Error || $busy->retry_after !== 10 || $busy->exhausted || !$paced instanceof CSM_Network_Error || $paced->retry_after !== 5 || !$paced->exhausted || !(new CSM_Cancelled_Error()) instanceof RuntimeException || !(new CSM_Yield()) instanceof RuntimeException) { throw new RuntimeException("Exception contract changed"); }');
child([$directory . '/class-csm-support.php'], 'if (!class_exists("CSM_Cancelled_Error", false) || !class_exists("CSM_Yield", false)) { throw new RuntimeException("Support exception dependencies missing"); }');
child([$directory . '/class-csm-transport.php'], 'if (!class_exists("CSM_Network_Error", false) || !class_exists("CSM_Busy_Error", false) || !class_exists("CSM_Pacing_Error", false)) { throw new RuntimeException("Transport exception dependencies missing"); }');
child([$directory . '/support.php', $directory . '/access.php', $directory . '/transport.php', ...array_reverse($files)], 'if (!class_exists("CSM_Support", false) || !class_exists("CSM_Access", false) || !class_exists("CSM_Transport", false)) { throw new RuntimeException("Installed MU loader entry points failed"); }');
echo 'PASS ' . count($files) . " independent class loads, reverse-order imports and exception dependency/retry contracts\n";
