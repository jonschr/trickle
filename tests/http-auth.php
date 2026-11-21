<?php
// Credential validation and redaction without bootstrapping a site.
define('ABSPATH','/fixture/');
function get_option($key,$default=''){return $default;}
function wp_parse_url($url){return parse_url($url);}
function wp_check_invalid_utf8($text,$strip=false){return $text;}
function wp_strip_all_tags($text){return strip_tags($text);}
require __DIR__.'/../includes/log.php';
require __DIR__.'/../includes/transport.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
check(CSM_Transport::http_auth([])===[],'Unprotected connections send no Basic Auth');
$a=['username'=>'demo-user','password'=>'secret:with spaces'];
$options=CSM_Transport::http_auth($a);
check($options===[CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERNAME=>$a['username'],CURLOPT_PASSWORD=>$a['password']],'cURL uses Basic Auth and preserves colons and spaces in passwords');
foreach([['username'=>'','password'=>'a'],['username'=>'x:y','password'=>'a'],['username'=>'u','password'=>"bad\nheader"],['username'=>[], 'password'=>'a'],['username'=>'u','password'=>''],['username'=>'u','password'=>str_repeat('a',4097)]]as$bad){
 $blocked=false;try{CSM_Transport::http_auth($bad);}catch(RuntimeException $e){$blocked=true;}check($blocked,'Invalid credentials are rejected before sending');
}
$clean=CSM_Log::clean($a['password'].' '.base64_encode($a['username'].':'.$a['password']));
check(!str_contains($clean,$a['password'])&&!str_contains($clean,base64_encode($a['username'].':'.$a['password'])),'Raw and encoded passwords are redacted');
