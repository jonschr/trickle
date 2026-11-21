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
