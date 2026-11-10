<?php
defined('ABSPATH') || exit;

final class CSM_Cancelled_Error extends RuntimeException {}
final class CSM_Yield extends RuntimeException {}

final class CSM_Support {
    private static ?string $work_id = null;
    private static bool $cancellable = true;
    private static ?float $step_deadline = null;

    public static function work(string $id, ?bool $cancellable = true): void {
        self::cancel_path($id); if (self::$work_id!==$id || $cancellable!==null) { self::$cancellable=$cancellable ?? true; } self::$work_id=$id;
    }

    private static function cancel_path(string $id): string {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new RuntimeException('Invalid transfer ID.'); }
        return self::private_dir() . '/' . $id . '-cancel.json';
    }

    public static function cancel(string $id): void {
        self::atomic_json(self::cancel_path($id), ['created'=>time(),'credential'=>class_exists('CSM_Access')?CSM_Access::owner():'local']);
    }

    public static function is_cancelled(string $id): bool { $path=self::cancel_path($id); clearstatcache(true,$path); return is_file($path); }
    public static function check_cancel(): void {
        if (self::$cancellable && self::$work_id && self::is_cancelled(self::$work_id)) {
            $marker=json_decode(file_get_contents(self::cancel_path(self::$work_id)),true);
            if (!isset($marker['credential']) || !class_exists('CSM_Access') || $marker['credential']===CSM_Access::owner() || !empty($GLOBALS['_csm_credential']['master'])) { throw new CSM_Cancelled_Error('Transfer cancelled.'); }
        }
    }

    public static function step(float $seconds = 8): void { self::$step_deadline = hrtime(true)/1e9 + max(.01, (float)apply_filters('csm_step_seconds', $seconds)); }
    public static function due(): bool { return self::$step_deadline !== null && hrtime(true)/1e9 >= self::$step_deadline; }

    public static function atomic_json(string $path, array $data): void {
        self::atomic_bytes($path,self::json($data));
    }

    public static function atomic_bytes(string $path,string $raw,int $mode = 0600): void {
        $tmp = $path . '.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $raw, LOCK_EX) !== strlen($raw)) { @unlink($tmp); throw new RuntimeException('Could not save transfer checkpoint.'); }
        if (!chmod($tmp, $mode)) { @unlink($tmp); throw new RuntimeException('Could not set transfer checkpoint permissions.'); }
        if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Could not save transfer checkpoint.'); }
    }

    private static ?float $source_deadline = null;
    private static int $source_seconds = 45;

    public static function begin_source_budget(): void {
        self::$source_seconds = max(1, min(45, (int)apply_filters('csm_source_packaging_seconds', 45)));
        self::$source_deadline = hrtime(true) / 1e9 + self::$source_seconds;
    }

    public static function check_source_budget(): void {
        self::check_cancel();
        if (self::$source_deadline !== null && hrtime(true) / 1e9 >= self::$source_deadline) {
            throw new RuntimeException('Source packaging exceeded the ' . self::$source_seconds . '-second limit. Select fewer components or a smaller media date range.');
        }
    }

    public static function end_source_budget(): void { self::$source_deadline = null; }

    public static function hash_file(string $path, ?callable $progress = null): string {
        $fp=fopen($path,'rb'); if (!$fp) { throw new RuntimeException('Cannot read checksum file: '.basename($path)); }
        $seconds=max(1,min(15,(int)apply_filters('csm_hash_seconds',15))); $deadline=hrtime(true)/1e9+$seconds; $hash=hash_init('sha256'); $read=0;
        try {
            while (!feof($fp)) {
                self::check_cancel(); self::check_source_budget();
                if (hrtime(true)/1e9>=$deadline) { throw new RuntimeException('Checksum exceeded the '.$seconds.'-second limit: '.basename($path)); }
                $bytes=fread($fp,262144); if ($bytes===false || ($bytes==='' && !feof($fp))) { throw new RuntimeException('Checksum read failed: '.basename($path)); }
                hash_update($hash,$bytes); $read+=strlen($bytes); if ($progress) { $progress($read); }
            }
            return hash_final($hash);
        } finally { fclose($fp); }
    }

    public static function verify_file(string $path, string $expected, ?array &$receipt): bool {
        clearstatcache(true, $path); $stat = @stat($path);
        if (!$stat || !is_file($path)) { return false; }
        $stamp = array_intersect_key($stat, array_flip(['size','mtime','ctime','ino','dev','mode']));
        if (($receipt['stamp'] ?? null) === $stamp && ($receipt['hash'] ?? null) === $expected) { return true; }
        $stable = max($stat['mtime'], $stat['ctime']) < time() - 2;
        $actual = self::hash_file($path);
        clearstatcache(true, $path); $after = @stat($path);
        if (!$after || $stamp !== array_intersect_key($after, array_flip(array_keys($stamp))) || !hash_equals($expected, $actual)) { return false; }
        // Only reuse a check made after timestamps settled; same-second writes must be rehashed.
        $receipt = $stable ? ['stamp'=>$stamp,'hash'=>$actual] : null;
        return true;
    }

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
        if (self::public_path($path)) { throw new RuntimeException('Staging storage must be outside the public web directory.'); }
        return $path;
    }

    public static function public_path(string $path): bool {
        $roots = array_filter([realpath(ABSPATH), !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false]);
        // Inspect ancestors too: a symlink beneath a public directory can expose an outside target.
        while (true) {
            $real = realpath($path);
            foreach ($roots as $root) { if ($real && str_starts_with($real . '/', rtrim($root, '/') . '/')) { return true; } }
            $parent = dirname($path);
            if ($parent === $path) { return false; }
            $path = $parent;
        }
    }

    public static function job(string $id): array { return CSM_Jobs::get($id); }

    public static function backup_dir(): string {
        $home = getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '');
        if ($home && is_dir($home) && is_writable($home)) {
            $path = rtrim($home, '/\\') . '/.trickle-backups/' . substr(hash_hmac('sha256', ABSPATH, wp_salt('auth')), 0, 24);
            if (is_dir($path) || @mkdir($path, 0700, true)) {
                $real = realpath($path);
                if ($real && !is_link($path) && is_writable($path) && !self::public_path($path)) { chmod($path, 0700); return $path; }
            }
        }
        return self::private_dir();
    }

    // Compatibility for integrations using the original internal helpers.
    public static function save_transactional(array $job): void { CSM_Jobs::save_transactional($job); }
    public static function save(array $job): void { CSM_Jobs::save($job); }
    public static function prune_job_records(): void { CSM_Jobs::prune(); }

    public static function lock(): string {
        $id = bin2hex(random_bytes(16));
        $fp = fopen(self::private_dir() . '/transfer.lock', 'c');
        if (!$fp) { throw new RuntimeException('Cannot open transfer lock.'); }
        if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); throw new CSM_Busy_Error('Another transfer is running on this site.'); }
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
        @set_time_limit(50);
        global $wpdb;
        if (isset($wpdb) && method_exists($wpdb,'db_server_info')) {
            $previous=$wpdb->suppress_errors(true);
            $wpdb->query('SET SESSION innodb_lock_wait_timeout=10');
            $wpdb->query('SET SESSION lock_wait_timeout=10');
            $wpdb->query(stripos($wpdb->db_server_info(),'MariaDB')!==false?'SET SESSION max_statement_time=10':'SET SESSION max_execution_time=10000');
            $wpdb->suppress_errors($previous);
        }
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
