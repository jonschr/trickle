<?php
function wp_json_encode($value,$flags=0,$depth=512){return json_encode($value,$flags,$depth);}
// Actual cancellation markers and time budgets; no WordPress, network or live files.
define('ABSPATH',sys_get_temp_dir().'/trickle-progress-guards-'.bin2hex(random_bytes(6)).'/public/');
$salts=0;
function wp_salt($kind){++$GLOBALS['salts'];return 'progress-guard-fixture';}
function apply_filters($name,$value){return $name==='csm_source_packaging_seconds'?1:$value;}
class CSM_Log {static function write(...$args){}}
require __DIR__.'/../includes/class-csm-support.php'; require __DIR__.'/../includes/class-csm-progress.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
$dir=CSM_Support::private_dir();
try{
    $id=str_repeat('d',32);CSM_Support::work($id);$before=$salts;
    for($i=0;$i<10000;++$i)CSM_Support::check_cancel();
    check($salts===$before,'Direct work-loop guards reuse the validated marker path without repeating private-storage setup');
    CSM_Support::cancel($id);$caught=false;
    try{CSM_Support::check_cancel();}catch(CSM_Cancelled_Error $e){$caught=true;}
    check($caught,'Direct guards observe a newly created cancellation marker on their next check');
    $id=str_repeat('a',32);CSM_Progress::start($id,'Receiving files');
    $before=$salts;
    for($i=0;$i<10000;++$i)CSM_Progress::update(['count'=>$i]);
    check($salts-$before<100,'Frequent counter updates do not repeat cancellation filesystem setup for every call');
    CSM_Progress::update(['component'=>'plugins/demo/exact-file.php']);CSM_Support::cancel($id);
    $start=microtime(true);$caught=false;
    for($i=0;$i<200;++$i){try{CSM_Progress::update(['count'=>$i]);}catch(CSM_Cancelled_Error $e){$caught=true;break;}usleep(2000);}
    check($caught&&microtime(true)-$start<.5,'A progress-only loop promptly observes a newly written cancellation marker');
    $id=str_repeat('b',32);CSM_Progress::start($id,'Checking files');CSM_Support::cancel($id);$caught=false;
    try{CSM_Progress::update(['component'=>'plugins/demo/next.php'],true);}catch(CSM_Cancelled_Error $e){$caught=true;}
    check($caught,'Forced phase updates check cancellation immediately');
    CSM_Progress::fail('Original failure');
    $failure=CSM_Progress::read($id)['failure']??[];
    check(($failure['error']??'')==='Original failure','Cancellation cannot suppress failure reporting');
    $id=str_repeat('c',32);CSM_Progress::start($id,'Packaging files');CSM_Support::begin_source_budget();
    // Expire only the deadline while the preceding forced cancellation check is still recent.
    (new ReflectionProperty(CSM_Support::class,'source_deadline'))->setValue(null,hrtime(true)/1e9-1);$caught=false;
    try{CSM_Progress::update(['count'=>2]);}catch(RuntimeException $e){$caught=str_contains($e->getMessage(),'exceeded the 1-second limit');}
    check($caught,'Source deadlines remain enforced between the throttled cancellation checks');
    CSM_Support::end_source_budget();CSM_Progress::finish('Checks complete');
}finally{CSM_Support::end_source_budget();CSM_Timing::flush();CSM_Support::remove_tree($dir);}
