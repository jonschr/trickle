<?php
// Real progress files under an isolated temporary directory; no WordPress or network.
define('ABSPATH','/fixture/');
$fixture=sys_get_temp_dir().'/trickle-activity-'.bin2hex(random_bytes(6));mkdir($fixture,0700);
class CSM_Support {
    static function private_dir(){return $GLOBALS['fixture'];}
    static function json($value){return json_encode($value)."\n";}
    static function work(...$args){}
    static function check_source_budget(){}
}
class CSM_Log {static array $entries=[];static function write($level,$event,$context){self::$entries[]=$context;}}
require __DIR__.'/../includes/progress.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$id=str_repeat('a',32);
try{
    CSM_Progress::start($id,'Building source stream');
    foreach(['plugins/first','plugins/second','themes/child','uploads/2025/01','uploads/2025/02','uploads/2026/01']as$component){
        for($i=0;$i<500;$i++)CSM_Progress::update(['phase'=>'Packaging file','component'=>$component.'/'.$i.'.php']);
    }
    CSM_Progress::update([],true);$progress=CSM_Progress::read($id);
    $components=array_values(array_filter(array_column($progress['events'],'component')));
    check($components===['plugins/first','plugins/second','themes/child','uploads/2025','uploads/2026'],'Thousands of files retain distinct plugin, theme and media-year milestones before polling');
    check($progress['component']==='uploads/2026/01/499.php','Current progress retains the exact filename');
    CSM_Progress::update(['phase'=>'Comparing media months','component'=>'2026/02'],true);
    check(end(CSM_Progress::read($id)['events'])['component']==='uploads/2026','Media month comparisons group under their year');
    CSM_Progress::finish('Files verified');
    check(end(CSM_Progress::read($id)['events'])['component']==='','Stream completion does not claim only the last plugin finished');
    CSM_Progress::start($id,'Checking files');
    CSM_Progress::update(['phase'=>'Checking PHP compatibility','component'=>'plugins/first/includes/bad.php']);
    CSM_Progress::fail('Parse error');
    check(end(CSM_Log::$entries)['component']==='plugins/first/includes/bad.php','Failure diagnostics retain the exact filename');
    CSM_Progress::start($id,'Continuing checks');
    for($i=0;$i<100;$i++)CSM_Progress::update(['phase'=>'Staging table','component'=>'wp_table_'.$i]);
    CSM_Progress::update([],true);$events=CSM_Progress::read($id)['events'];
    check(count($events)===65,'History is bounded to 64 milestones plus separately retained issues');
    $failures=array_values(array_filter($events,static fn($e)=>($e['state']??'')==='failed'));
    check(count($failures)===1&&$failures[0]['failure']['component']==='plugins/first/includes/bad.php','Routine events do not evict failures across requests');
    CSM_Progress::finish('Checks complete');
}finally{
    foreach(glob($fixture.'/*')as$file)unlink($file);rmdir($fixture);
}
