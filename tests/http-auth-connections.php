<?php
// Exercise saved-auth lifecycle through the real controller action and state response.
define('ABSPATH','/fixture/');$options=['siteurl'=>'https://local.test','home'=>'https://local.test'];$requests=[];
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;}
function wp_cache_delete(...$args){}
function wp_parse_url($url){return parse_url($url);}
function site_url(){return 'https://local.test';}function home_url(){return site_url();}function wp_salt($type){return 'fixture';}
function sanitize_text_field($text){return strip_tags($text);}function esc_url_raw($text,$schemes=[]){return $text;}
class CSM_Support{static function is_cancelled($id){return false;}}
class CSM_Plugin{static function parse_pairing($code){return ['url'=>'https://remote.test','key'=>str_repeat('a',64)];}}
class CSM_Transport{
 static function url($url){return $url;}
 static function http_auth($auth){return [];}
 static function request($peer,...$args){$GLOBALS['requests'][]=$peer;return ['name'=>'Fixture'];}
}
require __DIR__.'/../includes/class-csm-controller.php';
$operate=new ReflectionMethod(CSM_Controller::class,'operate');$call=static fn($data)=>$operate->invoke(null,'connect',$data);
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$auth=['username'=>'private-user','password'=>'private-password'];
$r=$call(['pairing'=>'fixture','http_auth'=>$auth+['ignored'=>'discard']]);$id=$r['saved'];
check($requests[0]['http_auth']===$auth && $options['_csm_peers'][$id]['http_auth']===$auth,'Save credentials before authenticated handshake and omit unknown fields');
check($r['state']['connections'][0]['has_http_auth'] && !str_contains(json_encode($r),'private-'),'Dashboard exposes only a saved-auth flag');
$call(['pairing'=>'fixture']);check($requests[1]['http_auth']===$auth,'Updating a connection preserves saved credentials when omitted');
$changed=['username'=>'new-user','password'=>'new-password'];$call(['pairing'=>'fixture','http_auth'=>$changed]);
check($requests[2]['http_auth']===$changed && $options['_csm_peer']['http_auth']===$changed,'Replacing credentials also updates the selected connection');
$options['_csm_control']=['id'=>str_repeat('b',32),'peer'=>$options['_csm_peer'],'direction'=>'pull','selection'=>[]];
check(!str_contains(json_encode(CSM_Controller::state()),'new-password'),'Retained transfer state does not expose saved passwords');
unset($options['_csm_control']);
$r=$call(['pairing'=>'fixture','http_auth'=>[]]);check(!isset($requests[3]['http_auth'])&&!$r['state']['connections'][0]['has_http_auth'],'Explicit removal clears saved credentials');
$blocked=false;try{$call(['pairing'=>'fixture','http_auth'=>'invalid']);}catch(RuntimeException $e){$blocked=true;}check($blocked,'Reject malformed settings before making a request');
