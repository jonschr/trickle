<?php
// Controller receipt reuse and uncertain uploads; no WordPress, files or network.
define('ABSPATH','/fixture/');
$options=[];$calls=[];$failed=false;$probeFailed=false;
$selection=['plugins'=>['demo'],'themes'=>[],'media'=>'none','transport'=>'stream'];
$remote=['seq'=>0,'bytes'=>0,'chain'=>'','done'=>false,'selection'=>hash('sha256',json_encode($selection))];
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
class CSM_Support {static function json($v){return json_encode($v);}static function step(...$args){}}
class CSM_Plugin {static function kinds($selection){return ['files'];}}
class CSM_Progress {static function run(){return 'fixture';}static function read($id){return [];}}
class CSM_Live_Stream {static function source(...$args){yield 'frame';}static function lookahead(...$args){yield '';}}
class CSM_Network_Error extends RuntimeException {}
class CSM_Transport {
 static function request($peer,$action,$data,...$args){
  $GLOBALS['calls'][]=$action;$state=&$GLOBALS['remote'];
  if($action==='live_state'){
   if($GLOBALS['probeFailed'])throw new CSM_Network_Error('State unavailable');
   return $state;
  }
  if($action==='status')return ['status'=>'staging'];
  if($action!=='live_receive'||$data['seq']!==$state['seq']||$data['offset']!==($state['offset']??0))throw new RuntimeException('Wrong receiver sequence');
  if(!$state['done']){$state['seq']++;$state['bytes']+=5;$state['chain']=hash('sha256',(string)$state['seq']);}
  if($GLOBALS['failed'])throw new CSM_Network_Error('Lost reply');
  return ['continue'=>true,'checkpoint'=>['offset'=>$state['bytes'],'size'=>null],'live_files'=>$state];
 }
}
require __DIR__.'/../includes/controller.php';
$method=new ReflectionMethod(CSM_Controller::class,'live_step');
function advance($control){$GLOBALS['method']->invoke(null,$control,false);return $GLOBALS['options']['_csm_control'];}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$control=['id'=>str_repeat('a',32),'created'=>time(),'direction'=>'push','peer'=>[],'selection'=>$selection,'exports'=>['files'=>$selection]];
$control=advance($control);check($calls===['live_state','live_receive']&&$remote['seq']===1,'First live upload discovers its destination checkpoint');
$calls=[];$control=advance($control);check($calls===['live_receive']&&$remote['seq']===2,'An acknowledged live window skips redundant state discovery');
$calls=[];$failed=$probeFailed=true;
try{advance($control);}catch(CSM_Network_Error $e){check($e->getMessage()==='Lost reply','A lost upload and failed probe preserve the original network error');}
$control=$options['_csm_control'];check($control['live_pending']&&$control['live_checkpoint']['seq']===2&&$remote['seq']===3,'Uncertain upload persists its pending marker without advancing the acknowledged receipt');
$failed=$probeFailed=false;$calls=[];$control=advance($control);
check($calls===['live_state','live_receive']&&$remote['seq']===4,'Retry discovers accepted bytes instead of replaying an uncertain window');
$failed=true;$calls=[];$control=advance($control);$failed=false;
check($calls===['live_receive','live_state','status']&&$control['live_checkpoint']['seq']===5&&!isset($control['live_pending']),'A successful recovery probe acknowledges progress after a lost response');
$calls=[];$control=advance($control);check($calls===['live_receive']&&$remote['seq']===6,'The recovered receipt is reusable on the following window');
foreach(['selection'=>str_repeat('f',64),'seq'=>-1,'bytes'=>-1,'offset'=>-1,'done'=>0]as$key=>$value){
 $bad=$control;$bad['live_checkpoint'][$key]=$value;$calls=[];$control=advance($bad);
 check($calls===['live_state','live_receive'],'Invalid cached '.$key.' requires receiver discovery');
}
$remote['offset']=17;$bad=$control;$bad['live_pending']=true;$calls=[];$control=advance($bad);
check($calls===['live_state','live_receive']&&$control['live_checkpoint']['offset']===17,'Discovered partial-frame offsets survive acknowledgement reuse');
$calls=[];$control=advance($control);check($calls===['live_receive'],'An acknowledged partial offset does not require another discovery');
