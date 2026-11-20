<?php
// Actual history persistence with isolated option storage, no WordPress or transfers.
define('ABSPATH','/fixture/');$options=[];
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;}
class CSM_Transport {static function url($url){return $url;}}
require __DIR__.'/../includes/controller.php';
$remember=new ReflectionMethod(CSM_Controller::class,'remember');
$control=['id'=>'fixture','peer'=>['url'=>'https://remote.test','name'=>'Remote'],'direction'=>'push','created'=>123,'selection'=>['tables'=>['wp_posts'],'plugins'=>['example'],'themes'=>[],'media'=>'all','git_overrides'=>['private'],'skip_paths'=>['private'],'follow_symlinks'=>['private']],'scope'=>['plugins'=>[['id'=>'example','name'=>'Example plugin','version'=>'1.2']]]];
$remember->invoke(null,$control,['status'=>'applied','applied'=>456,'tables'=>1,'files'=>12,'unchanged_files'=>8,'bytes'=>900,'rows'=>100,'secret'=>'not history']);
$entry=$options['_csm_recents']['fixture'];
if($entry['result']!==['tables'=>1,'files'=>12,'unchanged_files'=>8,'bytes'=>900,'rows'=>100]||$entry['scope']!==$control['scope'])throw new RuntimeException('History lost outcome or component names');
foreach(['git_overrides','skip_paths','follow_symlinks']as$key)if(isset($entry['selection'][$key]))throw new RuntimeException('History replayed a private approval');
if(isset($entry['result']['secret']))throw new RuntimeException('Unexpected summary fields saved');
echo "PASS Recent records retain selected names and measured totals, without replaying approvals or extra summary fields.\n";
