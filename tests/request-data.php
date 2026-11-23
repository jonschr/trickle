<?php
// Exercise the signed request boundary without WordPress, network or private credentials.
define('ABSPATH','/fixture/');define('CSM_VERSION','fixture');
$nonces=[];$input='';$reads=0;
function add_option($key,...$args){if(isset($GLOBALS['nonces'][$key]))return false;$GLOBALS['nonces'][$key]=true;return true;}
class CSM_Support {static function json($data){return json_encode($data,JSON_UNESCAPED_SLASHES);}}
class CSM_Access {static function id($key){return 'fixture';}static function credential($id){return ['key'=>'fixture-key'];}static function authorize(...$args){}}
class CSM_Updates {static function assert_version($v){if($v!==CSM_VERSION)throw new RuntimeException('Version mismatch',403);}}
require __DIR__.'/../includes/class-csm-transport.php';
class SignedInput {
 public $context;private int $position=0;
 function stream_open($path,$mode,$options,&$opened){return true;}
 function stream_read($length){$GLOBALS['reads']++;$s=substr($GLOBALS['input'],$this->position,$length);$this->position+=strlen($s);return $s;}
 function stream_eof(){return $this->position>=strlen($GLOBALS['input']);}
 function stream_stat(){return ['size'=>strlen($GLOBALS['input'])];}
}
stream_wrapper_unregister('php');stream_wrapper_register('php',SignedInput::class);
set_error_handler(static function($level,$message,$file,$line){throw new ErrorException($message,0,$level,$file,$line);});
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function signed_request($body='{}',$rawParams=null,$action='status',$hash=null){
 $GLOBALS['input']=$body;$GLOBALS['reads']=0;$_SERVER=[];
 foreach(CSM_Transport::headers($action,'fixture-key',$hash??hash('sha256',$body)) as $header){if(!str_contains($header,': '))continue;[$name,$value]=explode(': ',$header,2);$_SERVER['HTTP_'.str_replace('-','_',strtoupper($name))]=$value;}
 if($rawParams!==null)$_SERVER['HTTP_X_CSM_PARAMS']=base64_encode($rawParams);
 resign($action);
}
function resign($action='status'){$_SERVER['HTTP_X_CSM_SIGNATURE']=hash_hmac('sha256',implode("\n",[$action,$_SERVER['HTTP_X_CSM_TIME'],$_SERVER['HTTP_X_CSM_NONCE'],$_SERVER['HTTP_X_CSM_HASH'],$_SERVER['HTTP_X_CSM_PARAMS']]),'fixture-key');}
function rejected($work,$status){try{$work();}catch(Throwable $e){return $e instanceof RuntimeException&&$e->getCode()===$status;}return false;}
foreach(['KEY_ID','TIME','NONCE','HASH','PARAMS','SIGNATURE'] as $field){
 foreach([[],true,42,new stdClass(),str_repeat('x',4097)] as $bad){
  signed_request();$before=count($GLOBALS['nonces']);$_SERVER['HTTP_X_CSM_'.$field]=$bad;
  check(rejected(fn()=>CSM_Transport::authenticate('status'),403)&&count($GLOBALS['nonces'])===$before,'Malformed '.$field.' is rejected without PHP warnings or nonce consumption');
 }
}
foreach(['null','true','42','"text"','not-json'] as $params){signed_request('{}',$params);check(rejected(fn()=>CSM_Transport::authenticate('status'),403),'Non-structured or malformed signed parameters return controlled authentication errors');}
signed_request();$_SERVER['HTTP_X_CSM_PARAMS']='!';resign();check(rejected(fn()=>CSM_Transport::authenticate('status'),403),'Invalid base64 is rejected after signature verification');
foreach(['null','true','42','"text"',''] as $body){signed_request($body);check(rejected(fn()=>CSM_Transport::authenticate('status'),400),'Signed scalar/invalid JSON body returns a controlled request error');}
// Encode the mixed-content case normally so its exact bytes, including slashes, are signed.
$body=json_encode(['path'=>'https://example.test/a/b','text'=>'<script> & "quoted" %s'],JSON_UNESCAPED_SLASHES);
signed_request($body);$before=count($nonces);CSM_Transport::signed_credential('status');check(count($nonces)===$before,'Early header verification does not consume the nonce');
check(CSM_Transport::authenticate('status')===json_decode($body,true),'Signed JSON values retain exact text and slash semantics');
check(rejected(fn()=>CSM_Transport::authenticate('status'),403),'A successfully authenticated request cannot be replayed');
signed_request('[]');check(CSM_Transport::authenticate('status')===[],'Native empty-array request payload remains supported');
signed_request('unread stream body',null,'live_receive','stream');check(CSM_Transport::authenticate('live_receive')===['trickle_version'=>CSM_VERSION]&&$reads===0,'Stream authentication validates metadata without consuming incoming payload bytes');
restore_error_handler();stream_wrapper_restore('php');
