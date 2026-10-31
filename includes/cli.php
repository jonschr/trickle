<?php
defined('ABSPATH') || exit;

/** Manage saved sites and selective streamed transfers. */
final class CSM_CLI {
    private static function guard(callable $fn) {
        $https = $_SERVER['HTTPS'] ?? null;
        $_SERVER['HTTPS'] = wp_parse_url(get_option('siteurl'), PHP_URL_SCHEME) === 'https' ? 'on' : 'off';
        try { return $fn(); }
        catch (Throwable $e) { WP_CLI::error($e->getMessage()); }
        finally { if ($https === null) { unset($_SERVER['HTTPS']); } else { $_SERVER['HTTPS'] = $https; } }
    }

    private static function output(array $data, array $assoc, array $fields = []): void {
        unset($data['state'], $data['nonce']);
        if (($assoc['format'] ?? '') === 'json') { WP_CLI::line(wp_json_encode($data, JSON_UNESCAPED_SLASHES)); return; }
        $rows = array_is_list($data) ? $data : [$data];
        \WP_CLI\Utils\format_items('table', $rows, $fields ?: ['id', 'status', 'tables', 'rows', 'files', 'bytes', 'destination']);
    }

    /**
     * List saved remote sites, without their private keys.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json.
     */
    public function sites($args, $assoc): void {
        self::guard(static fn() => self::output(CSM_Controller::state()['connections'], $assoc, ['id', 'name', 'url']));
    }

    /**
     * Save a remote site's code once for both push and pull.
     *
     * ## OPTIONS
     * --code-file=<path>
     * : File containing the URL and key; use - to read standard input.
     *
     * [--name=<name>]
     * : Connection label, such as Staging or Production.
     *
     * [--format=<format>]
     * : table or json.
     */
    public function connect($args, $assoc): void {
        self::guard(static function () use ($assoc) {
            $path = $assoc['code-file'];
            $code = $path === '-' ? stream_get_contents(STDIN) : @file_get_contents($path);
            if ($code === false) { throw new RuntimeException('Cannot read the connection code file.'); }
            $result = CSM_Controller::execute('connect', ['pairing' => $code, 'name' => $assoc['name'] ?? '']);
            $peer = CSM_Controller::peer($result['saved']);
            self::output(array_intersect_key($peer, array_flip(['id', 'name', 'url'])), $assoc, ['id', 'name', 'url']);
        });
    }

    /**
     * Print this site's private connection code, or write it to a new private file.
     *
     * ## OPTIONS
     *
     * [--output=<path>]
     * : Create a new file with permissions 0600 instead of printing the secret.
     */
    public function code($args, $assoc): void {
        self::guard(static function () use ($assoc) {
            if (empty($assoc['output'])) { WP_CLI::line(CSM_Plugin::pairing()); return; }
            $fp = @fopen($assoc['output'], 'xb');
            if (!$fp) { throw new RuntimeException('Cannot create the code file; choose a new writable path.'); }
            try {
                if (!chmod($assoc['output'], 0600)) { throw new RuntimeException('Cannot make the code file private.'); }
                CSM_Support::write($fp, CSM_Plugin::pairing() . "\n");
            } finally { fclose($fp); }
            WP_CLI::line('Connection code written to a private file.');
        });
    }

    /**
     * List recent transfers and their exact selections.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json; json includes all selections and timestamps.
     */
    public function recent($args, $assoc): void {
        self::guard(static function () use ($assoc) {
            $rows = CSM_Controller::state()['recents'];
            if (($assoc['format'] ?? '') !== 'json') {
                $rows = array_map(static fn($r) => ['id' => $r['id'], 'date' => gmdate('c', $r['started_at']), 'direction' => $r['direction'], 'remote' => $r['remote_name'], 'status' => $r['status']], $rows);
            }
            self::output($rows, $assoc, ['id', 'date', 'direction', 'remote', 'status']);
        });
    }

