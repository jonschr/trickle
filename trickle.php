<?php
/**
 * Plugin Name: Trickle
 * Description: Selective, locally controlled push/pull migrations using continuous database and file streams.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Elodin
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
define('CSM_FILE', __FILE__);
define('CSM_VERSION', '0.1.0');
foreach (['support', 'database', 'files', 'transport', 'plugin'] as $csm_part) {
    require_once __DIR__ . '/includes/' . $csm_part . '.php';
}
add_action('plugins_loaded', ['CSM_Plugin', 'boot'], 1);
register_activation_hook(__FILE__, ['CSM_Plugin', 'install_loader']);
