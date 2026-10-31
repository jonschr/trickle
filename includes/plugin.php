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
        if (defined('CSM_RECOVERY_CRON')) {
            self::recover_interrupted((string)get_option('_csm_active_job'));
            // Other plugins were isolated; leave their due cron events untouched for the next request.
            exit;
        }
        if (isset($_GET['trickle'])) { self::endpoint(); }
        add_action('admin_menu', static function () { add_management_page('Trickle Migrate', 'Trickle Migrate', 'manage_options', 'trickle', [self::class, 'page']); });
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_csm_admin', [self::class, 'ajax']);
        add_action('trickle_recover', [self::class, 'recover_interrupted']);
    }

    public static function recover_interrupted(string $id): void {
        try { $job = CSM_Support::job($id); } catch (Throwable $e) { return; }
        if (!in_array($job['status'], ['applying', 'backup_copy'], true)) { return; }
        try { CSM_Support::lock(); }
        catch (Throwable $e) { wp_schedule_single_event(time() + 60, 'trickle_recover', [$id]); return; }
        try {
            $job = CSM_Support::job($id);
            if (!in_array($job['status'], ['applying', 'backup_copy'], true)) { return; }
            CSM_Progress::start($id, 'Recovering interrupted transfer');
            $restore = get_option('_csm_backup_restore');
            if (($restore['job_id'] ?? '') === $id) { CSM_Backups::restore($restore['backup_id']); }
            else { self::rollback($job); }
            CSM_Log::write('info', 'Interrupted transfer recovered', ['transfer_id' => $id]);
        } catch (Throwable $e) {
            CSM_Log::write('error', 'Automatic recovery will retry', ['transfer_id' => $id, 'error' => $e->getMessage()]);
            wp_schedule_single_event(time() + 300, 'trickle_recover', [$id]);
        } finally { CSM_Support::unlock(); }
    }

    public static function supported(): void {
        if (is_multisite()) { throw new RuntimeException('This release supports single-site WordPress only.'); }
        if (PHP_INT_SIZE < 8) { throw new RuntimeException('64-bit PHP is required.'); }
    }

    public static function manifest(bool $refresh = false): array {
        global $wpdb;
        self::supported();
        $inventory = CSM_Files::inventory($refresh);
        return array_merge($inventory, CSM_Database::identity(), CSM_Updates::capabilities(), ['file_modifications' => wp_is_file_mod_allowed('trickle'), 'active_plugins' => CSM_Activation::current(), 'installed_plugins' => array_keys(get_plugins()), 'trickle_plugin' => plugin_basename(CSM_FILE), 'can_receive' => !empty(CSM_Access::settings()['push']) && (!isset($GLOBALS['_csm_credential']) || !empty($GLOBALS['_csm_credential']['write'])), 'segments' => 1, 'component_compare' => 1, 'file_policy' => 1, 'canonical_url' => (string)get_option('siteurl'), 'instance_fingerprint' => CSM_Controller::fingerprint(), 'media_compare' => 1, 'media_browsing' => 1, 'media_dates' => 1, 'replacement_rules' => 1, 'protocol' => 1, 'plugin_version' => CSM_VERSION, 'name' => get_bloginfo('name'), 'icon' => get_site_icon_url(64), 'prefix' => $wpdb->prefix,
            'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'tables' => CSM_Database::inventory(), 'isolation' => self::loader_ready(), 'incoming_writes' => !empty(CSM_Access::settings()['push'])]);
    }

    public static function endpoint(): void {
        $action = sanitize_key(wp_unslash($_GET['trickle']));
        $streaming = false;
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new RuntimeException('POST required.', 405); }
            self::supported(); CSM_Support::long_request();
            if (!in_array($action, ['manifest', 'version', 'update_info', 'update_trickle', 'backups', 'restore_backup', 'media', 'media_hashes', 'file_policy', 'component_hashes', 'bundle', 'bundle_chunk', 'bundle_discard', 'segment_state', 'segment_data', 'export', 'prepare', 'receive', 'status', 'preview', 'progress', 'commit', 'rollback', 'discard'], true)) { throw new RuntimeException('Unknown action.', 404); }
            $data = CSM_Transport::authenticate($action);
            if (((in_array($action, ['export', 'bundle'], true) && ($data['kind'] ?? '') === 'files') || ($action === 'prepare' && in_array('files', self::kinds($data['selection'] ?? []), true))) && (($data['selection']['file_policy'] ?? 0) !== 1)) { throw new RuntimeException('Update Trickle on both sites before transferring files with Git and environment-file protection.'); }
            if (in_array($action, ['commit', 'rollback', 'restore_backup'], true)) { ignore_user_abort(true); }
            if (!in_array($action, ['progress', 'status'], true)) { CSM_Log::write('info', 'Authenticated incoming request', ['operation' => $action, 'transfer_id' => $data['id'] ?? null]); }
            self::prune_nonces();
            if ($action === 'version') { self::respond(['url' => site_url(), 'canonical_url' => (string)get_option('siteurl')] + CSM_Updates::capabilities() + ['plugin_version' => CSM_VERSION, 'instance_fingerprint' => CSM_Controller::fingerprint()]); }
            if ($action === 'update_info') { self::respond(CSM_Updates::info()); }
            if ($action === 'update_trickle') { CSM_Support::lock(); self::respond(CSM_Updates::install($data['target'] ?? '', $data['from'] ?? '')); }
            if ($action === 'backups') { self::respond(CSM_Backups::listing()); }
            if ($action === 'restore_backup') { CSM_Support::lock(); CSM_Progress::start($data['id'] ?? '', 'Restoring saved backup', $data['progress_run'] ?? null); self::respond(CSM_Backups::restore($data['id'] ?? '')); }
            if ($action === 'manifest') { self::respond(self::manifest(!empty($data['refresh']))); }
            if ($action === 'media') { self::respond(CSM_Files::media((int)($data['page'] ?? 1), $data['ids'] ?? [])); }
            if ($action === 'bundle') {
                CSM_Support::lock();
                CSM_Progress::start($data['id'] ?? '', 'Building resumable source stream', $data['progress_run'] ?? null);
                self::respond(CSM_Segments::bundle($data['id'] ?? '', $data['kind'] ?? '', $data['selection'] ?? []));
            }
            if ($action === 'bundle_chunk') {
                $bytes = CSM_Segments::chunk($data['id'] ?? '', $data['kind'] ?? '', (int)($data['offset'] ?? -1));
                while (ob_get_level()) { ob_end_clean(); }
                nocache_headers(); header('Content-Type: application/octet-stream'); echo $bytes; exit;
            }
            if ($action === 'bundle_discard') { CSM_Segments::cleanup_source($data['id'] ?? ''); self::respond([]); }
            if ($action === 'component_hashes') { self::respond(CSM_Components::inventory($data)); }
            if ($action === 'file_policy') { self::respond(['git_folders' => CSM_Files::git_folders($data['selection'] ?? [])]); }
            if ($action === 'media_hashes') { self::respond(CSM_Media::inventory($data)); }
            if ($action === 'progress') { CSM_Segments::assert_progress_owner($data['id'] ?? ''); self::respond(CSM_Progress::read($data['id'] ?? '')); }
            if ($action === 'preview') { $job = CSM_Support::job($data['id'] ?? ''); CSM_Access::assert_owner($job); self::respond(self::preview_page($job, (int)($data['offset'] ?? 0))); }
            if ($action === 'status') { $job = CSM_Support::job($data['id'] ?? ''); CSM_Access::assert_owner($job); self::respond(self::summary($job)); }
            if ($action === 'export') {
                CSM_Support::lock();
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
            if ($action === 'prepare') { self::respond(self::prepare($data['selection'] ?? [], $data['context'] ?? [])); }
            $id = $data['id'] ?? ''; $job = CSM_Support::job($id); CSM_Access::assert_owner($job);
            if ($action === 'segment_state') { self::respond(CSM_Segments::state($job, $data['kind'] ?? '', $data['bundle'] ?? [])); }
            if ($action === 'segment_data') {
                ignore_user_abort(true);
                $bytes = file_get_contents('php://input', false, null, 0, CSM_Segments::SIZE + 1);
                self::respond(CSM_Segments::receive($job, $data['kind'] ?? '', $data['bundle'] ?? [], (int)($data['offset'] ?? -1), $bytes, $data['sha256'] ?? ''));
            }
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

    public static function prepare(array $selection, array $context = []): array {
        $active = get_option('_csm_active_job');
        if ($active) {
            $previous = CSM_Support::job($active);
            if ($previous['status'] === 'applied') { CSM_Backups::retain($previous); }
            elseif ($previous['status'] === 'restored') { self::discard($previous); }
            else { throw new RuntimeException('Finish or cancel the active transfer before starting another on this destination. Saved backups do not block new transfers.'); }
        }
        $selection = CSM_Activation::selection(CSM_Files::media_selection(CSM_Database::replacement_selection($selection)));
        $selection += ['tables' => [], 'plugins' => [], 'themes' => [], 'media' => 'none', 'media_ids' => [], 'replace' => true];
        foreach (['tables', 'plugins', 'themes'] as $kind) {
            if (!is_array($selection[$kind])) { throw new RuntimeException('Invalid selection.'); }
            foreach ($selection[$kind] as $value) { if (!is_string($value)) { throw new RuntimeException('Invalid component.'); } }
        }
        if (!$selection['tables'] && !$selection['plugins'] && !$selection['themes'] && $selection['media'] === 'none') { throw new RuntimeException('Select something to transfer.'); }
        $job = ['plugin_version' => CSM_VERSION, 'credential' => CSM_Access::owner(), 'source' => esc_url_raw($context['source'] ?? '', ['http', 'https']), 'id' => bin2hex(random_bytes(16)), 'status' => 'staging', 'created' => time(), 'selection' => $selection, 'received' => [], 'database' => [], 'files' => [], 'ops' => []];
        CSM_Support::private_dir(); CSM_Support::save($job); update_option('_csm_active_job', $job['id'], false); return self::summary($job);
    }

    public static function receive(array $job, string $kind, $fp, ?string $run = null): array {
        CSM_Updates::assert_job($job);
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
        if (!array_diff(self::kinds($job['selection']), array_keys($job['received']))) {
            $preview = self::preview($job); $raw = CSM_Support::json($preview['items']);
            $path = CSM_Support::private_dir() . '/' . $job['id'] . '-preview.json';
            if (file_put_contents($path, $raw, LOCK_EX) !== strlen($raw)) { throw new RuntimeException('Could not save transfer preview.'); }
            chmod($path, 0600); $preview['file_hash'] = hash('sha256', $raw); $preview['items'] = array_slice($preview['items'], 0, 200); $job['preview'] = $preview;
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
        return ['id' => $job['id'], 'status' => $job['status'], 'tables' => count($job['database'] ?? []), 'rows' => $job['rows'] ?? 0, 'files' => count(array_filter($job['files'] ?? [], static fn($file) => empty($file['unchanged']))), 'unchanged_files' => count(array_filter($job['files'] ?? [], static fn($file) => !empty($file['unchanged']))),
            'activation_missing' => $job['activation_missing'] ?? [], 'health' => $job['health'] ?? null, 'preview' => $job['preview'] ?? null, 'skipped_folders' => $job['selection']['skip_paths'] ?? [], 'created' => $job['created'], 'applied' => $job['applied'] ?? null, 'bytes' => array_sum(array_column(array_filter($job['files'] ?? [], static fn($file) => empty($file['unchanged'])), 'size')), 'ready' => !array_diff(self::kinds($job['selection']), array_keys($job['received'])),
            'progress' => CSM_Progress::read($job['id']), 'media_watermark' => $job['media_watermark'] ?? null, 'error' => $job['error'] ?? null, 'pending' => array_values(array_diff(self::kinds($job['selection']), array_keys($job['received'])))];
    }

    public static function changes(array $job): array {
        $new = []; $paths = [];
        foreach ($job['files'] as $file) {
            CSM_Progress::update(['phase' => 'Verifying staged file', 'component' => $file['path']]);
            $target = CSM_Files::destination($file['path'], $job['selection']);
            if (!empty($file['unchanged'])) {
                if (!is_file($target) || !hash_equals($file['sha256'], hash_file('sha256', $target))) { throw new RuntimeException('An unchanged destination file changed after staging: ' . $file['path'] . '. Discard and prepare again.'); }
                $new[$file['path']] = $file; continue;
            }
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
                foreach (CSM_Files::scan($path, $kind . '/' . $component, $job['selection'] + ['protect_source_git' => true]) as $file) {
                    $relative = $kind . '/' . substr($file, strlen($root) + 1);
                    if (!empty($new[$relative]['unchanged'])) { continue; }
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
        return ['new' => $new, 'paths' => $paths];
    }

    public static function preview(array $job): array {
        $plan = self::changes($job); $items = []; $counts = ['add' => 0, 'replace' => 0, 'delete' => 0, 'unchanged' => 0]; $backup = 0; $signature = [];
        foreach ($job['files'] as $file) { if (!empty($file['unchanged'])) { $counts['unchanged']++; } }
        foreach ($plan['paths'] as $relative => $target) {
            $exists = is_file($target); $file = $plan['new'][$relative] ?? null;
            $action = !$file ? 'delete' : ($exists ? 'replace' : 'add'); $counts[$action]++;
            $size = $exists ? filesize($target) : 0; $backup += $size;
            $items[] = ['path' => $relative, 'action' => $action, 'before' => $size, 'after' => $file['size'] ?? 0];
            $signature[$relative] = [$action, $exists ? hash_file('sha256', $target) : null, $file['sha256'] ?? null];
        }
        $warnings = [];
        if ($job['database']) { $warnings[] = 'Selected database tables replace destination tables completely. Concurrent orders, submissions, and content are not merged.'; }
        if ($plan['paths']) { $warnings[] = 'Files apply one at a time. A temporary maintenance screen protects normal visitors during apply; recovery access stays available.'; }
        foreach ($job['files'] as $file) {
            if (!empty($file['unchanged'])) { continue; }
            $parts = explode('/', $file['path']);
            if (($parts[0] === 'plugins' && count($parts) <= 3 && str_ends_with($file['path'], '.php')) || ($parts[0] === 'themes' && count($parts) === 3 && $parts[2] === 'style.css')) {
                $headers = get_file_data($file['stage'], ['php' => 'Requires PHP', 'wp' => 'Requires at least']);
                if ($headers['php'] && version_compare(PHP_VERSION, $headers['php'], '<')) { throw new RuntimeException($file['path'] . ' requires PHP ' . $headers['php'] . '; destination uses ' . PHP_VERSION); }
                if ($headers['wp'] && version_compare(get_bloginfo('version'), $headers['wp'], '<')) { throw new RuntimeException($file['path'] . ' requires WordPress ' . $headers['wp']); }
            }
            if (str_ends_with($file['path'], '.php')) {
                try { token_get_all(file_get_contents($file['stage']), TOKEN_PARSE); }
                catch (ParseError $e) { throw new RuntimeException('PHP syntax check failed for ' . $file['path'] . ': ' . $e->getMessage()); }
            }
        }
        $free = disk_free_space(CSM_Support::private_dir());
        if ($free !== false && $free < $backup + 16 * 1024 * 1024) { throw new RuntimeException('Insufficient private storage for the destination rollback backup.'); }
        usort($items, static fn($a, $b) => (['delete' => 0, 'replace' => 1, 'add' => 2][$a['action']] <=> ['delete' => 0, 'replace' => 1, 'add' => 2][$b['action']]) ?: strcmp($a['path'], $b['path']));
        ksort($signature, SORT_STRING);
        return ['counts' => $counts, 'backup_bytes' => $backup, 'items' => $items, 'total' => count($items), 'warnings' => $warnings,
            'token' => hash('sha256', CSM_Support::json($signature)), 'checked_at' => time()];
    }

    public static function preview_page(array $job, int $offset = 0): array {
        $raw = @file_get_contents(CSM_Support::private_dir() . '/' . $job['id'] . '-preview.json');
        if ($offset < 0 || $raw === false || !hash_equals($job['preview']['file_hash'] ?? '', hash('sha256', $raw))) { throw new RuntimeException('Transfer preview is unavailable or changed. Prepare again.'); }
        $items = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return ['items' => array_slice($items, $offset, 200), 'more' => $offset + 200 < count($items), 'total' => count($items)];
    }

    public static function commit(array $job, ?string $run = null): array {
        CSM_Updates::assert_job($job);
        global $wpdb;
        CSM_Progress::start($job['id'], 'Checking destination', $run);
        $actor = current_user_can('manage_options') ? wp_get_current_user() : null;
        if ($job['status'] !== 'staging' || !self::summary($job)['ready']) { throw new RuntimeException('Both selected streams must be complete before applying.'); }
        if ($job['files'] && !wp_is_file_mod_allowed('trickle')) { throw new RuntimeException('WordPress configuration disallows file modifications on this destination.'); }
        if ($job['files'] && !self::loader_ready()) { throw new RuntimeException('Activate Trickle again to install its required recovery loader before applying file transfers.'); }
        $base = $job['backup_dir'] ?? CSM_Support::backup_dir() . '/' . $job['id'] . '-backup';
        $job['backup_dir'] = $base; CSM_Activation::capture($job); CSM_Support::save($job);
        if (CSM_Support::job($job['id']) !== $job) { throw new RuntimeException('Could not persist the transfer recovery state.'); }
        if (!is_dir($base) && !mkdir($base, 0700)) { throw new RuntimeException('Cannot create rollback storage.'); }
        $preview = self::preview($job);
        if (isset($job['preview']) && !hash_equals($job['preview']['token'], $preview['token'])) { throw new RuntimeException('Destination code changed after the transfer preview. Discard and prepare again before overwriting newer work.'); }
        $plan = self::changes($job); $new = $plan['new']; $paths = $plan['paths'];
        $backup_paths = $paths;
        foreach ($job['files'] as $file) {
            if (!str_starts_with($file['path'], 'uploads/')) { $backup_paths[$file['path']] = CSM_Files::destination($file['path'], $job['selection']); }
        }
        $ops = [];
        foreach ($backup_paths as $relative => $target) {
            CSM_Progress::update(['phase' => 'Backing up destination file', 'component' => $relative, 'count' => count($ops), 'total' => count($backup_paths), 'unit' => 'files']);
            $backup = $base . '/' . count($ops); $existed = is_file($target);
            if ($existed && !copy($target, $backup)) { throw new RuntimeException('Could not back up ' . $relative); }
            $ops[] = ['path' => $relative, 'backup' => $backup, 'existed' => $existed, 'mtime' => $existed ? filemtime($target) : 0, 'mode' => $existed ? (fileperms($target) & 0777) : 0644, 'sha256' => $existed ? hash_file('sha256', $backup) : null];
        }
        // Persist the complete recovery plan before mutation, without rewriting the large job per file.
        $plan = CSM_Support::json($ops);
        if (file_put_contents($base . '/operations.json', $plan, LOCK_EX) !== strlen($plan)) { throw new RuntimeException('Could not save the file recovery plan.'); }
        chmod($base . '/operations.json', 0600);
        $job['backup_dir'] = $base; $job['ops_hash'] = hash('sha256', $plan); $job['ops'] = []; $job['code_snapshot'] = true;
        $job['status'] = 'applying'; CSM_Support::save($job);
        if (CSM_Support::job($job['id']) !== $job) { throw new RuntimeException('Could not persist the transfer recovery state.'); }
        wp_schedule_single_event(time() + 60, 'trickle_recover', [$job['id']]);
        $probe = bin2hex(random_bytes(32));
        if ($paths || $job['database'] || isset($job['active_plugins_before'])) { update_option('_csm_maintenance', ['probe' => hash('sha256', $probe), 'id' => $job['id'], 'until' => time() + 15 * MINUTE_IN_SECONDS], false); }
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
            CSM_Activation::apply($job);
            CSM_Progress::update(['phase' => 'Checking frontend health', 'component' => 'WordPress frontend', 'unit' => '', 'count' => 0, 'total' => null, 'bytes' => 0], true);
            $job['health'] = CSM_Transport::health(site_url(), $probe);
            if ($job['health']['status'] >= 500 && $job['health']['status'] <= 599) { throw new RuntimeException('Destination health check returned HTTP ' . $job['health']['status']); }
            $job['db_applied'] = true; $job['status'] = 'applied'; $job['applied'] = time(); CSM_Support::save($job);
            if (function_exists('opcache_reset')) { opcache_reset(); }
            self::end_maintenance($job['id']);
            wp_clear_scheduled_hook('trickle_recover', [$job['id']]);
            CSM_Progress::finish('Transfer complete');
            $result = array_merge(self::summary($job), self::retain_controller($actor, $job));
            CSM_Backups::retain($job);
            return $result;
        } catch (Throwable $e) {
            CSM_Progress::fail($e->getMessage());
            $job['error'] = $e->getMessage(); CSM_Support::save($job);
            try { self::rollback($job); } catch (Throwable $rollback_error) { throw new RuntimeException($e->getMessage() . ' Rollback needs attention: ' . $rollback_error->getMessage()); }
            throw new RuntimeException('Apply failed; changes rolled back. ' . $e->getMessage());
        }
    }

    private static function end_maintenance(string $id): void {
        if ((get_option('_csm_maintenance', [])['id'] ?? '') === $id) { delete_option('_csm_maintenance'); }
    }

    private static function install_file(string $source, string $target, int $mtime, int $mode): void {
        if (!is_dir(dirname($target)) && !wp_mkdir_p(dirname($target))) { throw new RuntimeException('Cannot create destination directory.'); }
        $temp = tempnam(dirname($target), '.csm-');
        if (!$temp || !copy($source, $temp)) { throw new RuntimeException('Could not copy staged file.'); }
        chmod($temp, $mode); touch($temp, $mtime);
        if (!rename($temp, $target)) { unlink($temp); throw new RuntimeException('Could not replace destination file.'); }
        if (function_exists('opcache_invalidate')) { opcache_invalidate($target, true); }
    }

    public static function recovery_operations(array $job): array {
        if (!isset($job['ops_hash'])) { return $job['ops']; }
        $plan = @file_get_contents(($job['backup_dir'] ?? CSM_Support::private_dir() . '/' . $job['id'] . '-backup') . '/operations.json');
        if ($plan === false || !hash_equals($job['ops_hash'], hash('sha256', $plan))) { throw new RuntimeException('The file recovery plan is missing or changed.'); }
        $ops = json_decode($plan, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($ops) || !array_is_list($ops)) { throw new RuntimeException('Invalid file recovery plan.'); }
        return $ops;
    }

    public static function rollback(array $job): array {
        global $wpdb;
        CSM_Progress::update(['phase' => 'Restoring destination backup', 'component' => '', 'count' => 0, 'total' => null, 'unit' => '']);
        $actor = current_user_can('manage_options') ? wp_get_current_user() : null;
        if (!in_array($job['status'], ['applied', 'applying'], true)) { throw new RuntimeException('Only applied or interrupted transfers can be restored.'); }
        $active = get_option('_csm_active_job');
        if ($active && $active !== $job['id']) { throw new RuntimeException('Finish the active transfer before restoring another backup.'); }
        $ops = self::recovery_operations($job);
        $renames = [];
        foreach ($job['database'] as $table) {
            CSM_Progress::update(['phase' => 'Restoring table', 'component' => $table['target']]);
            $backup_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table['backup']))) === $table['backup'];
            $stage_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table['stage']))) === $table['stage'];
            if ($backup_exists) {
                CSM_Database::preserve_options(['database' => [['target' => $table['target'], 'stage' => $table['backup']]]], true);
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table['target']))) === $table['target']) { $renames[] = CSM_Support::ident($table['target']) . ' TO ' . CSM_Support::ident($table['stage']); }
                $renames[] = CSM_Support::ident($table['backup']) . ' TO ' . CSM_Support::ident($table['target']);
            } elseif (!$stage_exists && isset($table['existed']) && !$table['existed']) {
                CSM_Support::query('DROP TABLE IF EXISTS ' . CSM_Support::ident($table['target']));
            }
        }
        if ($renames) { CSM_Support::query('RENAME TABLE ' . implode(', ', $renames)); }
        wp_cache_flush();
        foreach (array_reverse($ops) as $op) {
            CSM_Progress::update(['phase' => 'Restoring file', 'component' => $op['path']]);
            if (CSM_Files::excluded($op['path'])) { continue; }
            $target = CSM_Files::destination($op['path'], $job['selection']);
            if ($op['existed']) { self::install_file($op['backup'], $target, $op['mtime'], $op['mode']); }
            elseif (is_file($target) && !unlink($target)) { throw new RuntimeException('Could not remove restored file.'); }
        }
        CSM_Activation::restore($job);
        self::end_maintenance($job['id']);
        wp_clear_scheduled_hook('trickle_recover', [$job['id']]);
        $job['status'] = 'restored'; CSM_Support::save($job); CSM_Backups::forget($job['id']);
        if (get_option('_csm_active_job') === $job['id']) { delete_option('_csm_active_job'); }
        CSM_Progress::finish('Destination restored'); return array_merge(self::summary($job), self::retain_controller($actor, $job));
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
        if ((get_option('_csm_backup_restore', [])['backup_id'] ?? '') === $job['id']) { throw new RuntimeException('Recovery is using this backup. It cannot be discarded yet.'); }
        if (in_array($job['status'], ['applying', 'backup_copy'], true)) { throw new RuntimeException('Recovery is in progress. Its backup cannot be discarded.'); }
        CSM_Segments::cleanup($job['id']);
        foreach ($job['database'] as $table) {
            foreach (['stage', 'backup'] as $kind) { CSM_Support::query('DROP TABLE IF EXISTS ' . CSM_Support::ident($table[$kind])); }
        }
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id']);
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id'] . '-backup');
        if (isset($job['backup_dir'])) { CSM_Support::remove_tree($job['backup_dir']); }
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id'] . '-incoming');
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id'] . '-preview.json');
        CSM_Progress::remove($job['id']);
        delete_option('_csm_job_' . $job['id']); CSM_Backups::forget($job['id']);
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
        $script = ($_GET['view'] ?? '') === 'backups' ? 'backups' : 'admin';
        if ($script === 'admin') { wp_enqueue_script('trickle-progress', plugins_url('assets/transfer-progress.js', CSM_FILE), [], CSM_VERSION . '.' . filemtime(dirname(CSM_FILE) . '/assets/transfer-progress.js'), true); }
        wp_enqueue_script('trickle', plugins_url('assets/' . $script . '.js', CSM_FILE), $script === 'admin' ? ['trickle-progress'] : [], CSM_VERSION . '.' . filemtime(dirname(CSM_FILE) . '/assets/' . $script . '.js'), true);
        wp_localize_script('trickle', 'CSM', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('csm_admin'), 'state' => CSM_Controller::state(), 'keys' => CSM_Access::list()]);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        $loader_error = '';
        if (!self::loader_ready() && wp_is_file_mod_allowed('trickle')) {
            try { self::install_loader(); }
            catch (Throwable $e) { $loader_error = $e->getMessage(); CSM_Log::write('error', 'Recovery loader update failed', ['error' => $loader_error]); }
        }
        $settings = CSM_Access::settings();
        require __DIR__ . (($_GET['view'] ?? '') === 'backups' ? '/backup-page.php' : '/admin.php');
    }
}
