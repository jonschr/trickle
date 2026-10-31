<?php
defined('ABSPATH') || exit;

final class CSM_Log {
    private static array $secrets = [];

    public static function secret(string $value): void {
        if ($value !== '') { self::$secrets[] = $value; }
    }

    public static function clean(string $text): string {
        foreach (array_merge(self::$secrets, [(string)get_option('_csm_key', '')]) as $secret) {
            if ($secret !== '') { $text = str_replace($secret, '[redacted]', $text); }
        }
        // Error bodies and SQL values can contain credentials or customer data.
        $text = preg_replace('/\s*Response:.*$/s', ' [response body omitted]', $text);
        $text = preg_replace('/Database operation failed:.*$/s', 'Database operation failed; database details omitted.', $text);
        $text = preg_replace('/\b(Duplicate entry|Data too long for column|Incorrect .+? value|Truncated incorrect .+? value).*$/is', 'Database value error; details omitted.', $text);
        $text = preg_replace('/\b[a-f0-9]{64}\b/i', '[redacted]', $text);
        $text = preg_replace('/\b(Bearer\s+)\S+/i', '$1[redacted]', $text);
        $text = preg_replace('/\b(authorization|cookie|set-cookie|x-csm-[a-z-]+)\s*:[^\r\n]*/i', '$1: [redacted]', $text);
        $text = preg_replace('/\b(password|secret|token|api[_ -]?key|signature|nonce|authorization|cookie)\b["\']?\s*[:=]\s*("[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '$1=[redacted]', $text);
        $text = preg_replace_callback('#https?://[^\s<>]+#i', static function ($match) {
            $parts = wp_parse_url($match[0]);
            return $parts && !empty($parts['host']) ? ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '') : '[URL omitted]';
        }, $text);
        return wp_check_invalid_utf8(substr(wp_strip_all_tags($text), 0, 1200), true);
    }

    public static function write(string $level, string $event, array $context = []): void {
        try {
            $allowed = ['operation', 'site', 'transfer_id', 'direction', 'phase', 'component', 'http_status', 'curl_errno', 'ssl_verify_result', 'duration_ms', 'retry_after', 'ca_source', 'error', 'tables', 'files', 'bytes'];
            $context = array_intersect_key($context, array_flip($allowed));
            foreach ($context as $key => $value) { $context[$key] = is_string($value) ? self::clean($value) : (is_scalar($value) || $value === null ? $value : '[omitted]'); }
            $entry = ['time' => gmdate('c'), 'level' => $level, 'event' => self::clean($event), 'context' => $context];
            $dir = CSM_Support::private_dir();
            $lock = @fopen($dir . '/diagnostics.lock', 'c');
            if (!$lock) { return; }
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) { return; }
                $path = $dir . '/diagnostics.log';
                clearstatcache(true, $path);
                if (is_file($path) && filesize($path) >= 512 * 1024 && !@rename($path, $path . '.1')) { return; }
                if (@file_put_contents($path, CSM_Support::json($entry), FILE_APPEND | LOCK_EX) !== false) { @chmod($path, 0600); }
            } finally { fclose($lock); }
        } catch (Throwable $e) { /* Diagnostics must never block a transfer or its recovery. */ }
    }

    public static function entries(): array {
        $dir = CSM_Support::private_dir(); $entries = [];
        foreach (['diagnostics.log.1', 'diagnostics.log'] as $name) {
            if (!is_file($dir . '/' . $name)) { continue; }
            foreach (file($dir . '/' . $name, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry)) { $entries[] = $entry; }
            }
        }
        return array_slice($entries, -200);
    }
}
