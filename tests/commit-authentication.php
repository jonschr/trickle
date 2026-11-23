<?php
// Signed commit guards execute before reading or applying any upload; no WordPress/network.
define('ABSPATH','/receiver/');define('CSM_VERSION','fixture');define('CSM_FILE',dirname(__DIR__).'/trickle.php');
function site_url(){return 'https://receiver.test';}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function get_option($key,$default=false){return ['siteurl'=>'https://receiver.test','home'=>'https://receiver.test'][$key]??$default;}
function home_url(){return 'https://receiver.test';}function wp_salt($name){return 'fixture';}
function add_option(...$args){return true;}
class CSM_Support {static function json($data){return json_encode($data);}}
class CSM_Access {static function id($key){return 'fixture';}static function credential($id){return ['key'=>str_repeat('a',64)];}static function authorize(...$args){}}
require __DIR__.'/../includes/class-csm-controller.php';
require __DIR__.'/../includes/class-csm-files.php';
require __DIR__.'/../includes/class-csm-updates.php';
require __DIR__.'/../includes/class-csm-transport.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function signed($change=[]){
 $headers=CSM_Transport::headers('commit',str_repeat('a',64),hash('sha256','{}'));
 foreach($headers as$header){if(!str_contains($header,': '))continue;[$name,$value]=explode(': ',$header,2);$_SERVER['HTTP_'.str_replace('-','_',strtoupper($name))]=$value;}
 $params=json_decode(base64_decode($_SERVER['HTTP_X_CSM_PARAMS']),true);$params=array_replace($params,$change);
 $_SERVER['HTTP_X_CSM_PARAMS']=base64_encode(json_encode($params));
 $_SERVER['HTTP_X_CSM_SIGNATURE']=hash_hmac('sha256',implode("\n",['commit',$_SERVER['HTTP_X_CSM_TIME'],$_SERVER['HTTP_X_CSM_NONCE'],$_SERVER['HTTP_X_CSM_HASH'],$_SERVER['HTTP_X_CSM_PARAMS']]),str_repeat('a',64));
}
foreach([
 [['trickle_build'=>str_repeat('f',64)],'builds must match'],
 [['trickle_version'=>'stale'],'versions must match'],
 [['sender_url'=>'https://receiver.test'],'different site'],
 [['sender_url'=>'https://sender.test','sender_instance'=>CSM_Controller::fingerprint()],'different site']
]as[$change,$fragment]){
 signed($change);$rejected=false;
 try{CSM_Transport::authenticate('commit');}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),$fragment);}
 check($rejected,'Signed stale build/version or self-connection fails before commit body processing');
}
signed(['sender_url'=>'https://sender.test','sender_instance'=>str_repeat('b',64)]);$reached=false;
try{CSM_Transport::authenticate('commit');}catch(RuntimeException $e){$reached=str_contains($e->getMessage(),'body checksum');}
check($reached,'A matching signed build and distinct sender pass guards and reach ordinary body authentication');