    /**
     * Show the exact automatic find/replace defaults for a remote and direction.
     *
     * ## OPTIONS
     *
     * [--remote=<site>]
     * : Saved site ID, exact URL, or unique connection name.
     *
     * [--direction=<direction>]
     * : push or pull; default pull.
     *
     * [--format=<format>]
     * : table or json.
     */
    public function replacements($args, $assoc): void {
        self::guard(static function () use ($assoc) {
            if (empty($assoc['remote']) && count(CSM_Controller::peers()) !== 1) { throw new RuntimeException('Specify --remote when several sites are saved.'); }
            $peer = CSM_Controller::peer($assoc['remote'] ?? '');
            $direction = $assoc['direction'] ?? 'pull';
            if (!in_array($direction, ['push', 'pull'], true)) { throw new RuntimeException('Choose push or pull.'); }
            $inventory = CSM_Controller::execute('manifest', ['site' => $peer['id']]);
            if (!$inventory['replacements']) { throw new RuntimeException('Update Trickle on both sites to preview replacements.'); }
            self::output($inventory['replacements'][$direction], $assoc, ['id', 'label', 'find', 'replace', 'enabled']);
        });
    }

    /**
     * Stage a push to a saved remote; applying requires --apply or a separate apply command.
     *
     * ## OPTIONS
     *
     * [--remote=<site>]
     * : Saved site ID, exact URL, or unique connection name; required when several sites are saved.
     *
     * [--plugins=<ids>]
     * : Comma-separated plugin folder IDs, or all.
     *
     * [--themes=<ids>]
     * : Comma-separated theme IDs, or all. Required parent themes are included.
     *
     * [--tables=<names>]
     * : Comma-separated source table names, or all.
     *
     * [--media=<mode>]
     * : none, all, changed, uploaded, selected, or compare. Default: none.
     *
     * [--rehash-media]
     * : Recalculate all media content hashes for --media=compare instead of reusing cached hashes.
     *
     * [--media-ids=<ids>]
     * : Comma-separated attachment IDs for selected media.
     *
     * [--uploaded-after=<date>]
     * : Inclusive upload start date in UTC, YYYY-MM-DD, for --media=uploaded.
     *
     * [--uploaded-before=<date>]
     * : Inclusive upload end date in UTC, YYYY-MM-DD, for --media=uploaded.
     *
     * [--since=<time>]
     * : UTC ISO date/time or Unix timestamp for changed media; otherwise use the last media watermark.
     *
     * [--skip-replace]
     * : Disable all database replacements, including custom rules.
     *
     * [--skip-default-replacements=<ids>]
     * : Disable comma-separated default rule IDs shown by trickle replacements.
     *
     * [--replacements=<path>]
     * : JSON file of custom find/replace rules to append to the defaults.
     *
     * [--recent=<id>]
     * : Reuse one recent push's exact site and selection instead of component flags.
     *
     * [--apply]
     * : Apply after staging and verification.
     *
     * [--yes]
     * : Confirm application without an interactive prompt.
     *
     * [--format=<format>]
     * : table or json.
     */
    public function push($args, $assoc): void { self::guard(static fn() => self::transfer('push', $assoc)); }

    /**
     * Stage a pull from a saved remote. Options are identical to push.
     *
     * ## OPTIONS
     *
     * [--remote=<site>]
     * : Saved site ID, exact URL, or unique connection name; required when several sites are saved.
     *
     * [--plugins=<ids>]
     * : Comma-separated plugin folder IDs, or all.
     *
     * [--themes=<ids>]
     * : Comma-separated theme IDs, or all. Required parent themes are included.
     *
     * [--tables=<names>]
     * : Comma-separated source table names, or all.
     *
     * [--media=<mode>]
     * : none, all, changed, uploaded, selected, or compare. Default: none.
     *
     * [--rehash-media]
     * : Recalculate all media content hashes for --media=compare instead of reusing cached hashes.
     *
     * [--media-ids=<ids>]
     * : Comma-separated attachment IDs for selected media.
     *
     * [--uploaded-after=<date>]
     * : Inclusive upload start date in UTC, YYYY-MM-DD, for --media=uploaded.
     *
     * [--uploaded-before=<date>]
     * : Inclusive upload end date in UTC, YYYY-MM-DD, for --media=uploaded.
     *
     * [--since=<time>]
     * : UTC ISO date/time or Unix timestamp for changed media; otherwise use the last media watermark.
     *
     * [--skip-replace]
     * : Disable all database replacements, including custom rules.
     *
     * [--skip-default-replacements=<ids>]
     * : Disable comma-separated default rule IDs shown by trickle replacements.
     *
     * [--replacements=<path>]
     * : JSON file of custom find/replace rules to append to the defaults.
     *
     * [--recent=<id>]
     * : Reuse one recent pull's exact site and selection instead of component flags.
     *
     * [--apply]
     * : Apply after staging and verification.
     *
     * [--yes]
     * : Confirm application without an interactive prompt.
     *
     * [--format=<format>]
     * : table or json.
     */
    public function pull($args, $assoc): void { self::guard(static fn() => self::transfer('pull', $assoc)); }

