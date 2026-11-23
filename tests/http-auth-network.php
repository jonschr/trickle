<?php
// Used only by http-auth-network.py against its disposable loopback HTTP server.
define('ABSPATH','/fixture/');define('WPINC','wp-includes');define('CSM_VERSION','fixture');
function apply_filters($name,$value,...$args){return $name==='csm_ca_bundle'?'':($name==='csm_request_interval'?.01:($name==='csm_setup_request_interval'?0:$value));}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function add_query_arg($key,$value,$url){return $url.'?'.urlencode($key).'='.urlencode($value);}
$options=[];function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;}
function do_action(...$args){}
class CSM_Log{static function secret($v){}static function write(...$args){}static function clean($v){return $v;}}
class CSM_Support{static function private_dir(){return $GLOBALS['argv'][2];}static function json($v){return json_encode($v);}static function check_cancel(){$GLOBALS['cancel_checks']=($GLOBALS['cancel_checks']??0)+1;if(!empty($GLOBALS['cancel_fixture']))throw new RuntimeException('Cancelled fixture');}}
class CSM_Access{static function id($key){return substr(hash('sha256',$key),0,16);}}
class CSM_Progress{static function update(...$args){}}
require __DIR__.'/../includes/transport.php';
$auth=['username'=>'demo','password'=>'secret:with spaces'];$peer=['url'=>$argv[1],'key'=>str_repeat('a',64),'http_auth'=>$auth];
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
check(CSM_Transport::request($peer,'manifest')['received']===2,'Signed JSON request passes Basic Auth');
check($cancel_checks>1,'Native cURL progress checks cancellation during the request');
check(CSM_Transport::health($peer['url'],'','',$auth)['verified'],'Protected health check passes Basic Auth');
$bytes='';CSM_Transport::request($peer,'live_files',[],null,static function($chunk)use(&$bytes){$bytes.=$chunk;});
check($bytes==='fixture stream','Stream download passes Basic Auth');
$stream=(static function(){yield 'fixture upload';})();
check(CSM_Transport::request($peer,'live_receive',[],$stream)['received']===14,'Stream upload passes Basic Auth');
$slow=$peer;$slow['url'].='/slow';$worked=0;
$idle=(static function()use(&$worked){for($i=0;$i<30;$i++){$worked++;usleep(1000);yield '';}})();
$stream=(static function(){yield 'fixture upload';})();
check(CSM_Transport::request($slow,'live_receive',[],$stream,null,5,0,$idle)['received']===14&&$worked===(function_exists('curl_multi_init')?30:0),'Response-wait look-ahead or its sequential fallback preserves upload bytes');
$idle=(static function(){yield '';throw new RuntimeException('Speculative source changed');})();$stream=(static function(){yield 'fixture upload';})();
check(CSM_Transport::request($slow,'live_receive',[],$stream,null,5,0,$idle)['received']===14,'Speculative work failure preserves the successful current upload');
$idle=(static function(){for($i=0;$i<40;$i++){usleep(10000);yield '';} $GLOBALS['cancel_fixture']=true;})();$stream=(static function(){yield 'fixture upload';})();$cancelled=false;
try{CSM_Transport::request($slow,'live_receive',[],$stream,null,5,0,$idle);}catch(RuntimeException $e){$cancelled=$e->getMessage()==='Cancelled fixture';}finally{$cancel_fixture=false;}
check(function_exists('curl_multi_init')?$cancelled:!$cancelled,'Cancellation is preserved for started look-ahead; unavailable multi skips speculative work');
$bad=$peer;$bad['http_auth']['password']='wrong';$rejected=false;
try{CSM_Transport::request($bad,'manifest');}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'HTTP Basic Auth')&&!str_contains($e->getMessage(),'private rejected');}
check($rejected,'Bad credentials return a useful error without echoing response contents');
$redirect=$peer;$redirect['url'].='/redirect';$rejected=false;
try{CSM_Transport::request($redirect,'manifest');}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'HTTP 302');}
check($rejected,'Redirects cannot forward Basic Auth credentials');

usleep(20000);$paused=false;try{CSM_Transport::request($peer,'progress');}catch(CSM_Network_Error $e){$paused=$e->retry_after===60;}
check($paused,'Service-unavailable Retry-After is retained for the affected endpoint');
check(CSM_Transport::request($peer,'manifest')['received']===2,'A paused progress endpoint does not block transfer or cleanup endpoints');
$paused=false;try{CSM_Transport::request($peer,'progress');}catch(CSM_Network_Error $e){$paused=$e->retry_after>0;}
check($paused,'Repeated progress requests honor their cooldown without network traffic');
try{CSM_Transport::request($peer,'throttle');}catch(CSM_Network_Error $e){}
$paused=false;try{CSM_Transport::request($peer,'manifest');}catch(CSM_Network_Error $e){$paused=$e->retry_after>0;}
check($paused,'HTTP 429 retains a host-wide cooldown across different endpoints');
