<?php
// Real report and log rotation reader; isolated private files, no site or network.
define('ABSPATH','/fixture/');define('CSM_VERSION','0.1.23');$dir=sys_get_temp_dir().'/trickle-report-'.bin2hex(random_bytes(6));mkdir($dir,0700);
function get_option($key,$default=''){return $key==='_csm_key'?str_repeat('a',64):$default;}function wp_parse_url($url){return parse_url($url);}function wp_check_invalid_utf8($s,$strip=false){return $s;}function wp_strip_all_tags($s){return strip_tags($s);}function site_url(){return 'https://local.test';}function get_bloginfo($key){return '7.1';}
class CSM_Support{static function private_dir(){return $GLOBALS['dir'];}static function json($v){return json_encode($v)."\n";}}
class CSM_Progress{static function read($id){return ['phase'=>'Receiving file','component'=>'plugins/test/a.php','failure'=>['error'=>'token=hidden'], 'nonce'=>'omit'];}}
require __DIR__.'/../includes/log.php';
try{$id=str_repeat('b',32);CSM_Log::write('error','Early failure',['transfer_id'=>$id,'http_status'=>502,'offset'=>1234]);for($i=0;$i<220;$i++)CSM_Log::write('info','Other transfer',['transfer_id'=>str_repeat('c',32)]);
$r=CSM_Log::report(['transfer_id'=>$id,'direction'=>'push','error'=>'token=hidden Response: private body','source'=>'https://user:pass@source.test/?key=hidden','pairing'=>'never-copy','selection'=>['secret'=>'never-copy']]);
if(count($r['entries'])!==1||$r['entries'][0]['context']['offset']!==1234)throw new RuntimeException('Transfer filtering lost early logs');
$text=json_encode($r);foreach(['hidden','private body','never-copy','nonce','user:pass']as$secret)if(str_contains($text,$secret))throw new RuntimeException('Sensitive data escaped redaction');
if(count(CSM_Log::entries())!==200)throw new RuntimeException('Existing recent-log bound changed');
$rejected=false;try{CSM_Log::report(['transfer_id'=>'../bad']);}catch(RuntimeException$e){$rejected=true;}if(!$rejected)throw new RuntimeException('Invalid ID accepted');
echo "PASS transfer-specific retained logs, context allowlist, redaction, progress, recent-log limit and ID validation.\n";
}finally{foreach(glob($dir.'/*')as$p)unlink($p);rmdir($dir);}
