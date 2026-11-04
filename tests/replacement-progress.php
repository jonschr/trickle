<?php
// Exercise the real replacement cursor with disposable files, including a bounded continuation.
define('ABSPATH','/fixture/');
$fixture=sys_get_temp_dir().'/trickle-replace-meter-'.bin2hex(random_bytes(6));mkdir($fixture,0700);
$limit=2;$steps=0;
class CSM_Support {
    static function private_dir(){return $GLOBALS['fixture'];}
    static function atomic_json($path,$value){file_put_contents($path,json_encode($value));}
    static function due(){return ++$GLOBALS['steps'] >= $GLOBALS['limit'];}
}
class CSM_Progress {static array $current=[];static function update($detail){self::$current=array_replace(self::$current,$detail);}}
class CSM_Jobs {static function save($job){}}
class CSM_Files {static function destination($path,$selection){return $GLOBALS['targets'][$path];}}
function do_action(...$args){}
require __DIR__.'/../includes/plugin.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
try{
    $targets=[];foreach(['plugins/first/a.php','plugins/second/b.php','themes/child/style.css','uploads/2026/01/a.jpg']as$index=>$path){$targets[$path]=$fixture.'/file-'.$index;file_put_contents($targets[$path],'fixture');}
    $job=['id'=>str_repeat('a',32),'selection'=>[],'apply_work'=>['cursor'=>0],'file_plan'=>['paths'=>$targets,'new'=>[]]];
    $method=new ReflectionMethod(CSM_Plugin::class,'replace_files');$cursor=$fixture.'/cursor.json';
    $done=$method->invokeArgs(null,[&$job,$cursor]);
    check(!$done&&$job['apply_work']['cursor']===2,'Bounded replacement retains its real cursor');
    check(CSM_Progress::$current['replacement']===['plugins'=>['done'=>2,'total'=>2],'themes'=>['done'=>0,'total'=>1],'media'=>['done'=>0,'total'=>1]],'Only completed plugin files are shown as replaced');
    $limit=100;$steps=0;$done=$method->invokeArgs(null,[&$job,$cursor]);
    check($done&&$job['apply_work']['cursor']===4,'Replacement continues from the checkpoint');
    check(CSM_Progress::$current['replacement']===['plugins'=>['done'=>2,'total'=>2],'themes'=>['done'=>1,'total'=>1],'media'=>['done'=>1,'total'=>1]],'Per-component counts survive continuation without double counting');
    check(!array_filter($targets,'is_file'),'Counters advance after the actual file operation');
}finally{
    foreach(glob($fixture.'/*')as$file)unlink($file);rmdir($fixture);
}