    private static function transfer(string $direction, array $assoc): void {
        if (get_option('_csm_control')) { throw new RuntimeException('A transfer is retained. Use status, resume, apply, rollback or discard before starting another.'); }
        if (!empty($assoc['recent'])) {
            $recent = CSM_Controller::recent($assoc['recent']);
            if ($recent['direction'] !== $direction) { throw new RuntimeException('This recent transfer uses the other direction; use trickle ' . $recent['direction'] . '.'); }
            if (array_intersect(array_keys($assoc), ['remote', 'plugins', 'themes', 'tables', 'media', 'rehash-media', 'media-ids', 'uploaded-after', 'uploaded-before', 'since', 'skip-replace', 'skip-default-replacements', 'replacements'])) { throw new RuntimeException('Use --recent alone to reuse exact settings, or specify a new remote and selection.'); }
            $peer = CSM_Controller::peer($recent['remote_id']); $selection = $recent['selection'];
        } else {
            $peers = CSM_Controller::peers();
            if (empty($assoc['remote']) && count($peers) !== 1) { throw new RuntimeException('Specify --remote using a saved site ID, URL, or unique name.'); }
            $peer = CSM_Controller::peer($assoc['remote'] ?? array_key_first($peers));
            $inventory = CSM_Controller::execute('manifest', ['site' => $peer['id']]);
            $source = $inventory[$direction === 'push' ? 'local' : 'remote'];
            if (($assoc['media'] ?? '') === 'uploaded' && empty($source['media_dates'])) { throw new RuntimeException('Update Trickle on the source site before transferring by upload date.'); }
            $selection = ['media' => $assoc['media'] ?? (isset($assoc['media-ids']) ? 'selected' : 'none'), 'replace' => !isset($assoc['skip-replace']), 'since' => 0, 'media_ids' => [], 'media_verify' => isset($assoc['rehash-media']), 'media_after' => $assoc['uploaded-after'] ?? '', 'media_before' => $assoc['uploaded-before'] ?? ''];
            foreach (['plugins', 'themes', 'tables'] as $kind) {
                $selection[$kind] = ($assoc[$kind] ?? '') === 'all' ? array_column(array_filter($source[$kind], static fn($item) => empty($item['protected'])), 'id') : self::csv($assoc[$kind] ?? '');
            }
            if ($inventory['replacements']) {
                $selection['replacements'] = $inventory['replacements'][$direction];
                $skipped = self::csv($assoc['skip-default-replacements'] ?? '');
                $unknown = array_diff($skipped, array_column($selection['replacements'], 'id'));
                if ($unknown) { throw new RuntimeException('Unknown default replacement IDs: ' . implode(', ', $unknown) . '. Use trickle replacements to list the available IDs.'); }
                foreach ($selection['replacements'] as &$rule) { $rule['enabled'] = !in_array($rule['id'], $skipped, true); }
                unset($rule);
                if (isset($assoc['replacements'])) {
                    $file = @file_get_contents($assoc['replacements']);
                    if ($file === false) { throw new RuntimeException('Cannot read the custom replacement JSON file.'); }
                    $custom = json_decode($file, true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($custom) || !array_is_list($custom)) { throw new RuntimeException('The custom replacement file must contain a JSON array of rules.'); }
                    foreach ($custom as $rule) {
                        if (!is_array($rule)) { throw new RuntimeException('Each custom replacement must be an object with find and replace text.'); }
                        $rule['kind'] = 'custom'; $selection['replacements'][] = $rule;
                    }
                }
            } elseif (isset($assoc['replacements']) || isset($assoc['skip-default-replacements'])) { throw new RuntimeException('Update Trickle on both sites before configuring replacement rules.'); }
            foreach ($source['themes'] as $theme) {
                if (in_array($theme['id'], $selection['themes'], true) && $theme['parent']) { $selection['themes'][] = $theme['parent']; }
            }
            $selection['themes'] = array_values(array_unique($selection['themes']));
            foreach (self::csv($assoc['media-ids'] ?? '') as $id) {
                if (!ctype_digit($id) || (int)$id < 1) { throw new RuntimeException('Media IDs must be positive attachment IDs.'); }
                $selection['media_ids'][] = (int)$id;
            }
            if (isset($assoc['since'])) {
                $stamp = ctype_digit($assoc['since']) ? (int)$assoc['since'] : strtotime($assoc['since'] . ' UTC');
                if (!$stamp) { throw new RuntimeException('Use a valid UTC date/time or Unix timestamp for --since.'); }
                $selection['since'] = $stamp;
            } elseif ($selection['media'] === 'changed') { $selection['since'] = $inventory['history'][$direction . ':' . $peer['url']] ?? 0; }
        }
        $summary = CSM_Controller::execute('prepare', ['site' => $peer['id'], 'direction' => $direction, 'selection' => $selection]);
        foreach (CSM_Plugin::kinds($selection) as $kind) { $summary = CSM_Controller::execute('transfer', ['id' => $summary['id'], 'kind' => $kind]); }
        $session = CSM_Controller::session();
        if (isset($assoc['apply'])) {
            WP_CLI::confirm('Apply the verified transfer to ' . $session['destination'] . '? Selected destination tables and files will be replaced.', $assoc);
            $summary = CSM_Controller::execute('commit', ['id' => $summary['id']]);
        }
        unset($summary['nonce']); self::output($summary + ['source' => $session['source'], 'destination' => $session['destination']], $assoc);
    }

