<?php
defined('ABSPATH') || exit;

final class CSM_Controller {
    public static function fingerprint(): string {
        return hash_hmac('sha256', ABSPATH . "\n" . (string)get_option('siteurl'), wp_salt('auth'));
    }

    private static function url_identity(string $url): string {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) { return ''; }
        $host = strtolower(rtrim($parts['host'], '.'));
        $port = $parts['port'] ?? null;
        if ($port === (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80)) { $port = null; }
        return $host . ($port ? ':' . $port : '') . rtrim(rawurldecode($parts['path'] ?? ''), '/');
    }

    public static function is_self(string $url): bool {
        $identity = self::url_identity($url);
        if ($identity === '') { return false; }
        foreach ([get_option('siteurl'), get_option('home'), site_url(), home_url()] as $local) {
            if ($identity === self::url_identity((string)$local)) { return true; }
        }
        return false;
    }

    public static function assert_remote(array $peer, array $manifest = []): void {
        $fingerprint = $manifest['instance_fingerprint'] ?? $peer['instance_fingerprint'] ?? '';
        if (self::is_self($peer['url']) || self::is_self($manifest['url'] ?? '') || self::is_self($manifest['canonical_url'] ?? '') || ($fingerprint !== '' && hash_equals(self::fingerprint(), $fingerprint))) {
            throw new RuntimeException('Choose a different site. This connection points back to the current WordPress installation.');
        }
    }

    public static function site_id(string $url): string {
        return substr(hash('sha256', CSM_Transport::url($url)), 0, 16);
    }

    public static function peers(): array {
        $peers = get_option('_csm_peers', []);
        foreach ([get_option('_csm_peer'), get_option('_csm_control')['peer'] ?? null] as $peer) {
            if (!$peer) { continue; }
            $id = self::site_id($peer['url']);
            if (!isset($peers[$id])) {
                $peers[$id] = $peer + ['id' => $id, 'name' => $peer['url'], 'icon' => ''];
                update_option('_csm_peers', $peers, false);
            }
        }
        return array_filter($peers, static fn($peer) => !self::is_self($peer['url']) && (empty($peer['instance_fingerprint']) || !hash_equals(self::fingerprint(), $peer['instance_fingerprint'])));
    }

    public static function peer(string $site = ''): array {
        $peers = self::peers();
        if ($site === '') {
            $selected = get_option('_csm_peer');
            if ($selected && isset($peers[self::site_id($selected['url'])])) { $site = self::site_id($selected['url']); }
            elseif (count($peers) === 1) { return reset($peers); }
        }
        foreach ($peers as $id => $peer) {
            if ($site === $id || $site === $peer['url']) { return $peer; }
        }
        $matches = array_filter($peers, static fn($peer) => strcasecmp($peer['name'] ?? '', $site) === 0);
        if (count($matches) === 1) { return reset($matches); }
        if (count($matches) > 1) { throw new RuntimeException('Several remote sites share that name; use the site ID or URL.'); }
        throw new RuntimeException('Choose a saved remote site, or add its connection code.');
    }

    public static function session(): ?array {
        $control = get_option('_csm_control');
        if (!$control) { return null; }
        return ['automatic' => !empty($control['automatic']), 'plugin_version' => $control['plugin_version'] ?? '', 'id' => $control['id'], 'remote_id' => self::site_id($control['peer']['url']), 'direction' => $control['direction'], 'selection' => $control['selection'],
            'view_url' => $control['direction'] === 'pull' ? home_url() : ($control['destination_home'] ?? $control['peer']['url']),
            'source' => $control['direction'] === 'pull' ? $control['peer']['url'] : site_url(), 'destination' => $control['direction'] === 'pull' ? site_url() : $control['peer']['url']];
    }

    public static function state(): array {
        $peers = self::peers();
        $session = self::session();
        $selected = get_option('_csm_peer');
        $id = $session['remote_id'] ?? ($selected ? self::site_id($selected['url']) : (count($peers) === 1 ? array_key_first($peers) : ''));
        if (!$session && !isset($peers[$id])) { $id = count($peers) === 1 ? array_key_first($peers) : ''; }
        $active = get_option('_csm_active_job');
        $recovering = $active && in_array(get_option('_csm_job_' . $active, [])['status'] ?? '', ['applying', 'backup_copy'], true);
        return ['destination_recovery' => $recovering ? $active : null, 'connections' => array_values(array_map(static fn($peer) => array_intersect_key($peer, array_flip(['id', 'url', 'name', 'icon'])), $peers)),
            'selected' => $id, 'recents' => array_values(array_filter(get_option('_csm_recents', []), static fn($recent) => !self::is_self($recent['remote_url'] ?? ''))), 'session' => $session];
    }

    public static function recent(string $id): array {
        $recent = get_option('_csm_recents', [])[$id] ?? null;
        if (!$recent) { throw new RuntimeException('Recent transfer not found.'); }
        $peer = self::peer($recent['remote_id']);
        if ($peer['url'] !== $recent['remote_url']) { throw new RuntimeException('The saved remote site no longer matches this transfer.'); }
        return $recent;
    }

    public static function inventory(array $peer, bool $refresh = false): array {
        self::assert_remote($peer);
        $remote = CSM_Transport::request($peer, 'manifest', ['refresh' => $refresh]);
        self::assert_remote($peer, $remote);
        $local = CSM_Plugin::manifest($refresh);
        $replacements = isset($remote['root'], $remote['content']) && ($remote['replacement_rules'] ?? 0) === 1
            ? ['pull' => CSM_Database::replacement_defaults($remote, $local), 'push' => CSM_Database::replacement_defaults($local, $remote)] : null;
        return ['remote' => $remote, 'local' => $local, 'replacements' => $replacements, 'history' => get_option('_csm_history', []), 'state' => self::state()];
    }

    private static function remember(array $control, array $summary): void {
        $recent = get_option('_csm_recents', []);
        $peer = $control['peer'];
        $entry = ['id' => $control['id'], 'remote_id' => self::site_id($peer['url']), 'remote_url' => $peer['url'], 'remote_name' => $peer['name'] ?? $peer['url'],
            'direction' => $control['direction'], 'selection' => $control['selection'], 'started_at' => $control['created'],
            'completed_at' => $summary['applied'] ?? ($recent[$control['id']]['completed_at'] ?? null),
            'status' => $summary['status'] === 'staging' && $summary['ready'] ? 'staged' : $summary['status']];
        unset($entry['selection']['git_overrides'], $entry['selection']['skip_paths']);
        unset($recent[$control['id']]);
        $recent = [$control['id'] => $entry] + $recent;
        uasort($recent, static fn($a, $b) => $b['started_at'] <=> $a['started_at']);
        update_option('_csm_recents', array_slice($recent, 0, 20, true), false);
    }

    private static function review(array $peer, bool $pull, array $selection, array $confirmed = []): array {
        $remote = CSM_Transport::request($peer, 'file_policy', ['selection' => $selection]);
        $local = CSM_Files::git_folders($selection);
        $folders = $pull ? array_values(array_unique(array_merge($local, $remote['git_folders']))) : $remote['git_folders'];
        foreach ($confirmed as $folder) { if (!is_string($folder) || !in_array($folder, $folders, true)) { throw new RuntimeException('Git confirmation no longer matches the selected folders. Review the transfer again.'); } }
        return ['git_folders' => array_map(static fn($folder) => ['path' => $folder, 'source' => $pull && in_array($folder, $remote['git_folders'], true), 'destination' => in_array($folder, $pull ? $local : $remote['git_folders'], true)], $folders),
            'skip_paths' => array_values(array_diff($folders, $confirmed)), 'git_overrides' => array_values($confirmed), 'protect_source_git' => $pull];
    }

    private static function finish(array $control): array {
        $id = $control['id']; $pull = $control['direction'] === 'pull';
        $summary = $pull ? CSM_Plugin::summary(CSM_Support::job($id)) : CSM_Transport::request($control['peer'], 'status', ['id' => $id]);
        if (!in_array($summary['status'], ['applied', 'restored'], true)) { throw new RuntimeException('Finish or cancel the active transfer before starting another.'); }
        if ($pull && $summary['status'] === 'applied' && !in_array($id, get_option('_csm_backups', []), true)) { CSM_Backups::retain(CSM_Support::job($id)); }
        try {
            if (!empty($control['segmented'])) {
                if ($pull) { CSM_Transport::request($control['peer'], 'bundle_discard', ['id' => $id]); }
                else { CSM_Segments::cleanup($id); }
            }
        } catch (Throwable $e) { $summary['cleanup_warning'] = 'Transfer complete; source stream cleanup needs attention. ' . $e->getMessage(); CSM_Log::write('error', 'Source stream cleanup failed', ['transfer_id' => $id, 'error' => $e->getMessage()]); }
        if ($summary['status'] === 'restored') {
            if ($pull) { CSM_Plugin::discard(CSM_Support::job($id)); }
            else { CSM_Transport::request($control['peer'], 'discard', ['id' => $id]); }
        }
        self::remember($control, $summary);
        delete_option('_csm_control');
        return $summary + ['state' => self::state()];
    }

    public static function execute(string $action, array $data = []): array {
        $operation = static fn()=>self::attempt($action, $data);
        return in_array($action, ['preflight','transfer'], true) ? CSM_Transport::retry($operation) : $operation();
    }

    private static function attempt(string $action, array $data): array {
        CSM_Plugin::supported(); CSM_Support::long_request();
        if (in_array($action, ['commit', 'rollback', 'recover', 'recover_destination', 'restore_backup'], true)) { ignore_user_abort(true); }
        $locked = !in_array($action, ['state', 'recent', 'manifest', 'media', 'progress', 'backup_progress', 'backups', 'status', 'logs'], true);
        if ($locked) { CSM_Support::lock(); }
        $record = !in_array($action, ['state', 'recent', 'media', 'progress', 'status', 'logs'], true);
        $context = ['operation' => $action, 'transfer_id' => $data['id'] ?? null, 'direction' => $data['direction'] ?? null];
        if ($record) { CSM_Log::write('info', 'Dashboard/CLI operation started', $context); }
        try {
            $result = self::operate($action, $data);
            if ($record) { CSM_Log::write('info', 'Dashboard/CLI operation completed', $context); }
            return $result;
        }
        catch (Throwable $e) {
            if ($action !== 'logs') { CSM_Log::write('error', 'Dashboard/CLI operation failed', $context + ['error' => $e->getMessage()]); }
            if (in_array($action, ['preflight', 'transfer', 'commit', 'rollback'], true)) {
                CSM_Progress::fail($e->getMessage());
                $control = get_option('_csm_control');
                $progress = $control ? CSM_Progress::read($control['id']) : [];
                $failure = $progress['failure'] ?? $progress;
                $context = trim(($failure['phase'] ?? '') . ': ' . ($failure['component'] ?? ''), ': ');
                if ($e instanceof CSM_Network_Error) { throw $e; }
                throw new RuntimeException($e->getMessage() . ($context ? ' Last activity: ' . $context . '.' : ''), (int)$e->getCode(), $e);
            }
            throw $e;
        }
        finally { if ($locked) { CSM_Support::unlock(); } }
    }

    private static function timeline(array $control): array {
        $streams = []; $components = ['database' => 0, 'plugins' => 0, 'themes' => 0, 'media' => 0];
        foreach (CSM_Plugin::kinds($control['selection']) as $kind) {
            $bundle = $control['bundles'][$kind] ?? null;
            $received = $control['checkpoints'][$kind]['offset'] ?? 0;
            if ($control['direction'] === 'pull' && ($control['selection']['transport'] ?? '') === 'stream') { $received = max($received, CSM_Segments::received_bytes($control['id'], $kind)); }
            $streams[$kind] = ['size' => $bundle['size'] ?? null, 'received' => $received];
            foreach ($bundle['components'] ?? [] as $component => $size) { if (isset($components[$component])) { $components[$component] += $size; } }
        }
        return ['id' => $control['id'], 'started' => $control['created'], 'elapsed' => max(0, microtime(true) - $control['created']), 'components' => $components, 'streams' => $streams,
            'selected' => array_keys(array_filter(['database' => !empty($control['selection']['tables']), 'plugins' => !empty($control['selection']['plugins']), 'themes' => !empty($control['selection']['themes']), 'media' => ($control['selection']['media'] ?? 'none') !== 'none'])),
            'ready' => !in_array(null, array_column($streams, 'size'), true)];
    }

    private static function progress(array $control): array {
        return self::activity($control) + ['timeline' => self::timeline($control)];
    }

    private static function activity(array $control): array {
        $local = CSM_Progress::read($control['id']);
        try { self::assert_remote($control['peer']); }
        catch (Throwable $e) { return $local + ['poll_error' => $e->getMessage()]; }
        // Once all source snapshots exist, a pull's receiving/apply work runs entirely on this site.
        if ($control['direction'] === 'pull' && !array_diff(CSM_Plugin::kinds($control['selection']), array_keys($control['bundles'] ?? []))) { return $local; }
        try { $remote = CSM_Transport::request($control['peer'], 'progress', ['id' => $control['id']], null, null, 5); }
        catch (Throwable $e) { return $local + ['poll_error' => 'Remote progress is unavailable; the transfer may still be running.']; }
        // Match operations, rather than comparing timestamps from two different server clocks.
        if (($remote['run'] ?? '') !== ($local['run'] ?? '')) { return $local; }
        if (!empty($remote['failure'])) { return $remote; }
        if (!empty($local['failure']['component']) || ($local['state'] ?? '') === 'complete') { return $local; }
        return !empty($remote['component']) ? $remote : $local;
    }

    private static function segment_step(array $control, string $kind, bool $preflight = false): array {
        if (!in_array($kind, CSM_Plugin::kinds($control['selection']), true)) { throw new RuntimeException('Invalid stream kind.'); }
        $peer = $control['peer']; $pull = $control['direction'] === 'pull'; $id = $control['id'];
        CSM_Progress::start($id, (empty($control['bundles'][$kind]) ? 'Preparing ' : 'Transferring ') . $kind . ' stream');
        if (empty($control['bundles'][$kind])) {
            $selection = $control['exports'][$kind] ?? null;
            if (!$selection) {
                $selection = $control['selection']; unset($selection['replacements']);
                if ($kind === 'files' && CSM_Files::compare_media($selection)) {
                    $comparison = CSM_Media::compare($peer, $pull, !empty($selection['media_verify']), $selection);
                    $selection['media'] = 'compare';
                    $selection['media_delta'] = $comparison['delta']; $control['media_comparison'] = $comparison['stats'];
                }
                if ($kind === 'files' && (!empty($selection['plugins']) || !empty($selection['themes']))) {
                    CSM_Progress::update(['phase'=>'Comparing plugin and theme checksums', 'component'=>'Plugin/theme inventory'], true);
                    $comparison = CSM_Components::compare($peer, $pull, $selection); $selection = CSM_Components::selection($selection, $comparison);
                }
                $control['exports'][$kind] = $selection; update_option('_csm_control', $control, false);
            }
            $control['bundles'][$kind] = $pull ? CSM_Transport::request($peer, 'bundle', ['id' => $id, 'kind' => $kind, 'selection' => $selection, 'progress_run' => CSM_Progress::run()]) : CSM_Segments::bundle($id, $kind, $selection);
            update_option('_csm_control', $control, false);
        }
        if ($preflight) {
            $summary = $pull ? CSM_Plugin::summary(CSM_Support::job($id)) : CSM_Transport::request($peer, 'status', ['id' => $id]);
            return $summary + ['timeline' => self::timeline($control)];
        }
        $bundle = $control['bundles'][$kind];
        $state = $pull ? CSM_Segments::state(CSM_Support::job($id), $kind, $bundle) : CSM_Transport::request($peer, 'segment_state', ['id' => $id, 'kind' => $kind, 'bundle' => $bundle]);
        CSM_Progress::update(['phase' => 'Transferring ' . $kind . ' stream', 'component' => $kind, 'count' => $state['offset'], 'total' => $bundle['size'], 'unit' => 'bytes', 'bytes' => $state['offset']], true);
        if (($control['selection']['transport'] ?? 'chunks') === 'stream') {
            $summary = $pull ? CSM_Segments::pull($peer, CSM_Support::job($id), $kind, $bundle)
                : CSM_Transport::request($peer, 'stream_data', ['id'=>$id, 'kind'=>$kind, 'bundle'=>$bundle, 'offset'=>$state['offset'], 'progress_run'=>CSM_Progress::run()], CSM_Segments::source($id, $kind, $state['offset'], $bundle));
        } else {
            $bytes = '';
            if ($state['offset'] < $bundle['size']) {
                if ($pull) {
                    $fp = fopen('php://temp', 'w+b');
                    try { CSM_Transport::request($peer, 'bundle_chunk', ['id' => $id, 'kind' => $kind, 'offset' => $state['offset']], null, $fp, 45); rewind($fp); $bytes = stream_get_contents($fp, CSM_Segments::SIZE + 1); }
                    finally { fclose($fp); }
                } else { $bytes = CSM_Segments::chunk($id, $kind, $state['offset']); }
                if ($bytes === '' || strlen($bytes) > CSM_Segments::SIZE) { throw new RuntimeException('Source returned an invalid stream segment.'); }
            }
            $summary = $pull ? CSM_Segments::receive(CSM_Support::job($id), $kind, $bundle, $state['offset'], $bytes, hash('sha256', $bytes))
                : CSM_Transport::request($peer, 'segment_data', ['id' => $id, 'kind' => $kind, 'bundle' => $bundle, 'offset' => $state['offset'], 'sha256' => hash('sha256', $bytes)], (static function () use ($bytes) { yield $bytes; })());
        }
        $control['checkpoints'][$kind] = $summary['checkpoint']; update_option('_csm_control', $control, false);
        // Keep checkpoint replies small; recent settings and full UI state change only when staging finishes.
        $state = [];
        if (empty($summary['continue'])) { self::remember($control, $summary); $state = ['state' => self::state()]; }
        return $summary + ['timeline' => self::timeline($control), 'health' => $control['health'] ?? null, 'media_comparison' => $control['media_comparison'] ?? null] + $state;
    }

    private static function operate(string $action, array $data): array {
        if ($action === 'recover_destination') {
            $id = get_option('_csm_active_job');
            if (!$id) { return ['status' => 'idle']; }
            $job = CSM_Support::job($id);
            if (!in_array($job['status'], ['applying', 'backup_copy'], true)) { return CSM_Plugin::summary($job); }
            CSM_Progress::start($id, 'Recovering interrupted transfer');
            $restore = get_option('_csm_backup_restore');
            return ($restore['job_id'] ?? '') === $id ? CSM_Backups::restore($restore['backup_id']) : CSM_Plugin::rollback($job);
        }
        if (in_array($action, ['prepare', 'select', 'update_remote', 'restore_backup'], true) && ($previous = get_option('_csm_control'))) { self::finish($previous); }
        if (in_array($action, ['backups', 'restore_backup', 'backup_progress'], true)) {
            $site = $data['site'] ?? '';
            if ($action === 'backups') { return $site === '' ? CSM_Backups::listing() : CSM_Transport::request(self::peer($site), 'backups'); }
            if ($action === 'backup_progress') { return $site === '' ? CSM_Progress::read($data['id'] ?? '') : CSM_Transport::request(self::peer($site), 'progress', ['id' => $data['id'] ?? '']); }
            CSM_Progress::start($data['id'] ?? '', 'Restoring saved backup');
            return $site === '' ? CSM_Backups::restore($data['id'] ?? '') : CSM_Transport::request(self::peer($site), 'restore_backup', ['id' => $data['id'] ?? '', 'progress_run' => CSM_Progress::run()]);
        }
        if ($action === 'logs') { return ['entries' => CSM_Log::entries()]; }
        if ($action === 'state') { return self::state(); }
        if ($action === 'recent') { return self::recent($data['id'] ?? ''); }
        if ($action === 'keys') { return ['keys' => CSM_Access::list()]; }
        if ($action === 'issue_key') { return CSM_Access::issue($data['name'] ?? '', !empty($data['read']), !empty($data['write']), (int)($data['days'] ?? 30)); }
        if ($action === 'revoke_key') { CSM_Access::revoke($data['id'] ?? ''); return ['keys' => CSM_Access::list()]; }
        if ($action === 'settings') {
            update_option('_csm_settings', ['pull' => !empty($data['pull']), 'push' => !empty($data['push']), 'updates' => !empty($data['updates'])], false);
            if (!empty($data['rotate'])) { update_option('_csm_key', bin2hex(random_bytes(32)), false); delete_option('_csm_tokens'); }
            return ['pairing' => CSM_Plugin::pairing()];
        }
        if ($action === 'connect') {
            $peer = CSM_Plugin::parse_pairing($data['pairing'] ?? '');
            self::assert_remote($peer);
            $remote = CSM_Transport::request($peer, 'manifest');
            self::assert_remote($peer, $remote);
            $id = self::site_id($peer['url']); $peers = self::peers();
            $name = sanitize_text_field($data['name'] ?? '');
            $peer += ['id' => $id, 'name' => $name ?: ($peers[$id]['name'] ?? (($remote['name'] ?? '') ?: $peer['url'])), 'icon' => esc_url_raw($remote['icon'] ?? '', ['http', 'https']), 'instance_fingerprint' => $remote['instance_fingerprint'] ?? ''];
            $peers[$id] = $peer; update_option('_csm_peers', $peers, false);
            $control = get_option('_csm_control');
            if (!$control || $control['peer']['url'] === $peer['url']) {
                update_option('_csm_peer', $peer, false);
                if ($control) { $control['peer'] = $peer; update_option('_csm_control', $control, false); }
            }
            return ['saved' => $id, 'state' => self::state()];
        }
        if ($action === 'select') {
            if (get_option('_csm_control')) { throw new RuntimeException('Finish and discard the retained transfer before changing remote sites. Its recovery controls stay attached to its original site.'); }
            $peer = self::peer($data['site'] ?? '');
            update_option('_csm_peer', $peer, false);
            return self::state();
        }
        if ($action === 'manifest') {
            $session = self::session();
            $peer = self::peer($session['remote_id'] ?? ($data['site'] ?? ''));
            return self::inventory($peer, !empty($data['refresh']));
        }
        if (in_array($action, ['remote_update_info', 'update_remote'], true)) {
            $peer = self::peer($data['site'] ?? ''); self::assert_remote($peer);
            try { $remote = CSM_Transport::request($peer, 'version'); }
            catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'Unknown action') && !str_contains($e->getMessage(), 'HTTP 404')) { throw $e; }
                $remote = CSM_Transport::request($peer, 'manifest');
            }
            self::assert_remote($peer, $remote);
            if (($remote['remote_update'] ?? 0) !== 1) { throw new RuntimeException('This remote Trickle version does not support remote updates. Install the current Trickle ZIP there once to enable this feature.'); }
            if ($action === 'remote_update_info') {
                $info = CSM_Transport::request($peer, 'update_info');
                $eligible = !empty($info['ready']) && !empty($info['can_update_trickle']) && !empty($info['file_modifications']) && $info['release'] === CSM_VERSION && version_compare($info['installed'], CSM_VERSION, '<');
                if (empty($info['ready'])) { $reason = $info['blocked_reason']; }
                elseif ($info['release'] !== CSM_VERSION) { $reason = 'The JSON feed publishes ' . $info['release'] . '; this site requires ' . CSM_VERSION . '. Publish the matching release or update both sites manually.'; }
                elseif (version_compare($info['installed'], CSM_VERSION, '>')) { $reason = 'The remote is newer. Update this site first; remote downgrades are not allowed.'; }
                elseif (empty($info['file_modifications'])) { $reason = 'WordPress disallows file modifications on the remote site.'; }
                elseif (empty($info['can_update_trickle'])) { $reason = 'Enable authenticated Trickle updates and use an administrator or read/write key.'; }
                else { $reason = 'The matching published release is available. Updating Trickle does not start a migration.'; }
                return $info + ['target' => CSM_VERSION, 'can_update_to_target' => $eligible, 'reason' => $reason];
            }
            if (get_option('_csm_control')) { throw new RuntimeException('Finish and discard the retained transfer before updating the remote plugin.'); }
            if (($remote['plugin_version'] ?? '') === CSM_VERSION) { return ['installed' => CSM_VERSION, 'updated' => false]; }
            if (version_compare($remote['plugin_version'], CSM_VERSION, '>')) { throw new RuntimeException('The remote Trickle is newer. Update this site first; remote downgrades are not allowed.'); }
            CSM_Transport::request($peer, 'update_trickle', ['target' => CSM_VERSION, 'from' => $remote['plugin_version']]);
            $version = CSM_Transport::request($peer, 'version'); self::assert_remote($peer, $version); CSM_Updates::assert_version($version['plugin_version'] ?? null);
            return ['installed' => $version['plugin_version'], 'updated' => true];
        }
        if ($action === 'media') {
            $session = self::session(); $direction = $session['direction'] ?? ($data['direction'] ?? '');
            if (!in_array($direction, ['push', 'pull'], true)) { throw new RuntimeException('Choose push or pull.'); }
            $query = ['page' => (int)($data['page'] ?? 1), 'ids' => $data['ids'] ?? []];
            return $direction === 'push' ? CSM_Files::media($query['page'], $query['ids']) : CSM_Transport::request(self::peer($session['remote_id'] ?? ($data['site'] ?? '')), 'media', $query);
        }
        if ($action === 'review') {
            $peer = self::peer($data['site'] ?? ''); self::assert_remote($peer);
            if (!in_array($data['direction'] ?? '', ['push', 'pull'], true)) { throw new RuntimeException('Choose push or pull.'); }
            return self::review($peer, $data['direction'] === 'pull', $data['selection'] ?? [], $data['git_confirmed'] ?? []);
        }
        if ($action === 'prepare') {
            if (get_option('_csm_control')) { throw new RuntimeException('Discard the retained transfer and its backup before starting another.'); }
            $peer = self::peer($data['site'] ?? '');
            $direction = $data['direction'] ?? '';
            if (!in_array($direction, ['pull', 'push'], true)) { throw new RuntimeException('Choose push or pull.'); }
            self::assert_remote($peer);
            $remote = CSM_Transport::request($peer, 'manifest');
            self::assert_remote($peer, $remote); CSM_Updates::assert_version($remote['plugin_version'] ?? null);
            $selection = CSM_Activation::selection(CSM_Files::media_selection(CSM_Database::replacement_selection($data['selection'] ?? [])));
            // Saved comparison selections now use the all-media scope; the wire format stays compatible.
            if ($selection['media'] === 'compare') { $selection['media'] = 'all'; }
            $selection['transport'] = $selection['transport'] ?? 'stream';
            if (!in_array($selection['transport'], ['stream','chunks'], true)) { throw new RuntimeException('Choose streaming or chunked transport.'); }
            if ($selection['transport'] === 'stream' && empty($remote['resumable_stream'])) { throw new RuntimeException('Update Trickle on both sites before using resumable streaming.'); }
            // Never replay an old approval from recent settings or trust client-provided exclusions.
            unset($selection['git_overrides'], $selection['skip_paths'], $selection['protect_source_git']);
            if (CSM_Plugin::kinds($selection) !== ['database']) {
                if (empty($remote['file_policy']) || empty($remote['component_compare'])) { throw new RuntimeException('Update Trickle on both sites before transferring files with mandatory exclusions and Git protection.'); }
                $selection['file_policy'] = 1;
                $selection = array_merge($selection, self::review($peer, $direction === 'pull', $selection, $data['git_confirmed'] ?? []));
                unset($selection['git_folders']);
            }
            if (CSM_Files::compare_media($selection) && empty($remote['media_compare'])) { throw new RuntimeException('Update Trickle on both sites before comparing media checksums.'); }
            if ($direction === 'push' && !empty($selection['tables']) && array_key_exists('replacements', $selection)) {
                if (($remote['replacement_rules'] ?? 0) !== 1) { throw new RuntimeException('Update Trickle on the destination before transferring a database with explicit replacement rules.'); }
            }
            if ($direction === 'push') { $selection = CSM_Files::selection($selection); CSM_Database::validate($selection['tables'] ?? []); }
            $destination_selection = $selection;
            if (empty($selection['tables'])) { unset($destination_selection['replacements']); }
            $context = ['source' => $direction === 'pull' ? $peer['url'] : site_url()];
            $summary = $direction === 'pull' ? CSM_Plugin::prepare($selection, $context) : CSM_Transport::request($peer, 'prepare', ['selection' => $destination_selection, 'context' => $context]);
            $control = ['destination_home' => $remote['home'] ?? $peer['url'], 'automatic' => !empty($data['automatic']), 'plugin_version' => CSM_VERSION, 'segmented' => !empty($remote['segments']), 'direction' => $direction, 'selection' => $selection, 'id' => $summary['id'], 'peer' => $peer, 'created' => time(), 'previous_history' => get_option('_csm_history', [])];
            update_option('_csm_peer', $peer, false); update_option('_csm_control', $control, false);
            self::remember($control, $summary);
            return $summary + ['state' => self::state()];
        }
        $control = get_option('_csm_control');
        if (!$control || !hash_equals($control['id'], $data['id'] ?? '')) { throw new RuntimeException('This transfer is not the current local session.'); }
        $peer = $control['peer']; $pull = $control['direction'] === 'pull'; $id = $control['id'];
        if ($action === 'finish') { return self::finish($control); }
        if ($action === 'recover') {
            $current = $pull ? CSM_Plugin::summary(CSM_Support::job($id)) : CSM_Transport::request($peer, 'status', ['id' => $id]);
            if ($current['status'] === 'applying') { return self::operate('rollback', ['id' => $id]); }
            if ($current['status'] === 'staging' && !empty($data['cancel'])) { self::operate('discard', ['id' => $id]); return ['status' => 'cancelled', 'state' => self::state()]; }
            return $current + ['state' => self::state()];
        }
        if (in_array($action, ['preflight', 'transfer', 'commit'], true)) { self::assert_remote($peer); CSM_Updates::assert_job($control); }
        if ($action === 'commit') { $version = CSM_Transport::request($peer, 'manifest'); self::assert_remote($peer, $version); CSM_Updates::assert_version($version['plugin_version'] ?? null); }
        if ($action === 'preview') { return $pull ? CSM_Plugin::preview_page(CSM_Support::job($id), (int)($data['offset'] ?? 0)) : CSM_Transport::request($peer, 'preview', ['id' => $id, 'offset' => (int)($data['offset'] ?? 0)]); }
        if ($action === 'progress') { return self::progress($control); }
        if ($action === 'preflight') { return self::segment_step($control, $data['kind'] ?? '', true); }
        if ($action === 'transfer' && !empty($control['segmented'])) { return self::segment_step($control, $data['kind'] ?? ''); }
        if ($action === 'transfer') {
            $kind = $data['kind'] ?? '';
            if (!in_array($kind, CSM_Plugin::kinds($control['selection']), true)) { throw new RuntimeException('Invalid stream.'); }
            $export_selection = $control['selection'];
            if ($kind === 'files' && CSM_Files::compare_media($export_selection)) {
                CSM_Progress::start($id, 'Comparing media years, then differing months and files');
                $comparison = CSM_Media::compare($peer, $pull, !empty($export_selection['media_verify']), $export_selection);
                $export_selection['media'] = 'compare';
                $export_selection['media_delta'] = $comparison['delta'];
                $control['media_comparison'] = $comparison['stats'];
                update_option('_csm_control', $control, false);
            }
            if ($kind === 'files' && (!empty($export_selection['plugins']) || !empty($export_selection['themes']))) {
                CSM_Progress::start($id, 'Comparing plugin and theme files');
                $comparison = CSM_Components::compare($peer, $pull, $export_selection);
                $export_selection = CSM_Components::selection($export_selection, $comparison);
            }
            if ($pull) {
                CSM_Progress::start($id, 'Downloading stream');
                $path = CSM_Support::private_dir() . '/' . $id . '-incoming';
                $fp = fopen($path, 'w+b');
                if (!$fp) { throw new RuntimeException('Cannot create incoming stream storage.'); }
                try {
                    unset($export_selection['replacements']);
                    CSM_Transport::request($peer, 'export', ['selection' => $export_selection, 'kind' => $kind, 'progress_id' => $id, 'progress_run' => CSM_Progress::run()], null, $fp);
                    rewind($fp); $summary = CSM_Plugin::summary(CSM_Plugin::receive(CSM_Support::job($id), $kind, $fp));
                } finally { fclose($fp); unlink($path); }
            } else {
                CSM_Progress::start($id, 'Exporting stream');
                $generator = $kind === 'database' ? CSM_Database::stream($control['selection']) : CSM_Files::stream($export_selection);
                $summary = CSM_Transport::request($peer, 'receive', ['id' => $id, 'kind' => $kind, 'progress_run' => CSM_Progress::run()], CSM_Support::gzip($generator));
                CSM_Progress::finish(ucfirst($kind) . ' sent and verified');
            }
        } elseif ($action === 'commit') {
            CSM_Progress::start($id, 'Waiting for destination');
            $summary = $pull ? CSM_Plugin::commit(CSM_Support::job($id)) : CSM_Transport::request($peer, 'commit', ['id' => $id, 'progress_run' => CSM_Progress::run()]);
            CSM_Progress::finish('Transfer complete');
            $history = get_option('_csm_history', []);
            if (in_array($control['selection']['media'] ?? 'none', ['all', 'changed'], true) && !isset($control['media_comparison'])) { $history[$control['direction'] . ':' . $peer['url']] = $summary['media_watermark']; }
            update_option('_csm_history', $history, false);
        } elseif ($action === 'rollback') {
            CSM_Progress::start($id, 'Restoring destination backup');
            $summary = $pull ? CSM_Plugin::rollback(CSM_Support::job($id)) : CSM_Transport::request($peer, 'rollback', ['id' => $id, 'progress_run' => CSM_Progress::run()]);
            CSM_Progress::finish('Destination restored');
            update_option('_csm_history', $control['previous_history'], false);
        } elseif ($action === 'discard') {
            if ($pull) {
                CSM_Plugin::discard(CSM_Support::job($id));
                if (!empty($control['segmented'])) {
                    try { CSM_Transport::request($peer, 'bundle_discard', ['id' => $id]); }
                    catch (Throwable $e) { CSM_Log::write('error', 'Source stream cleanup failed', ['transfer_id' => $id, 'error' => $e->getMessage()]); }
                }
            } else {
                CSM_Transport::request($peer, 'discard', ['id' => $id]);
                if (!empty($control['segmented'])) { CSM_Segments::cleanup($id); }
            }
            CSM_Progress::remove($id);
            delete_option('_csm_control'); return [];
        } elseif ($action === 'status') {
            $summary = $pull ? CSM_Plugin::summary(CSM_Support::job($id)) : CSM_Transport::request($peer, 'status', ['id' => $id]);
            $summary['progress'] = self::progress($control);
            $summary['timeline'] = self::timeline($control);
            return $summary + ['health' => $control['health'] ?? null, 'media_comparison' => $control['media_comparison'] ?? null, 'state' => self::state()];
        } else { throw new RuntimeException('Unknown operation.'); }
        self::remember($control, $summary);
        return $summary + ['health' => $control['health'] ?? null, 'media_comparison' => $control['media_comparison'] ?? null, 'state' => self::state()];
    }
}
