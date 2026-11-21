<?php
// Controller acknowledgement/retry behavior only; no site, files or network.
define('ABSPATH','/fixture/');
$options=[];$calls=[];$offset=0;$fail=false;
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
class CSM_Support {static function step(...$args){}static function due(){return false;}}
class CSM_Plugin {static function kinds($selection){return ['files'];}}
class CSM_Progress {static function start(...$args){}static function update(...$args){}static function run(){return '';}static function read($id){return [];}}
class CSM_Jobs {static function get($id){return ['id'=>$id];}}
class CSM_Segments {const UPLOAD_SIZE=16;const DOWNLOAD_SIZE=32;static function received_bytes(...$args){return 0;}static function state($job,$kind,$bundle){return ['offset'=>0,'size'=>$bundle['size']];}static function pull($peer,$job,$kind,$bundle){$GLOBALS['calls'][]='download';return ['continue'=>true,'checkpoint'=>['offset'=>16,'size'=>$bundle['size']]];}static function source(...$args){yield 'fixture';}}
class CSM_Transport {
    static function request($peer,$operation,$data,...$args){
        $GLOBALS['calls'][]=$operation;
        if($operation==='bundle')return ['size'=>40,'sha256'=>str_repeat('a',64)];
        if($operation==='segment_state')return ['offset'=>$GLOBALS['offset'],'size'=>$data['bundle']['size']];
        if($data['offset']!==$GLOBALS['offset'])throw new RuntimeException('Unexpected offset');
        $GLOBALS['offset']+=$data['length'];
        if($GLOBALS['fail'])throw new RuntimeException('Lost response');
        return ['continue'=>true,'checkpoint'=>['offset'=>$GLOBALS['offset'],'size'=>$data['bundle']['size']]];
    }
}
require __DIR__.'/../includes/controller.php';
$method=new ReflectionMethod(CSM_Controller::class,'segment_step');
function advance($control){return $GLOBALS['method']->invoke(null,$control,'files');}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$bundle=['size'=>40,'sha256'=>str_repeat('a',64)];
$control=['id'=>str_repeat('b',32),'created'=>microtime(true),'direction'=>'push','selection'=>['plugins'=>['demo'],'media'=>'none','transport'=>'stream'],'peer'=>[],'bundles'=>['files'=>$bundle]];
advance($control);$control=$options['_csm_control'];
check($calls===['segment_state','stream_data']&&$offset===16,'First request discovers the destination offset');
$calls=[];advance($control);$control=$options['_csm_control'];
check($calls===['stream_data']&&$offset===32,'Acknowledged next window needs no offset round trip');
$calls=[];$fail=true;try{advance($control);}catch(RuntimeException $e){check($e->getMessage()==='Lost response','Simulate a reply lost after destination accepted bytes');}
check(!empty($options['_csm_control']['checkpoint_pending']['files']),'Uncertain request invalidates acknowledgement reuse');
check($options['_csm_control']['checkpoints']['files']['offset']===32,'Last acknowledged progress remains available while a reply is uncertain');
$fail=false;$calls=[];advance($options['_csm_control']);$control=$options['_csm_control'];
check($calls===['segment_state','stream_data']&&$offset===40,'Retry discovers accepted bytes instead of replaying the upload');
$calls=[];advance($control);
check($calls===['stream_data'],'Post-upload processing reuses the acknowledged complete offset');
foreach(['sha256'=>str_repeat('c',64),'offset'=>41,'size'=>41]as$key=>$value){
    $bad=$control;$bad['checkpoints']['files'][$key]=$value;$calls=[];advance($bad);
    check($calls[0]==='segment_state','Mismatched or invalid cached '.$key.' requires discovery');
}

$control=['id'=>str_repeat('d',32),'created'=>microtime(true),'direction'=>'pull','selection'=>['media'=>'all','transport'=>'stream'],'peer'=>['url'=>'https://remote.fixture'],'exports'=>['files'=>['media'=>'all']]];
$calls=[];$r=advance($control);check($calls===['bundle']&&$r['continue']===true,'Large source packaging completes without starting a download in the same request');
$control=$options['_csm_control'];$calls=[];advance($control);check($calls===['download'],'The next request downloads the saved bundle instead of rebuilding it');
