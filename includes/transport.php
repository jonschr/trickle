<?php
defined('ABSPATH') || exit;

final class CSM_Transport {
    public static function url(string $url): string {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) { throw new RuntimeException('Use the WordPress installation URL without credentials, query strings or fragments.'); }
        $local = in_array($parts['host'], ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($parts['host'], '.localhost');
        if (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $local)) { throw new RuntimeException('HTTPS is required outside localhost.'); }
        return rtrim($url, '/');
    }

    public static function headers(string $action, string $key, string $bodyhash, array $params = []): array {
        $time = (string)time(); $nonce = bin2hex(random_bytes(16));
        $params = base64_encode(CSM_Support::json($params));
        $signature = hash_hmac('sha256', implode("\n", [$action, $time, $nonce, $bodyhash, $params]), $key);
        return ['X-CSM-Time: ' . $time, 'X-CSM-Nonce: ' . $nonce, 'X-CSM-Hash: ' . $bodyhash, 'X-CSM-Params: ' . $params, 'X-CSM-Signature: ' . $signature, 'Expect:'];
    }

    public static function authenticate(string $action): array {
        $key = get_option('_csm_key');
        $time = $_SERVER['HTTP_X_CSM_TIME'] ?? ''; $nonce = $_SERVER['HTTP_X_CSM_NONCE'] ?? '';
        $hash = $_SERVER['HTTP_X_CSM_HASH'] ?? ''; $params = $_SERVER['HTTP_X_CSM_PARAMS'] ?? '';
        $signature = $_SERVER['HTTP_X_CSM_SIGNATURE'] ?? '';
        if (!$key || !ctype_digit($time) || abs(time() - (int)$time) > 300 || !preg_match('/^[a-f0-9]{32}$/D', $nonce) || strlen($params) > 4096) {
            throw new RuntimeException('Authentication failed. Check the connection key and both server clocks.', 403);
        }
        $expected = hash_hmac('sha256', implode("\n", [$action, $time, $nonce, $hash, $params]), $key);
        if (!hash_equals($expected, $signature)) { throw new RuntimeException('Authentication failed.', 403); }
        $nonce_key = '_csm_nonce_' . $nonce;
        if (!add_option($nonce_key, time(), '', false)) { throw new RuntimeException('Request was already used.', 403); }
        $settings = get_option('_csm_settings', ['pull' => true, 'push' => false]);
        if (in_array($action, ['export', 'manifest'], true) && empty($settings['pull'])) { throw new RuntimeException('Remote reads are disabled on this site.', 403); }
        if (!in_array($action, ['export', 'manifest'], true) && empty($settings['push'])) { throw new RuntimeException('Remote writes are disabled. Enable incoming writes on the destination.', 403); }
        $params = json_decode(base64_decode($params, true), true, 64, JSON_THROW_ON_ERROR);
        if ($action === 'receive') {
            if ($hash !== 'stream') { throw new RuntimeException('Expected a stream upload.'); }
            return $params;
        }
        $body = file_get_contents('php://input', false, null, 0, 1048577);
        if (strlen($body) > 1048576 || !hash_equals(hash('sha256', $body), $hash)) { throw new RuntimeException('Request body checksum mismatch.', 403); }
        return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    }

    public static function request(array $peer, string $action, array $data = [], ?Generator $upload = null, $download = null): array {
        if (!extension_loaded('curl')) { throw new RuntimeException('The PHP cURL extension is required.'); }
        $url = add_query_arg('trickle', $action, self::url($peer['url']));
        $ch = curl_init($url); $response = ''; $status = 0; $bytes = 0;
        $body = $upload ? '' : CSM_Support::json($data);
        $headers = self::headers($action, $peer['key'], $upload ? 'stream' : hash('sha256', $body), $upload ? $data : []);
        $headers[] = 'Content-Type: ' . ($upload ? 'application/octet-stream' : 'application/json');
        $headers[] = 'Accept: ' . ($download ? 'application/octet-stream' : 'application/json');
        $headers[] = 'Cache-Control: no-store';
        $headers[] = 'User-Agent: Trickle/' . CSM_VERSION . ' (WordPress site migration)';
        $options = [CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 0, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$status) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int)$m[1]; } return strlen($line); },
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use ($download, &$response, &$bytes, &$status) {
                if ($download && $status === 200) { CSM_Support::write($download, $chunk); $bytes += strlen($chunk); }
                elseif (strlen($response) < 1048576) { $response .= $chunk; }
                return strlen($chunk);
            },
        ];
        $ca = apply_filters('csm_ca_bundle', defined('CSM_CA_BUNDLE') ? CSM_CA_BUNDLE : '');
        if ($ca) { $options[CURLOPT_CAINFO] = $ca; }
        $upload_error = null;
        if ($upload) {
            $buffer = ''; $initialized = false;
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_CUSTOMREQUEST] = 'POST';
            $options[CURLOPT_READFUNCTION] = static function ($curl, $fp, $length) use ($upload, &$buffer, &$initialized, &$upload_error) {
                try {
                    while (strlen($buffer) < $length) {
                        if (!$initialized) { $upload->rewind(); $initialized = true; }
                        if (!$upload->valid()) { break; }
                        $buffer .= $upload->current(); $upload->next();
                    }
                    $out = substr($buffer, 0, $length); $buffer = substr($buffer, strlen($out)); return $out;
                } catch (Throwable $e) { $upload_error = $e; return CURL_READFUNC_ABORT; }
            };
        } else {
            $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch); $error = curl_error($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($options); curl_close($ch);
        if ($upload_error) { throw $upload_error; }
        if ($ok === false) { throw new RuntimeException('Connection failed: ' . $error); }
        if ($download && $status === 200) { return ['bytes' => $bytes]; }
        $parsed = json_decode($response, true);
        if ($status !== 200 || !is_array($parsed) || empty($parsed['ok'])) {
            throw new RuntimeException($parsed['error'] ?? ('Endpoint returned HTTP ' . $status . '. Check firewall rules, redirects and upload/time limits. Response: ' . substr(wp_strip_all_tags($response), 0, 240)));
        }
        return $parsed['data'] ?? [];
    }
}
