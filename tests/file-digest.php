<?php
// Bounded file hashing, including unavailable/failed acceleration and growth after the initial stat.
define('ABSPATH',__DIR__);
$mode=$argv[1] ?? '';$provider_calls=0;
if (in_array($mode,['false','throw'],true)) {
    if (function_exists('openssl_digest')) { throw new RuntimeException('Disable openssl_digest for provider failure fixtures.'); }
    function openssl_digest($bytes,$algorithm,$binary) {
        ++$GLOBALS['provider_calls'];
        if ($GLOBALS['mode']==='throw') { throw new RuntimeException('Provider unavailable'); }
        return false;
    }
}
function apply_filters($name,$value){return $value;}
require __DIR__.'/../includes/support.php';require __DIR__.'/../includes/package.php';
$path=tempnam(sys_get_temp_dir(),'trickle-digest-');
try {
    foreach ([0,4095,4096,1048576,2097152,2097153] as $size) {
        $bytes=$size?random_bytes($size):'';file_put_contents($path,$bytes);
        if (CSM_Support::hash_file($path)!==hash('sha256',$bytes)) { throw new RuntimeException('File SHA-256 changed at size '.$size); }
    }
    file_put_contents($path,str_repeat('a',262144));$grew=false;
    $actual=CSM_Support::hash_file($path,static function()use($path,&$grew){if(!$grew){$grew=true;file_put_contents($path,str_repeat('b',2097152),FILE_APPEND);}});
    if ($actual!==hash_file('sha256',$path)) { throw new RuntimeException('Growing files must switch to bounded incremental hashing without losing bytes.'); }
    if(in_array($mode,['false','throw'],true)&&$provider_calls!==1){throw new RuntimeException('Failed provider must remain bypassed.');}
    echo "PASS Exact bounded file checksums, growing-file fallback and provider compatibility ($mode)\n";
} finally {unlink($path);}