    private static function csv(string $value): array { return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn($item) => $item !== ''))); }

    private static function current(): array {
        $session = CSM_Controller::session();
        if (!$session) { throw new RuntimeException('No transfer is retained.'); }
        return $session;
    }

    /**
     * Show the retained transfer and its source/destination.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     */
    public function status($args, $assoc): void {
        self::guard(static function () use ($assoc) {
            $session = self::current(); $summary = CSM_Controller::execute('status', ['id' => $session['id']]);
            self::output($summary + ['source' => $session['source'], 'destination' => $session['destination']], $assoc);
        });
    }

    /**
     * Retry incomplete streams of the retained transfer without applying.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     */
    public function resume($args, $assoc): void {
        self::guard(static function () use ($assoc) {
            $session = self::current(); $summary = CSM_Controller::execute('status', ['id' => $session['id']]);
            if ($summary['status'] !== 'staging') { throw new RuntimeException('Only a staging transfer can resume its streams.'); }
            foreach ($summary['pending'] as $kind) { $summary = CSM_Controller::execute('transfer', ['id' => $session['id'], 'kind' => $kind]); }
            self::output($summary + ['source' => $session['source'], 'destination' => $session['destination']], $assoc);
        });
    }

    /**
     * Apply the retained, verified transfer.
     *
     * ## OPTIONS
     *
     * [--yes]
     *
     * [--format=<format>]
     */
    public function apply($args, $assoc): void { self::guard(static fn() => self::finish('commit', 'Apply the verified transfer to', $assoc)); }

    /**
     * Restore the destination backup, overwriting changes since application.
     *
     * ## OPTIONS
     *
     * [--yes]
     *
     * [--format=<format>]
     */
    public function rollback($args, $assoc): void { self::guard(static fn() => self::finish('rollback', 'Restore the pre-transfer backup on', $assoc)); }

    /**
     * Delete retained staging data and rollback backup. Applied changes remain.
     *
     * ## OPTIONS
     *
     * [--yes]
     *
     * [--format=<format>]
     */
    public function discard($args, $assoc): void { self::guard(static fn() => self::finish('discard', 'Permanently delete the retained staging data and backup for', $assoc)); }

    private static function finish(string $action, string $question, array $assoc): void {
        $session = self::current();
        CSM_Controller::execute('status', ['id' => $session['id']]);
        WP_CLI::confirm($question . ' ' . $session['destination'] . '?', $assoc);
        $result = CSM_Controller::execute($action, ['id' => $session['id']]);
        unset($result['nonce']);
        self::output(($result ?: ['id' => $session['id'], 'status' => 'discarded', 'tables' => 0, 'rows' => 0, 'files' => 0, 'bytes' => 0]) + ['source' => $session['source'], 'destination' => $session['destination']], $assoc);
    }
}
