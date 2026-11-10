<?php
// Real apply planning, backup copying, restore planning and file restoration; isolated files only.
define('ABSPATH','/fixture/');define('CSM_FILE','/fixture/trickle.php');
$dir=sys_get_temp_dir().'/trickle-delta-'.bin2hex(random_bytes(6));mkdir($dir,0700);define('WPMU_PLUGIN_DIR',$dir.'/mu');mkdir(WPMU_PLUGIN_DIR,0700);
$options=[];$jobs=[];$stop='';$scans=0;$checks=0;
class Captured extends RuntimeException{public array $job;function __construct($job){$this->job=$job;}}
class CSM_Support{
 static function write($fp,$bytes){fwrite($fp,$bytes);}static function private_dir(){return $GLOBALS['dir'];}static function backup_dir(){return $GLOBALS['dir'];}static function step(){}static function due(){return false;}static function check_cancel(){}
 static function hash_file($p){return hash_file('sha256',$p);}static function json($v){return json_encode($v)."\n";}static function atomic_bytes($p,$v,$mode=0600){file_put_contents($p,$v);chmod($p,$mode);}static function atomic_json($p,$v){self::atomic_bytes($p,self::json($v));}
}
class CSM_Jobs{static function save($job){$GLOBALS['jobs'][$job['id']]=$job;if(($GLOBALS['stop']==='backup'&&isset($job['apply_work']))||($GLOBALS['stop']==='restore'&&$job['status']==='backup_copy'))throw new Captured($job);}static function get($id){return $GLOBALS['jobs'][$id];}}
class CSM_Updates{static function assert_job($job){}}
class CSM_Activation{static function capture(&$job){}}
class CSM_Progress{static function read($id){return [];}static function start(...$a){}static function update(...$a){}}
class CSM_Files{
 static function destination($relative,$selection){return $GLOBALS['dir'].'/'.$relative;}static function assert_writable(...$a){}static function skipped(...$a){return false;}static function excluded(...$a){return false;}
 static function root($kind,$component){return $GLOBALS['dir'].'/'.$kind;}static function safe_relative($p){return $p;}
 static function scan($path,...$a){++$GLOBALS['scans'];if(is_dir($path)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile())yield $f->getPathname();}}
}
function current_user_can($cap){return false;}function wp_is_file_mod_allowed($context){return true;}function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}function update_option($k,$v,...$args){$GLOBALS['options'][$k]=$v;}function plugin_basename($p){return 'trickle/trickle.php';}function site_url(){return 'https://fixture.test';}function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0700,true);}
require __DIR__.'/../includes/plugin.php';require __DIR__.'/../includes/backups.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);++$GLOBALS['checks'];echo "PASS $label\n";}
function put($path,$text){wp_mkdir_p(dirname($path));file_put_contents($path,$text);}
function captured($fn){try{$fn();}catch(Captured$c){return $c->job;}throw new RuntimeException('Expected checkpoint capture');}
try{
 file_put_contents(WPMU_PLUGIN_DIR.'/0-trickle-loader.php',CSM_Plugin::loader_code());
 $unchanged='themes/matched/style.css';$pluginSame='plugins/demo/same.txt';$changed='plugins/demo/change.txt';$added='plugins/demo/new.txt';$deleted='plugins/demo/obsolete.txt';
 foreach([$unchanged=>'theme unchanged',$pluginSame=>'plugin unchanged',$changed=>'before',$deleted=>'obsolete']as$p=>$text)put(CSM_Files::destination($p,[]),$text);
 $paths=[];foreach([$changed,$added,$deleted]as$p)$paths[$p]=CSM_Files::destination($p,[]);
 $new=[$unchanged=>['path'=>$unchanged,'unchanged'=>true,'sha256'=>hash('sha256','theme unchanged')],$pluginSame=>['path'=>$pluginSame,'unchanged'=>true,'sha256'=>hash('sha256','plugin unchanged')],$changed=>['path'=>$changed,'size'=>5,'sha256'=>hash('sha256','after')],$added=>['path'=>$added,'size'=>3,'sha256'=>hash('sha256','new')]];
 $state=['phase'=>'plan','index'=>0,'new'=>$new,'paths'=>$paths,'items'=>[],'counts'=>['add'=>0,'replace'=>0,'delete'=>0,'unchanged'=>2],'backup'=>0,'signature'=>[]];
 $job=['id'=>str_repeat('a',32),'status'=>'staging','created'=>time(),'selection'=>['plugins'=>['demo'],'themes'=>['matched'],'tables'=>[],'media'=>'none'],'files'=>array_values($new),'database'=>[],'received'=>['files'=>true],'preview_work'=>$state];
 $stop='backup';$job=captured(fn()=>CSM_Plugin::commit($job));$stop='';
 check(array_keys($job['apply_work']['backup_paths'])===array_keys($paths),'Actual commit backs up only replacement/addition/deletion paths');
 $backup=new ReflectionMethod(CSM_Plugin::class,'backup_files');$base=$job['backup_dir'];check($backup->invokeArgs(null,[&$job,$base]),'Changed-file backup completes');
 $ops=$job['apply_work']['ops'];check(count($ops)===3&&count(array_filter($ops,fn($op)=>$op['existed']))===2,'Only two existing files are copied; new file gets removal metadata');
 check(array_sum(array_map(fn($op)=>$op['existed']?filesize($op['backup']):0,$ops))===strlen('beforeobsolete'),'Backup bytes exclude checksum-matched code');
 $save=new ReflectionMethod(CSM_Plugin::class,'save_recovery_plan');$save->invokeArgs(null,[&$job,$base]);
 check(!$job['code_snapshot']&&$job['backup_components']===['plugins'=>['demo'],'themes'=>[],'media'=>false],'Recovery metadata records change-only scope and affected components');
 $job['status']='applied';$job['applied']=time();$jobs[$job['id']]=$job;$options['_csm_backups']=[$job['id']];$listing=CSM_Backups::listing()['backups'][0];check($listing['plugins']===['demo']&&$listing['themes']===[]&&!$listing['media'],'Backup UI omits selected-but-unchanged themes');
 $later='themes/matched/later.txt';put(CSM_Files::destination($later,[]),'later');$scans=0;$stop='restore';$restore=captured(fn()=>CSM_Backups::restore($job['id']));$stop='';check($scans===0&&array_column($restore['ops'],'path')===array_column($ops,'path'),'Change-only restore never scans or deletes unrelated component files');
 put($paths[$changed],'after');put($paths[$added],'new');unlink($paths[$deleted]);put(CSM_Files::destination($unchanged,[]),'edited later');
 $method=new ReflectionMethod(CSM_Plugin::class,'restore_files');check($method->invokeArgs(null,[&$restore,$ops]),'Actual file restoration completes');
 check(file_get_contents($paths[$changed])==='before'&&!file_exists($paths[$added])&&file_get_contents($paths[$deleted])==='obsolete','Replaced, added and removed files recover correctly');
 check(file_get_contents(CSM_Files::destination($unchanged,[]))==='edited later'&&file_exists(CSM_Files::destination($later,[])),'Unchanged and later files remain untouched');
 $legacy=$job;$legacy['code_snapshot']=true;unset($legacy['backup_components']);$jobs[$legacy['id']]=$legacy;$stop='restore';$scans=0;$oldRestore=captured(fn()=>CSM_Backups::restore($legacy['id']));$stop='';check($scans>0&&in_array($later,array_column($oldRestore['ops'],'path'),true),'Legacy full-component backups retain snapshot cleanup behavior');
 $allSame=$job;$allSame['id']=str_repeat('b',32);$allSame['apply_work']=['phase'=>'backup','backup_paths'=>[],'ops'=>[],'cursor'=>0];$allSame['file_plan']=['new'=>$new,'paths'=>[],'signature'=>[]];$empty=$dir.'/empty';mkdir($empty,0700);$backup->invokeArgs(null,[&$allSame,$empty]);$save->invokeArgs(null,[&$allSame,$empty]);check($allSame['backup_components']===['plugins'=>[],'themes'=>[],'media'=>false]&&CSM_Plugin::recovery_operations($allSame)===[],'An all-matched transfer has no file backup payload');
 echo "$checks change-only backup checks passed.\n";
}finally{$walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($walk as$f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
