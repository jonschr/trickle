<?php
// TRICKLE_LOADER: generated on activation; normal site requests are unaffected.
defined('ABSPATH') || exit;
$csm_request = (isset($_GET['trickle']) && $_GET['trickle'] !== 'manifest')
    || (($_POST['action'] ?? '') === 'csm_admin' && !in_array($_POST['op'] ?? '', ['manifest', 'connect'], true))
    || (is_admin() && ($_GET['page'] ?? '') === 'trickle');
$csm_plugin = __CSM_PLUGIN__;
$csm_active = get_option('active_plugins', []);
if ($csm_request && in_array($csm_plugin, $csm_active, true)) {
    define('CSM_ORIGINAL_ACTIVE_PLUGINS', $csm_active);
    define('CSM_ORIGINAL_TEMPLATE', get_option('template'));
    define('CSM_ORIGINAL_STYLESHEET', get_option('stylesheet'));
    add_filter('option_active_plugins', static fn($plugins) => array_values(array_intersect($plugins, [$csm_plugin])), PHP_INT_MAX);
    add_filter('option_template', static fn() => '__csm_transfer_no_theme__', PHP_INT_MAX);
    add_filter('option_stylesheet', static fn() => '__csm_transfer_no_theme__', PHP_INT_MAX);
}
unset($csm_request, $csm_plugin, $csm_active);
