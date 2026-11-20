<?php
// Real component hash caches in a private disposable directory; no WordPress or network.
define('ABSPATH','/fixture/');
$dir=sys_get_temp_dir().'/trickle-setup-hashes-'.bin2hex(random_bytes(6));
mkdir($dir.'/plugins/example',0700,true);mkdir($dir.'/private',0700);
file_put_contents($dir.'/plugins/example/a.php','first');file_put_contents($dir.'/plugins/example/b.php','other');
class CSM_Support {
 static function private_dir(){return $GLOBALS['dir'].'/private';}
 static function json($v){return json_encode($v,JSON_UNESCAPED_SLASHES);}
 static function atomic_json($path,$data){file_put_contents($path,self::json($data));}
 static function step(){} static function due(){return false;}
 static function hash_file($file){return hash_file('sha256',$file);}
}
class CSM_Progress {static function update($v){}}
class CSM_Files {
 static function root($kind,$id=null){return $GLOBALS['dir'].'/'.$kind;}
 static function safe_relative($id){if(str_contains($id,'..'))throw new RuntimeException('Unsafe ID');}
 static function source_path($path,$relative,$selection){return $path;}
 static function scan($root,$relative,$selection,$deadline,$source){foreach(glob($root.'/*') as $path){if(!in_array($relative.'/'.basename($path),$selection['skip_paths']??[],true))yield $path;}}
}
require __DIR__.'/../includes/components.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
try {
 sleep(3); // Match the production cache's two-second recent-write guard.
 $warm=CSM_Components::inventory(['selection'=>['plugins'=>['example']],'source'=>true]);
 check($warm['hashed']===2,'Setup hashes the component files once');
 $transfer=CSM_Components::inventory(['selection'=>['plugins'=>['example'],'protect_source_git'=>true,'skip_paths'=>['plugins/unrelated']],'source'=>true]);
 check($transfer['hashed']===0 && $transfer['components']===$warm['components'],'Transfer reuses setup hashes across policy keys after checking current files');
 $excluded=CSM_Components::inventory(['selection'=>['plugins'=>['example'],'skip_paths'=>['plugins/example/a.php']],'source'=>true]);
 check($excluded['files']===1 && $excluded['components']!==$warm['components'],'Current exclusions rebuild the digest instead of reusing a broad folder result');
 file_put_contents($dir.'/plugins/example/a.php','other');
 $changed=CSM_Components::inventory(['selection'=>['plugins'=>['example']],'source'=>true]);
 check($changed['components']!==$warm['components'],'Files changed after setup are not accepted as the earlier comparison');
 $verified=CSM_Components::inventory(['selection'=>['plugins'=>['example']],'verify'=>true]);
 check($verified['hashed']===2,'Explicit checksum verification still reads every file');
} finally {
 $remove=function($path)use(&$remove){if(is_dir($path)){foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);}else unlink($path);};$remove($dir);
}
