<?php
defined('ABSPATH') || exit;

final class CSM_Updates {
    public static function assert_version($version): void {
        if (!is_string($version) || $version !== CSM_VERSION) {
            throw new RuntimeException('Trickle versions must match before transferring. This site: ' . CSM_VERSION . '; connected site: ' . (is_string($version) && $version !== '' ? $version : 'unknown') . '. Update the older installation, then refresh versions.');
        }
    }

    public static function assert_job(array $record): void {
        if (($record['plugin_version'] ?? '') !== CSM_VERSION) { throw new RuntimeException('Trickle changed since this transfer was prepared. Discard and prepare again. Existing rollback and recovery controls remain available.'); }
    }

    public static function capabilities(): array {
        $settings = CSM_Access::settings();
        return ['remote_update' => 1, 'remote_updates_allowed' => !empty($settings['updates']),
            'can_update_trickle' => !empty($settings['updates']) && (!isset($GLOBALS['_csm_credential']) || !empty($GLOBALS['_csm_credential']['write']))];
    }

    private static function checker() {
        $checker = $GLOBALS['trickle_update_checker'] ?? null;
        if (!$checker) { throw new RuntimeException('Trickle JSON updater is unavailable. Reinstall Trickle manually.'); }
        return $checker;
    }

    private static function release() {
        $info = @self::checker()->requestInfo();
        if (!$info || !is_string($info->version ?? null) || !is_string($info->download_url ?? null) || wp_parse_url($info->download_url, PHP_URL_SCHEME) !== 'https' || !wp_parse_url($info->download_url, PHP_URL_HOST)) {
            throw new RuntimeException('The configured Trickle JSON feed did not return a usable HTTPS release. Try again after the release is published.');
        }
        return $info;
    }

    public static function info(): array {
        $info = self::release(); $reason = '';
        try { self::preflight(); } catch (Throwable $e) { $reason = $e->getMessage(); }
        return ['ready' => $reason === '', 'blocked_reason' => $reason] + self::capabilities() + ['installed' => CSM_VERSION, 'release' => $info->version,
            'file_modifications' => wp_is_file_mod_allowed('trickle'), 'requires_php' => $info->requires_php ?? '', 'requires_wp' => $info->requires ?? ''];
    }

