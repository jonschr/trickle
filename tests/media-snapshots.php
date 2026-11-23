<?php
// Real media inventory cache files; no WordPress, network, or site writes.
define('ABSPATH','/fixture/');
$fixture=sys_get_temp_dir().'/test-trickle-media-snapshots-'.bin2hex(random_bytes(6));mkdir($fixture,0700);
class CSM_Support {
    static function private_dir(){return $GLOBALS['fixture'];}
    static function json($value){return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";}
}
class CSM_Files {static function root($kind){return '/fixture/uploads';}}
require __DIR__.'/../includes/class-csm-media.php';
$token=hash('sha256',CSM_Support::json([]));
$index=['comparison'=>'size_mtime','years'=>[],'token'=>$token];
$snapshot=$fixture.'/media-snapshot-'.$token.'.json';
$cache=$fixture.'/media-hashes-'.substr(hash('sha256','/fixture/uploads'),0,16).'.json';
function check($value,$label){if(!$value)throw new LogicException($label);echo 'PASS '.$label."\n";}
function expired($token,$label){
    try{CSM_Media::inventory(['level'=>'months','token'=>$token,'years'=>[]]);}
    catch(RuntimeException $error){check(str_contains($error->getMessage(),'changed or expired'),$label);return;}
    throw new LogicException('Expected a controlled expired-inventory exception: '.$label);
}
try{
    expired($token,'Missing pinned snapshot and cache return a controlled expiry');
    expired('','An empty token returns a controlled expiry');
    file_put_contents($cache,CSM_Support::json($index));
    $result=CSM_Media::inventory(['level'=>'months','token'=>$token,'years'=>[]]);
    check($result===['items'=>[]],'Matching current cache recovers a missing pinned snapshot');
    file_put_contents($snapshot,CSM_Support::json($index));
    file_put_contents($cache,'null');
    check(CSM_Media::inventory(['level'=>'months','token'=>$token,'years'=>[]])===['items'=>[]],'Pinned snapshot takes precedence over an unrelated cache');
    unlink($snapshot);
    foreach(['null','{broken',CSM_Support::json(array_replace($index,['token'=>str_repeat('a',64)])),CSM_Support::json(array_replace($index,['years'=>['tampered'=>[]]]))] as $raw){
        file_put_contents($cache,$raw);expired($token,'Malformed, mismatched or tampered fallback is rejected');
    }
}finally{foreach(glob($fixture.'/*')as$file)unlink($file);rmdir($fixture);}
