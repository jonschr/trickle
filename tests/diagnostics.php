<?php
// Run only on the isolated QA-C site: cove wp trickle-qa-c eval-file tests/diagnostics.php
if (wp_parse_url(get_option('siteurl'), PHP_URL_HOST) !== 'trickle-qa-c.localhost') { throw new RuntimeException('Diagnostics checks require isolated QA-C.'); }
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); }
    $checks++; echo "PASS $message\n";
};
$dir = CSM_Support::private_dir();
$saved = [];
foreach (['diagnostics.log', 'diagnostics.log.1'] as $name) { $saved[$name] = is_file($dir . '/' . $name) ? file_get_contents($dir . '/' . $name) : null; }
try {
    $secret = 'diagnostic-secret-' . bin2hex(random_bytes(16)); CSM_Log::secret($secret);
    $clean = CSM_Log::clean('Known ' . $secret . ' token=sample-token password="sample-password" {"api_key":"json-secret"} Bearer auth-value ' . str_repeat('a', 64));
    $check(!str_contains($clean, $secret) && !str_contains($clean, 'sample-token') && !str_contains($clean, 'sample-password') && !str_contains($clean, 'json-secret') && !str_contains($clean, 'auth-value') && !str_contains($clean, str_repeat('a', 64)), 'Known keys, tokens, JSON secrets and bearer credentials are redacted');
    $clean = CSM_Log::clean('https://user:password@example.com/path?key=private Response: private response content');
    $check(!str_contains($clean, 'password') && !str_contains($clean, 'key=') && !str_contains($clean, 'private response'), 'URL credentials, query strings and response bodies are omitted');
    $check(!str_contains(CSM_Log::clean('Database operation failed: Duplicate entry customer@example.com'), 'customer@example.com'), 'Database exception values are omitted');
    $check(!str_contains(CSM_Log::clean('Duplicate entry customer@example.com for key email'), 'customer@example.com'), 'Direct database errors omit customer values too');
    $check(!str_contains(CSM_Log::clean("Cookie: first=private; second=private\nX-CSM-Signature: private"), 'private'), 'Authentication headers are redacted in full');
    CSM_Log::write('error', 'Diagnostics self-test', ['operation' => 'connect', 'error' => 'token=private', 'pairing' => $secret, 'request_body' => $secret, 'headers' => $secret]);
    $rows = CSM_Log::entries(); $last = end($rows);
    $check($last['event'] === 'Diagnostics self-test' && !isset($last['context']['pairing'], $last['context']['request_body'], $last['context']['headers']) && !str_contains(json_encode($last), $secret), 'Structured context rejects request payloads and headers');
    $check((fileperms($dir . '/diagnostics.log') & 0777) === 0600 && !str_starts_with(realpath($dir) . '/', realpath(ABSPATH) . '/'), 'Log storage is private and outside the webroot');
    $check(CSM_Transport::ca_bundle('https://example.com') === ABSPATH . WPINC . '/certificates/ca-bundle.crt', 'Public hosts use WordPress public certificate authorities');
    $check(str_ends_with(CSM_Transport::ca_bundle('https://trickle-qa-a.localhost:8453'), '/local/root.crt'), 'Development certificate authority is scoped to localhost');
    $bad_ca = static fn() => ABSPATH . WPINC . '/certificates/ca-bundle.crt';
    add_filter('csm_ca_bundle', $bad_ca, 999);
    $rejected = false;
    try { CSM_Transport::request(['url' => 'https://trickle-qa-a.localhost:8453', 'key' => str_repeat('b', 64)], 'manifest'); }
    catch (Throwable $error) { $rejected = str_contains($error->getMessage(), 'certificate'); }
    finally { remove_filter('csm_ca_bundle', $bad_ca, 999); }
    $rows = CSM_Log::entries(); $last = end($rows);
    $check($rejected && $last['context']['curl_errno'] === 60 && $last['context']['ssl_verify_result'] !== 0 && !str_contains(json_encode($rows), str_repeat('b', 64)), 'Untrusted TLS is still rejected and its verification error is logged without credentials');
    CSM_Support::lock();
    try { $check(is_array(CSM_Controller::execute('logs')['entries']), 'Backend log reads work while the transfer mutation lock is held'); }
    finally { CSM_Support::unlock(); }
    file_put_contents($dir . '/diagnostics.log', str_repeat('x', 512 * 1024));
    CSM_Log::write('info', 'Rotation self-test');
    $check(is_file($dir . '/diagnostics.log.1') && filesize($dir . '/diagnostics.log') < 512 * 1024, 'Logs rotate at the storage limit');
    for ($i = 0; $i < 205; $i++) { CSM_Log::write('info', 'Bounded history self-test'); }
    $check(count(CSM_Log::entries()) === 200, 'Backend returns a bounded recent history');
    $lock = fopen($dir . '/diagnostics.lock', 'c'); flock($lock, LOCK_EX);
    CSM_Log::write('info', 'Contended log lock self-test'); flock($lock, LOCK_UN); fclose($lock);
    $check(true, 'A contended diagnostic log cannot block a transfer');
} finally {
    foreach ($saved as $name => $contents) {
        $path = $dir . '/' . $name;
        if ($contents === null) { if (is_file($path)) { unlink($path); } }
        else { file_put_contents($path, $contents); chmod($path, 0600); }
    }
}
echo "$checks diagnostics checks passed.\n";
