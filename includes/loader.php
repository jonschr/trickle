<?php
// TRICKLE_LOADER: generated on activation; normal site requests are unaffected.
defined('ABSPATH') || exit;
$csm_recovery_job = get_option('_csm_active_job');
$csm_recovery_cron = defined('DOING_CRON') && DOING_CRON && $csm_recovery_job && in_array(get_option('_csm_job_' . $csm_recovery_job, [])['status'] ?? '', ['applying', 'backup_copy'], true);
if ($csm_recovery_cron) { define('CSM_RECOVERY_CRON', true); }
$csm_request = (isset($_GET['trickle']) && $_GET['trickle'] !== 'manifest')
    || (($_POST['action'] ?? '') === 'csm_admin' && !in_array($_POST['op'] ?? '', ['manifest', 'connect'], true))
    || (is_admin() && ($_GET['page'] ?? '') === 'trickle')
    || $csm_recovery_cron;
$csm_plugin = __CSM_PLUGIN__;
$csm_active = get_option('active_plugins', []);
$csm_maintenance = get_option('_csm_maintenance', []);
$csm_probe = isset($csm_maintenance['probe']) && hash_equals($csm_maintenance['probe'], hash('sha256', (string)($_SERVER['HTTP_X_TRICKLE_HEALTH'] ?? '')));
if (!$csm_probe && in_array($csm_plugin, $csm_active, true) && !$csm_request && !(defined('WP_CLI') && WP_CLI) && !is_admin() && !empty($csm_maintenance['until']) && $csm_maintenance['until'] > time()) {
    status_header(503); header('Retry-After: 60'); header('Cache-Control: no-store');
    wp_die('A site update is being applied. Please try again shortly.', 'Site update in progress', ['response' => 503]);
}

if ($csm_request && in_array($csm_plugin, $csm_active, true)) {
    define('CSM_ORIGINAL_ACTIVE_PLUGINS', $csm_active);
    define('CSM_ORIGINAL_TEMPLATE', get_option('template'));
    define('CSM_ORIGINAL_STYLESHEET', get_option('stylesheet'));
    $GLOBALS['_csm_active_plugins_filter'] = static fn($plugins) => array_values(array_intersect($plugins, [$csm_plugin]));
    add_filter('option_active_plugins', $GLOBALS['_csm_active_plugins_filter'], PHP_INT_MAX);
    add_filter('option_template', static fn() => '__csm_transfer_no_theme__', PHP_INT_MAX);
    add_filter('option_stylesheet', static fn() => '__csm_transfer_no_theme__', PHP_INT_MAX);
}
unset($csm_request, $csm_plugin, $csm_active, $csm_maintenance, $csm_probe, $csm_recovery_job, $csm_recovery_cron);
