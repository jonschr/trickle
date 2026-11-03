<?php
// Exercise preference validation and persistence without booting or changing a site.
define('ABSPATH', __DIR__);
$options = [];
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; }
function wp_cache_delete(...$args){}
require __DIR__ . '/../includes/controller.php';
$operate = new ReflectionMethod(CSM_Controller::class, 'operate');
$check = static function ($ok) { if (!$ok) throw new RuntimeException('Preference check failed'); };
$defaults = $operate->invoke(null, 'transfer_settings', ['transport' => 'chunks', 'file_verify' => true]);
$check($defaults === ['transport' => 'chunks', 'file_verify' => true]);
$check(get_option('_csm_transfer_defaults') === $defaults);
foreach (['invalid', [], null] as $transport) {
    try { $operate->invoke(null, 'transfer_settings', ['transport' => $transport]); throw new LogicException('Accepted invalid transport'); }
    catch (RuntimeException $e) { $check(get_option('_csm_transfer_defaults') === $defaults); }
}
$options['_csm_control'] = ['id' => 'active'];
try { $operate->invoke(null, 'transfer_settings', ['transport' => 'stream']); throw new LogicException('Changed preferences during transfer'); }
catch (RuntimeException $e) { $check(get_option('_csm_transfer_defaults') === $defaults); }
echo "Preference checks passed: saved defaults, strict transport validation and active-transfer protection.\n";