    private static function preflight(): void {
        if (get_option('_csm_control') || get_option('_csm_active_job') || glob(CSM_Support::private_dir() . '/segment-*.source.owner')) { throw new RuntimeException('Finish and discard retained transfers on this site and its connected controllers before updating Trickle.'); }
        if (!CSM_Plugin::loader_ready()) { throw new RuntimeException('Open the remote Trickle dashboard once to refresh its recovery loader before updating.'); }
        if (!wp_is_file_mod_allowed('trickle')) { throw new RuntimeException('WordPress disallows file modifications on the remote site.'); }
        if (is_link(WP_PLUGIN_DIR . '/' . dirname(plugin_basename(CSM_FILE))) || CSM_Files::git_folders(['plugins' => [basename(dirname(CSM_FILE))]])) { throw new RuntimeException('Trickle is inside a Git working directory. Update that checkout manually.'); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if (get_filesystem_method([], WP_PLUGIN_DIR) !== 'direct') { throw new RuntimeException('Remote updates require direct filesystem access; update through the remote WordPress dashboard instead.'); }
    }

    public static function install(string $target, string $from): array {
        if ($from !== CSM_VERSION) { throw new RuntimeException('The remote Trickle version changed. Refresh versions before updating.'); }
        if (!preg_match('/^[0-9][0-9A-Za-z.+_-]{0,39}$/D', $target) || version_compare($target, CSM_VERSION, '<=')) { throw new RuntimeException('Remote updates must be upgrades. Update this controlling site first when the remote is newer.'); }
        self::preflight();
        $info = self::release();
        if ($info->version !== $target) { throw new RuntimeException('The JSON feed currently publishes Trickle ' . $info->version . ', but the controlling site requires ' . $target . '. Publish the matching release or update both installations manually.'); }
        if ((!empty($info->requires_php) && version_compare(PHP_VERSION, $info->requires_php, '<')) || (!empty($info->requires) && version_compare(get_bloginfo('version'), $info->requires, '<'))) { throw new RuntimeException('The published Trickle release requires a newer PHP or WordPress version on this site.'); }
        $plugin = plugin_basename(CSM_FILE);
        // The recovery loader filters this option during RPCs; preserve the complete real list.
        $isolation = $GLOBALS['_csm_active_plugins_filter'] ?? null;
        if (defined('CSM_ORIGINAL_ACTIVE_PLUGINS') && !$isolation) { throw new RuntimeException('Refresh the remote recovery loader before updating Trickle.'); }
        $active = defined('CSM_ORIGINAL_ACTIVE_PLUGINS') ? CSM_ORIGINAL_ACTIVE_PLUGINS : get_option('active_plugins', []);
        $publish = static function ($updates) use ($plugin, $target, $info) {
            if (!is_object($updates)) { $updates = (object)['response' => [], 'no_update' => [], 'checked' => []]; }
            $updates->response[$plugin] = (object)['slug' => basename(dirname(CSM_FILE)), 'plugin' => $plugin, 'new_version' => $target, 'package' => $info->download_url, 'url' => $info->homepage ?? ''];
            return $updates;
        };
        $validate = static function ($source, $remote_source, $upgrader, $extra) use ($plugin, $target) {
            if (is_wp_error($source) || ($extra['plugin'] ?? '') !== $plugin) { return $source; }
            $main = trailingslashit($source) . basename(CSM_FILE);
            $header = is_file($main) ? get_file_data($main, ['name' => 'Plugin Name', 'version' => 'Version', 'php' => 'Requires PHP', 'wp' => 'Requires at least']) : [];
            if (basename(untrailingslashit($source)) !== basename(dirname(CSM_FILE)) || ($header['name'] ?? '') !== 'Trickle' || ($header['version'] ?? '') !== $target) { return new WP_Error('trickle_package', 'The downloaded ZIP does not contain the expected Trickle version and directory.'); }
            if ((!empty($header['php']) && version_compare(PHP_VERSION, $header['php'], '<')) || (!empty($header['wp']) && version_compare(get_bloginfo('version'), $header['wp'], '<'))) { return new WP_Error('trickle_requirements', 'The downloaded Trickle package is incompatible with this site.'); }
            return $source;
        };
        add_filter('site_transient_update_plugins', $publish, PHP_INT_MAX);
        add_filter('upgrader_source_selection', $validate, PHP_INT_MAX, 4);
        ignore_user_abort(true);
        if ($isolation) { remove_filter('option_active_plugins', $isolation, PHP_INT_MAX); }
        try {
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $result = $upgrader->upgrade($plugin);
            if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
            if (is_wp_error($upgrader->skin->result)) { throw new RuntimeException($upgrader->skin->result->get_error_message()); }
            if (!$result) { throw new RuntimeException('WordPress could not install the Trickle update. Check the remote diagnostics and filesystem permissions.'); }
            $header = get_file_data(CSM_FILE, ['version' => 'Version']);
            if ($header['version'] !== $target) { throw new RuntimeException('The installed Trickle version does not match the requested release. Verify the remote installation manually.'); }
            CSM_Plugin::install_loader();
            return ['installed' => $target, 'updated' => true];
        } finally {
            update_option('active_plugins', $active);
            if ($isolation) { add_filter('option_active_plugins', $isolation, PHP_INT_MAX); }
            remove_filter('site_transient_update_plugins', $publish, PHP_INT_MAX);
            remove_filter('upgrader_source_selection', $validate, PHP_INT_MAX);
            if (function_exists('opcache_reset')) { opcache_reset(); }
        }
    }
}
