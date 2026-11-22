<?php
// Same wire bytes with OpenSSL, without it, and after a provider failure.
define('ABSPATH',__DIR__);
$mode=$argv[1] ?? '';
if (in_array($mode,['false','throw'],true)) {
    if (function_exists('openssl_digest')) { throw new RuntimeException('Run provider failure fixtures with openssl_digest disabled.'); }
    function openssl_digest($bytes,$algorithm,$binary) {
        ++$GLOBALS['provider_calls'];
        if ($GLOBALS['mode']==='throw') { throw new RuntimeException('Provider unavailable'); }
        return false;
    }
}
require __DIR__.'/../includes/package.php';
$provider_calls=0;
foreach (['',str_repeat('a',4095),str_repeat('b',4096),random_bytes(262144)] as $bytes) {
    foreach ([false,true] as $binary) {
        if (CSM_Package::digest($bytes,$binary)!==hash('sha256',$bytes,$binary)) { throw new RuntimeException('SHA-256 bytes changed.'); }
    }
}
if (in_array($mode,['false','throw'],true) && $provider_calls!==1) { throw new RuntimeException('Failed providers must be bypassed for the remainder of the request.'); }
echo "PASS SHA-256 compatibility and provider fallback ($mode)\n";
