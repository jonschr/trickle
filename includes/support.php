<?php
defined('ABSPATH') || exit;

final class CSM_Support {
    public static function gzip(Generator $input): Generator {
        $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 3]);
        if (!$context) { throw new RuntimeException('Cannot initialize stream compression.'); }
        foreach ($input as $bytes) {
            $compressed = deflate_add($context, $bytes, ZLIB_NO_FLUSH);
            if ($compressed === false) { throw new RuntimeException('Stream compression failed.'); }
            if ($compressed !== '') { yield $compressed; }
        }
        yield deflate_add($context, '', ZLIB_FINISH);
    }

    public static function decompress($fp): void {
        if (!stream_filter_append($fp, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31])) { throw new RuntimeException('Cannot decompress incoming stream.'); }
    }

    public static function json($value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public static function line($fp): array {
        $line = fgets($fp, 32 * 1024 * 1024);
        if ($line === false || !str_ends_with($line, "\n")) {
            throw new RuntimeException('Incomplete stream or record exceeds the 32 MB record limit.');
        }
        $value = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new RuntimeException('Invalid stream record.'); }
        return $value;
    }

    public static function write($fp, string $bytes): void {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($fp, substr($bytes, $offset));
            if (!$written) { throw new RuntimeException('Could not write stream; check disk space.'); }
            $offset += $written;
        }
    }

    public static function ident(string $name): string {
        if (!preg_match('/^[a-zA-Z0-9_$-]{1,64}$/D', $name)) {
            throw new RuntimeException('Unsupported SQL identifier: ' . $name);
        }
        return '`' . $name . '`';
    }

    public static function query(string $sql) {
        global $wpdb;
        $result = $wpdb->query($sql);
        if ($result === false) { throw new RuntimeException('Database operation failed: ' . $wpdb->last_error); }
        return $result;
    }

    public static function private_dir(): string {
        $base = rtrim(sys_get_temp_dir(), '/\\');
        $path = $base . '/trickle-' . substr(hash_hmac('sha256', ABSPATH, wp_salt('auth')), 0, 24);
        if (!is_dir($path) && !mkdir($path, 0700, true)) {
            throw new RuntimeException('Cannot create private staging storage.');
        }
        if (is_link($path) || !is_writable($path)) { throw new RuntimeException('Unsafe staging directory.'); }
        chmod($path, 0700);
        $webroot = realpath(ABSPATH);
        if ($webroot && str_starts_with(realpath($path) . '/', $webroot . '/')) {
            throw new RuntimeException('Staging storage must be outside the public WordPress directory.');
        }
        return $path;
    }

    public static function job(string $id): array {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new RuntimeException('Invalid transfer ID.'); }
        $job = get_option('_csm_job_' . $id);
        if (!$job) { throw new RuntimeException('Transfer not found.'); }
        return $job;
    }

    public static function backup_dir(): string {
        $home = getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '');
        if ($home && is_dir($home) && is_writable($home)) {
            $path = rtrim($home, '/\\') . '/.trickle-backups/' . substr(hash_hmac('sha256', ABSPATH, wp_salt('auth')), 0, 24);
            if (is_dir($path) || @mkdir($path, 0700, true)) {
                $real = realpath($path);
                $public = array_filter([realpath(ABSPATH), realpath($_SERVER['DOCUMENT_ROOT'] ?? '')]);
                if ($real && !is_link($path) && is_writable($path) && !array_filter($public, static fn($root) => $real === $root || str_starts_with($real . '/', rtrim($root, '/') . '/'))) { chmod($path, 0700); return $path; }
            }
        }
        return self::private_dir();
    }

    public static function save(array $job): void {
        update_option('_csm_job_' . $job['id'], $job, false);
    }

    public static function lock(): string {
        $id = bin2hex(random_bytes(16));
        $fp = fopen(self::private_dir() . '/transfer.lock', 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another transfer is running on this site.'); }
        // Keep the OS lock alive for the duration of this PHP request, even across a fatal error.
        $GLOBALS['_csm_lock'] = $fp;
        return $id;
    }

    public static function unlock(): void {
        if (isset($GLOBALS['_csm_lock'])) { fclose($GLOBALS['_csm_lock']); unset($GLOBALS['_csm_lock']); }
    }

    public static function remove_tree(string $path): void {
        if (!file_exists($path)) { return; }
        if (is_dir($path) && !is_link($path)) {
            foreach (new FilesystemIterator($path) as $item) { self::remove_tree($item->getPathname()); }
            rmdir($path);
        } else { unlink($path); }
    }

    public static function long_request(): void {
        @set_time_limit(0);
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    }

    public static function replace(string $text, array $pairs): string {
        if (!$pairs) { return $text; }
        // Match JSON slash escapes without decoding/re-encoding unrelated JSON formatting.
        $escaped = [];
        foreach ($pairs as $find => $replacement) { if (str_contains($find, '/') && !str_contains($find, '\\/')) { $escaped[str_replace('/', '\\/', $find)] = str_replace('/', '\\/', $replacement); } }
        $pairs += $escaped;
        $replaced = strtr($text, $pairs);
        // Leave unrelated values byte-for-byte intact, including malformed legacy serialization.
        if ($replaced === $text) { return $text; }
        // Parse serialized strings without instantiating PHP objects or executing __wakeup().
        if (is_serialized($text)) {
            $offset = 0;
            $result = self::serialized($text, $offset, $pairs);
            if ($offset !== strlen($text)) { throw new RuntimeException('Unexpected trailing serialized data.'); }
            return $result;
        }
        return $replaced;
    }

    private static function serialized(string $s, int &$p, array $pairs): string {
        $tail = substr($s, $p);
        if (preg_match('/^s:(\d+):"/', $tail, $m)) {
            $start = $p + strlen($m[0]);
            $length = (int)$m[1];
            if (substr($s, $start + $length, 2) !== '";') { throw new RuntimeException('Invalid serialized string.'); }
            $value = substr($s, $start, $length);
            $p = $start + $length + 2;
            $value = self::replace($value, $pairs);
            return 's:' . strlen($value) . ':"' . $value . '";';
        }
        if (preg_match('/^(a:(\d+):\{|O:\d+:"[^"]*":(\d+):\{)/', $tail, $m)) {
            $header = $m[1];
            $count = (int)($m[2] !== '' ? $m[2] : $m[3]);
            $p += strlen($header);
            $output = $header;
            for ($i = 0; $i < $count * 2; $i++) { $output .= self::serialized($s, $p, $pairs); }
            if (($s[$p++] ?? '') !== '}') { throw new RuntimeException('Invalid serialized container.'); }
            return $output . '}';
        }
        if (preg_match('/^(N;|[bidrR]:[^;]*;|E:\d+:"[^"]*";)/', $tail, $m)) {
            $p += strlen($m[0]);
            return $m[0];
        }
        throw new RuntimeException('Unsupported serialized value; transfer stopped rather than corrupting it.');
    }
}
