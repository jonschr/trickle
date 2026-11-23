<?php
// Binary update metadata uses the same signature, nonce and credential guards as JSON.
define('ABSPATH','/fixture/');define('CSM_VERSION','fixture');
$nonces=[];
function add_option($key,...$args){if(isset($GLOBALS['nonces'][$key]))return false;$GLOBALS['nonces'][$key]=true;return true;}
class CSM_Support {static function json($data){return json_encode($data);}}
class CSM_Access {static function id($key){return 'fixture';}static function credential($id){return ['key'=>str_repeat('a',64)];}static function authorize(...$args){}}
require __DIR__.'/../includes/transport.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function signed_zip($params,$hash=null){
 foreach(CSM_Transport::headers('update_trickle_zip',str_repeat('a',64),$hash??str_repeat('b',64),$params)as$header){if(!str_contains($header,': '))continue;[$name,$value]=explode(': ',$header,2);$_SERVER['HTTP_'.str_replace('-','_',strtoupper($name))]=$value;}
}
$valid=['sha256'=>str_repeat('b',64),'size'=>700000];
signed_zip($valid);check(CSM_Transport::authenticate('update_trickle_zip')['size']===700000,'Signed binary metadata passes without consuming JSON input');
$rejected=false;try{CSM_Transport::authenticate('update_trickle_zip');}catch(RuntimeException $e){$rejected=$e->getCode()===403;}
check($rejected,'Binary update nonce cannot be replayed');
foreach([['size'=>0],['size'=>700001],['size'=>'10'],['size'=>true],['sha256'=>null],['sha256'=>str_repeat('c',64)]]as$change){
 signed_zip(array_replace($valid,$change));$rejected=false;try{CSM_Transport::authenticate('update_trickle_zip');}catch(RuntimeException $e){$rejected=$e->getCode()===403;}
 check($rejected,'Invalid length or a digest different from the signed body digest is rejected');
}
signed_zip($valid);$_SERVER['HTTP_X_CSM_PARAMS']=base64_encode(json_encode(array_replace($valid,['size'=>1])));
$rejected=false;try{CSM_Transport::authenticate('update_trickle_zip');}catch(RuntimeException $e){$rejected=$e->getCode()===403;}
check($rejected,'Binary metadata tampering fails authentication');
