<?php
/** Preview-only checks: private staging fixtures, no migration or destination replacements. */
if (!defined('ABSPATH') || realpath(ABSPATH) !== realpath(getenv('HOME') . '/Cove/Sites/trickle-qa-a.localhost/public') || get_option('_csm_control') || get_option('_csm_active_job')) { throw new RuntimeException('Requires idle trickle-qa-a.'); }
$id = 'trickle-compatibility-' . bin2hex(random_bytes(4));
$stage = CSM_Support::private_dir() . '/' . $id;
if (!mkdir($stage, 0700)) { throw new RuntimeException('Cannot create compatibility fixtures.'); }
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($label); }
    $checks++; echo "PASS $label\n";
};
$preview = static function (string $kind, array $contents) use ($id, $stage): string {
    $job = ['selection' => ['plugins' => [], 'themes' => [], 'tables' => [], 'media' => 'none'], 'files' => [], 'database' => []];
    $job['selection'][$kind] = [$id];
    foreach ($contents as $relative => $content) {
        $path = $stage . '/' . bin2hex(random_bytes(4)); file_put_contents($path, $content);
        $job['files'][] = ['path' => $kind . '/' . $id . '/' . $relative, 'stage' => $path, 'size' => strlen($content), 'sha256' => hash('sha256', $content)];
    }
    try { CSM_Plugin::preview($job); }
    catch (RuntimeException $e) { return $e->getMessage(); }
    return '';
};
try {
    $error = $preview('plugins', ['main.php' => "<?php\n/* Plugin Name: Compatibility Demo */\nfunction invalid( {"]);
    $check(str_contains($error, 'Plugin "Compatibility Demo": PHP parse error on destination PHP ' . PHP_VERSION), 'Plugin parse notice names the component and actual destination PHP version');
    $check(str_contains($error, 'line 3:') && str_contains($error, 'plugins/' . $id . '/main.php') && str_contains($error, 'Update or deselect this plugin'), 'Notice retains the failing file, line and recovery action');
    $error = $preview('themes', ['includes/broken.php' => "<?php\nfunction invalid( {", 'style.css' => '/* Theme Name: Compatibility Theme */']);
    $check(str_contains($error, 'Theme "Compatibility Theme": PHP parse error on destination PHP ' . PHP_VERSION) && str_contains($error, '/includes/broken.php'), 'Nested theme error uses its staged theme name even when the header follows the broken file');
    $error = $preview('plugins', ['main.php' => '<?php function invalid( {']);
    $check(str_contains($error, 'Plugin "' . $id . '"'), 'Components without a name header fall back to the folder name');
    $error = $preview('plugins', ['main.php' => "<?php\n/* Plugin Name: Future Plugin\nRequires PHP: 99.0\n*/"]);
    $check(str_contains($error, 'Plugin "Future Plugin": requires PHP 99.0; destination uses PHP ' . PHP_VERSION), 'Declared PHP requirement errors identify both required and destination versions');
    $check($preview('plugins', ['main.php' => "<?php\n/* Plugin Name: Valid Plugin */\nfunction trickle_valid_fixture() {} "]) === '', 'Valid PHP still passes preview without executing the staged code');
    $check(!file_exists(WP_PLUGIN_DIR . '/' . $id) && !file_exists(get_theme_root() . '/' . $id), 'Preview leaves destination plugin and theme folders untouched');
    echo "$checks compatibility checks passed.\n";
} finally { CSM_Support::remove_tree($stage); }
