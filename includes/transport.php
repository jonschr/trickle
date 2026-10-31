<?php
defined('ABSPATH') || exit;

final class CSM_Transport {
    public static function ca_bundle(string $url): string {
        return (string)apply_filters('csm_ca_bundle', defined('CSM_CA_BUNDLE') ? CSM_CA_BUNDLE : ABSPATH . WPINC . '/certificates/ca-bundle.crt', $url);
    }

    public static function url(string $url): string {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) { throw new RuntimeException('Use the WordPress installation URL without credentials, query strings or fragments.'); }
        $local = in_array($parts['host'], ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($parts['host'], '.localhost');
        if (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $local)) { throw new RuntimeException('HTTPS is required outside localhost.'); }
        return rtrim($url, '/');
    }

    public static function headers(string $action, string $key, string $bodyhash, array $params = []): array {
        $time = (string)time(); $nonce = bin2hex(random_bytes(16));
        $params['trickle_version'] = CSM_VERSION;
        $params = base64_encode(CSM_Support::json($params));
        $signature = hash_hmac('sha256', implode("\n", [$action, $time, $nonce, $bodyhash, $params]), $key);
        return ['X-CSM-Key-Id: ' . CSM_Access::id($key), 'X-CSM-Time: ' . $time, 'X-CSM-Nonce: ' . $nonce, 'X-CSM-Hash: ' . $bodyhash, 'X-CSM-Params: ' . $params, 'X-CSM-Signature: ' . $signature, 'Expect:'];
    }

    public static function authenticate(string $action): array {
        $credential = CSM_Access::credential($_SERVER['HTTP_X_CSM_KEY_ID'] ?? '');
        $key = $credential['key'];
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
        CSM_Access::authorize($credential, $action);
        $params = json_decode(base64_decode($params, true), true, 64, JSON_THROW_ON_ERROR);
        if (in_array($action, ['prepare', 'bundle', 'bundle_chunk', 'export', 'receive', 'segment_state', 'segment_data', 'commit'], true)) { CSM_Updates::assert_version($params['trickle_version'] ?? null); }
        if (in_array($action, ['receive', 'segment_data'], true)) {
            if ($hash !== 'stream') { throw new RuntimeException('Expected a stream upload.'); }
            return $params;
        }
        $body = file_get_contents('php://input', false, null, 0, 1048577);
        if (strlen($body) > 1048576 || !hash_equals(hash('sha256', $body), $hash)) { throw new RuntimeException('Request body checksum mismatch.', 403); }
        return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    }

    public static function health(string $url, string $probe = ''): array {
        $url = add_query_arg('trickle_health', bin2hex(random_bytes(8)), self::url($url));
        $ch = curl_init($url); $ca = self::ca_bundle($url);
        $options = [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'User-Agent: Trickle/' . CSM_VERSION . ' health check']];
        if ($ca) { $options[CURLOPT_CAINFO] = $ca; }
        if ($probe !== '') { $options[CURLOPT_HTTPHEADER][] = 'X-Trickle-Health: ' . $probe; }
        curl_setopt_array($ch, $options); $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'verified' => $ok !== false && $status >= 200 && $status < 300];
    }

    public static function request(array $peer, string $action, array $data = [], ?Generator $upload = null, $download = null, int $timeout = 0): array {
        if (!extension_loaded('curl')) { throw new RuntimeException('The PHP cURL extension is required.'); }
        CSM_Log::secret($peer['key']);
        $url = add_query_arg('trickle', $action, self::url($peer['url']));
        $ch = curl_init($url); $response = ''; $status = 0; $bytes = 0;
        $body = $upload ? '' : CSM_Support::json($data);
        $headers = self::headers($action, $peer['key'], $upload ? 'stream' : hash('sha256', $body), $upload ? $data : []);
        $headers[] = 'Content-Type: ' . ($upload ? 'application/octet-stream' : 'application/json');
        $headers[] = 'Accept: ' . ($download ? 'application/octet-stream' : 'application/json');
        $headers[] = 'Cache-Control: no-store';
        $headers[] = 'User-Agent: Trickle/' . CSM_VERSION . ' (WordPress site migration)';
        $options = [CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => $timeout ?: 30, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$status) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int)$m[1]; } return strlen($line); },
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use ($download, &$response, &$bytes, &$status) {
                if ($download && $status === 200) { CSM_Support::write($download, $chunk); $bytes += strlen($chunk); CSM_Progress::update(['bytes' => $bytes]); }
                elseif (strlen($response) < 1048576) { $response .= $chunk; }
                return strlen($chunk);
            },
        ];
        $ca = self::ca_bundle($peer['url']);
        if ($ca) { $options[CURLOPT_CAINFO] = $ca; }
        $context = ['operation' => $action, 'site' => $peer['url'], 'transfer_id' => $data['id'] ?? null,
            'ca_source' => $ca === ABSPATH . WPINC . '/certificates/ca-bundle.crt' ? 'WordPress CA bundle' : ($ca ? 'Custom CA bundle (' . basename($ca) . ')' : 'System CA bundle')];
        if (!in_array($action, ['progress', 'status'], true)) { CSM_Log::write('info', 'Outgoing request started', $context); }
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
        $context += ['http_status' => $status, 'curl_errno' => curl_errno($ch), 'ssl_verify_result' => curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT), 'duration_ms' => (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000)];
        unset($options, $ch);
        if ($upload_error) { CSM_Log::write('error', 'Source stream failed', $context + ['error' => $upload_error->getMessage()]); throw $upload_error; }
        if ($ok === false) {
            CSM_Log::write('error', 'Outgoing connection failed', $context + ['error' => $error]);
            throw new RuntimeException('Connection failed: ' . $error . (($context['curl_errno'] === 60) ? '. The sending site could not verify the remote certificate chain. Check its CA bundle; TLS verification is enabled. See Diagnostics below.' : ''));
        }
        if ($download && $status === 200) { CSM_Log::write('info', 'Stream downloaded', $context + ['bytes' => $bytes]); return ['bytes' => $bytes]; }
        $parsed = json_decode($response, true);
        if ($status !== 200 || !is_array($parsed) || empty($parsed['ok'])) {
            CSM_Log::write('error', 'Remote request rejected', $context + ['error' => $parsed['error'] ?? 'Unexpected or non-JSON response; response body omitted.']);
            throw new RuntimeException($parsed['error'] ?? ('Endpoint returned HTTP ' . $status . '. Check firewall rules, redirects and upload/time limits. Response: ' . substr(wp_strip_all_tags($response), 0, 240)));
        }
        if (!in_array($action, ['progress', 'status'], true)) { CSM_Log::write('info', 'Outgoing request completed', $context); }
        return $parsed['data'] ?? [];
    }
}
