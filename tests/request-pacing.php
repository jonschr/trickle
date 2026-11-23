<?php
function wp_json_encode($value,$flags=0,$depth=512){return json_encode($value,$flags,$depth);}
// Shared host scheduling without WordPress or network traffic.
define('ABSPATH','/fixture/');
$root=sys_get_temp_dir().'/test-trickle-pacing-'.bin2hex(random_bytes(6));mkdir($root,0700);
$interval=.1;$options=[];
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
function apply_filters($hook,$value,...$args){return $hook==='csm_setup_request_interval'?($GLOBALS['setup_interval']??0):($GLOBALS['interval']??$value);}
class CSM_Support {static function private_dir(){return $GLOBALS['root'];}static function check_cancel(){if(!empty($GLOBALS['cancel']))throw new RuntimeException('Cancelled');}}
require __DIR__.'/../includes/class-csm-transport.php';
$method=new ReflectionMethod(CSM_Transport::class,'paced');
$run=static fn($url,$action,$fn)=>$method->invoke(null,$url,$action,$fn);
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
try{
 $times=[];$send=static function()use(&$times){$times[]=microtime(true);return [];};
 $run('https://REMOTE.test/a','manifest',$send);$run('https://remote.test/b','prepare',$send);
 check($times[1]-$times[0]>=.09,'Different actions and installation paths on one normalized host share spacing');
 $finished=0.;$run('https://remote.test','live_receive',static function()use(&$finished){usleep(120000);$finished=microtime(true);return [];});
 $run('https://remote.test','manifest',$send);
 check(end($times)-$finished>=.09,'Slow requests also leave the configured quiet gap after completion');
 $count=count($times);$deferred=false;
 try{$run('https://remote.test','progress',$send);}catch(CSM_Pacing_Error $e){$deferred=$e->exhausted&&$e->retry_after>0;}
 check($deferred&&count($times)===$count,'A poll during the spacing interval adds no request');
 $run('https://remote.test','live_receive',static function()use($run,$send){
  $blocked=false;try{$run('https://remote.test','progress',$send);}catch(CSM_Pacing_Error $e){$blocked=true;}
  check($blocked,'A nested poll cannot create a concurrent host request');return [];
 });
 $blocked=false;$cancel=true;
 try{$run('https://remote.test','manifest',$send);}catch(RuntimeException $e){$blocked=$e->getMessage()==='Cancelled';}finally{$cancel=false;}
 check($blocked,'Cancellation interrupts a pacing wait before sending');
 $count=count($times);$run('https://remote.test','manifest',static function()use($run,$send){$run('https://another.test','manifest',$send);return [];});
 check(count($times)===$count+1,'Independent hosts have independent budgets');
 try{$run('https://remote.test','manifest',static function(){throw new RuntimeException('Operation failed');});}catch(RuntimeException $e){}
 $run('https://remote.test','manifest',$send);check(true,'A failed operation releases the host lock');
 $interval=null;$count=count($times);
 $run('https://remote.test','live_receive',$send);$run('https://remote.test','live_files',$send);$run('https://remote.test','progress',$send);
 check(count($times)===$count+3,'Default zero quiet time applies to push, pull and polling, overriding an older reserved gap');
 $run('https://remote.test','live_receive',static function()use($run,$send){
  $blocked=false;try{$run('https://remote.test','live_files',$send);}catch(CSM_Pacing_Error $e){$blocked=true;}
  check($blocked,'Zero quiet time still prevents concurrent requests to the host');return [];
 });
 $interval=null;$setup_interval=.1;$times=[];
 $run('https://startup.test','manifest',$send);$run('https://startup.test','prepare',$send);$run('https://startup.test','live_receive',$send);$run('https://startup.test','live_files',$send);
 check($times[1]-$times[0]>=.09&&$times[2]-$times[1]>=.09,'Startup requests and the first data window share the setup start interval');
 check($times[3]-$times[2]<.09,'Subsequent data windows have no recurring startup delay');
 $setup_interval=0;$count=count($times);$run('https://startup.test','manifest',$send);$run('https://startup.test','progress',$send);
 check(count($times)===$count+2,'Disabling global setup spacing takes effect immediately');
 $throttle=new ReflectionMethod(CSM_Transport::class,'throttle');
 foreach([429,430]as$status){
  $host='https://limited'.$status.'.test';$blocked=false;
  try{$throttle->invoke(null,$host,$status,90);}catch(CSM_Network_Error $e){$blocked=$e->exhausted&&$e->retry_after===90;}
  check($blocked,'HTTP '.$status.' retains Retry-After and prevents immediate automatic retries');
  foreach(['manifest','progress','health','commit']as$action){
   $count=count($times);$blocked=false;
   try{$run($host,$action,$send);}catch(CSM_Pacing_Error $e){$blocked=$e->retry_after>0;}
   check($blocked&&count($times)===$count,'HTTP '.$status.' cooldown blocks '.$action.' without sending');
  }
 }
}finally{foreach(glob($root.'/*')as$file)unlink($file);rmdir($root);}
