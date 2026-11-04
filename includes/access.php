<?php
defined('ABSPATH') || exit;

/** Separately revocable, scoped connection keys; the original administrator key remains compatible. */
final class CSM_Access {
    public static function settings(): array { return get_option('_csm_settings', ['pull' => true, 'push' => true]); }

    public static function id(string $key): string { return substr(hash('sha256', $key), 0, 24); }

    public static function list(): array {
        return array_values(array_map(static fn($token) => array_intersect_key($token, array_flip(['id', 'name', 'read', 'write', 'expires'])), get_option('_csm_tokens', [])));
    }

    public static function issue(string $name, bool $read, bool $write, int $days): array {
        if (!$read && !$write) { throw new RuntimeException('A connection needs read or write access.'); }
        if ($days < 1 || $days > 90) { throw new RuntimeException('Choose a key lifetime from 1 to 90 days.'); }
        $tokens = get_option('_csm_tokens', []);
        if (count($tokens) >= 20) { throw new RuntimeException('Revoke an unused connection key before creating another (20 maximum).'); }
        $key = bin2hex(random_bytes(32)); $id = self::id($key);
        $tokens[$id] = ['id' => $id, 'key' => $key, 'name' => substr(sanitize_text_field($name), 0, 80) ?: 'Connection', 'read' => $read, 'write' => $write, 'expires' => time() + $days * DAY_IN_SECONDS];
        update_option('_csm_tokens', $tokens, false);
        return ['id' => $id, 'pairing' => site_url() . "\n" . $key, 'keys' => self::list()];
    }

    public static function revoke(string $id): void {
        $tokens = get_option('_csm_tokens', []);
        if (!isset($tokens[$id])) { throw new RuntimeException('Connection key not found.'); }
        unset($tokens[$id]); update_option('_csm_tokens', $tokens, false);
    }

    public static function credential(string $id): array {
        $master = (string)get_option('_csm_key');
        if ($master && ($id === '' || hash_equals(self::id($master), $id))) { return ['id' => self::id($master), 'key' => $master, 'read' => true, 'write' => true, 'master' => true]; }
        $token = get_option('_csm_tokens', [])[$id] ?? null;
        if (!$token || $token['expires'] <= time()) { throw new RuntimeException('Connection key expired or was revoked. Ask the remote administrator for a new code.', 403); }
        return $token + ['master' => false];
    }

    public static function authorize(array $token, string $action): void {
        if ($action==='cancel' && (!empty($token['read']) || !empty($token['write']))) { $GLOBALS['_csm_credential']=$token; return; }
        if (in_array($action, ['update_trickle','update_trickle_build'],true)) {
            if (empty($token['write'])) { throw new RuntimeException('Use an administrator or read/write connection key to update Trickle.', 403); }
            $GLOBALS['_csm_credential'] = $token; return;
        }
        $reads = ['export', 'manifest', 'media', 'media_hashes', 'file_policy', 'component_hashes', 'component_manifest', 'media_manifest', 'bundle', 'bundle_stream', 'bundle_chunk', 'bundle_discard', 'estimates'];
        $status = ['backups', 'version', 'update_info', 'build_info', 'progress', 'status', 'preview', 'segment_state','cancel'];
        $settings = self::settings();
        $read = !empty($settings['pull']) && !empty($token['read']); $write = !empty($settings['push']) && !empty($token['write']);
        if (in_array($action, ['version', 'update_info','build_info'], true) && !empty($token['write'])) { $GLOBALS['_csm_credential'] = $token; return; }
        if (in_array($action, $reads, true) ? !$read : (in_array($action, $status, true) ? !$read && !$write : !$write)) {
            throw new RuntimeException('This connection does not permit ' . (in_array($action, $reads, true) ? 'remote reads' : 'this operation') . '. Check the key scope and remote access settings.', 403);
        }
        $GLOBALS['_csm_credential'] = $token;
    }

    public static function owner(): string { return $GLOBALS['_csm_credential']['id'] ?? 'local'; }

    public static function assert_owner(array $record): void {
        if (isset($record['credential']) && $record['credential'] !== self::owner() && empty($GLOBALS['_csm_credential']['master'])) { throw new RuntimeException('This transfer belongs to another connection key. Use its original key or the site administrator connection.', 403); }
    }
}
