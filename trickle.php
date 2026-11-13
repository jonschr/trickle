<?php
/**
 * Plugin Name: Trickle
 * Plugin URI: https://github.com/jonschr/trickle
 * Description: Selective, locally controlled push/pull migrations using continuous database and file streams.
 * Version: 0.1.27
 * Update URI: https://github.com/jonschr/trickle
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Elodin
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
define('CSM_FILE', __FILE__);
define('CSM_VERSION', '0.1.27');

// Match Guest Key's static JSON updater in admin, cron, AJAX, REST and WP-CLI.
if (!defined('CSM_UPDATE_URL')) {
    define('CSM_UPDATE_URL', 'https://raw.githubusercontent.com/jonschr/trickle/master/update.json');
}
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
$GLOBALS['trickle_update_checker'] = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(CSM_UPDATE_URL, CSM_FILE, 'trickle');

foreach (['support', 'jobs', 'access', 'log', 'progress', 'database', 'activation', 'files', 'components', 'package', 'estimates', 'segments', 'media', 'transport', 'updates', 'backups', 'presets', 'controller', 'plugin'] as $csm_part) {
    require_once __DIR__ . '/includes/' . $csm_part . '.php';
}
add_action('plugins_loaded', ['CSM_Plugin', 'boot'], 1);
register_activation_hook(__FILE__, ['CSM_Plugin', 'activate']);

if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/includes/cli.php';
    WP_CLI::add_command('trickle', CSM_CLI::class);
}
