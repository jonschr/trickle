<?php
defined('ABSPATH') || exit;

class CSM_Network_Error extends RuntimeException {
    public int $retry_after = 0;
    public bool $exhausted = false;
}

final class CSM_Busy_Error extends CSM_Network_Error {
    public int $retry_after = 10;
}

final class CSM_Transport {
    private static bool $retrying = false;
    private static ?float $retry_deadline = null;

    public static function retry(callable $operation): array {
        if (self::$retrying || (defined('CSM_STOP_ON_ERROR') && CSM_STOP_ON_ERROR)) { return $operation(); }
        self::$retrying = true; self::$retry_deadline=hrtime(true)/1e9+50;
        try {
            for ($attempt = 0, $busy = 0; ; ) {
                try { return $operation(); }
                catch (CSM_Network_Error $e) {
                    $wait = $e instanceof CSM_Busy_Error ? max(1, $e->retry_after) : max($e->retry_after, $e->retry_after ? 30 * 2 ** $attempt : 2 ** $attempt);
                    $exhausted = $e instanceof CSM_Busy_Error ? $busy++ >= 1 : $attempt++ >= 2;
                    if ($e->exhausted || $exhausted || $wait > 5 || hrtime(true)/1e9+$wait>=self::$retry_deadline) { $e->retry_after = max($e->retry_after, $wait); $e->exhausted = true; throw $e; }
                    CSM_Log::write('info', 'Connection interrupted; retrying safe transfer step', ['error'=>$e->getMessage(), 'duration_ms'=>$wait * 1000]);
                    sleep($wait);
                }
            }
        } finally { self::$retrying = false; self::$retry_deadline=null; }
    }

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

    public static function signed_credential(string $action): array {
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
        CSM_Access::authorize($credential, $action);
        return $credential;
    }

    public static function authenticate(string $action): array {
        self::signed_credential($action);
        $nonce = $_SERVER['HTTP_X_CSM_NONCE'];
        $hash = $_SERVER['HTTP_X_CSM_HASH'];
        $params = $_SERVER['HTTP_X_CSM_PARAMS'];
        $nonce_key = '_csm_nonce_' . $nonce;
        if (!add_option($nonce_key, time(), '', false)) { throw new RuntimeException('Request was already used.', 403); }
        $params = json_decode(base64_decode($params, true), true, 64, JSON_THROW_ON_ERROR);
        if (in_array($action, ['prepare', 'bundle', 'bundle_chunk', 'bundle_stream', 'component_manifest', 'media_manifest', 'export', 'receive', 'stream_data', 'segment_state', 'segment_data', 'commit'], true)) { CSM_Updates::assert_version($params['trickle_version'] ?? null); }
        if (in_array($action, ['receive', 'stream_data', 'segment_data'], true)) {
            if ($hash !== 'stream') { throw new RuntimeException('Expected a stream upload.'); }
            return $params;
        }
        $body = file_get_contents('php://input', false, null, 0, 1048577);
        if (strlen($body) > 1048576 || !hash_equals(hash('sha256', $body), $hash)) { throw new RuntimeException('Request body checksum mismatch.', 403); }
        return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    }

