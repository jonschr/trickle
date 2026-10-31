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
        return ['id' => $control['id'], 'remote_id' => self::site_id($control['peer']['url']), 'direction' => $control['direction'], 'selection' => $control['selection'],
            'source' => $control['direction'] === 'pull' ? $control['peer']['url'] : site_url(), 'destination' => $control['direction'] === 'pull' ? site_url() : $control['peer']['url']];
    }

    public static function state(): array {
        $peers = self::peers();
        $session = self::session();
        $selected = get_option('_csm_peer');
        $id = $session['remote_id'] ?? ($selected ? self::site_id($selected['url']) : (count($peers) === 1 ? array_key_first($peers) : ''));
        if (!$session && !isset($peers[$id])) { $id = count($peers) === 1 ? array_key_first($peers) : ''; }
        return ['connections' => array_values(array_map(static fn($peer) => array_intersect_key($peer, array_flip(['id', 'url', 'name', 'icon'])), $peers)),
            'selected' => $id, 'recents' => array_values(array_filter(get_option('_csm_recents', []), static fn($recent) => !self::is_self($recent['remote_url'] ?? ''))), 'session' => $session];
    }

    public static function recent(string $id): array {
        $recent = get_option('_csm_recents', [])[$id] ?? null;
        if (!$recent) { throw new RuntimeException('Recent transfer not found.'); }
        $peer = self::peer($recent['remote_id']);
        if ($peer['url'] !== $recent['remote_url']) { throw new RuntimeException('The saved remote site no longer matches this transfer.'); }
        return $recent;
    }

    private static function remember(array $control, array $summary): void {
        $recent = get_option('_csm_recents', []);
        $peer = $control['peer'];
        $entry = ['id' => $control['id'], 'remote_id' => self::site_id($peer['url']), 'remote_url' => $peer['url'], 'remote_name' => $peer['name'] ?? $peer['url'],
            'direction' => $control['direction'], 'selection' => $control['selection'], 'started_at' => $control['created'],
            'completed_at' => $summary['applied'] ?? ($recent[$control['id']]['completed_at'] ?? null),
            'status' => $summary['status'] === 'staging' && $summary['ready'] ? 'staged' : $summary['status']];
        unset($recent[$control['id']]);
        $recent = [$control['id'] => $entry] + $recent;
        uasort($recent, static fn($a, $b) => $b['started_at'] <=> $a['started_at']);
        update_option('_csm_recents', array_slice($recent, 0, 20, true), false);
    }

    public static function execute(string $action, array $data = []): array {
        CSM_Plugin::supported(); CSM_Support::long_request();
        $locked = !in_array($action, ['state', 'recent', 'manifest', 'media', 'progress', 'status', 'logs'], true);
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
            if (in_array($action, ['transfer', 'commit', 'rollback'], true)) {
                CSM_Progress::fail($e->getMessage());
                $control = get_option('_csm_control');
                $progress = $control ? self::progress($control) : [];
                $failure = $progress['failure'] ?? $progress;
                $context = trim(($failure['phase'] ?? '') . ': ' . ($failure['component'] ?? ''), ': ');
                throw new RuntimeException($e->getMessage() . ($context ? ' Last activity: ' . $context . '.' : ''), (int)$e->getCode(), $e);
            }
            throw $e;
        }
        finally { if ($locked) { CSM_Support::unlock(); } }
    }

    private static function progress(array $control): array {
        $local = CSM_Progress::read($control['id']);
        try { self::assert_remote($control['peer']); }
        catch (Throwable $e) { return $local + ['poll_error' => $e->getMessage()]; }
        try { $remote = CSM_Transport::request($control['peer'], 'progress', ['id' => $control['id']], null, null, 5); }
        catch (Throwable $e) { return $local + ['poll_error' => 'Remote progress is unavailable; the transfer may still be running.']; }
        // Match operations, rather than comparing timestamps from two different server clocks.
        if (($remote['run'] ?? '') !== ($local['run'] ?? '')) { return $local; }
        if (!empty($remote['failure'])) { return $remote; }
        if (!empty($local['failure']['component']) || ($local['state'] ?? '') === 'complete') { return $local; }
        return !empty($remote['component']) ? $remote : $local;
    }

    private static function operate(string $action, array $data): array {
        if ($action === 'logs') { return ['entries' => CSM_Log::entries()]; }
        if ($action === 'state') { return self::state(); }
        if ($action === 'recent') { return self::recent($data['id'] ?? ''); }
        if ($action === 'settings') {
            update_option('_csm_settings', ['pull' => !empty($data['pull']), 'push' => !empty($data['push'])], false);
            if (!empty($data['rotate'])) { update_option('_csm_key', bin2hex(random_bytes(32)), false); }
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
            $remote = CSM_Transport::request($peer, 'manifest', ['refresh' => !empty($data['refresh'])]);
            self::assert_remote($peer, $remote);
            $local = CSM_Plugin::manifest(!empty($data['refresh']));
            $replacements = isset($remote['root'], $remote['content']) && ($remote['replacement_rules'] ?? 0) === 1
                ? ['pull' => CSM_Database::replacement_defaults($remote, $local), 'push' => CSM_Database::replacement_defaults($local, $remote)] : null;
            return ['remote' => $remote, 'local' => $local, 'replacements' => $replacements,
                'history' => get_option('_csm_history', []), 'state' => self::state()];
        }
        if ($action === 'media') {
            $session = self::session(); $direction = $session['direction'] ?? ($data['direction'] ?? '');
            if (!in_array($direction, ['push', 'pull'], true)) { throw new RuntimeException('Choose push or pull.'); }
            $query = ['page' => (int)($data['page'] ?? 1), 'ids' => $data['ids'] ?? []];
            return $direction === 'push' ? CSM_Files::media($query['page'], $query['ids']) : CSM_Transport::request(self::peer($session['remote_id'] ?? ($data['site'] ?? '')), 'media', $query);
        }
        if ($action === 'prepare') {
            if (get_option('_csm_control')) { throw new RuntimeException('Discard the retained transfer and its backup before starting another.'); }
            $peer = self::peer($data['site'] ?? '');
            $direction = $data['direction'] ?? '';
            if (!in_array($direction, ['pull', 'push'], true)) { throw new RuntimeException('Choose push or pull.'); }
            self::assert_remote($peer);
            $remote = CSM_Transport::request($peer, 'manifest');
            self::assert_remote($peer, $remote);
            $selection = CSM_Files::media_selection(CSM_Database::replacement_selection($data['selection'] ?? []));
            if ($selection['media'] === 'compare' && empty($remote['media_compare'])) { throw new RuntimeException('Update Trickle on both sites before comparing media checksums.'); }
            if ($direction === 'push' && !empty($selection['tables']) && array_key_exists('replacements', $selection)) {
                if (($remote['replacement_rules'] ?? 0) !== 1) { throw new RuntimeException('Update Trickle on the destination before transferring a database with explicit replacement rules.'); }
            }
            if ($direction === 'push') { $selection = CSM_Files::selection($selection); CSM_Database::validate($selection['tables'] ?? []); }
            $destination_selection = $selection;
            if (empty($selection['tables'])) { unset($destination_selection['replacements']); }
            $summary = $direction === 'pull' ? CSM_Plugin::prepare($selection) : CSM_Transport::request($peer, 'prepare', ['selection' => $destination_selection]);
            $control = ['direction' => $direction, 'selection' => $selection, 'id' => $summary['id'], 'peer' => $peer, 'created' => time(), 'previous_history' => get_option('_csm_history', [])];
            update_option('_csm_peer', $peer, false); update_option('_csm_control', $control, false);
            self::remember($control, $summary);
            return $summary + ['state' => self::state()];
        }
        $control = get_option('_csm_control');
        if (!$control || !hash_equals($control['id'], $data['id'] ?? '')) { throw new RuntimeException('This transfer is not the current local session.'); }
        $peer = $control['peer']; $pull = $control['direction'] === 'pull'; $id = $control['id'];
        if (in_array($action, ['transfer', 'commit'], true)) { self::assert_remote($peer); }
        if ($action === 'commit') { self::assert_remote($peer, CSM_Transport::request($peer, 'manifest')); }
        if ($action === 'progress') { return self::progress($control); }
        if ($action === 'transfer') {
            $kind = $data['kind'] ?? '';
            if (!in_array($kind, CSM_Plugin::kinds($control['selection']), true)) { throw new RuntimeException('Invalid stream.'); }
            $export_selection = $control['selection'];
            if ($kind === 'files' && $export_selection['media'] === 'compare') {
                CSM_Progress::start($id, 'Comparing media years, then differing months and files');
                $comparison = CSM_Media::compare($peer, $pull, !empty($export_selection['media_verify']));
                $export_selection['media_delta'] = $comparison['delta'];
                $control['media_comparison'] = $comparison['stats'];
                update_option('_csm_control', $control, false);
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
            if (in_array($control['selection']['media'] ?? 'none', ['all', 'changed'], true)) { $history[$control['direction'] . ':' . $peer['url']] = $summary['media_watermark']; }
            update_option('_csm_history', $history, false);
        } elseif ($action === 'rollback') {
            CSM_Progress::start($id, 'Restoring destination backup');
            $summary = $pull ? CSM_Plugin::rollback(CSM_Support::job($id)) : CSM_Transport::request($peer, 'rollback', ['id' => $id, 'progress_run' => CSM_Progress::run()]);
            CSM_Progress::finish('Destination restored');
            update_option('_csm_history', $control['previous_history'], false);
        } elseif ($action === 'discard') {
            if ($pull) { CSM_Plugin::discard(CSM_Support::job($id)); } else { CSM_Transport::request($peer, 'discard', ['id' => $id]); }
            CSM_Progress::remove($id);
            delete_option('_csm_control'); return [];
        } elseif ($action === 'status') {
            $summary = $pull ? CSM_Plugin::summary(CSM_Support::job($id)) : CSM_Transport::request($peer, 'status', ['id' => $id]);
            $summary['progress'] = self::progress($control);
            return $summary + ['media_comparison' => $control['media_comparison'] ?? null, 'state' => self::state()];
        } else { throw new RuntimeException('Unknown operation.'); }
        self::remember($control, $summary);
        return $summary + ['media_comparison' => $control['media_comparison'] ?? null, 'state' => self::state()];
    }
}
