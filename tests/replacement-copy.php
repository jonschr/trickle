<?php
// Real private replacement installer; force one block per step, with no WordPress or site mutation.
define('ABSPATH',__DIR__);
class CSM_Support {
 static function due(){return true;} static function check_cancel(){}
 static function write($fp,$bytes){if(fwrite($fp,$bytes)!==strlen($bytes))throw new RuntimeException('Write failed');}
}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
require __DIR__.'/../includes/class-csm-plugin.php';
$dir=sys_get_temp_dir().'/trickle-sealed-'.bin2hex(random_bytes(6));mkdir($dir,0700);$source=$dir.'/source';$target=$dir.'/target';$payload=random_bytes(1048576);file_put_contents($source,$payload);$state=null;$recorded=[];
$method=new ReflectionMethod(CSM_Plugin::class,'install_file_step');
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";};
try {
 $callback=static function($s)use(&$recorded){clearstatcache(true,$s['temp']);$recorded[]=['temp'=>$s['temp'],'mode'=>fileperms($s['temp'])&0777];};
 $args=[$source,$target,time(),0644,&$state,$callback];$result=$method->invokeArgs(null,$args);
 clearstatcache(true,$state['temp']);$check(!$result && $state['copy']['offset']===262144 && !file_exists($target),'Replacement copy yields after its first block without changing the target');
 $check(count($recorded)===1 && $recorded[0]['mode']===0 && (fileperms($state['temp'])&0777)===0,'Public-directory temporary code is unreadable while staged across requests');
 do {$args=[$source,$target,time(),0644,&$state,$callback];$result=$method->invokeArgs(null,$args);}while(!$result);
 clearstatcache(true,$target);$check(file_get_contents($target)===$payload && (fileperms($target)&0777)===0644 && !file_exists($state['temp']),'Completed file is renamed into place with its intended permissions and exact bytes');
 $check(count($recorded)===1,'Initial temp location is journalled once before the first copy block');
 $failed=null;$streams=count(get_resources('stream'));$caught=false;
 try{CSM_Plugin::install_file_step($source,$dir.'/never-published',time(),0644,$failed,static function(){throw new RuntimeException('Lost checkpoint acknowledgement');});}
 catch(RuntimeException $e){$caught=$e->getMessage()==='Lost checkpoint acknowledgement';}
 clearstatcache(true,$failed['temp']);
 $check($caught&&count(get_resources('stream'))===$streams&&!file_exists($dir.'/never-published')&&(fileperms($failed['temp'])&0777)===0,'Checkpoint failure closes the held descriptor and leaves unpublished data sealed');
 $missing=null;$caught=false;
 try{CSM_Plugin::install_file_step($dir.'/missing-source',$dir.'/missing-target',time(),0644,$missing);}
 catch(RuntimeException $e){$caught=str_contains($e->getMessage(),'Copy source is missing');}
 $check($caught&&count(get_resources('stream'))===$streams&&!file_exists($dir.'/missing-target'),'Source failure cannot leak an opened replacement descriptor or publish a file');

} finally {foreach(glob($dir.'/{*,.*}',GLOB_BRACE) as $file){if(is_file($file)){chmod($file,0600);unlink($file);}}rmdir($dir);}
