<?php
defined('ABSPATH') || exit;

final class CSM_Plugin {
    public static function loader_code(): string {
        return str_replace('__CSM_PLUGIN__', var_export(plugin_basename(CSM_FILE), true), file_get_contents(__DIR__ . '/loader.php'));
    }

    public static function install_loader(): void {
        $path = WPMU_PLUGIN_DIR . '/0-trickle-loader.php';
        if (is_file($path) && !str_contains(file_get_contents($path), 'TRICKLE_LOADER')) { throw new RuntimeException('An unrelated file occupies the Trickle loader path.'); }
        if (!wp_mkdir_p(WPMU_PLUGIN_DIR) || file_put_contents($path, self::loader_code(), LOCK_EX) === false) {
            throw new RuntimeException('Trickle needs permission to install its recovery loader in wp-content/mu-plugins.');
        }
    }

    public static function loader_ready(): bool {
        $path = WPMU_PLUGIN_DIR . '/0-trickle-loader.php';
        return is_file($path) && !is_link($path) && hash_equals(hash('sha256', self::loader_code()), hash_file('sha256', $path));
    }

    public static function boot(): void {
        if (!get_option('_csm_key')) { add_option('_csm_key', bin2hex(random_bytes(32)), '', false); }
        if (isset($_GET['trickle'])) { self::endpoint(); }
        add_action('admin_menu', static function () { add_management_page('Trickle Migrate', 'Trickle Migrate', 'manage_options', 'trickle', [self::class, 'page']); });
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_csm_admin', [self::class, 'ajax']);
    }

    public static function supported(): void {
        if (is_multisite()) { throw new RuntimeException('This release supports single-site WordPress only.'); }
        if (PHP_INT_SIZE < 8) { throw new RuntimeException('64-bit PHP is required.'); }
    }

    public static function manifest(bool $refresh = false): array {
        global $wpdb;
        self::supported();
        return array_merge(CSM_Files::inventory($refresh), CSM_Database::identity(), ['canonical_url' => (string)get_option('siteurl'), 'instance_fingerprint' => CSM_Controller::fingerprint(), 'media_compare' => 1, 'media_browsing' => 1, 'media_dates' => 1, 'replacement_rules' => 1, 'protocol' => 1, 'plugin_version' => CSM_VERSION, 'name' => get_bloginfo('name'), 'icon' => get_site_icon_url(64), 'prefix' => $wpdb->prefix,
            'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'tables' => CSM_Database::inventory(), 'isolation' => self::loader_ready(), 'incoming_writes' => !empty(get_option('_csm_settings', [])['push'])]);
    }

    public static function endpoint(): void {
        $action = sanitize_key(wp_unslash($_GET['trickle']));
        $streaming = false;
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new RuntimeException('POST required.', 405); }
            self::supported(); CSM_Support::long_request();
            if (!in_array($action, ['manifest', 'media', 'media_hashes', 'export', 'prepare', 'receive', 'status', 'progress', 'commit', 'rollback', 'discard'], true)) { throw new RuntimeException('Unknown action.', 404); }
            $data = CSM_Transport::authenticate($action);
            if (!in_array($action, ['progress', 'status'], true)) { CSM_Log::write('info', 'Authenticated incoming request', ['operation' => $action, 'transfer_id' => $data['id'] ?? null]); }
            self::prune_nonces();
            if ($action === 'manifest') { self::respond(self::manifest(!empty($data['refresh']))); }
            if ($action === 'media') { self::respond(CSM_Files::media((int)($data['page'] ?? 1), $data['ids'] ?? [])); }
            if ($action === 'media_hashes') { self::respond(CSM_Media::inventory($data)); }
            if ($action === 'progress') { self::respond(CSM_Progress::read($data['id'] ?? '')); }
            if ($action === 'status') { self::respond(self::summary(CSM_Support::job($data['id'] ?? ''))); }
            if ($action === 'export') {
                if (!empty($data['progress_id'])) { CSM_Progress::start($data['progress_id'], 'Exporting ' . ($data['kind'] ?? 'stream'), $data['progress_run'] ?? null); }
                $selection = CSM_Files::selection($data['selection'] ?? []);
                CSM_Database::validate($selection['tables'] ?? []);
                if (!in_array($data['kind'] ?? '', ['database', 'files'], true)) { throw new RuntimeException('Invalid stream kind.'); }
                while (ob_get_level()) { ob_end_clean(); }
                nocache_headers(); header('Content-Type: application/octet-stream'); header('X-Accel-Buffering: no');
                header('Content-Disposition: attachment; filename="trickle-' . $data['kind'] . '.csm"');
                $streaming = true;
                $generator = $data['kind'] === 'database' ? CSM_Database::stream($selection) : CSM_Files::stream($selection);
                $out = fopen('php://output', 'wb');
                foreach (CSM_Support::gzip($generator) as $bytes) { CSM_Support::write($out, $bytes); flush(); if (connection_aborted()) { throw new RuntimeException('Receiving connection closed during export.'); } }
                fclose($out); CSM_Progress::finish('Export verified'); exit;
            }
            CSM_Support::lock();
            if ($action === 'prepare') { self::respond(self::prepare($data['selection'] ?? [])); }
            $id = $data['id'] ?? ''; $job = CSM_Support::job($id);
            if ($action === 'receive') {
                $fp = fopen('php://input', 'rb');
                try { $job = self::receive($job, $data['kind'] ?? '', $fp, $data['progress_run'] ?? null); }
                finally { fclose($fp); }
                self::respond(self::summary($job));
            }
            if ($action === 'commit') { self::respond(self::commit($job, $data['progress_run'] ?? null)); }
            if ($action === 'rollback') { CSM_Progress::start($id, 'Restoring destination backup', $data['progress_run'] ?? null); self::respond(self::rollback($job)); }
            if ($action === 'discard') { self::discard($job); self::respond([]); }
            self::respond(self::summary($job));
        } catch (Throwable $e) {
            CSM_Log::write('error', 'Incoming request failed', ['operation' => $action, 'error' => $e->getMessage()]);
            CSM_Progress::fail($e->getMessage());
            if ($streaming) { error_log('Trickle export stopped: ' . $e->getMessage()); exit; }
            self::respond([], $e->getMessage(), in_array($e->getCode(), [403, 404, 405], true) ? $e->getCode() : 400);
        }
    }

