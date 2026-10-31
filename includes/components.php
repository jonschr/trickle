<?php
defined('ABSPATH') || exit;

/** Cached content hashes for repeat plugin and theme transfers. */
final class CSM_Components {
    private static function cache_path(array $selection): string {
        return CSM_Support::private_dir() . '/components-' . substr(hash('sha256', CSM_Support::json([$selection['plugins'] ?? [], $selection['themes'] ?? [], $selection['skip_paths'] ?? [], $selection['git_overrides'] ?? []])), 0, 24) . '.json';
    }

    private static function stamp(string $path): string {
        clearstatcache(true, $path); $s = lstat($path);
        if (!$s || !is_file($path)) { throw new RuntimeException('Component file disappeared during comparison.'); }
        return implode(':', array_map(static fn($key) => $s[$key], ['size', 'mtime', 'ctime', 'ino', 'dev', 'mode']));
    }

    private static function read(array $selection): array {
        $raw = @file_get_contents(self::cache_path($selection));
        $cache = $raw === false ? [] : json_decode($raw, true);
        if (!is_array($cache) || !isset($cache['files'], $cache['token']) || !hash_equals($cache['token'], hash('sha256', CSM_Support::json($cache['files'])))) { return []; }
        return $cache;
    }

    public static function inventory(array $query): array {
        $selection = $query['selection'] ?? []; $selection['media'] = 'none';
        $path = self::cache_path($selection);
        if (($query['level'] ?? '') === 'files') {
            $cache = self::read($selection); $offset = $query['offset'] ?? 0;
            if (!is_int($offset) || $offset < 0 || !is_string($query['token'] ?? null) || !hash_equals($cache['token'] ?? '', $query['token'])) { throw new RuntimeException('Component inventory changed. Retry preparation.'); }
            return ['files' => array_slice($cache['files'], $offset, 500, true), 'more' => $offset + 500 < count($cache['files'])];
        }
        $old = self::read($selection); $files = []; $groups = []; $hashed = 0;
        foreach (['plugins', 'themes'] as $kind) {
            foreach ($selection[$kind] ?? [] as $id) {
                CSM_Files::safe_relative($id);
                if (str_contains($id, '/')) { throw new RuntimeException('Invalid component ID.'); }
                $root = CSM_Files::root($kind, $id);
                foreach (CSM_Files::scan($root . '/' . $id, $kind . '/' . $id, $selection) as $file) {
                    $relative = $kind . '/' . substr($file, strlen($root) + 1); $stamp = self::stamp($file);
                    $cached = $old['files'][$relative] ?? [];
                    if (empty($query['verify']) && max(filemtime($file), filectime($file)) < time() - 2 && ($cached['stamp'] ?? '') === $stamp) { $hash = $cached['hash']; }
                    else { $hash = hash_file('sha256', $file); $hashed++; if (!$hash || self::stamp($file) !== $stamp) { throw new RuntimeException('Component changed while comparing: ' . $relative); } }
                    $files[$relative] = ['hash' => $hash, 'stamp' => $stamp];
                }
            }
        }
        ksort($files, SORT_STRING);
        foreach ($files as $relative => $file) { $parts = explode('/', $relative); $groups[$parts[0] . '/' . $parts[1]][$relative] = $file['hash']; }
        $hashes = []; foreach ($groups as $id => $items) { $hashes[$id] = hash('sha256', CSM_Support::json($items)); }
        $cache = ['files' => $files, 'token' => hash('sha256', CSM_Support::json($files))];
        $raw = CSM_Support::json($cache); $temp = $path . '.' . bin2hex(random_bytes(8));
        if (file_put_contents($temp, $raw, LOCK_EX) !== strlen($raw)) { @unlink($temp); throw new RuntimeException('Could not save component checksum cache.'); }
        chmod($temp, 0600); if (!rename($temp, $path)) { @unlink($temp); throw new RuntimeException('Could not save component checksum cache.'); }
        return ['token' => $cache['token'], 'components' => $hashes, 'files' => count($files), 'hashed' => $hashed];
    }

    public static function compare(array $peer, bool $pull, array $selection): array {
        $remote = static fn($query) => CSM_Transport::request($peer, 'component_hashes', $query);
        $local = static fn($query) => self::inventory($query);
        $source = $pull ? $remote : $local; $destination = $pull ? $local : $remote;
        $src = $source(['selection' => $selection, 'verify' => !empty($selection['file_verify'])]); $dst = $destination(['selection' => $selection, 'verify' => !empty($selection['file_verify'])]);
        $dest = []; $same = [];
        // Page inventories so a component with thousands of files never exceeds a control response.
        for ($offset = 0; $offset < $dst['files']; $offset += 500) { $page = $destination(['level' => 'files', 'selection' => $selection, 'token' => $dst['token'], 'offset' => $offset]); $dest += $page['files']; }
        for ($offset = 0; $offset < $src['files']; $offset += 500) {
            $page = $source(['level' => 'files', 'selection' => $selection, 'token' => $src['token'], 'offset' => $offset]);
            foreach ($page['files'] as $path => $file) { if (($dest[$path]['hash'] ?? null) === $file['hash']) { $same[$path] = $file; } }
        }
        $plan = base64_encode(gzencode(CSM_Support::json($same), 3));
        // shortcut: exceptionally large equality plans use a full transfer; replace with server-side paged plans if needed.
        if (strlen($plan) > 600000) { return ['same' => '', 'matched' => 0, 'fallback' => true]; }
        return ['same' => $plan, 'matched' => count($same), 'fallback' => false];
    }

    public static function unchanged(array $selection): array {
        $plan = $selection['component_same'] ?? '';
        if ($plan === '') { return []; }
        if (!is_string($plan) || strlen($plan) > 600000) { throw new RuntimeException('Invalid unchanged-file plan.'); }
        $bytes = base64_decode($plan, true); $raw = $bytes === false ? false : @gzdecode($bytes, 32 * 1024 * 1024);
        $files = $raw === false ? null : json_decode($raw, true);
        if (!is_array($files)) { throw new RuntimeException('Invalid unchanged-file plan.'); }
        return $files;
    }

    public static function verify(string $path, array $file): bool {
        return is_string($file['hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $file['hash']) && self::stamp($path) === ($file['stamp'] ?? '');
    }
}
