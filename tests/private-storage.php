<?php
// Actual filesystem permissions, repeated access and replacement by a symlink; no WordPress.
define('ABSPATH','/trickle-private-fixture-'.bin2hex(random_bytes(8)).'/');
function wp_salt($kind){return 'private-storage-fixture';}
require __DIR__.'/../includes/support.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$path=CSM_Support::private_dir();$outside=$path.'-outside';
try{
    clearstatcache(true,$path);check((fileperms($path)&07777)===0700,'Private staging is created with owner-only permissions');
    $ctime=filectime($path);usleep(1100000);
    for($i=0;$i<100;++$i){if(CSM_Support::private_dir()!==$path)throw new RuntimeException('Staging path changed');}
    clearstatcache(true,$path);check(filectime($path)===$ctime,'Repeated staging lookups leave directory metadata unchanged');
    chmod($path,0755);CSM_Support::private_dir();clearstatcache(true,$path);
    check((fileperms($path)&07777)===0700,'Changed permissions are repaired on the next lookup');
    rmdir($path);mkdir($outside,0700);symlink($outside,$path);$blocked=false;
    try{CSM_Support::private_dir();}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'Unsafe staging');}
    check($blocked,'Replacing the staging directory with a symlink is rejected');
}finally{if(is_link($path))unlink($path);elseif(is_dir($path))rmdir($path);if(is_dir($outside))rmdir($outside);}

// Deterministically let a competing request create the path immediately before mkdir returns false.
eval('namespace TricklePrivateRace; use \\RuntimeException; use \\Generator;
function sys_get_temp_dir(){return $GLOBALS["private_race_root"];}
function mkdir($path,$mode,$recursive){
    if($GLOBALS["private_race_kind"]==="directory") { \\mkdir($path,$mode,$recursive); }
    elseif($GLOBALS["private_race_kind"]==="symlink") { \\symlink($GLOBALS["private_race_outside"],$path); }
    return false;
}
'.str_replace("require_once __DIR__ . '/timing.php';",'',substr(file_get_contents(__DIR__.'/../includes/support.php'),5)));
$GLOBALS['private_race_root']=$path.'-race';$GLOBALS['private_race_outside']=$outside;
mkdir($GLOBALS['private_race_root'],0700);mkdir($outside,0700);
$racePath=$GLOBALS['private_race_root'].'/'.basename($path);
try{
    $GLOBALS['private_race_kind']='directory';
    check(TricklePrivateRace\CSM_Support::private_dir()===$racePath,'Concurrent private directory creation is accepted');
    clearstatcache(true,$racePath);check((fileperms($racePath)&07777)===0700,'Concurrent creation retains owner-only permissions');rmdir($racePath);
    foreach(['symlink','failure']as$kind){
        $GLOBALS['private_race_kind']=$kind;$blocked=false;
        try{TricklePrivateRace\CSM_Support::private_dir();}catch(RuntimeException $e){$blocked=true;}
        check($blocked,'Concurrent '.$kind.' is still rejected');if(is_link($racePath))unlink($racePath);
    }
}finally{if(is_link($racePath))unlink($racePath);elseif(is_dir($racePath))rmdir($racePath);rmdir($GLOBALS['private_race_root']);rmdir($outside);}
