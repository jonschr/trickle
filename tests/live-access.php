<?php
// New native endpoints use the existing read/write scopes; no WordPress or network.
define('ABSPATH','/fixture/');$settings=['pull'=>true,'push'=>true];
function get_option($name,$default=false){return $name==='_csm_settings'?$GLOBALS['settings']:$default;}
require __DIR__.'/../includes/class-csm-access.php';
$tokens=['read'=>['id'=>'read','read'=>true,'write'=>false],'write'=>['id'=>'write','read'=>false,'write'=>true],'both'=>['id'=>'both','read'=>true,'write'=>true]];
foreach(['live_files'=>['read','both'],'live_receive'=>['write','both'],'live_state'=>['read','write','both']]as$action=>$allowed){
 foreach($tokens as$name=>$token){$ok=true;try{CSM_Access::authorize($token,$action);}catch(RuntimeException$e){$ok=false;}if($ok!==in_array($name,$allowed,true))throw new RuntimeException('Scope mismatch: '.$action.' '.$name);}
 echo 'PASS '.$action.' preserves existing connection read/write scopes'.PHP_EOL;
}
$settings=['pull'=>false,'push'=>false];foreach(['live_files','live_receive','live_state']as$action){$blocked=false;try{CSM_Access::authorize($tokens['both'],$action);}catch(RuntimeException$e){$blocked=true;}if(!$blocked)throw new RuntimeException('Disabled access bypass');}
echo 'PASS Disabled site access blocks streaming endpoints'.PHP_EOL;
