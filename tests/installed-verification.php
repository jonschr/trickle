<?php
// Direct, bounded destination checks; no WordPress site, network or shell process.
define('ABSPATH','/fixture/');define('CSM_FILE','/fixture/trickle.php');
$base=sys_get_temp_dir().'/trickle-installed-'.bin2hex(random_bytes(6));mkdir($base,0700);
$due=false;$cancel=false;$tables=[];$active=['fixture/plugin.php','trickle/trickle.php'];$saved=[];
class CSM_Support {static function check_cancel(){if($GLOBALS['cancel'])throw new RuntimeException('Cancelled');}static function hash_file($p){return hash_file('sha256',$p);}static function due(){return $GLOBALS['due'];}}
class CSM_Jobs {static function save($job){$GLOBALS['saved'][]=$job;}}
class CSM_Files {static function destination($relative,$selection){return $GLOBALS['base'].'/'.basename($relative);}}
class CSM_Database {static function table_exists($name){return in_array($name,$GLOBALS['tables'],true);}}
class CSM_Activation {static function current(){return $GLOBALS['active'];}}
class CSM_Progress {static function update(...$args){}}
function plugin_basename($p){return 'trickle/trickle.php';}
require __DIR__.'/../includes/plugin.php';
$check=new ReflectionMethod(CSM_Plugin::class,'verify_installed_step');
function verify(array &$job){return $GLOBALS['check']->invokeArgs(null,[&$job]);}
function pass($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function rejected($job,$message){try{verify($job);}catch(RuntimeException $e){return str_contains($e->getMessage(),$message);}return false;}
try{
 $new=[];$paths=[];foreach(['a.txt','b.txt']as$name){file_put_contents($base.'/'.$name,'new-'.$name);$key='plugins/fixture/'.$name;$paths[$key]=$base.'/'.$name;$new[$key]=['sha256'=>hash_file('sha256',$base.'/'.$name)];}
 $tables=['target','backup'];$job=['id'=>str_repeat('a',32),'selection'=>[],'file_plan'=>['paths'=>$paths,'new'=>$new],'database'=>[['target'=>'target','stage'=>'stage','backup'=>'backup','existed'=>true]],'active_plugins_before'=>['old.php'],'source_active_plugins'=>$active];
 $due=true;pass(!verify($job)&&$job['verification_work']===['phase'=>'files','cursor'=>1],'Installed-file verification checkpoints its exact cursor');
 pass(!verify($job)&&$job['verification_work']['cursor']===2,'Next step resumes at the next file');
 pass(!verify($job)&&$job['verification_work']===['phase'=>'tables','cursor'=>1],'Table verification has its own bounded cursor');
 pass(verify($job)&&$job['verification']===['verified'=>true,'files'=>2,'tables'=>1],'Completion requires installed bytes, committed tables and activation state');
 unset($job['verification_work'],$job['verification']);$due=false;
 file_put_contents($base.'/a.txt','corrupt');pass(rejected($job,'Installed file checksum failed'),'Actual installed-byte corruption fails verification');file_put_contents($base.'/a.txt','new-a.txt');
 $tables=['target','backup','stage'];pass(rejected($job,'Table replacement verification failed'),'An unrenamed staging table fails verification');
 $tables=['backup'];pass(rejected($job,'Table replacement verification failed'),'A missing destination table fails verification');
 $tables=['target'];pass(rejected($job,'Table replacement verification failed'),'A missing required rollback table fails verification');$tables=['target','backup'];
 $active=['trickle/trickle.php'];pass(rejected($job,'Plugin activation changed'),'Activation drift fails verification');$active=$job['source_active_plugins'];
 $obsolete=$job;$obsolete['file_plan']['paths']['plugins/fixture/obsolete.txt']=$base.'/obsolete.txt';file_put_contents($base.'/obsolete.txt','leftover');pass(rejected($obsolete,'Obsolete file remains'),'An obsolete file left behind fails verification');unlink($base.'/obsolete.txt');
 $cancel=true;pass(rejected($job,'Cancelled'),'Cancellation interrupts installed verification');$cancel=false;
 $empty=['selection'=>[],'file_plan'=>['paths'=>[],'new'=>[]],'database'=>[]];pass(verify($empty)&&$empty['verification']['verified'],'An unchanged transfer completes without an HTTP liveness probe');
}finally{foreach(new FilesystemIterator($base)as$file)unlink($file->getPathname());rmdir($base);}
