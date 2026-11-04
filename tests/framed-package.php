<?php
// Real frame encoder/decoder and crash publication recovery, isolated from WordPress.
define('ABSPATH','/trickle-frame-fixture-'.bin2hex(random_bytes(6)).'/'); define('DAY_IN_SECONDS',86400);
function wp_salt($scheme){return 'frame-fixture';}
function apply_filters($hook,$value){return $hook==='csm_step_seconds'?.001:($hook==='csm_hash_seconds'?1:$value);}
function get_option($key,$default=false){return $default;}
class CSM_Access {static function owner(){return 'fixture';} static function assert_owner($record){}}
class CSM_Log {static function write(...$args){}}
class CSM_Progress {static function update(...$args){}}
require __DIR__.'/../includes/support.php';require __DIR__.'/../includes/jobs.php';require __DIR__.'/../includes/package.php';require __DIR__.'/../includes/segments.php';
$checks=0;function check($ok,$label){if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label."\n";}
$dir=CSM_Support::private_dir(); $path=$dir.'/incoming'; $bytes=random_bytes(4*1024*1024);
try {
 $fp=fopen($path,'wb');CSM_Package::put($fp,$bytes);fclose($fp);
 check(CSM_Support::hash_file($path)===hash_file('sha256',$path),'Cancellable checksum reader preserves ordinary SHA-256 compatibility');
 $bundle=['size'=>filesize($path),'sha256'=>hash_file('sha256',$path),'format'=>2];
 $first=CSM_Package::unpack($path,$bundle); check(!$first,'Frame verification yields with a durable cursor');
 file_put_contents($path.'.raw','unacknowledged junk',FILE_APPEND);
 for($i=0;$i<100 && !CSM_Package::unpack($path,$bundle);$i++){}
 check(hash_file('sha256',$path.'.raw')===hash('sha256',$bytes),'Resume truncates unacknowledged output and preserves exact bytes');
 $state=json_decode(file_get_contents($path.'.unpack'),true);
 check(!empty($state['verified']),'Complete compressed snapshot digest is verified as well as individual frames');
 $broken=$dir.'/corrupt';copy($path,$broken);$fp=fopen($broken,'r+b');fseek($fp,38);fwrite($fp,"bad");fclose($fp);$caught=false;
 try{CSM_Package::unpack($broken,$bundle);}catch(RuntimeException $e){$caught=str_contains($e->getMessage(),'Frame checksum');}
 check($caught,'Changed frame bytes cannot be staged');
 $bad=$dir.'/wrong-snapshot';copy($path,$bad);$wrong=$bundle;$wrong['sha256']=str_repeat('0',64);$caught=false;
 try{for($i=0;$i<100 && !CSM_Package::unpack($bad,$wrong);$i++){}}catch(RuntimeException $e){$caught=str_contains($e->getMessage(),'snapshot checksum');}
 check($caught,'Valid frames cannot substitute a different pinned snapshot');
 file_put_contents($path.'.raw','lost');$caught=false;try{CSM_Package::unpack($path,$bundle);}catch(RuntimeException $e){$caught=str_contains($e->getMessage(),'missing or truncated');}
 check($caught,'Missing acknowledged output fails rather than filling its checkpoint with zero bytes');
 $published=$dir.'/published.source';file_put_contents($published,'already-published');CSM_Support::atomic_json($published.'.build',['complete'=>true,'offset'=>17,'components'=>['plugins'=>17],'cursor'=>['done'=>true]]);
 $r=CSM_Package::build($published,'files',[]);check($r['size']===17 && $r['sha256']===hash('sha256','already-published'),'Crash between snapshot rename and metadata publication recovers the published snapshot');
 $id=bin2hex(random_bytes(16));$base=$dir.'/segment-'.$id.'-files.source';file_put_contents($base,'expired');CSM_Support::atomic_json($base.'.owner',['credential'=>'fixture']);touch($base.'.owner',time()-2*DAY_IN_SECONDS);
 $lock=fopen($base.'.lock','c');flock($lock,LOCK_EX);CSM_Segments::prune_sources();check(is_file($base),'Source reaper never deletes a locked active builder');flock($lock,LOCK_UN);fclose($lock);CSM_Segments::prune_sources();check(!is_file($base)&&!is_file($base.'.owner'),'Source independently removes expired packages and updater-blocking ownership files');
 class TrickleSlowChecksumStream {
  public $context;private int $n=0;
  function stream_open($path,$mode,$options,&$opened){return true;}
  function stream_read($count){usleep(300000);$this->n++;return str_repeat('x',min($count,8192));}
  function stream_eof(){return $this->n>=8;}
  function stream_stat(){return [];}
 }
 stream_wrapper_register('trickle-slow-checksum',TrickleSlowChecksumStream::class);$start=microtime(true);$caught=false;
 try{CSM_Support::hash_file('trickle-slow-checksum://fixture');}catch(RuntimeException $e){$caught=str_contains($e->getMessage(),'Checksum exceeded');}
 check($caught && microtime(true)-$start<2,'Slow checksum work releases its worker at a finite wall-clock boundary');stream_wrapper_unregister('trickle-slow-checksum');
 echo "$checks framed-package checks passed.\n";
} finally {CSM_Support::remove_tree($dir);}