    public static function health(string $url, string $probe = '', string $transfer_id = ''): array {
        $url = add_query_arg('trickle_health', bin2hex(random_bytes(8)), self::url($url));
        $ch = curl_init($url); $ca = self::ca_bundle($url);
        $options = [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'User-Agent: ' . (string)apply_filters('csm_user_agent', 'Trickle/' . CSM_VERSION . ' health check')]];
        if ($ca) { $options[CURLOPT_CAINFO] = $ca; }
        if ($probe !== '') { $options[CURLOPT_HTTPHEADER][] = 'X-Trickle-Health: ' . $probe; }
        curl_setopt_array($ch, $options); $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = CSM_Log::clean(curl_error($ch));
        $context = ['operation'=>'health', 'transfer_id'=>$transfer_id ?: null, 'site'=>$url, 'http_status'=>$status, 'curl_errno'=>curl_errno($ch),
            'ssl_verify_result'=>curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT), 'duration_ms'=>(int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME)*1000),
            'ca_source'=>$ca === ABSPATH . WPINC . '/certificates/ca-bundle.crt' ? 'WordPress CA bundle' : ($ca ? 'Custom CA bundle (' . basename($ca) . ')' : 'System CA bundle')];
        if ($error !== '') { $context['error'] = $error; }
        CSM_Log::write($ok === false || $status >= 500 ? 'error' : 'info', 'Destination health check completed', $context);
        return ['status' => $status, 'verified' => $ok !== false && $status >= 200 && $status < 300, 'error'=>$error];
    }

    public static function request(array $peer, string $action, array $data = [], ?Generator $upload = null, $download = null, int $timeout = 0, int $download_limit = 0): array {
        if (!$timeout) { $timeout = ($upload || $download) ? 50 : ($action==='bundle'?55:45); }
        $request = static fn()=>self::send($peer, $action, $data, $upload, $download, $timeout, $download_limit);
        return !$upload && !$download && in_array($action, ['manifest','version','file_policy','component_hashes','media_hashes','status','segment_state'], true) ? self::retry($request) : $request();
    }

    private static function send(array $peer, string $action, array $data, ?Generator $upload, $download, int $timeout, int $download_limit): array {
        if (self::$retry_deadline!==null) { $left=(int)ceil(self::$retry_deadline-hrtime(true)/1e9); if ($left<1) { $error=new CSM_Network_Error('Transfer step reached its request limit. Resume from its saved checkpoint.'); $error->exhausted=true; throw $error; } $timeout=min($timeout,$left); }
        if (!extension_loaded('curl')) { throw new RuntimeException('The PHP cURL extension is required.'); }
        CSM_Log::secret($peer['key']);
        $url = add_query_arg('trickle', $action, self::url($peer['url']));
        $cooldown = '_csm_cooldown_' . substr(hash('sha256', wp_parse_url($peer['url'], PHP_URL_HOST)), 0, 20);
        $remaining = (int)get_option($cooldown, 0) - time();
        if ($remaining > 0) { $e = new CSM_Network_Error('Remote server requested a pause. Retry in ' . $remaining . ' seconds.'); $e->retry_after = $remaining; throw $e; }
        $ch = curl_init($url); $response = ''; $status = 0; $bytes = 0; $retry_after = 0;
        $download_offset = $download ? ftell($download) : 0;
        $body = $upload ? '' : CSM_Support::json($data);
        $headers = self::headers($action, $peer['key'], $upload ? 'stream' : hash('sha256', $body), $upload ? $data : []);
        $headers[] = 'Content-Type: ' . ($upload ? 'application/octet-stream' : 'application/json');
        $headers[] = 'Accept: ' . ($download ? 'application/octet-stream' : 'application/json');
        $headers[] = 'Cache-Control: no-store';
        $headers[] = 'User-Agent: ' . (string)apply_filters('csm_user_agent', 'Trickle/' . CSM_VERSION . ' (WordPress site migration)');
        $options = [CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$status, &$retry_after) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int)$m[1]; $retry_after = 0; }
                if (stripos($line, 'Retry-After:') === 0) { $value = trim(substr($line, 12)); $retry_after = ctype_digit($value) ? (int)$value : max(0, (strtotime($value) ?: time()) - time()); }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use ($action, $download, $download_limit, $download_offset, &$response, &$bytes, &$status) {
                if ($download && $status === 200) {
                    if ($download_limit && $bytes + strlen($chunk) > $download_limit) { throw new RuntimeException('Downloaded stream exceeds its declared size limit.'); }
                    CSM_Support::write($download, $chunk); $bytes += strlen($chunk); if (!in_array($action,['component_manifest','media_manifest'],true)) { CSM_Progress::update(['bytes' => $download_offset + $bytes, 'count'=>$download_offset + $bytes]); }
                }
                elseif (strlen($response) < 1048576) { $response .= $chunk; }
                return strlen($chunk);
            },
        ];
        $options[CURLOPT_NOPROGRESS] = false;
        $options[CURLOPT_XFERINFOFUNCTION] = static function () use ($action) { if (!in_array($action,['cancel','bundle_discard','rollback','status','progress'],true)) { CSM_Support::check_cancel(); } return 0; };
        // A retained snapshot should respond immediately; a stalled body can safely resume.
        if ($download && $action === 'bundle_stream') { $options[CURLOPT_LOW_SPEED_LIMIT] = 1; $options[CURLOPT_LOW_SPEED_TIME] = 30; }
        $ca = self::ca_bundle($peer['url']);
        if ($ca) { $options[CURLOPT_CAINFO] = $ca; }
        $context = ['operation' => $action, 'stream_kind'=>$data['kind'] ?? null,'offset'=>$data['offset'] ?? null,'length'=>$data['length'] ?? null,'snapshot_size'=>$data['bundle']['size'] ?? null,'timeout_seconds'=>$timeout, 'site' => $peer['url'], 'transfer_id' => $data['id'] ?? null,
            'ca_source' => $ca === ABSPATH . WPINC . '/certificates/ca-bundle.crt' ? 'WordPress CA bundle' : ($ca ? 'Custom CA bundle (' . basename($ca) . ')' : 'System CA bundle')];
        if ($action==='media_manifest') { $context['component']=($data['year'] ?? '').'/'.($data['month'] ?? ''); }
        if (!in_array($action, ['progress', 'status'], true)) { CSM_Log::write('info', 'Outgoing request started', $context); }
        $upload_error = null;
        if ($upload) {
            $buffer = ''; $initialized = false;
            $options[CURLOPT_UPLOAD] = true;
            if ($action === 'stream_data' && isset($data['length'])) { $options[CURLOPT_INFILESIZE] = $data['length']; }
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
        if ($retry_after) { $context['retry_after'] = $retry_after; }
        do_action('csm_request_complete', $action, $context, $bytes);
        unset($options, $ch);
        if ($action === 'bundle' && ($ok === false || in_array($status, [502,503,504], true)) && !empty($data['id'])) {
            // Last observed work is evidence of progress, not proof that this file caused the error.
            try {
                $last = self::request($peer, 'progress', ['id'=>$data['id']], null, null, 5);
                CSM_Log::write('error', 'Last observed source activity after packaging failure', $context +
                    array_intersect_key($last, array_flip(['run','phase','component','count','total','unit'])) +
                    ['request_run'=>$data['progress_run'] ?? null, 'progress_matches_request'=>isset($data['progress_run']) && ($last['run'] ?? null) === $data['progress_run'],
                     'progress_age_seconds'=>isset($last['updated']) ? max(0, round(microtime(true)-$last['updated'], 1)) : null]);
            } catch (Throwable $probe) { CSM_Log::write('error', 'Source activity unavailable after packaging failure', $context + ['error'=>$probe->getMessage()]); }
        }
        if ($upload_error) { CSM_Log::write('error', 'Source stream failed', $context + ['error' => $upload_error->getMessage()]); throw $upload_error; }
        if ($ok === false) {
            CSM_Log::write('error', 'Outgoing connection failed', $context + ['error' => $error]);
            $class = in_array($context['curl_errno'], [7,18,28,35,52,55,56,92], true) ? CSM_Network_Error::class : RuntimeException::class;
            throw new $class('Connection failed: ' . $error . (($context['curl_errno'] === 60) ? '. The sending site could not verify the remote certificate chain. Check its CA bundle; TLS verification is enabled. See Diagnostics below.' : ''));
        }
        if ($download && $status === 200) { CSM_Log::write('info', 'Stream downloaded', $context + ['bytes' => $bytes]); return ['bytes' => $bytes]; }
        $parsed = json_decode($response, true);
        if ($status !== 200 || !is_array($parsed) || empty($parsed['ok'])) {
            CSM_Log::write('error', 'Remote request rejected', $context + ['error' => $parsed['error'] ?? 'Unexpected or non-JSON response; response body omitted.']);
            $message = $parsed['error'] ?? ('Endpoint returned HTTP ' . $status . '. Check firewall rules, redirects and upload/time limits.');
            // Older sources returned an unclassified lock error while a disconnected build kept running.
            $busy = $status !== 429 && (!empty($parsed['busy']) || ($action === 'bundle' && in_array($message, ['Another transfer is running on this site.', 'Source stream is still being built. Retry preparation shortly.'], true)));
            if ($busy || !empty($parsed['retryable']) || in_array($status, [429,502,503,504], true)) {
                $e = $busy ? new CSM_Busy_Error($message) : new CSM_Network_Error($message);
                $e->retry_after = max($e->retry_after, $retry_after, (int)($parsed['retry_after'] ?? 0), $status === 429 ? 30 : 0);
                if ($retry_after > 0 || (!$busy && $e->retry_after > 0)) { update_option($cooldown, time() + $e->retry_after, false); }
                throw $e;
            }
            throw new RuntimeException($message);
        }
        if (!in_array($action, ['progress', 'status'], true)) { CSM_Log::write('info', 'Outgoing request completed', $context); }
        return $parsed['data'] ?? [];
    }
}