    public static function respond(array $data, string $error = '', int $status = 200): never {
        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers(); status_header($status); header('Content-Type: application/json; charset=utf-8');
        echo CSM_Support::json($error ? ['ok' => false, 'error' => $error] : ['ok' => true, 'data' => $data]); exit;
    }

    public static function prune_nonces(): void {
        global $wpdb;
        // Expired nonce records cannot be replayed because their signed timestamps have expired too.
        $names = $wpdb->get_col($wpdb->prepare('SELECT option_name FROM ' . CSM_Support::ident($wpdb->options) . ' WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 100', $wpdb->esc_like('_csm_nonce_') . '%', time() - 600));
        foreach ($names as $name) { delete_option($name); }
    }

    public static function prepare(array $selection): array {
        if (get_option('_csm_active_job')) { throw new RuntimeException('Discard the previous transfer and its backup before starting another on this destination.'); }
        $selection = CSM_Files::media_selection(CSM_Database::replacement_selection($selection));
        $selection += ['tables' => [], 'plugins' => [], 'themes' => [], 'media' => 'none', 'media_ids' => [], 'replace' => true];
        foreach (['tables', 'plugins', 'themes'] as $kind) {
            if (!is_array($selection[$kind])) { throw new RuntimeException('Invalid selection.'); }
            foreach ($selection[$kind] as $value) { if (!is_string($value)) { throw new RuntimeException('Invalid component.'); } }
        }
        if (!$selection['tables'] && !$selection['plugins'] && !$selection['themes'] && $selection['media'] === 'none') { throw new RuntimeException('Select something to transfer.'); }
        $job = ['id' => bin2hex(random_bytes(16)), 'status' => 'staging', 'created' => time(), 'selection' => $selection, 'received' => [], 'database' => [], 'files' => [], 'ops' => []];
        CSM_Support::private_dir(); CSM_Support::save($job); update_option('_csm_active_job', $job['id'], false); return self::summary($job);
    }

