<?php
// Real update authorization and capabilities; no network, WordPress or file writes.
define('ABSPATH', '/fixture/');define('CSM_FILE',dirname(__DIR__).'/trickle.php');
require __DIR__.'/../includes/files.php';
$settings=[];
function get_option($key,$default=false){return $key==='_csm_settings'?$GLOBALS['settings']:$default;}
require __DIR__.'/../includes/access.php';require __DIR__.'/../includes/updates.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
foreach([[],['pull'=>false,'push'=>false],['pull'=>false,'push'=>false,'updates'=>false]] as $settings){
    foreach([['master'=>true,'read'=>true,'write'=>true],['read'=>true,'write'=>true],['read'=>false,'write'=>true]] as $key){
        foreach(['version','update_info','build_info','update_trickle','update_trickle_build'] as $action)CSM_Access::authorize($key,$action);
        $cap=CSM_Updates::capabilities();check($cap['remote_updates_allowed']&&$cap['can_update_trickle'],'Write credential permits updates without the old toggle or migration permissions');
    }
    $read=['read'=>true,'write'=>false];$denied=false;try{CSM_Access::authorize($read,'update_trickle');}catch(RuntimeException $e){$denied=$e->getCode()===403;}
    check($denied,'Read-only credential cannot install code');
    $denied=false;try{CSM_Access::authorize($read,'update_trickle_build');}catch(RuntimeException $e){$denied=$e->getCode()===403;}check($denied,'Read-only credential cannot install a local build');
    $GLOBALS['_csm_credential']=$read;check(!CSM_Updates::capabilities()['can_update_trickle'],'Read-only inventory does not advertise update capability');
}
$settings=['pull'=>false,'push'=>false];$denied=false;try{CSM_Access::authorize(['read'=>true,'write'=>true],'prepare');}catch(RuntimeException $e){$denied=$e->getCode()===403;}
check($denied,'Always-available plugin updates do not grant migration writes');
