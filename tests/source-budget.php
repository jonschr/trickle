<?php
// Real snapshot writer with an intentionally slow generator; no WordPress or network.
define('ABSPATH', '/trickle-budget-fixture-'.bin2hex(random_bytes(8)).'/');
function wp_salt($scheme){return 'offline-budget-test';}
function apply_filters($hook,$value){return $hook==='csm_source_packaging_seconds'?1:$value;}
class CSM_Access{static function owner(){return 'test';}static function assert_owner($value){}}
class CSM_Files{static bool $slow=true;static function selection($s){return $s;}}
class CSM_Database{static function validate($s){}static function stream($s,$chain=false):Generator{for($i=0;$i<4;$i++){if(CSM_Files::$slow)usleep(300000);yield str_repeat('data',5000);}}}
require __DIR__.'/../includes/support.php';require __DIR__.'/../includes/package.php';require __DIR__.'/../includes/segments.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$id=bin2hex(random_bytes(16));$dir=CSM_Support::private_dir();$path=$dir.'/segment-'.$id.'-database.source';$limit=ini_get('max_execution_time');
try{
    $start=microtime(true);$error=null;
    try{CSM_Segments::bundle($id,'database',['tables'=>[]]);}catch(RuntimeException $e){$error=$e;}
    check($error&&str_contains($error->getMessage(),'exceeded the 1-second limit')&&microtime(true)-$start<2,'Slow packaging stops at its wall-clock budget with a terminal error');
    check(!file_exists($path.'.part')&&!file_exists($path)&&!file_exists($path.'.json'),'Incomplete package is removed and never advertised as a snapshot');
    $lock=fopen($path.'.lock','c');check(flock($lock,LOCK_EX|LOCK_NB),'Timeout releases the source packaging lock');fclose($lock);
    CSM_Support::check_source_budget();check(ini_get('max_execution_time')===$limit,'Deadline scope and PHP execution limit are restored');
    CSM_Files::$slow=false;$bundle=CSM_Segments::bundle($id,'database',['tables'=>[]]);
    check(hash_file('sha256',$path)===$bundle['sha256'],'A subsequent small package succeeds with its full digest');
    CSM_Files::$slow=true;$start=microtime(true);$cached=CSM_Segments::bundle($id,'database',['tables'=>[]]);
    check($cached===$bundle&&microtime(true)-$start<.2,'Completed snapshot is reused without rebuilding');
}finally{CSM_Support::end_source_budget();CSM_Support::remove_tree($dir);}
