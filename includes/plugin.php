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
        return is_file($path) && !is_link($path) && hash_equals(hash('sha256', self::loader_code()), hash_file('sha256',$path));
    }

    public static function boot(): void {
        if (!get_option('_csm_key')) { add_option('_csm_key', bin2hex(random_bytes(32)), '', false); }
        // Upgrades do not run activation hooks; refresh the security-sensitive MU loader once per version.
        if (get_option('_csm_loader_version') !== CSM_VERSION && wp_is_file_mod_allowed('trickle')) {
            try {
                if (!self::loader_ready()) { self::install_loader(); }
                update_option('_csm_loader_version', CSM_VERSION, false);
            } catch (Throwable $error) { CSM_Log::write('error', 'Recovery loader refresh failed', ['error' => $error->getMessage()]); }
        }
        if (defined('CSM_RECOVERY_CRON')) {
            self::recover_interrupted((string)get_option('_csm_active_job'));
            // Other plugins were isolated; leave their due cron events untouched for the next request.
            exit;
        }
        if (isset($_GET['trickle'])) { self::endpoint(); }
        add_action('admin_menu', static function () { add_management_page('Trickle Migrate', 'Trickle Migrate', 'manage_options', 'trickle', [self::class, 'page']); });
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_csm_admin', [self::class, 'ajax']);
        add_action('trickle_prune_sources', [CSM_Segments::class, 'prune_sources']);
        if (!wp_next_scheduled('trickle_prune_sources')) { wp_schedule_event(time()+300, 'hourly', 'trickle_prune_sources'); }
        add_action('trickle_cancel_transfer',[CSM_Controller::class,'retry_cancel']);
        add_action('trickle_cleanup_source', [CSM_Controller::class, 'retry_source_cleanup'], 10, 2);
        add_action('trickle_recover', [self::class, 'recover_interrupted']);
    }

    public static function recover_interrupted(string $id): void {
        try { $job = CSM_Support::job($id); } catch (Throwable $e) { return; }
        if (!in_array($job['status'], ['applying', 'backup_copy', 'restoring'], true) || get_option('_csm_recovery_blocked_'.$id)) { return; }
        $heartbeat=0; foreach (['-heartbeat','-apply.json','-restore.json'] as $suffix) { $path=CSM_Support::private_dir().'/'.$id.$suffix; clearstatcache(true,$path); if (is_file($path)) { $heartbeat=max($heartbeat,filemtime($path)); } }
        if ($heartbeat>time()-30) { wp_schedule_single_event(time()+30,'trickle_recover',[$id]); return; }
        try { CSM_Support::lock(); }
        catch (Throwable $e) { wp_schedule_single_event(time() + 60, 'trickle_recover', [$id]); return; }
        try {
            $job = CSM_Support::job($id);
            if (!in_array($job['status'], ['applying', 'backup_copy', 'restoring'], true)) { return; }
            CSM_Support::work($id,false);
            CSM_Progress::start($id, 'Recovering interrupted transfer');
            $restore = get_option('_csm_backup_restore');
            if (($restore['job_id'] ?? '') === $id) { $result=CSM_Backups::restore($restore['backup_id']); if (!empty($result['continue'])) { return; } }
            else { $result=self::rollback($job); if (!empty($result['continue'])) { return; } }
            CSM_Log::write('info', 'Interrupted transfer recovered', ['transfer_id' => $id]);
        } catch (Throwable $e) {
            $current=CSM_Support::job($id);
            $cursor=CSM_Support::private_dir().'/'.$id.'-restore.json';
            $stamp=hash('sha256',CSM_Support::json([$current['status'],$current['backup_copy'] ?? null,is_file($cursor)?file_get_contents($cursor):$current['restore_cursor'] ?? 0]));
            $failures=get_option('_csm_recovery_attempts_'.$id,[]); $count=($failures['stamp'] ?? '')===$stamp?($failures['count'] ?? 0)+1:1;
            update_option('_csm_recovery_attempts_'.$id,['stamp'=>$stamp,'count'=>$count],false);
            if ($count>=5) {
                update_option('_csm_recovery_blocked_'.$id,$e->getMessage(),false);
                CSM_Log::write('error','Recovery needs attention; site remains protected',['transfer_id'=>$id,'error'=>$e->getMessage()]);
            } else {
                CSM_Log::write('error', 'Automatic recovery will retry', ['transfer_id' => $id, 'error' => $e->getMessage()]);
                wp_schedule_single_event(time() + 60, 'trickle_recover', [$id]);
            }
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
        try { $estimates=CSM_Estimates::read(true); }
        catch (Throwable $error) { $estimates=null; CSM_Log::write('error','Size estimate unavailable',['error'=>$error->getMessage()]); }
        return array_merge($inventory, CSM_Database::identity(), CSM_Updates::capabilities(), ['file_modifications' => wp_is_file_mod_allowed('trickle'), 'active_plugins' => CSM_Activation::current(), 'installed_plugins' => array_keys(get_plugins()), 'trickle_plugin' => plugin_basename(CSM_FILE), 'can_receive' => !empty(CSM_Access::settings()['push']) && (!isset($GLOBALS['_csm_credential']) || !empty($GLOBALS['_csm_credential']['write'])), 'bounded_work'=>2, 'segments' => 1, 'resumable_stream' => 1, 'component_compare' => 1, 'file_policy' => 1, 'canonical_url' => (string)get_option('siteurl'), 'instance_fingerprint' => CSM_Controller::fingerprint(), 'media_manifest'=>1, 'media_compare' => 1, 'media_browsing' => 1, 'media_dates' => 1, 'replacement_rules' => 1, 'protocol' => 1, 'plugin_version' => CSM_VERSION, 'estimates'=>$estimates, 'name' => get_bloginfo('name'), 'icon' => get_site_icon_url(64), 'prefix' => $wpdb->prefix,
            'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'tables' => CSM_Database::inventory(), 'isolation' => self::loader_ready(), 'incoming_writes' => !empty(CSM_Access::settings()['push'])]);
    }

    public static function endpoint(): void {
        $action = sanitize_key(wp_unslash($_GET['trickle']));
        $streaming = false;
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new RuntimeException('POST required.', 405); }
            self::supported(); CSM_Support::long_request();
            if (!in_array($action, ['manifest', 'version', 'update_info', 'update_trickle', 'backups', 'restore_backup', 'media', 'media_hashes', 'file_policy', 'component_hashes', 'component_manifest', 'media_manifest', 'bundle', 'bundle_stream', 'stream_data', 'bundle_chunk', 'bundle_discard', 'cancel', 'estimates', 'segment_state', 'segment_data', 'export', 'prepare', 'receive', 'status', 'preview', 'progress', 'commit', 'rollback', 'discard'], true)) { throw new RuntimeException('Unknown action.', 404); }
            $data = CSM_Transport::authenticate($action);
            $work_id=$data['id'] ?? $data['selection']['comparison_id'] ?? '';
            if (is_string($work_id) && preg_match('/^[a-f0-9]{32}$/D',$work_id) && in_array($action,['component_hashes','media_hashes'],true)) {
                $data['id']=$work_id; CSM_Segments::lease($work_id); CSM_Support::work($work_id);
                CSM_Progress::start($work_id,'Comparing file checksums',$data['progress_run'] ?? null);
            }
            if (!empty($data['id']) && preg_match('/^[a-f0-9]{32}$/D',$data['id'])) { CSM_Support::work($data['id'],!in_array($action,['cancel','rollback','discard','bundle_discard','status','progress'],true)); }
            if (in_array($action,['component_hashes','media_hashes','file_policy','export','receive'],true)) { CSM_Support::begin_source_budget(); @set_time_limit(50); }
            // Native upgrades may leave the previous MU loader until an administrator visits Trickle.
            if (!self::loader_ready() && wp_is_file_mod_allowed('trickle')) {
                try { self::install_loader(); }
                catch (Throwable $error) { CSM_Log::write('error', 'Recovery loader refresh failed', ['error'=>$error->getMessage()]); }
            }
            if (((in_array($action, ['export', 'bundle'], true) && ($data['kind'] ?? '') === 'files') || ($action === 'prepare' && in_array('files', self::kinds($data['selection'] ?? []), true))) && (($data['selection']['file_policy'] ?? 0) !== 1)) { throw new RuntimeException('Update Trickle on both sites before transferring files with Git and environment-file protection.'); }
            if (in_array($action, ['commit', 'rollback', 'restore_backup'], true)) { ignore_user_abort(true); }
            if (!in_array($action, ['progress', 'status'], true)) { CSM_Log::write('info', 'Authenticated incoming request', ['operation' => $action, 'transfer_id' => $data['id'] ?? null]); }
            self::prune_nonces();
            if ($action === 'version') { self::respond(['url' => site_url(), 'canonical_url' => (string)get_option('siteurl')] + CSM_Updates::capabilities() + ['plugin_version' => CSM_VERSION, 'instance_fingerprint' => CSM_Controller::fingerprint()]); }
            if ($action === 'update_info') { self::respond(CSM_Updates::info()); }
            if ($action === 'update_trickle') { CSM_Support::lock(); self::respond(CSM_Updates::install($data['target'] ?? '', $data['from'] ?? '')); }
            if ($action === 'backups') { self::respond(CSM_Backups::listing()); }
            if ($action === 'restore_backup') { CSM_Support::lock(); CSM_Progress::start($data['id'] ?? '', 'Restoring saved backup', $data['progress_run'] ?? null); self::respond(CSM_Backups::restore($data['id'] ?? '')); }
            if ($action === 'estimates') { self::respond(CSM_Estimates::read(true)); }
            if ($action === 'manifest') { self::respond(self::manifest(!empty($data['refresh']))); }
            if ($action === 'media') { self::respond(CSM_Files::media((int)($data['page'] ?? 1), $data['ids'] ?? [])); }
            if ($action === 'bundle') {
                if (($data['format'] ?? 0)!==2) { throw new RuntimeException('Install this Trickle build on both sites before packaging.'); }
                CSM_Support::lock();
                CSM_Progress::start($data['id'] ?? '', 'Building resumable source stream', $data['progress_run'] ?? null);
                self::respond(CSM_Segments::bundle($data['id'] ?? '', $data['kind'] ?? '', $data['selection'] ?? []));
            }
            if ($action === 'bundle_chunk') {
                $bytes = CSM_Segments::chunk($data['id'] ?? '', $data['kind'] ?? '', (int)($data['offset'] ?? -1));
                while (ob_get_level()) { ob_end_clean(); }
                nocache_headers(); header('Content-Type: application/octet-stream'); echo $bytes; exit;
            }
            if ($action === 'cancel') { CSM_Segments::cancel($data['id'] ?? ''); self::respond(['cancel_requested'=>true]); }
            if ($action === 'bundle_discard') { CSM_Segments::cleanup_source($data['id'] ?? ''); self::respond([]); }
            if ($action === 'bundle_stream') {
                $offset = $data['offset'] ?? -1;
                if (!is_int($offset)) { throw new RuntimeException('Invalid source offset.'); }
                $generator = CSM_Segments::source($data['id'] ?? '', $data['kind'] ?? '', $offset, $data['bundle'] ?? []);
                $generator->rewind();
                while (ob_get_level()) { ob_end_clean(); }
                nocache_headers(); header('Content-Type: application/octet-stream'); header('Content-Length: ' . ($data['bundle']['size'] - $offset)); header('X-Accel-Buffering: no');
                $streaming = true; $out = fopen('php://output', 'wb');
                foreach ($generator as $bytes) { CSM_Support::write($out, $bytes); flush(); if (connection_aborted()) { break; } }
                fclose($out); exit;
            }
            if (in_array($action,['component_manifest','media_manifest'],true)) {
                $raw = CSM_Support::json($action==='media_manifest'?CSM_Media::manifest($data):CSM_Components::manifest($data));
                if (strlen($raw) > 32 * 1024 * 1024) { throw new RuntimeException('Component manifest exceeds its size limit.'); }
                $bytes = gzencode($raw, 3);
                while (ob_get_level()) { ob_end_clean(); }
                nocache_headers(); header('Content-Type: application/octet-stream'); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;
            }
            if ($action === 'component_hashes') { $result=CSM_Components::inventory($data); CSM_Progress::update(['component'=>'']); CSM_Progress::finish('Plugin and theme checksums ready'); self::respond($result); }
            if ($action === 'file_policy') { self::respond(['git_folders' => CSM_Files::git_folders($data['selection'] ?? [])]); }
            if ($action === 'media_hashes') { $result=CSM_Media::inventory($data); CSM_Progress::update(['component'=>'']); CSM_Progress::finish('Media checksums ready'); self::respond($result); }
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
            if ($action === 'stream_data') {
                CSM_Updates::assert_job($job);
                if (!is_int($data['offset'] ?? null)) { throw new RuntimeException('Invalid destination offset.'); }
                if (isset($data['length']) && !is_int($data['length'])) { throw new RuntimeException('Invalid upload window length.'); }
                CSM_Progress::start($job['id'], 'Receiving resumable stream', $data['progress_run'] ?? null);
                $fp = fopen('php://input', 'rb');
                try { self::respond(CSM_Segments::receive_stream($job, $data['kind'] ?? '', $data['bundle'] ?? [], $data['offset'], $fp, $data['length'] ?? null)); }
                finally { fclose($fp); }
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
        } catch (CSM_Yield $e) { self::respond(['continue'=>true]);
        } catch (Throwable $e) {
            CSM_Log::write('error', 'Incoming request failed', ['operation' => $action, 'error' => $e->getMessage()]);
            CSM_Progress::fail($e->getMessage());
            if ($streaming) { error_log('Trickle export stopped: ' . $e->getMessage()); exit; }
            self::respond($e instanceof CSM_Network_Error ? ['retryable'=>true, 'retry_after'=>$e->retry_after, 'busy'=>$e instanceof CSM_Busy_Error] : [], $e->getMessage(), $e instanceof CSM_Busy_Error ? 409 : (in_array($e->getCode(), [403, 404, 405], true) ? $e->getCode() : 400));
        }
    }

    public static function respond(array $data, string $error = '', int $status = 200): never {
        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers(); status_header($status); header('Content-Type: application/json; charset=utf-8');
        echo CSM_Support::json($error ? ['ok' => false, 'error' => $error] + $data : ['ok' => true, 'data' => $data]); exit;
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

    public static function receive_step(array $job, string $kind, $fp): array {
        CSM_Updates::assert_job($job);
        if ($job['status']!=='staging' || !in_array($kind,self::kinds($job['selection']),true)) { throw new RuntimeException('Transfer is not awaiting this stream.'); }
        if (!empty($job['received'][$kind])) { return $job; }
        $already=!empty($job['import_done'][$kind]);
        $done=$already || ($kind==='database'?CSM_Database::stage_step($fp,$job):CSM_Files::stage_step($fp,$job));
        if ($done) { $job['import_done'][$kind]=true; }
        if ($done) { $job['received'][$kind]=true; unset($job['error']); }
        if (!array_diff(self::kinds($job['selection']),array_keys($job['received']))) {
            if (!$already) { unset($job['received'][$kind]); CSM_Support::save($job); return $job; }
            $preview=self::preview_step($job);
            if ($preview) { $job['preview']=$preview; }
            else { unset($job['received'][$kind]); }
        }
        CSM_Support::save($job);
        if (!empty($job['received'][$kind])) { CSM_Progress::finish(ucfirst($kind).' verified'); }
        return $job;
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
        $new = []; $paths = []; $checked = 0;
        foreach ($job['files'] as $file) {
            CSM_Progress::update(['phase' => 'Verifying staged file', 'component' => $file['path'], 'count' => $checked++, 'total' => count($job['files']), 'unit' => 'files', 'bytes' => 0]);
            $target = CSM_Files::destination($file['path'], $job['selection']);
            if (!empty($file['unchanged'])) {
                if (!is_file($target) || !hash_equals($file['sha256'], CSM_Support::hash_file($target))) { throw new RuntimeException('An unchanged destination file changed after staging: ' . $file['path'] . '. Discard and prepare again.'); }
                $new[$file['path']] = $file; continue;
            }
            if (!is_file($file['stage']) || !hash_equals($file['sha256'], CSM_Support::hash_file($file['stage']))) { throw new RuntimeException('A staged file is missing or changed.'); }
            $new[$file['path']] = $file; $paths[$file['path']] = $target;
        }
        CSM_Progress::update(['count' => $checked]);
        // Selected plugin/theme directories are replacements, not merges that leave obsolete PHP files behind.
        foreach (['plugins', 'themes'] as $kind) {
            foreach ($job['selection'][$kind] as $component) {
                CSM_Progress::update(['phase' => 'Checking destination component', 'component' => $kind . '/' . $component, 'count' => 0, 'total' => null, 'unit' => '', 'bytes' => 0]);
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
        $checked = 0;
        foreach ($paths as $relative => $target) {
            CSM_Progress::update(['phase' => 'Checking destination file', 'component' => $relative, 'count' => $checked++, 'total' => count($paths), 'unit' => 'files', 'bytes' => 0]);
            $ancestor = dirname($target);
            while (!file_exists($ancestor)) { $ancestor = dirname($ancestor); }
            if (!is_writable($ancestor) || (file_exists($target) && (!is_file($target) || !is_writable($target) || !is_writable(dirname($target))))) { throw new RuntimeException('Destination is not writable: ' . $relative); }
        }
        CSM_Progress::update(['count' => $checked]);
        return ['new' => $new, 'paths' => $paths];
    }

    private static function compatibility_error(array $job, array $file, string $problem): RuntimeException {
        $parts = explode('/', $file['path']);
        $kind = ['plugins' => 'plugin', 'themes' => 'theme'][$parts[0]] ?? 'file';
        $name = $kind === 'file' ? basename($file['path']) : $parts[1];
        if ($kind !== 'file') {
            foreach ($job['files'] as $candidate) {
                $path = explode('/', $candidate['path']);
                if ($path[0] !== $parts[0] || ($path[1] ?? '') !== $parts[1]) { continue; }
                if (($kind === 'theme' && count($path) === 3 && $path[2] === 'style.css') || ($kind === 'plugin' && count($path) <= 3 && str_ends_with($candidate['path'], '.php'))) {
                    $header = get_file_data(!empty($candidate['unchanged']) ? CSM_Files::destination($candidate['path'], $job['selection']) : $candidate['stage'], ['name' => $kind === 'theme' ? 'Theme Name' : 'Plugin Name']);
                    if (trim($header['name'])) { $name = wp_strip_all_tags($header['name']); break; }
                }
            }
        }
        return new RuntimeException(ucfirst($kind) . ' "' . $name . '": ' . $problem . '. Update or deselect this ' . $kind . ', then retry. File: ' . $file['path'] . '.');
    }

    private static function check_compatibility(array $job, array $file): void {
        if (!empty($file['unchanged'])) { return; }
        $parts = explode('/', $file['path']);
        if (str_ends_with($file['path'], '.php') || ($parts[0] === 'themes' && count($parts) === 3 && $parts[2] === 'style.css')) { CSM_Progress::update(['phase' => 'Checking PHP compatibility', 'component' => $file['path'], 'count' => 0, 'total' => count($job['files']), 'unit' => 'files', 'bytes' => 0]); }
        if (($parts[0] === 'plugins' && count($parts) <= 3 && str_ends_with($file['path'], '.php')) || ($parts[0] === 'themes' && count($parts) === 3 && $parts[2] === 'style.css')) {
            $headers = get_file_data($file['stage'], ['php' => 'Requires PHP', 'wp' => 'Requires at least']);
            if ($headers['php'] && version_compare(PHP_VERSION, $headers['php'], '<')) { throw self::compatibility_error($job, $file, 'requires PHP ' . $headers['php'] . '; destination uses PHP ' . PHP_VERSION); }
            if ($headers['wp'] && version_compare(get_bloginfo('version'), $headers['wp'], '<')) { throw new RuntimeException($file['path'] . ' requires WordPress ' . $headers['wp']); }
        }
        if (str_ends_with($file['path'], '.php')) {
            try { token_get_all(file_get_contents($file['stage']), TOKEN_PARSE); }
            catch (ParseError $e) { throw self::compatibility_error($job, $file, 'PHP parse error on destination PHP ' . PHP_VERSION . ' (line ' . $e->getLine() . ': ' . $e->getMessage() . ')'); }
        }
    }


    public static function preview_step(array &$job): ?array {
        $state=$job['preview_work'] ?? ['phase'=>'files','index'=>0,'new'=>[],'paths'=>[],'items'=>[],'counts'=>['add'=>0,'replace'=>0,'delete'=>0,'unchanged'=>0],'backup'=>0,'signature'=>[]];
        CSM_Support::step();
        if ($state['phase']==='files') {
            while ($state['index']<count($job['files'])) {
                $file=$job['files'][$state['index']]; $target=CSM_Files::destination($file['path'],$job['selection']);
                $check=!empty($file['unchanged'])?$target:$file['stage'];
                CSM_Progress::update(['phase'=>'Verifying staged file','component'=>$file['path'],'count'=>$state['index'],'total'=>count($job['files']),'unit'=>'files']);
                if (!is_file($check) || !hash_equals($file['sha256'],CSM_Support::hash_file($check))) { throw new RuntimeException('A staged or unchanged file is missing or changed: '.$file['path']); }
                self::check_compatibility($job,$file); $state['new'][$file['path']]=$file;
                if (!empty($file['unchanged'])) { $state['counts']['unchanged']++; }
                else { $state['paths'][$file['path']]=$target; }
                $state['index']++;
                if (CSM_Support::due()) { $job['preview_work']=$state; CSM_Support::save($job); return null; }
            }
            $state['phase']='obsolete'; $state['index']=0;
        }
        if ($state['phase']==='obsolete') {
            $components=[]; foreach (['plugins','themes'] as $kind) { foreach ($job['selection'][$kind] as $component) { $components[]=[$kind,$component]; } }
            while ($state['index']<count($components)) {
                [$kind,$component]=$components[$state['index']]; $root=CSM_Files::root($kind,$component); $path=$root.'/'.CSM_Files::safe_relative($component);
                foreach (CSM_Files::scan($path,$kind.'/'.$component,$job['selection']+['protect_source_git'=>true]) as $file) {
                    $relative=$kind.'/'.substr($file,strlen($root)+1);
                    if (isset($state['walked'][$relative])) { continue; }
                    $state['walked'][$relative]=true;
                    if (empty($state['new'][$relative]['unchanged'])) { $state['paths'][$relative]=CSM_Files::destination($relative,$job['selection']); }
                    if (CSM_Support::due()) { $job['preview_work']=$state; CSM_Support::save($job); return null; }
                }
                $state['index']++;
            }
            unset($state['walked']); $state['phase']='plan'; $state['index']=0;
        }
        $paths=array_keys($state['paths']);
        while ($state['index']<count($paths)) {
            $relative=$paths[$state['index']]; $target=$state['paths'][$relative]; $exists=is_file($target); $file=$state['new'][$relative] ?? null;
            $ancestor=dirname($target); while (!file_exists($ancestor)) { $ancestor=dirname($ancestor); }
            if (!is_writable($ancestor) || (file_exists($target) && (!is_file($target) || !is_writable($target) || !is_writable(dirname($target))))) { throw new RuntimeException('Destination is not writable: '.$relative); }
            $action=!$file?'delete':($exists?'replace':'add'); $size=$exists?filesize($target):0;
            $state['counts'][$action]++; $state['backup']+=$size;
            $state['items'][]=['path'=>$relative,'action'=>$action,'before'=>$size,'after'=>$file['size'] ?? 0];
            $state['signature'][$relative]=[$action,$exists?CSM_Support::hash_file($target):null,$file['sha256'] ?? null];
            CSM_Progress::update(['phase'=>'Checking destination file','component'=>$relative,'count'=>++$state['index'],'total'=>count($paths),'unit'=>'files']);
            if (CSM_Support::due()) { $job['preview_work']=$state; CSM_Support::save($job); return null; }
        }
        $free=disk_free_space(CSM_Support::private_dir());
        if ($free!==false && $free<$state['backup']+16777216) { throw new RuntimeException('Insufficient private storage for the destination rollback backup.'); }
        ksort($state['signature'],SORT_STRING);
        usort($state['items'],static fn($a,$b)=>(['delete'=>0,'replace'=>1,'add'=>2][$a['action']]<=>['delete'=>0,'replace'=>1,'add'=>2][$b['action']])?:strcmp($a['path'],$b['path']));
        $raw=CSM_Support::json($state['items']); $path=CSM_Support::private_dir().'/'.$job['id'].'-preview.json';
        CSM_Support::atomic_json($path,$state['items']);
        $preview=['counts'=>$state['counts'],'backup_bytes'=>$state['backup'],'items'=>array_slice($state['items'],0,200),'total'=>count($state['items']),'warnings'=>$job['database']?['Selected database tables replace destination tables completely.']:[], 'token'=>hash('sha256',CSM_Support::json($state['signature'])),'checked_at'=>time(),'file_hash'=>hash('sha256',$raw)];
        $job['file_plan']=['new'=>$state['new'],'paths'=>$state['paths'],'signature'=>$state['signature']];
        unset($job['preview_work']); CSM_Support::save($job); return $preview;
    }

    public static function preview(array $job): array {
        $plan = self::changes($job); $items = []; $counts = ['add' => 0, 'replace' => 0, 'delete' => 0, 'unchanged' => 0]; $backup = 0; $signature = [];
        foreach ($job['files'] as $file) { if (!empty($file['unchanged'])) { $counts['unchanged']++; } }
        foreach ($plan['paths'] as $relative => $target) {
            $exists = is_file($target); $file = $plan['new'][$relative] ?? null;
            $action = !$file ? 'delete' : ($exists ? 'replace' : 'add'); $counts[$action]++;
            $size = $exists ? filesize($target) : 0; $backup += $size;
            $items[] = ['path' => $relative, 'action' => $action, 'before' => $size, 'after' => $file['size'] ?? 0];
            $signature[$relative] = [$action, $exists ? CSM_Support::hash_file($target) : null, $file['sha256'] ?? null];
        }
        $warnings = [];
        if ($job['database']) { $warnings[] = 'Selected database tables replace destination tables completely. Concurrent orders, submissions, and content are not merged.'; }
        if ($plan['paths']) { $warnings[] = 'Files apply one at a time. A temporary maintenance screen protects normal visitors during apply; recovery access stays available.'; }
        $compat_checked = 0;
        foreach ($job['files'] as $file) { self::check_compatibility($job,$file); $compat_checked++; }
        if ($compat_checked) { CSM_Progress::update(['phase'=>'Checking PHP compatibility','component'=>'','count'=>$compat_checked,'total'=>count($job['files']),'unit'=>'files','bytes'=>0]); }
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
        if (!in_array($job['status'],['staging','applying'],true) || !self::summary($job)['ready']) { throw new RuntimeException('Both selected streams must be complete before applying.'); }
        if ($job['files'] && !wp_is_file_mod_allowed('trickle')) { throw new RuntimeException('WordPress configuration disallows file modifications on this destination.'); }
        if ($job['files'] && !self::loader_ready()) { throw new RuntimeException('Activate Trickle again to install its required recovery loader before applying file transfers.'); }
        $base = $job['backup_dir'] ?? CSM_Support::backup_dir() . '/' . $job['id'] . '-backup';
        $job['backup_dir'] = $base; if (empty($job['apply_work'])) { CSM_Activation::capture($job); } CSM_Support::save($job);
        if (CSM_Support::job($job['id']) !== $job) { throw new RuntimeException('Could not persist the transfer recovery state.'); }
        if (!is_dir($base) && !mkdir($base, 0700)) { throw new RuntimeException('Cannot create rollback storage.'); }
        CSM_Support::step();
        if (empty($job['apply_work'])) {
            $preview=self::preview_step($job);
            if (!$preview) { return self::summary($job)+['continue'=>true]; }
            if (isset($job['preview']) && !hash_equals($job['preview']['token'],$preview['token'])) { throw new RuntimeException('Destination code changed after preview. Discard and prepare again.'); }
            $plan=$job['file_plan']; $backup_paths=$plan['paths'];
            foreach ($job['files'] as $file) { if (!str_starts_with($file['path'],'uploads/')) { $backup_paths[$file['path']]=CSM_Files::destination($file['path'],$job['selection']); } }
            $job['apply_work']=['phase'=>'backup','backup_paths'=>$backup_paths,'ops'=>[],'cursor'=>0]; CSM_Support::save($job);
        }
        $work=&$job['apply_work']; $cursor_path=CSM_Support::private_dir().'/'.$job['id'].'-apply.json';
        if (is_file($cursor_path)) { $work=array_replace($work,json_decode(file_get_contents($cursor_path),true)); }
        $new=$job['file_plan']['new']; $paths=$job['file_plan']['paths'];
        if ($work['phase']==='backup') {
            $backup_paths=$work['backup_paths']; $keys=array_keys($backup_paths);
            while (count($work['ops'])<count($keys)) {
                $relative=$keys[count($work['ops'])]; $target=$backup_paths[$relative];
                if (CSM_Files::destination($relative,$job['selection'])!==$target) { throw new RuntimeException('Destination path changed after preview: '.$relative); } $existed=is_file($target); $backup=$base.'/'.count($work['ops']);
                CSM_Progress::update(['phase'=>'Backing up destination file','component'=>$relative,'count'=>count($work['ops']),'total'=>count($keys),'unit'=>'files']);
                if ($existed && !self::copy_step($target,$backup,$work['copy'])) { CSM_Support::save($job); return self::summary($job)+['continue'=>true]; }
                $digest=$existed?CSM_Support::hash_file($backup):null;
                $expected=array_key_exists($relative,$job['file_plan']['signature'])?$job['file_plan']['signature'][$relative][1]:($new[$relative]['sha256'] ?? null);
                if ($expected!==$digest) { throw new RuntimeException('Destination changed while backing up: '.$relative); }
                $work['ops'][]=['path'=>$relative,'backup'=>$backup,'existed'=>$existed,'mtime'=>$existed?filemtime($target):0,'mode'=>$existed?(fileperms($target)&0777):0644,'sha256'=>$digest];
                unset($work['copy']);
                if (CSM_Support::due()) { CSM_Support::save($job); return self::summary($job)+['continue'=>true]; }
            }
            $work['phase']='apply'; CSM_Support::save($job);
        }
        $ops=$work['ops'];
        // Persist the complete recovery plan before mutation, without rewriting the large job per file.
        $plan = CSM_Support::json($ops);
        if (file_put_contents($base . '/operations.json', $plan, LOCK_EX) !== strlen($plan)) { throw new RuntimeException('Could not save the file recovery plan.'); }
        chmod($base . '/operations.json', 0600);
        $job['backup_dir'] = $base; $job['ops_hash'] = hash('sha256', $plan); $job['ops'] = []; $job['code_snapshot'] = true;
        $job['status'] = 'applying'; CSM_Support::save($job);
        if (CSM_Support::job($job['id']) !== $job) { throw new RuntimeException('Could not persist the transfer recovery state.'); }
        wp_clear_scheduled_hook('trickle_recover', [$job['id']]); wp_schedule_single_event(time() + 60, 'trickle_recover', [$job['id']]);
        $probe = bin2hex(random_bytes(32));
        if ($paths || $job['database'] || isset($job['active_plugins_before'])) { update_option('_csm_maintenance', ['probe' => hash('sha256', $probe), 'id' => $job['id'], 'until' => time() + 15 * MINUTE_IN_SECONDS], false); }
        try {
            $completed = $work['cursor']; $index=0;
            foreach ($paths as $relative => $target) {
                if ($index++ < $work['cursor']) { continue; }
                if (CSM_Files::destination($relative,$job['selection'])!==$target) { throw new RuntimeException('Destination path changed after preview: '.$relative); }
                CSM_Progress::update(['phase' => isset($new[$relative]) ? 'Replacing file' : 'Removing obsolete file', 'component' => $relative, 'count' => $completed, 'total' => count($paths), 'unit' => 'files']);
                if (isset($new[$relative])) { if (!self::install_file_step($new[$relative]['stage'],$target,$new[$relative]['mtime'],$new[$relative]['mode'],$work['install'],static function($state) use ($cursor_path,&$work) { CSM_Support::atomic_json($cursor_path,['cursor'=>$work['cursor'],'install'=>$state]); })) { CSM_Support::atomic_json($cursor_path,['cursor'=>$work['cursor'],'install'=>$work['install']]); CSM_Support::save($job); return self::summary($job)+['continue'=>true]; } unset($work['install']); }
                elseif (is_file($target) && !unlink($target)) { throw new RuntimeException('Could not remove obsolete file.'); }
                CSM_Progress::update(['count' => ++$completed]);
                $work['cursor']=$completed; CSM_Support::atomic_json($cursor_path,['cursor'=>$completed,'install'=>null]);
                do_action('csm_checkpoint',$job['id'],'apply',$completed);
                if (CSM_Support::due()) { CSM_Support::save($job); return self::summary($job)+['continue'=>true]; }
            }
            CSM_Support::check_cancel();
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
            if ($job['health']['status']===0) { throw new RuntimeException('Destination health check could not reach the site.'); }
            if ($job['health']['status'] >= 500 && $job['health']['status'] <= 599) { throw new RuntimeException('Destination health check returned HTTP ' . $job['health']['status']); }
            $job['db_applied'] = true; $job['status'] = 'applied'; $job['applied'] = time(); CSM_Support::save($job);
            if (function_exists('opcache_reset')) { opcache_reset(); }
            self::end_maintenance($job['id']);
            wp_clear_scheduled_hook('trickle_recover', [$job['id']]);
            unset($job['apply_work'],$job['file_plan']); CSM_Support::save($job);
            CSM_Progress::finish('Transfer complete');
            $result = array_merge(self::summary($job), self::retain_controller($actor, $job));
            CSM_Backups::retain($job);
            return $result;
        } catch (Throwable $e) {
            CSM_Progress::fail($e->getMessage());
            $job['error'] = $e->getMessage(); CSM_Support::save($job);
            try { $recovered=self::rollback($job); if (!empty($recovered['continue'])) { return $recovered; } } catch (Throwable $rollback_error) { throw new RuntimeException($e->getMessage() . ' Rollback needs attention: ' . $rollback_error->getMessage()); }
            throw new RuntimeException('Apply failed; changes rolled back. ' . $e->getMessage());
        }
    }

    private static function end_maintenance(string $id): void {
        if ((get_option('_csm_maintenance', [])['id'] ?? '') === $id) { delete_option('_csm_maintenance'); }
        delete_option('_csm_recovery_attempts_'.$id); delete_option('_csm_recovery_blocked_'.$id);
    }

    private static function copy_step(string $source, string $target, ?array &$state, bool $sealed = false): bool {
        clearstatcache(true,$source); $stat=@stat($source);
        if (!$stat || !is_file($source)) { throw new RuntimeException('Copy source is missing: '.basename($source)); }
        $stamp=[$stat['size'],$stat['mtime'],$stat['ctime'],$stat['ino']];
        $state ??= ['offset'=>0,'stamp'=>$stamp];
        if ($stamp!==$state['stamp']) { throw new RuntimeException('File changed during copy: '.basename($source)); }
        if ($state['offset']>0 && (!is_file($target) || filesize($target)<$state['offset'])) { throw new RuntimeException('Copy checkpoint is missing or truncated.'); }
        if ($sealed && is_file($target)) { chmod($target,0600); }
        $in=fopen($source,'rb'); $out=fopen($target,'c+b');
        if ($sealed && is_file($target)) { chmod($target,0000); }
        if (!$in || !$out || !ftruncate($out,$state['offset']) || fseek($in,$state['offset'])!==0 || fseek($out,$state['offset'])!==0) { throw new RuntimeException('Cannot resume file copy.'); }
        try {
            while ($state['offset']<$stat['size']) {
                CSM_Support::check_cancel();
                $bytes=fread($in,min(262144,$stat['size']-$state['offset']));
                if ($bytes===false || $bytes==='') { throw new RuntimeException('File copy was truncated.'); }
                CSM_Support::write($out,$bytes); $state['offset']+=strlen($bytes);
                if (CSM_Support::due()) { break; }
            }
            fflush($out); return $state['offset']===$stat['size'];
        } finally { fclose($in); fclose($out); }
    }

    private static function install_file_step(string $source,string $target,int $mtime,int $mode,?array &$state,?callable $checkpoint = null): bool {
        if (!is_dir(dirname($target)) && !wp_mkdir_p(dirname($target))) { throw new RuntimeException('Cannot create destination directory.'); }
        if (!$state) {
            $temp=tempnam(dirname($target),'.csm-');
            if (!$temp) { throw new RuntimeException('Cannot create replacement file.'); }
            chmod($temp,0000); $state=['temp'=>$temp,'copy'=>null];
            if ($checkpoint) { $checkpoint($state); }
        }
        if (!is_file($state['temp'])) { $state=null; return self::install_file_step($source,$target,$mtime,$mode,$state,$checkpoint); }
        if (!self::copy_step($source,$state['temp'],$state['copy'],true)) { return false; }
        touch($state['temp'],$mtime);
        if (!rename($state['temp'],$target)) { throw new RuntimeException('Could not replace destination file.'); }
        if (!chmod($target,$mode)) { throw new RuntimeException('Could not set destination file permissions.'); }
        if (function_exists('opcache_invalidate')) { opcache_invalidate($target,true); }
        return true;
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
        CSM_Support::work($job['id'],false);
        CSM_Progress::update(['phase' => 'Restoring destination backup', 'component' => '', 'count' => 0, 'total' => null, 'unit' => '']);
        $actor = current_user_can('manage_options') ? wp_get_current_user() : null;
        if (!in_array($job['status'], ['applied', 'applying','restoring'], true)) { throw new RuntimeException('Only applied or interrupted transfers can be restored.'); }
        $active = get_option('_csm_active_job');
        if ($active && $active !== $job['id']) { throw new RuntimeException('Finish the active transfer before restoring another backup.'); }
        $job['status']='restoring'; CSM_Support::save($job);
        CSM_Support::work($job['id'],false); CSM_Support::step();
        if (empty(get_option('_csm_maintenance'))) { update_option('_csm_maintenance',['id'=>$job['id'],'until'=>time()+900],false); }
        wp_clear_scheduled_hook('trickle_recover',[$job['id']]); wp_schedule_single_event(time()+60,'trickle_recover',[$job['id']]);
        $apply_cursor=CSM_Support::private_dir().'/'.$job['id'].'-apply.json';
        if (is_file($apply_cursor)) { $cursor=json_decode(file_get_contents($apply_cursor),true); if (isset($job['apply_work'])) { $job['apply_work']=array_replace($job['apply_work'],$cursor); } }
        if (!empty($job['apply_work']['install']['temp'])) { @unlink($job['apply_work']['install']['temp']); unset($job['apply_work']['install']); }
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
        $restore_path=CSM_Support::private_dir().'/'.$job['id'].'-restore.json';
        if (is_file($restore_path)) { $cursor=json_decode(file_get_contents($restore_path),true); $job['restore_cursor']=$cursor['cursor']; $job['restore_install']=$cursor['install'] ?? null; }
        $restored=$job['restore_cursor'] ?? 0; $index=0;
        foreach (array_reverse($ops) as $op) {
            if ($index++<$restored) { continue; }
            CSM_Progress::update(['phase' => 'Restoring file', 'component' => $op['path']]);
            if (CSM_Files::excluded($op['path'])) { continue; }
            $target = CSM_Files::destination($op['path'], $job['selection']);
            if ($op['existed']) { if (!is_file($op['backup']) || (empty($job['restore_install']['copy']['stamp']) && isset($op['sha256']) && !hash_equals($op['sha256'],CSM_Support::hash_file($op['backup'])))) { throw new RuntimeException('Backup file is missing or changed: '.$op['path']); } if (!self::install_file_step($op['backup'],$target,$op['mtime'],$op['mode'],$job['restore_install'],static function($state) use ($restore_path,&$job) { CSM_Support::atomic_json($restore_path,['cursor'=>$job['restore_cursor'] ?? 0,'install'=>$state]); })) { CSM_Support::atomic_json($restore_path,['cursor'=>$job['restore_cursor'] ?? 0,'install'=>$job['restore_install']]); CSM_Support::save($job); return self::summary($job)+['continue'=>true]; } unset($job['restore_install']); }
            elseif (is_file($target) && !unlink($target)) { throw new RuntimeException('Could not remove restored file.'); }
            $job['restore_cursor']=$index; CSM_Support::atomic_json($restore_path,['cursor'=>$index,'install'=>null]);
            if (CSM_Support::due()) { CSM_Support::save($job); return self::summary($job)+['continue'=>true]; }
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
        if (in_array($job['status'], ['applying', 'backup_copy', 'restoring'], true)) { throw new RuntimeException('Recovery is in progress. Its backup cannot be discarded.'); }
        CSM_Segments::cleanup($job['id']);
        foreach ($job['database'] as $table) {
            foreach (['stage', 'backup'] as $kind) { CSM_Support::query('DROP TABLE IF EXISTS ' . CSM_Support::ident($table[$kind])); }
        }
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id']);
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id'] . '-backup');
        if (isset($job['backup_dir'])) { CSM_Support::remove_tree($job['backup_dir']); }
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id'] . '-incoming');
        CSM_Support::remove_tree(CSM_Support::private_dir() . '/' . $job['id'] . '-preview.json');
        foreach (['-apply.json','-restore.json','-retry.json','-heartbeat'] as $suffix) { CSM_Support::remove_tree(CSM_Support::private_dir().'/'.$job['id'].$suffix); }
        CSM_Progress::remove($job['id']);
        CSM_Support::remove_tree(CSM_Support::private_dir().'/job-records/'.$job['id']);
        delete_option('_csm_cursor_'.$job['id']);
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
        } catch (Throwable $e) { CSM_Log::write('error', 'Dashboard request failed', ['operation' => $action ?? 'unknown', 'error' => $e->getMessage()]); self::respond($e instanceof CSM_Network_Error ? ['retryable'=>true,'busy'=>$e instanceof CSM_Busy_Error,'retry_exhausted'=>$e->exhausted, 'retry_after'=>$e->retry_after] : [], $e->getMessage(), 400); }
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
        wp_enqueue_style('trickle-workspace', plugins_url('assets/workspace.css', CSM_FILE), ['trickle'], CSM_VERSION . '.' . filemtime(dirname(CSM_FILE) . '/assets/workspace.css'));
        $script = ($_GET['view'] ?? '') === 'backups' ? 'backups' : 'admin';
        if ($script === 'admin') {
            wp_enqueue_script('trickle-chart-library', plugins_url('assets/vendor/chartjs/chart.umd.js', CSM_FILE), [], '4.6.1', true);
            wp_add_inline_script('trickle-chart-library', 'window._csmPreviousChart = window.Chart;', 'before');
            wp_add_inline_script('trickle-chart-library', 'window.CSMChart = window.Chart; window.Chart = window._csmPreviousChart; delete window._csmPreviousChart;', 'after');
            wp_enqueue_script('trickle-progress-chart', plugins_url('assets/progress-chart.js', CSM_FILE), ['trickle-chart-library'], CSM_VERSION . '.' . filemtime(dirname(CSM_FILE) . '/assets/progress-chart.js'), true);
            wp_enqueue_script('trickle-progress', plugins_url('assets/transfer-progress.js', CSM_FILE), ['trickle-progress-chart'], CSM_VERSION . '.' . filemtime(dirname(CSM_FILE) . '/assets/transfer-progress.js'), true);
        }
        wp_enqueue_script('trickle', plugins_url('assets/' . $script . '.js', CSM_FILE), $script === 'admin' ? ['trickle-progress'] : [], CSM_VERSION . '.' . filemtime(dirname(CSM_FILE) . '/assets/' . $script . '.js'), true);
        if ($script === 'admin') { wp_enqueue_script('trickle-tabs', plugins_url('assets/admin-tabs.js', CSM_FILE), ['trickle'], CSM_VERSION . '.' . filemtime(dirname(CSM_FILE) . '/assets/admin-tabs.js'), true); }
        if ($script === 'admin') { wp_enqueue_script('trickle-sticky', plugins_url('assets/sticky-actions.js', CSM_FILE), ['trickle-tabs'], CSM_VERSION . '.' . filemtime(dirname(CSM_FILE) . '/assets/sticky-actions.js'), true); }
        wp_localize_script('trickle', 'CSM', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('csm_admin'), 'state' => CSM_Controller::state(), 'keys' => CSM_Access::list()]);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        $loader_error = '';
        if (!self::loader_ready() && wp_is_file_mod_allowed('trickle')) {
            try { self::install_loader(); }
            catch (Throwable $e) { $loader_error = $e->getMessage(); CSM_Log::write('error', 'Recovery loader update failed', ['error' => $loader_error]); }
        }
        $transfer_defaults = get_option('_csm_transfer_defaults', []) + ['transport' => 'stream', 'file_verify' => false];
        $settings = CSM_Access::settings();
        require __DIR__ . (($_GET['view'] ?? '') === 'backups' ? '/backup-page.php' : '/admin.php');
    }
}
