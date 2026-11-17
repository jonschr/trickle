<?php
// Offline, separate-process checks of the real request-draining lock.
define('ABSPATH', $argv[1] ?? '/trickle-runtime-'.bin2hex(random_bytes(8)).'/');
function wp_salt($scheme){return 'runtime-fixture';}
require __DIR__.'/../includes/support.php';
$mode=$argv[2] ?? 'parent';
if($mode!=='parent'){
 $ok=CSM_Support::runtime_lock($mode==='exclusive');echo $ok?"yes\n":"no\n";flush();
 if($mode==='hold'&&$ok)fgets(STDIN);
 exit;
}
function child($mode,&$pipes){return proc_open([PHP_BINARY,__FILE__,ABSPATH,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);}
function probe($mode){$p=child($mode,$pipes);fclose($pipes[0]);$out=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('Child failed');return $out==='yes';}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$p=child('hold',$pipes);
try{
 check(trim(fgets($pipes[1]))==='yes','Ordinary request holds shared gate');
 check(probe('shared'),'Ordinary requests can run concurrently');
 check(!CSM_Support::runtime_lock(true),'Apply waits for an existing ordinary request');
 fclose($pipes[0]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);$p=null;
 check(CSM_Support::runtime_lock(true),'Apply acquires gate after existing request finishes');
 check(!probe('shared'),'No ordinary plugin request starts during apply');
 check(!probe('exclusive'),'A second apply cannot enter the same runtime');
 check(CSM_Support::runtime_lock(true),'Continued apply in the same request retains its gate');
}finally{
 if(is_resource($p)){fclose($pipes[0]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);}
 $dir=CSM_Support::private_dir();unlink($dir.'/runtime.lock');rmdir($dir);
}
