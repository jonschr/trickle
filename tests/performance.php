<?php
// Isolated file fixtures; real packaging, decoding, import and preview. No site or network.
$baseline=in_array('--baseline',$argv,true);$root=$baseline?($argv[2]??''):dirname(__DIR__);$dir=sys_get_temp_dir().'/trickle-perf-'.bin2hex(random_bytes(6));mkdir($dir,0700);
define('ABSPATH',$dir.'/public/');define('WP_CONTENT_DIR',ABSPATH.'wp-content');define('WP_PLUGIN_DIR',WP_CONTENT_DIR.'/plugins');define('CSM_FILE',$root.'/trickle.php');
mkdir(WP_PLUGIN_DIR.'/demo',0700,true);mkdir(WP_CONTENT_DIR.'/themes/child',0700,true);mkdir(WP_CONTENT_DIR.'/uploads/2026/01',0700,true);
$hashCalls=0;$budget=8;$cancel=false;$saved=[];
function apply_filters($name,$value,...$args){if($name==='csm_step_seconds')return $GLOBALS['budget'];if($name==='csm_hash_seconds')++$GLOBALS['hashCalls'];return $value;}
function wp_salt($name){return 'performance-fixture';}function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0700,true);}function wp_get_theme($id){return new class{function exists(){return false;}function get($key){return '';}};}function wp_upload_dir(...$a){return ['basedir'=>WP_CONTENT_DIR.'/uploads','error'=>false];}function plugin_basename($p){return 'trickle/trickle.php';}function get_file_data(...$a){return ['php'=>'','wp'=>'','name'=>'Fixture'];}function get_bloginfo($n){return '7.1';}
class CSM_Components{static function unchanged($s){return [];}static function verify($p,$s){return true;}}
class CSM_Activation{static function current(){return [];}static function source(&$j,$h){}}
class CSM_Progress{static function update(...$a){}static function read($id){return [];}}
class CSM_Jobs{static function save($job){$GLOBALS['saved'][$job['id']]=$job;}}
foreach(['support','files','package','plugin']as$part)require $root.'/includes/'.$part.'.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function countFrames($path){$fp=fopen($path,'rb');$count=0;while(!feof($fp)){ $h=fread($fp,36);if($h==='')break;if(strlen($h)!==36)throw new RuntimeException('bad frame');$len=unpack('N',substr($h,0,4))[1];fseek($fp,$len,SEEK_CUR);++$count;}fclose($fp);return $count;}
function packFixture($path,$selection){$steps=0;do{$bundle=CSM_Package::build($path,'files',$selection);if(++$steps>400)throw new RuntimeException('packaging did not advance');$state=json_decode(file_get_contents($path.'.build'),true);$roundtrip=json_decode(json_encode($state),true);if($state!==$roundtrip)throw new RuntimeException('Checkpoint not serializable');}while(!empty($bundle['continue']));return [$bundle,$steps];}
$private=CSM_Support::private_dir();
try{
 for($i=0;$i<400;$i++)file_put_contents(WP_PLUGIN_DIR.'/demo/file-'.$i.'.txt',str_repeat('small-'.$i,9));
 file_put_contents(WP_PLUGIN_DIR.'/demo/large.bin',str_repeat("large payload\n",240000));file_put_contents(WP_CONTENT_DIR.'/themes/child/style.css','/* Theme Name: Child */');
 // Mandatory exclusions and Git protection apply to the new walker as well.
 mkdir(WP_PLUGIN_DIR.'/demo/node_modules');file_put_contents(WP_PLUGIN_DIR.'/demo/node_modules/secret.txt','excluded');file_put_contents(WP_PLUGIN_DIR.'/demo/.env','secret');mkdir(WP_PLUGIN_DIR.'/demo/development');mkdir(WP_PLUGIN_DIR.'/demo/development/.git');file_put_contents(WP_PLUGIN_DIR.'/demo/development/private.txt','git protected');
 $selection=['plugins'=>['demo'],'themes'=>['child'],'media'=>'none','protect_source_git'=>true];$start=microtime(true);$hashCalls=0;[$bundle,$steps]=packFixture($private.'/one',$selection);$sourceHashes=$hashCalls;$frames=countFrames($private.'/one');$seconds=microtime(true)-$start;
 if(!$baseline)check($sourceHashes===1,'Single-pass source packaging only rereads the completed package, not individual source files');
 $budget=.003;$hashCalls=0;[$resumed,$requests]=packFixture($private.'/resumed',$selection);check($requests>1,'Packaging crosses serialized request checkpoints');
 $n=0;while(!CSM_Package::unpack($private.'/resumed',$resumed)){if(++$n>500)throw new RuntimeException('unpack stalled');}
 $job=['id'=>str_repeat('b',32),'selection'=>$selection+['tables'=>[]],'files'=>[],'database'=>[]];$fp=fopen($private.'/resumed.raw','rb');$n=0;do{$done=CSM_Files::stage_step($fp,$job);if(++$n>500)throw new RuntimeException('import stalled');}while(!$done);fclose($fp);
 check(count($job['files'])===402,'Resumed compressed frames import each eligible file exactly once');
 foreach($job['files']as$file)if(!hash_equals($file['sha256'],hash_file('sha256',$file['stage'])))throw new RuntimeException('Staged content differs');
 check(!array_filter($job['files'],fn($f)=>str_contains($f['path'],'secret')||str_contains($f['path'],'private')||str_contains($f['path'],'.env')),'Environment, node_modules and Git exclusions survive planning changes');
 if(!$baseline){
  // Preview's directory walk serializes actual entries instead of reopening completed directories.
  $previewSteps=0;do{$preview=CSM_Plugin::preview_step($job);if(++$previewSteps>500)throw new RuntimeException('preview stalled');}while(!$preview);
  check($preview['counts']['replace']===402,'Resumable preview discovers all replacement paths once');
  $receipt=null;$path=WP_PLUGIN_DIR.'/demo/file-0.txt';$expected=hash_file('sha256',$path);$hashCalls=0;CSM_Support::verify_file($path,$expected,$receipt);$freshCalls=$hashCalls;CSM_Support::verify_file($path,$expected,$receipt);
  check($hashCalls>=$freshCalls,'Fresh-file checks do not assume timestamps have settled');
  $old=time()-10;touch($path,$old); // ctime is intentionally still fresh; receipt must remain unusable.
  $receipt=null;CSM_Support::verify_file($path,$expected,$receipt);check($receipt===null,'Recent ctime prevents unsafe same-second verification reuse');
  usleep(3100000); // Verify real filesystem timestamps have settled before measuring receipt reuse.
  $receipt=null;$hashCalls=0;check(CSM_Support::verify_file($path,$expected,$receipt),'Stable file passes its first checksum');CSM_Support::verify_file($path,$expected,$receipt);CSM_Support::verify_file($path,$expected,$receipt);check($hashCalls===1&&$receipt!==null,'Three stable-file checks perform one full content read');
  file_put_contents($path,'changed');check(!CSM_Support::verify_file($path,$expected,$receipt),'Destination mutation invalidates verification');
  $walk=[];$visited=[];$budget=.00001;do{CSM_Support::step(1);foreach(CSM_Files::walk($walk,[[WP_PLUGIN_DIR.'/demo','plugins/demo']],$selection)as$entry){$visited[]=$entry[0];break;}}while($walk['pending']||$walk['active']);check(count($visited)===401&&count(array_unique($visited))===401,'Directory cursor resumes one file at a time without duplication');
  $budget=8;CSM_Support::step();$allowed=[];$walk=[];foreach(CSM_Files::walk($walk,[[WP_PLUGIN_DIR.'/demo/development','plugins/demo/development']],['protect_source_git'=>true,'git_overrides'=>['plugins/demo/development']])as$entry)$allowed[]=$entry[0];check($allowed===['plugins/demo/development/private.txt'],'Explicit Git-code inclusion retains code while excluding Git metadata');
  $walk=[];$budget=.00001;CSM_Support::step();usleep(20000);iterator_to_array(CSM_Files::walk($walk,[[WP_PLUGIN_DIR.'/demo','plugins/demo']],$selection));check(!empty($walk['pending']),'An expired traversal budget retains pending directories');$budget=8;CSM_Support::step();
  $cancelId=str_repeat('c',32);CSM_Support::work($cancelId,true);CSM_Support::cancel($cancelId);$stopped=false;$walk=[];try{foreach(CSM_Files::walk($walk,[[WP_PLUGIN_DIR.'/demo','plugins/demo']],$selection)as$entry){}}catch(CSM_Cancelled_Error$e){$stopped=true;}check($stopped,'Cancellation interrupts resumed directory traversal');$hashStopped=false;try{CSM_Support::hash_file($path);}catch(CSM_Cancelled_Error$e){$hashStopped=true;}check($hashStopped,'Cancellation also interrupts full-file checksum reads');unlink($private.'/'.$cancelId.'-cancel.json');CSM_Support::work($cancelId,false);
  $broken=$private.'/corrupt';copy($private.'/resumed',$broken);$fp=fopen($broken,'r+b');fseek($fp,40);fwrite($fp,'BAD');fclose($fp);$bad=false;try{CSM_Package::unpack($broken,$resumed);}catch(RuntimeException$e){$bad=str_contains($e->getMessage(),'checksum');}check($bad,'Corrupt compressed frames remain rejected');
 }
 echo 'METRICS '.json_encode(['baseline'=>$baseline,'files'=>402,'source_checksum_calls'=>$sourceHashes,'frames'=>$frames,'package_bytes'=>$bundle['size'],'initial_seconds'=>round($seconds,3),'resumed_requests'=>$requests])."\n";
}finally{CSM_Support::remove_tree($private);CSM_Support::remove_tree($dir);}