    public static function receive(array $job, string $kind, $fp, ?string $run = null): array {
        if ($job['status'] !== 'staging' || !in_array($kind, self::kinds($job['selection']), true)) { throw new RuntimeException('Transfer is not awaiting this stream.'); }
        CSM_Progress::start($job['id'], 'Receiving ' . $kind, $run);
        unset($job['received'][$kind]);
        if ($kind === 'files') { $job['files'] = []; }
        CSM_Support::save($job);
        CSM_Support::decompress($fp);
        try {
            if ($kind === 'database') {
                foreach ($job['database'] as $table) { CSM_Support::query('DROP TABLE IF EXISTS ' . CSM_Support::ident($table['stage'])); }
                CSM_Database::stage($fp, $job);
            } else {
                CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id']);
                CSM_Files::stage($fp, $job);
            }
            $job['received'][$kind] = true; unset($job['error']);
        } catch (Throwable $e) {
            CSM_Progress::fail($e->getMessage());
            unset($job['received'][$kind]); $job['error'] = $e->getMessage(); CSM_Support::save($job); throw $e;
        }
        CSM_Support::save($job); CSM_Progress::finish(ucfirst($kind) . ' verified'); return $job;
    }

    public static function kinds(array $selection): array {
        $result = [];
        if (!empty($selection['tables'])) { $result[] = 'database'; }
        if (!empty($selection['plugins']) || !empty($selection['themes']) || ($selection['media'] ?? 'none') !== 'none') { $result[] = 'files'; }
        return $result;
    }

    public static function summary(array $job): array {
        return ['id' => $job['id'], 'status' => $job['status'], 'tables' => count($job['database'] ?? []), 'rows' => $job['rows'] ?? 0, 'files' => count($job['files'] ?? []),
            'created' => $job['created'], 'applied' => $job['applied'] ?? null, 'bytes' => array_sum(array_column($job['files'] ?? [], 'size')), 'ready' => !array_diff(self::kinds($job['selection']), array_keys($job['received'])),
            'progress' => CSM_Progress::read($job['id']), 'media_watermark' => $job['media_watermark'] ?? null, 'error' => $job['error'] ?? null, 'pending' => array_values(array_diff(self::kinds($job['selection']), array_keys($job['received'])))];
    }

