<?php
// Actual comparison and exclusions against a temporary filesystem; no sites or network.
define('ABSPATH','/fixture/');define('CSM_FILE','/fixture/trickle.php');
$fixture=sys_get_temp_dir().'/trickle-force-'.bin2hex(random_bytes(6));
define('WP_PLUGIN_DIR',$fixture.'/plugins');
foreach(['plugins/example','themes/example','cache']as$dir)mkdir($fixture.'/'.$dir,0700,true);
foreach(['plugins','themes']as$kind){
 file_put_contents($fixture.'/'.$kind.'/example/code.php','<?php // fixture');
 foreach(['.git','node_modules']as$excluded){mkdir($fixture.'/'.$kind.'/example/'.$excluded);file_put_contents($fixture.'/'.$kind.'/example/'.$excluded.'/private','excluded');}
 file_put_contents($fixture.'/'.$kind.'/example/.gitignore','excluded');file_put_contents($fixture.'/'.$kind.'/example/.env','excluded');
}
class CSM_Support {
 static function private_dir(){return $GLOBALS['fixture'].'/cache';}
 static function json($value){return json_encode($value,JSON_UNESCAPED_SLASHES)."\n";}
 static function atomic_json($path,$value){file_put_contents($path,self::json($value));}
 static function step(){}static function due(){return !empty($GLOBALS["yield_package"]);}static function check_source_budget(){}static function check_cancel(){}static function write($fp,$bytes){fwrite($fp,$bytes);}
 static function hash_file($path){return hash_file('sha256',$path);}
}
class CSM_Activation {static function current(){return [];}}
function plugin_basename($file){return 'trickle/trickle.php';}
function wp_upload_dir(...$args){return ['basedir'=>$GLOBALS['fixture'].'/uploads','error'=>false];}
class CSM_Progress {static function update(...$args){}static function run(){return null;}}
class CSM_Transport {static function request($peer,$action,$query){if($action!=='component_hashes')throw new RuntimeException('Unexpected remote request');return CSM_Components::inventory($query);}}
function wp_get_theme($id){return new class {function exists(){return true;}function get($key){return '';}function get_theme_root(){return $GLOBALS['fixture'].'/themes';}};}
require __DIR__.'/../includes/files.php';require __DIR__.'/../includes/components.php';require __DIR__.'/../includes/package.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function remove_fixture($path){if(is_dir($path)){foreach(new FilesystemIterator($path)as$p)remove_fixture($p->getPathname());rmdir($path);}else unlink($path);}
try{
 foreach([false,true]as$pull)foreach([[false,false],[true,false],[false,true],[true,true]]as[$plugins,$themes]){
  $s=CSM_Files::media_selection(['plugins'=>['example'],'themes'=>['example'],'plugins_force'=>$plugins,'themes_force'=>$themes,'media'=>'all','media_force'=>false]);
  $plan=CSM_Components::compare([], $pull, $s);$same=CSM_Components::unchanged(CSM_Components::selection($s,$plan));
  $expected=[];if(!$plugins)$expected[]='plugins/example/code.php';if(!$themes)$expected[]='themes/example/code.php';
  check(array_keys($same)===$expected&&$plan['matched']===count($expected),($pull?'Pull':'Push').' skips only unforced component files, including token-based plans');
  check(CSM_Files::compare_media($s),'Plugin/theme force does not force media');
 }
 $legacy=['file_force'=>true,'plugins'=>['example'],'themes'=>['example']];check(CSM_Components::compare([],true,$legacy)['same']==='','Old global file flag remains compatible with retained selections');
 foreach(['file_force','plugins_force','themes_force']as$flag)foreach(['yes',1,[]]as$invalid){$caught=false;try{CSM_Files::media_selection([$flag=>$invalid]);}catch(RuntimeException$e){$caught=true;}check($caught,'Invalid '.$flag.' rejected');}
 check(!CSM_Files::compare_media(CSM_Files::media_selection(['media'=>'all','media_force'=>true])),'Media force independently bypasses media comparison');
 foreach(['plugins','themes']as$kind){$files=iterator_to_array(CSM_Files::scan($fixture.'/'.$kind.'/example',$kind.'/example',['plugins_force'=>true,'themes_force'=>true]));check(count($files)===1&&basename(reset($files))==='code.php','Forced scan still excludes Git metadata, node_modules and .env');}

 $path=$fixture.'/cache/stream';$yield_package=true;for($attempt=0;$attempt<30;$attempt++){$bundle=CSM_Package::build($path,'files',['plugins'=>['example'],'themes'=>['example'],'media'=>'none']);if(empty($bundle['continue']))break;}$yield_package=false;check(empty($bundle['continue'])&&$attempt>2,'Component metadata survives bounded packaging checkpoints');$ranges=$bundle['component_ranges'];
 check(isset($ranges['plugins'],$ranges['themes'])&&$ranges['plugins']['end']===$ranges['themes']['start'],'Actual file generator publishes sequential compressed component boundaries');
 $fp=fopen($path,'rb');
 foreach($ranges as$kind=>$range){fseek($fp,$range['start']);$raw='';while(ftell($fp)<$range['end']){$header=fread($fp,36);$n=unpack('N',substr($header,0,4))[1];$raw.=gzdecode(fread($fp,$n));}check(str_contains($raw,'"path":"'.$kind.'/example/code.php"')&&!str_contains($raw,'"path":"'.($kind==='plugins'?'themes':'plugins').'/'),'Each range contains only its actual component frames');}fclose($fp);
 $retry=CSM_Package::build($path,'files',[]);check($retry['component_ranges']===$ranges,'Published snapshot recovery preserves component ranges');
}finally{remove_fixture($fixture);}
