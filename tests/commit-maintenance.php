<?php
// A bounded push must recheck versions through the maintenance-safe endpoint.
define('ABSPATH','/fixture/');
$id=str_repeat('a',32);$calls=[];
$control=['id'=>$id,'direction'=>'push','peer'=>['url'=>'https://destination.example'],'selection'=>[]];
function get_option($name,$default=false){return $name==='_csm_control'?$GLOBALS['control']:$default;}
function wp_cache_delete(...$args){}
function site_url(){return 'https://source.example';}
function home_url(){return site_url();}
function wp_parse_url($url){return parse_url($url);}
class CSM_Support {static function work(...$args){}static function check_cancel(){}}
class CSM_Updates {static function assert_job($job){}static function assert_version($version){}static function assert_build($version){}}
class CSM_Progress {static function start(...$args){}static function run(){return 'fixture';}}
class CSM_Transport {
 static function request($peer,$action,$data=[]){
  $GLOBALS['calls'][]=[$action,$data];
  if($action==='manifest')throw new RuntimeException('Maintenance HTTP 503');
  if($action==='version')return ['url'=>$peer['url'],'plugin_version'=>'0.1.20'];
  if($action==='commit')throw new RuntimeException('Commit reached');
  throw new RuntimeException('Unexpected endpoint');
 }
}
require __DIR__.'/../includes/controller.php';
$method=new ReflectionMethod(CSM_Controller::class,'operate');
for($checkpoint=0;$checkpoint<2;$checkpoint++){
 try{$method->invoke(null,'commit',['id'=>$id]);throw new RuntimeException('Commit not reached');}
 catch(RuntimeException $error){if($error->getMessage()!=='Commit reached')throw $error;}
}
if(array_column($calls,0)!==['version','commit','version','commit'])throw new RuntimeException('Checkpoint requested full inventory');
if($calls[0][1]['id']!==$id||$calls[2][1]['id']!==$id)throw new RuntimeException('Version checks lack transfer correlation');
echo "PASS Repeated push checkpoints use signed maintenance-safe version checks with transfer IDs\n";