    public static function commit(array $job, ?string $run = null): array {
        global $wpdb;
        CSM_Progress::start($job['id'], 'Checking destination', $run);
        $actor = current_user_can('manage_options') ? wp_get_current_user() : null;
        if ($job['status'] !== 'staging' || !self::summary($job)['ready']) { throw new RuntimeException('Both selected streams must be complete before applying.'); }
        if ($job['files'] && !wp_is_file_mod_allowed('trickle')) { throw new RuntimeException('WordPress configuration disallows file modifications on this destination.'); }
        if ($job['files'] && !self::loader_ready()) { throw new RuntimeException('Activate Trickle again to install its required recovery loader before applying file transfers.'); }
        $base = CSM_Support::private_dir() . '/' . $job['id'] . '-backup';
        if (!is_dir($base) && !mkdir($base, 0700)) { throw new RuntimeException('Cannot create rollback storage.'); }
        $new = []; $paths = [];
        foreach ($job['files'] as $file) {
            CSM_Progress::update(['phase' => 'Verifying staged file', 'component' => $file['path']]);
            $target = CSM_Files::destination($file['path'], $job['selection']);
            if (!is_file($file['stage']) || !hash_equals($file['sha256'], hash_file('sha256', $file['stage']))) { throw new RuntimeException('A staged file is missing or changed.'); }
            $new[$file['path']] = $file; $paths[$file['path']] = $target;
        }
        // Selected plugin/theme directories are replacements, not merges that leave obsolete PHP files behind.
        foreach (['plugins', 'themes'] as $kind) {
            foreach ($job['selection'][$kind] as $component) {
                CSM_Progress::update(['phase' => 'Checking destination component', 'component' => $kind . '/' . $component]);
                $root = CSM_Files::root($kind, $component);
                $path = $root . '/' . CSM_Files::safe_relative($component);
                if (!file_exists($path)) { continue; }
                if (is_link($path)) { throw new RuntimeException('Destination component is a symbolic link.'); }
                $files = is_file($path) ? [new SplFileInfo($path)] : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if ($file->isLink()) { throw new RuntimeException('Destination contains symbolic links.'); }
                    if (!$file->isFile()) { continue; }
                    $relative = $kind . '/' . substr($file->getPathname(), strlen($root) + 1);
                    $paths[$relative] = CSM_Files::destination($relative, $job['selection']);
                }
            }
        }
        foreach ($paths as $relative => $target) {
            CSM_Progress::update(['phase' => 'Checking destination file', 'component' => $relative]);
            $ancestor = dirname($target);
            while (!file_exists($ancestor)) { $ancestor = dirname($ancestor); }
            if (!is_writable($ancestor) || (file_exists($target) && (!is_file($target) || !is_writable($target) || !is_writable(dirname($target))))) { throw new RuntimeException('Destination is not writable: ' . $relative); }
        }
        $ops = [];
        foreach ($paths as $relative => $target) {
            CSM_Progress::update(['phase' => 'Backing up destination file', 'component' => $relative, 'count' => count($ops), 'total' => count($paths), 'unit' => 'files']);
            $backup = $base . '/' . count($ops); $existed = is_file($target);
            if ($existed && !copy($target, $backup)) { throw new RuntimeException('Could not back up ' . $relative); }
            $ops[] = ['path' => $relative, 'backup' => $backup, 'existed' => $existed, 'mtime' => $existed ? filemtime($target) : 0, 'mode' => $existed ? (fileperms($target) & 0777) : 0644];
        }
        // Persist the complete recovery plan before mutation, without rewriting the large job per file.
        $plan = CSM_Support::json($ops);
        if (file_put_contents($base . '/operations.json', $plan, LOCK_EX) !== strlen($plan)) { throw new RuntimeException('Could not save the file recovery plan.'); }
        chmod($base . '/operations.json', 0600);
        $job['ops_hash'] = hash('sha256', $plan); $job['ops'] = [];
        $job['status'] = 'applying'; CSM_Support::save($job);
        if (CSM_Support::job($job['id']) !== $job) { throw new RuntimeException('Could not persist the transfer recovery state.'); }
        try {
            $completed = 0;
            foreach ($paths as $relative => $target) {
                CSM_Progress::update(['phase' => isset($new[$relative]) ? 'Replacing file' : 'Removing obsolete file', 'component' => $relative, 'count' => $completed, 'total' => count($paths), 'unit' => 'files']);
                if (isset($new[$relative])) { self::install_file($new[$relative]['stage'], $target, $new[$relative]['mtime'], $new[$relative]['mode']); }
                elseif (!unlink($target)) { throw new RuntimeException('Could not remove obsolete file.'); }
                CSM_Progress::update(['count' => ++$completed]);
            }
            $renames = [];
            foreach ($job['database'] as &$table) {
                CSM_Progress::update(['phase' => 'Preparing table replacement', 'component' => $table['target'], 'count' => 0, 'total' => count($job['database']), 'unit' => 'tables']);
                $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table['target']))) === $table['target'];
                $table['existed'] = $exists;
                if ($exists) { $renames[] = CSM_Support::ident($table['target']) . ' TO ' . CSM_Support::ident($table['backup']); }
                $renames[] = CSM_Support::ident($table['stage']) . ' TO ' . CSM_Support::ident($table['target']);
            }
            unset($table);
            CSM_Support::save($job);
            CSM_Database::preserve_options($job);
            if ($renames) { CSM_Progress::update(['phase' => 'Replacing database tables atomically', 'component' => implode(', ', array_column($job['database'], 'target'))], true); }
            if ($renames) {
                CSM_Support::query('RENAME TABLE ' . implode(', ', $renames));
                CSM_Progress::update(['count' => count($job['database'])], true);
            }
            wp_cache_flush();
            $job['db_applied'] = true; $job['status'] = 'applied'; $job['applied'] = time(); CSM_Support::save($job);
            if (function_exists('opcache_reset')) { opcache_reset(); }
            CSM_Progress::finish('Transfer complete');
            return array_merge(self::summary($job), self::retain_controller($actor, $job));
        } catch (Throwable $e) {
            CSM_Progress::fail($e->getMessage());
            $job['error'] = $e->getMessage(); CSM_Support::save($job);
            try { self::rollback($job); } catch (Throwable $rollback_error) { throw new RuntimeException($e->getMessage() . ' Rollback needs attention: ' . $rollback_error->getMessage()); }
            throw new RuntimeException('Apply failed; changes rolled back. ' . $e->getMessage());
        }
    }

    private static function install_file(string $source, string $target, int $mtime, int $mode): void {
        if (!is_dir(dirname($target)) && !wp_mkdir_p(dirname($target))) { throw new RuntimeException('Cannot create destination directory.'); }
        $temp = tempnam(dirname($target), '.csm-');
        if (!$temp || !copy($source, $temp)) { throw new RuntimeException('Could not copy staged file.'); }
        chmod($temp, $mode); touch($temp, $mtime);
        if (!rename($temp, $target)) { unlink($temp); throw new RuntimeException('Could not replace destination file.'); }
        if (function_exists('opcache_invalidate')) { opcache_invalidate($target, true); }
    }

    public static function rollback(array $job): array {
        global $wpdb;
        CSM_Progress::update(['phase' => 'Restoring destination backup', 'component' => '', 'count' => 0, 'total' => null, 'unit' => '']);
        $actor = current_user_can('manage_options') ? wp_get_current_user() : null;
        if (!in_array($job['status'], ['applied', 'applying'], true)) { throw new RuntimeException('Only applied or interrupted transfers can be restored.'); }
        $ops = $job['ops'];
        if (isset($job['ops_hash'])) {
            $plan = @file_get_contents(CSM_Support::private_dir() . '/' . $job['id'] . '-backup/operations.json');
            if ($plan === false || !hash_equals($job['ops_hash'], hash('sha256', $plan))) { throw new RuntimeException('The file recovery plan is missing or changed.'); }
            $ops = json_decode($plan, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($ops) || !array_is_list($ops)) { throw new RuntimeException('Invalid file recovery plan.'); }
        }
        $renames = [];
        foreach ($job['database'] as $table) {
            CSM_Progress::update(['phase' => 'Restoring table', 'component' => $table['target']]);
            $backup_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table['backup']))) === $table['backup'];
            $stage_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table['stage']))) === $table['stage'];
            if ($backup_exists) {
                CSM_Database::preserve_options(['database' => [['target' => $table['target'], 'stage' => $table['backup']]]], true);
                $renames[] = CSM_Support::ident($table['target']) . ' TO ' . CSM_Support::ident($table['stage']);
                $renames[] = CSM_Support::ident($table['backup']) . ' TO ' . CSM_Support::ident($table['target']);
            } elseif (!$stage_exists && isset($table['existed']) && !$table['existed']) {
                CSM_Support::query('DROP TABLE IF EXISTS ' . CSM_Support::ident($table['target']));
            }
        }
        if ($renames) { CSM_Support::query('RENAME TABLE ' . implode(', ', $renames)); }
        wp_cache_flush();
        foreach (array_reverse($ops) as $op) {
            CSM_Progress::update(['phase' => 'Restoring file', 'component' => $op['path']]);
            $target = CSM_Files::destination($op['path'], $job['selection']);
            if ($op['existed']) { self::install_file($op['backup'], $target, $op['mtime'], $op['mode']); }
            elseif (is_file($target) && !unlink($target)) { throw new RuntimeException('Could not remove restored file.'); }
        }
        $job['status'] = 'restored'; CSM_Support::save($job); CSM_Progress::finish('Destination restored'); return array_merge(self::summary($job), self::retain_controller($actor, $job));
    }

    private static function retain_controller(?WP_User $actor, array $job): array {
        global $wpdb;
        if (!$actor || !array_intersect(array_column($job['database'], 'target'), [$wpdb->users, $wpdb->usermeta])) { return []; }
        // Keep the authenticated local operator in control even when importing a different users table.
        clean_user_cache($actor->ID);
        $user = get_user_by('login', $actor->user_login);
        if (!$user) {
            $id = wp_insert_user(['user_login' => $actor->user_login, 'user_pass' => wp_generate_password(40), 'display_name' => $actor->display_name, 'role' => 'administrator']);
            if (is_wp_error($id)) { throw new RuntimeException('Could not preserve the controlling administrator: ' . $id->get_error_message()); }
            $user = new WP_User($id);
        }
        if ($wpdb->update($wpdb->users, ['user_pass' => $actor->user_pass], ['ID' => $user->ID]) === false) { throw new RuntimeException('Could not preserve administrator credentials.'); }
        clean_user_cache($user->ID); $user = new WP_User($user->ID); $user->set_role('administrator');
        $token = WP_Session_Tokens::get_instance($user->ID)->create(time() + 2 * DAY_IN_SECONDS);
        wp_set_current_user($user->ID); wp_set_auth_cookie($user->ID, false, '', $token);
        $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user->ID, time() + 2 * DAY_IN_SECONDS, 'logged_in', $token);
        return ['nonce' => wp_create_nonce('csm_admin')];
    }

    public static function discard(array $job): void {
        if ($job['status'] === 'applying') { throw new RuntimeException('Restore the interrupted transfer before discarding it.'); }
        foreach ($job['database'] as $table) {
            foreach (['stage', 'backup'] as $kind) { CSM_Support::query('DROP TABLE IF EXISTS ' . CSM_Support::ident($table[$kind])); }
        }
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id']);
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id'] . '-backup');
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id'] . '-incoming');
        CSM_Progress::remove($job['id']);
        delete_option('_csm_job_' . $job['id']);
        if (get_option('_csm_active_job') === $job['id']) { delete_option('_csm_active_job'); }
    }

    public static function peer(): array { return CSM_Controller::peer(); }

    public static function ajax(): void {
        if (!current_user_can('manage_options')) { self::respond([], 'Administrator access required.', 403); }
        check_ajax_referer('csm_admin', 'nonce');
        try {
            $data = json_decode(wp_unslash($_POST['data'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR);
            $action = sanitize_key($_POST['op'] ?? '');
            self::respond(CSM_Controller::execute($action, $data));
        } catch (Throwable $e) { CSM_Log::write('error', 'Dashboard request failed', ['operation' => $action ?? 'unknown', 'error' => $e->getMessage()]); self::respond([], $e->getMessage(), 400); }
    }

    public static function pairing(): string { return site_url() . "\n" . get_option('_csm_key'); }

    public static function parse_pairing(string $code): array {
        $code = trim($code);
        if (preg_match('#^(https?://\S+)\s+([a-f0-9]{64})$#D', $code, $matches)) {
            $peer = ['url' => $matches[1], 'key' => $matches[2]];
        } else {
            // Codes issued by the first preview encoded the same URL/key pair as base64 JSON.
            $decoded = base64_decode($code, true);
            $peer = $decoded === false ? null : json_decode($decoded, true);
        }
        if (!is_array($peer) || !is_string($peer['url'] ?? null) || !is_string($peer['key'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $peer['key'])) {
            throw new RuntimeException('Invalid connection code. Copy both the site URL and the key from the remote Trickle page.');
        }
        return ['url' => CSM_Transport::url($peer['url']), 'key' => $peer['key']];
    }

    public static function assets(string $hook): void {
        if ($hook !== 'tools_page_trickle') { return; }
        wp_enqueue_style('trickle', plugins_url('assets/admin.css', CSM_FILE), [], CSM_VERSION . '.' . filemtime(dirname(CSM_FILE) . '/assets/admin.css'));
        wp_enqueue_script('trickle', plugins_url('assets/admin.js', CSM_FILE), [], CSM_VERSION . '.' . filemtime(dirname(CSM_FILE) . '/assets/admin.js'), true);
        wp_localize_script('trickle', 'CSM', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('csm_admin'), 'state' => CSM_Controller::state()]);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        $settings = get_option('_csm_settings', ['pull' => true, 'push' => false]);
        require __DIR__ . '/admin.php';
    }
}
