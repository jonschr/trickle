<?php
// Comparison never opens file contents, including cold caches and legacy rehash requests.
define('ABSPATH','/fixture/');
$dir=sys_get_temp_dir().'/trickle-setup-hashes-'.bin2hex(random_bytes(6));
mkdir($dir.'/plugins/example',0700,true);mkdir($dir.'/private',0700);
file_put_contents($dir.'/plugins/example/a.php','first');file_put_contents($dir.'/plugins/example/b.php','other');
class CSM_Support {
 static function private_dir(){return $GLOBALS['dir'].'/private';}
 static function json($v){return json_encode($v,JSON_UNESCAPED_SLASHES);}
 static function atomic_json($path,$data){file_put_contents($path,self::json($data));}
 static function step(){} static function due(){return false;}
 static function hash_file($file){throw new RuntimeException('Pre-transfer content read');}
}
class CSM_Progress {static function update($v){}}
class CSM_Files {
 static function root($kind,$id=null){return $GLOBALS['dir'].'/'.$kind;}
 static function safe_relative($id){if(str_contains($id,'..'))throw new RuntimeException('Unsafe ID');}
 static function source_path($path,$relative,$selection){return $path;}
 static function paths($selection){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($GLOBALS['dir'].'/uploads',FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile())yield ['uploads/'.substr($f->getPathname(),strlen($GLOBALS['dir'].'/uploads/')),$f->getPathname()];}}
 static function scan($root,$relative,$selection,$deadline,$source){foreach(glob($root.'/*') as $path){if(!in_array($relative.'/'.basename($path),$selection['skip_paths']??[],true))yield $path;}}
}
require __DIR__.'/../includes/class-csm-components.php';
require __DIR__.'/../includes/class-csm-media.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
try {
 $mtime=time()-60; foreach(glob($dir.'/plugins/example/*') as $f)touch($f,$mtime);
 $warm=CSM_Components::inventory(['selection'=>['plugins'=>['example']],'source'=>true]);
 check($warm['hashed']===0 && $warm['comparison']==='size_mtime','Cold inventory uses metadata without hashing file contents');
 $transfer=CSM_Components::inventory(['selection'=>['plugins'=>['example'],'protect_source_git'=>true,'skip_paths'=>['plugins/unrelated']],'source'=>true]);
 check($transfer['hashed']===0 && $transfer['components']===$warm['components'],'Policy changes preserve size/mtime comparisons');
 $excluded=CSM_Components::inventory(['selection'=>['plugins'=>['example'],'skip_paths'=>['plugins/example/a.php']],'source'=>true]);
 check($excluded['files']===1 && $excluded['components']!==$warm['components'],'Current exclusions change the metadata inventory');
 file_put_contents($dir.'/plugins/example/a.php','other');touch($dir.'/plugins/example/a.php',$mtime);
 $same=CSM_Components::inventory(['selection'=>['plugins'=>['example']],'verify'=>true]);
 check($same['components']===$warm['components'] && $same['hashed']===0,'Equal size and mtime skip differing bytes, including a saved legacy rehash preference');
 touch($dir.'/plugins/example/a.php',$mtime+1);
 $changed=CSM_Components::inventory(['selection'=>['plugins'=>['example']],'source'=>true]);
 check($changed['components']!==$warm['components'],'A timestamp-only change selects the file');
 file_put_contents($dir.'/plugins/example/a.php','longer');touch($dir.'/plugins/example/a.php',$mtime);
 $changed=CSM_Components::inventory(['selection'=>['plugins'=>['example']],'source'=>true]);
 check($changed['components']!==$warm['components'],'A size-only change selects the file');
 mkdir($dir.'/uploads/2030/01',0700,true);file_put_contents($dir.'/uploads/2030/01/a.jpg','first');touch($dir.'/uploads/2030/01/a.jpg',$mtime);
 $media=CSM_Media::inventory(['level'=>'years']);
 check($media['comparison']==='size_mtime' && $media['stats']['hashed_files']===0,'Cold media comparison scans metadata without opening file contents');
 file_put_contents($dir.'/uploads/2030/01/a.jpg','other');touch($dir.'/uploads/2030/01/a.jpg',$mtime);
 $same=CSM_Media::inventory(['level'=>'years','verify'=>true]);
 check($media['items']===$same['items'] && $same['stats']['hashed_files']===0,'Media also uses size/mtime when bytes differ and a legacy rehash flag is supplied');
 touch($dir.'/uploads/2030/01/a.jpg',$mtime+1);
 $changed=CSM_Media::inventory(['level'=>'years']);
 check($media['items']!==$changed['items'],'A media timestamp change invalidates its metadata comparison');
} finally {
 $remove=function($path)use(&$remove){if(is_dir($path)){foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);}else unlink($path);};$remove($dir);
}
