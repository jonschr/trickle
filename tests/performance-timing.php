<?php
// Real progress, checksums and diagnostic storage; isolated private files, no site/network.
$root=sys_get_temp_dir().'/trickle-timing-'.bin2hex(random_bytes(6));
define('ABSPATH',$root.'/public/'); define('CSM_VERSION','fixture');
function wp_salt($kind){return 'timing-fixture';}
function apply_filters($name,$value){return $value;}
function get_option($key,$default=''){return $default;}
function wp_parse_url($url){return parse_url($url);}
function wp_check_invalid_utf8($text,$strip=false){return $text;}
function wp_strip_all_tags($text){return strip_tags($text);}
function site_url(){return 'https://fixture.test';}
function get_bloginfo($key){return 'fixture';}
foreach (['support','log','progress'] as $part) { require __DIR__.'/../includes/'.$part.'.php'; }
class CSM_Files { static function destination($path,$selection){return $GLOBALS['dir'].'/'.basename($path);} }
require __DIR__.'/../includes/plugin.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
$dir=CSM_Support::private_dir(); $id=str_repeat('c',32);
try {
    CSM_Timing::begin($id,'commit');
    CSM_Progress::start($id,'Verifying staged file');
    for ($i=0;$i<20000;++$i) { CSM_Progress::update(['component'=>'plugins/demo-'.($i%20).'/includes/file-'.$i.'.php','count'=>$i]); }
    $path=$dir.'/data'; $data=random_bytes(1024*1024); file_put_contents($path,$data);
    $state=null; check(CSM_Plugin::copy_step($path,$dir.'/copied',$state),'Timed copy preserves actual bytes and resumable state');
    $job=['file_plan'=>['paths'=>['uploads/copied'=>$dir.'/copied'],'new'=>['uploads/copied'=>['sha256'=>hash('sha256',$data)]]],'database'=>[],'selection'=>[]];
    $verify=new ReflectionMethod(CSM_Plugin::class,'verify_installed_step');
    check($verify->invokeArgs(null,[&$job]) && $job['verification']['verified'],'Real final verification records its phase and verifies installed bytes');
    CSM_Progress::finish('Checks complete');
    try { CSM_Timing::measure('apply.components',static function(){throw new RuntimeException('original failure');}); }
    catch (RuntimeException $error) { check($error->getMessage()==='original failure','Timers preserve errors and record failed work'); }
    CSM_Timing::flush(); CSM_Timing::flush();
    $report=CSM_Log::report(['transfer_id'=>$id]); $perf=$report['performance']; $metrics=$perf['metrics'];
    check($perf['samples']===1 && $perf['operations']['commit']['requests']===1,'One sample per request, no per-file logs or duplicate flushes');
    check(CSM_Log::entries()===[],'Performance samples do not inflate dashboard log polling responses');
    check($metrics['progress.update']['calls']===20004 && $metrics['progress.write_forced']['calls']===3,'Throttled and forced progress writes counted separately');
    check(($metrics['progress.write']['calls']??0)<100,'Thousands of updates retain bounded progress writes');
    check($metrics['checksum.read']['bytes']===strlen($data) && $metrics['apply.components']['calls']===1,'Read volume and exceptional phases survive diagnostics export');
    check($metrics['files.copy']['bytes']===strlen($data) && $metrics['apply.verify_installed']['calls']===1,'Real apply timers distinguish file copies and final checksum verification');
    check($perf['operations']['commit']['bootstrap_samples']===0,'CLI requests do not misreport process age as WordPress bootstrap');
    check(isset($perf['operations']['commit']['process_cpu_samples']) && !isset($perf['operations']['commit']['cpu_ms']) && str_contains($perf['scope'],'process-wide'),'CPU counters explicitly describe process scope on threaded PHP');
    CSM_Timing::begin($id,'progress'); CSM_Progress::read($id); CSM_Timing::flush();
    $other=str_repeat('d',32); CSM_Timing::begin($other,'progress'); CSM_Progress::read($id); CSM_Timing::flush();
    $perf=CSM_Log::report(['transfer_id'=>$id])['performance'];
    check($perf['samples']===2 && $perf['operations']['progress']['requests']===1 && $perf['metrics']['progress.read']['calls']===2,'Polling overhead is separate and other transfers never enter totals');
    CSM_Timing::begin('','prepare'); CSM_Timing::measure('prepare.selection',static fn()=>42);
    $prepared=str_repeat('e',32); CSM_Timing::begin($prepared,'prepare'); CSM_Timing::flush();
    check(CSM_Log::report(['transfer_id'=>$prepared])['performance']['metrics']['prepare.selection']['calls']===1,'Preparation before ID allocation is retained under the new transfer');
    $clean=CSM_Timing::numeric(['ms'=>1.5,'metrics'=>['progress.update'=>['ms'=>2,'calls'=>1,'secret'=>'never-copy']],'path'=>'/private/customer']);
    check(!str_contains(json_encode($clean),'never-copy') && !str_contains(json_encode($clean),'customer'),'Performance samples accept only numeric fields');
    unlink($dir.'/diagnostics.lock'); mkdir($dir.'/diagnostics.lock');
    CSM_Timing::begin($id,'commit'); $result=CSM_Timing::measure('apply.preview',static fn()=>42); CSM_Timing::flush();
    check($result===42,'Unavailable diagnostic storage cannot interrupt work'); rmdir($dir.'/diagnostics.lock');
    echo 'MEASURED '.json_encode(['updates'=>$metrics['progress.update']['calls'],'progress_ms'=>$metrics['progress.update']['ms'],'writes'=>($metrics['progress.write']['calls']??0)+$metrics['progress.write_forced']['calls'],'write_ms'=>($metrics['progress.write']['ms']??0)+$metrics['progress.write_forced']['ms']]).PHP_EOL;
} finally { CSM_Timing::flush(); CSM_Support::remove_tree($dir); }
