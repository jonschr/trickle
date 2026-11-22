<?php
// Real file generator, frame codec, incremental importer and replay journals. Private fixtures only.
$root=sys_get_temp_dir().'/trickle-live-'.bin2hex(random_bytes(6));
define('ABSPATH',$root.'/public/');define('WP_CONTENT_DIR',ABSPATH.'wp-content');define('WP_PLUGIN_DIR',WP_CONTENT_DIR.'/plugins');define('CSM_FILE',dirname(__DIR__).'/trickle.php');
mkdir(WP_PLUGIN_DIR.'/demo',0700,true);mkdir(WP_CONTENT_DIR.'/themes',0700,true);mkdir(WP_CONTENT_DIR.'/uploads',0700,true);
mkdir(ABSPATH.'wp-admin/includes',0700,true);file_put_contents(ABSPATH.'wp-admin/includes/plugin.php','<?php');file_put_contents(ABSPATH.'wp-admin/includes/update.php','<?php');
$budget=.002;$hashReads=0;$saved=[];
function apply_filters($hook,$value,...$args){if($hook==='csm_step_seconds')return $GLOBALS['budget'];if($hook==='csm_hash_seconds')$GLOBALS['hashReads']++;return $value;}
function wp_salt($scheme){return 'live-fixture';}function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0700,true);}function wp_upload_dir(...$args){return ['basedir'=>WP_CONTENT_DIR.'/uploads','error'=>false];}
function wp_get_theme($id){return new class{function get($key){return '';}function exists(){return true;}};}
function plugin_basename($p){return 'trickle/trickle.php';}function get_option($key,$default=false){return $default;}
function get_plugins(){return ['demo/main.php'=>['Name'=>'Demo','Version'=>'1','PluginURI'=>'']];}function get_site_transient($name){return false;}function get_template(){return 'default';}function get_stylesheet(){return 'default';}function esc_url_raw($url,...$args){return $url;}
function get_theme_roots(){return [];}function wp_get_themes(){return [];}
class CSM_Activation {static function current(){return [];}static function source(&$job,$header){}}
class CSM_Progress {static function update(...$args){CSM_Support::check_cancel();}static function read($id){return [];}static function run(){return 'fixture';}}
class CSM_Jobs {static function save($job){$GLOBALS['saved'][$job['id']]=json_decode(json_encode($job),true);}}
class CSM_Updates {static function assert_job($job){}}
class CSM_Access {static function owner(){return 'fixture';}static function assert_owner($state){if(isset($state['credential'])&&$state['credential']!=='fixture')throw new RuntimeException('Foreign owner');}}
class CSM_Log {static function clean($s){return $s;}static function write(...$args){}}
class CSM_Plugin {static function kinds($s){return ['files'];}static function summary($job){return ['id'=>$job['id'],'files'=>count($job['files']??[]),'status'=>$job['status']];}static function receive_step($job,$kind,$fp,$continuing){$job['received'][$kind]=true;CSM_Jobs::save($job);return $job;}}
class CSM_Network_Error extends RuntimeException {}class CSM_Busy_Error extends CSM_Network_Error {}
foreach(['support','files','components','package','live-stream','segments']as$part)require __DIR__.'/../includes/'.$part.'.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function newJob(){return ['id'=>bin2hex(random_bytes(16)),'status'=>'staging','selection'=>['plugins'=>['demo'],'themes'=>[],'tables'=>[],'media'=>'none'],'files'=>[],'database'=>[],'received'=>[]];}
function selection(){return ['plugins'=>['demo'],'themes'=>[],'tables'=>[],'media'=>'none','file_policy'=>1];}
function window($id,$selection,$seq){return implode('',iterator_to_array(CSM_Live_Stream::source($id,$selection,$seq)));}
function deliver(&$job,$selection,$bytes){$writer=new CSM_Live_Stream($job,hash('sha256',CSM_Support::json($selection)));foreach(str_split($bytes,997)as$part)$writer->write($part);$writer->finish();}
function rejects($fn,$fragment){try{$fn();}catch(Throwable$e){return str_contains($e->getMessage(),$fragment);}return false;}
function window_chain($encoded,$format){$chain='';$header=null;$checkpoint=null;$dataFrames=0;for($p=0;$p<strlen($encoded);){$field=unpack('N',substr($encoded,$p,4))[1];$n=36+($field&0x7fffffff);$frame=substr($encoded,$p,$n);$p+=$n;if($field&0x80000000){$r=json_decode(gzdecode(substr($frame,36)),true);if($r['type']==='window')$header=$r;else$checkpoint=$r;}else{$chain=hash('sha256',$chain.($format===2?substr($frame,0,36):hash('sha256',$frame)));$dataFrames++;}}return[$header,$checkpoint,$chain,$dataFrames];}
$private=CSM_Support::private_dir();$data=random_bytes(6*1024*1024+123);
file_put_contents(WP_PLUGIN_DIR.'/demo/main.php','<?php /* Plugin Name: Demo */');file_put_contents(WP_PLUGIN_DIR.'/demo/large.bin',$data);file_put_contents(WP_PLUGIN_DIR.'/demo/empty','');file_put_contents(WP_PLUGIN_DIR.'/demo/.env','secret');
try{
 $job=newJob();$selection=selection();$seq=0;$windows=[];$before=$hashReads;
 do{
  $encoded=window($job['id'],$selection,$seq);$windows[]=$encoded;
  check($encoded===window($job['id'],$selection,$seq),'An unacknowledged window replays exactly, including its frame hashes');
  $copy=$job;$writer=new CSM_Live_Stream($copy,hash('sha256',CSM_Support::json($selection)));
  $writer->write(substr($encoded,0,-9));check(rejects(fn()=>$writer->finish(),'Incomplete'),'A disconnected response cannot acknowledge an incomplete window');
  // Reload persisted receiver state; replay must truncate unacknowledged staged bytes.
  deliver($job,$selection,$encoded);$job=$saved[$job['id']];$seq++;
  if($seq>200)throw new RuntimeException('No stream completion');
 }while(empty($job['live_files']['done']));
 check($seq>1,'The large file spans independently resumable request windows');
 check($hashReads===$before,'Source and importer resume SHA-256 contexts without rereading file contents');
 check(count($job['files'])===3,'Selected files, including empty files, arrive once and excluded secrets never arrive');
 foreach($job['files']as$file){check(hash_file('sha256',$file['stage'])===$file['sha256'],'Streamed file matches its source checksum');}
 check(!is_file($private.'/segment-'.$job['id'].'-files.source')&&!glob($private.'/*.incoming*'),'Neither end creates a full archive or decompressed intermediate stream');
 check(file_get_contents(WP_PLUGIN_DIR.'/demo/large.bin')===$data,'Streaming never mutates live destination/source files');
 // Metadata-matching components generate no source or receiver content reads until receipt is complete.
 $metadataSelection=selection();$inventory=CSM_Components::inventory(['selection'=>$metadataSelection,'source'=>true]);
 $metadataSelection['component_same']=base64_encode(gzencode(CSM_Support::json(['component_token'=>$inventory['token']])));
 $matchingJob=newJob();$before=$hashReads;$budgetBefore=$budget;$budget=8;
 $matchingWindow=window($matchingJob['id'],$metadataSelection,0);deliver($matchingJob,$metadataSelection,$matchingWindow);
 check($hashReads===$before && count($matchingJob['files'])===3,'Matching files skip source and destination content hashing while transferring metadata');
 foreach($matchingJob['files'] as &$matchingFile){
  check(!isset($matchingFile['sha256']) && isset($matchingFile['comparison_stamp']),'Metadata-only skip has no manufactured content checksum');
  $target=CSM_Files::destination($matchingFile['path'],$matchingJob['selection']);
  check(CSM_Files::verify_unchanged($target,$matchingFile) && $matchingFile['sha256']===hash_file('sha256',$target),'Post-transfer verification establishes the actual local recovery checksum');
 }unset($matchingFile);$budget=$budgetBefore;
 $changedFile=array_values(array_filter($matchingJob['files'],static fn($f)=>$f['size']>0))[0];$target=CSM_Files::destination($changedFile['path'],$matchingJob['selection']);$original=file_get_contents($target);$oldTime=filemtime($target);
 file_put_contents($target,str_repeat('X',strlen($original)));touch($target,$oldTime);
 check(!CSM_Files::verify_unchanged($target,$changedFile),'A post-receipt content change is rejected even when size and mtime are preserved');
 file_put_contents($target,$original);touch($target,$oldTime);
 $oldBudget=$budget;$budget=8;$vector=newJob();$encoded=window($vector['id'],$selection,0);[$h,$end,$expected,$frames]=window_chain($encoded,2);check($h['chain_format']===2&&$frames>10&&$end['chain']===$expected,'New windows commit each frame length, control bit and verified payload digest in their ordered chain');deliver($vector,$selection,$encoded);
 $headLength=36+(unpack('N',substr($encoded,0,4))[1]&0x7fffffff);$h['chain_format']=3;$unsupported=CSM_Package::frame(CSM_Support::json($h),true).substr($encoded,$headLength);$unsupportedJob=newJob();$unsupportedJob['id']=$vector['id'];check(rejects(function()use($unsupportedJob,$selection,$unsupported){$j=$unsupportedJob;deliver($j,$selection,$unsupported);},'Unsupported stream checksum'),'Unknown checksum formats fail before staging data');
 $legacy=newJob();$legacyDir=$private.'/segment-'.$legacy['id'].'-files.source.live';mkdir($legacyDir,0700);$initial=json_decode(file_get_contents($private.'/segment-'.$vector['id'].'-files.source.live/state.json'),true);unset($initial['chain_format']);CSM_Support::atomic_json($legacyDir.'/state.json',$initial);$oldWindow=window($legacy['id'],$selection,0);[$h,$end,$expected,$frames]=window_chain($oldWindow,1);check(!isset($h['chain_format'])&&$end['chain']===$expected,'Older source journals retain their original full-frame checksum format');
 $writer=new CSM_Live_Stream($legacy,hash('sha256',CSM_Support::json($selection)));$writer->write(substr($oldWindow,0,intdiv(strlen($oldWindow),2)));$writer->save_partial();$legacy=$saved[$legacy['id']];check($legacy['live_partial']['chain_format']===1,'Older partial windows retain their original checksum mode');$offset=$legacy['live_partial']['offset'];unlink($legacyDir.'/window');unlink($legacyDir.'/next.json');$tail=implode('',iterator_to_array(CSM_Live_Stream::source($legacy['id'],$selection,0,$offset)));check($tail===substr($oldWindow,$offset),'An interrupted old producer regenerates the exact original replay bytes');deliver($legacy,$selection,$tail);check($legacy['live_files']['done'],'Legacy partial windows finish through the current importer');$budget=$oldBudget;
 $dir=$private.'/segment-'.$job['id'].'-files.source.live';
 check(count(glob($dir.'/window*'))===1&&filesize($dir.'/window')<strlen($data),'Only the latest replay window remains retained');
 // Corrupt/reorder data and bind every stream to its job, selection and sequence.
 // Verified frame prefixes survive a connection that never delivers its window trailer.
 $partialJob=newJob();$partialWindow=window($partialJob['id'],$selection,0);$headLength=36+(unpack('N',substr($partialWindow,0,4))[1]&0x7fffffff);
 $cut=max($headLength,strlen($partialWindow)-17);$streams=count(get_resources('stream'));$writer=new CSM_Live_Stream($partialJob,hash('sha256',CSM_Support::json($selection)));$writer->write(substr($partialWindow,0,$cut));
 check($writer->save_partial(),'A timed-out window checkpoints its complete frames without claiming completion');
 check($partialJob['live_partial']['chain_format']===2,'New partial windows persist their checksum format for header-free resumption');
 check(count(get_resources('stream'))===$streams,'Publishing a partial checkpoint closes its request-local output descriptor');
 $partialJob=$saved[$partialJob['id']];$offset=$partialJob['live_partial']['offset'];
 $tail=implode('',iterator_to_array(CSM_Live_Stream::source($partialJob['id'],$selection,0,$offset)));
 check($tail===substr($partialWindow,$offset),'Source replay resumes exactly at the last verified frame boundary');
 deliver($partialJob,$selection,$tail);check($partialJob['live_files']['seq']===1&&!isset($partialJob['live_partial']),'A frame-prefix retry commits the window once and clears the partial cursor');
 // Rebuild an interrupted producer from its pinned timestamp and starting cursor.
 $regen=newJob();$oldBudget=$budget;$budget=8;$full=window($regen['id'],$selection,0);$budget=$oldBudget;
 $writer=new CSM_Live_Stream($regen,hash('sha256',CSM_Support::json($selection)));$writer->write(substr($full,0,intdiv(strlen($full),2)));$writer->save_partial();$regen=$saved[$regen['id']];
 $partialFile=$regen['imports']['files']['current'];check($partialFile&&$partialFile['written']>0,'The resume fixture contains an acknowledged partial file');file_put_contents($partialFile['stage'],'unacknowledged trailing bytes',FILE_APPEND);
 $d=$private.'/segment-'.$regen['id'].'-files.source.live';unlink($d.'/window');unlink($d.'/next.json');$offset=$regen['live_partial']['offset'];
 $budget=8;$tail=implode('',iterator_to_array(CSM_Live_Stream::source($regen['id'],$selection,0,$offset)));$budget=$oldBudget;deliver($regen,$selection,$tail);
 check($regen['live_files']['done'],'A crashed producer can rebuild a lost replay and resume its verified prefix');
 check(hash_file('sha256',$partialFile['stage'])===hash('sha256',$data),'A fresh request truncates unacknowledged staged bytes and preserves the source checksum');
 $foreign=bin2hex(random_bytes(16));CSM_Support::atomic_json($private.'/segment-'.$foreign.'-files.comparison.owner',['credential'=>'foreign']);
 check(rejects(fn()=>window($foreign,$selection,0),'Foreign owner'),'Source streaming respects retained comparison ownership');
 $fresh=newJob();$first=window($fresh['id'],$selection,0);$bad=$first;$bad[40]=chr(ord($bad[40])^1);
 check(rejects(function()use($fresh,$selection,$bad){$j=$fresh;deliver($j,$selection,$bad);},'checksum'),'Corrupt compressed frames cannot be staged');
 // Even a recompressed frame with a valid transport checksum cannot forge file contents.
 $corruptJob=newJob();$oldBudget=$budget;$budget=8;$corruptWindow=window($corruptJob['id'],$selection,0);$budget=$oldBudget;
 $altered='';$pos=0;$changedBody=false;
 while($pos<strlen($corruptWindow)){$field=unpack('N',substr($corruptWindow,$pos,4))[1];$length=$field&0x7fffffff;$frame=substr($corruptWindow,$pos,36+$length);$raw=gzdecode(substr($frame,36));if(!$changedBody&&str_starts_with($raw,'<?php')){$raw[0]='!';$frame=CSM_Package::frame($raw);$changedBody=true;}$altered.=$frame;$pos+=36+$length;}
 check($changedBody&&rejects(function()use($corruptJob,$selection,$altered){$j=$corruptJob;deliver($j,$selection,$altered);},'File checksum'),'Per-file SHA-256 catches changed data even when the compressed frame checksum is valid');
 $other=newJob();check(rejects(function()use($other,$selection,$first){$j=$other;deliver($j,$selection,$first);},'does not match'),'Another job cannot reuse a valid stream window');
 $changed=$selection;$changed['media']='all';check(rejects(fn()=>window($fresh['id'],$changed,0),'another selection'),'A source refuses changed selections during retry');
 check(rejects(fn()=>window($fresh['id'],$selection,3),'sequence'),'Unacknowledged source windows cannot be skipped');
 check(rejects(function()use($fresh,$selection,$first){$j=$fresh;deliver($j,$selection,$first.'extra');},'Unexpected bytes'),'Trailing data cannot turn into a successful checkpoint');
 // Simulate a crash after source acknowledgement is committed but before the old replay is removed.
 deliver($fresh,$selection,$first);$d=$private.'/segment-'.$fresh['id'].'-files.source.live';copy($d.'/next.json',$d.'/state.json');
 $next=window($fresh['id'],$selection,1);deliver($fresh,$selection,$next);
 check($fresh['live_files']['seq']===2,'Source acknowledgement recovery removes a stale replay after a crash');
 // Changes to pinned source files surface as explicit errors, rather than endless network retries.
 $changedJob=newJob();$bytes=window($changedJob['id'],$selection,0);deliver($changedJob,$selection,$bytes);$path=WP_PLUGIN_DIR.'/demo/large.bin';file_put_contents($path,'changed');
 $failed=false;for($i=1;$i<20;$i++){try{$bytes=window($changedJob['id'],$selection,$i);deliver($changedJob,$selection,$bytes);}catch(RuntimeException$e){$failed=str_contains($e->getMessage(),'Source stream failed');break;}}
 check($failed,'Source mutation produces a specific source error before installation');
 if(in_array('--large',$argv,true)){
  $budget=8;$size=1024*1024*1024+17;$fp=fopen(WP_PLUGIN_DIR.'/demo/large.bin','wb');ftruncate($fp,$size);fclose($fp);
  $large=newJob();$seq=0;do{$writer=new CSM_Live_Stream($large,hash('sha256',CSM_Support::json($selection)));foreach(CSM_Live_Stream::source($large['id'],$selection,$seq)as$frame)$writer->write($frame);$writer->finish();$large=$saved[$large['id']];$seq++;if($seq>100)throw new RuntimeException('Large stream stalled');}while(empty($large['live_files']['done']));
  $file=array_values(array_filter($large['files'],static fn($f)=>str_ends_with($f['path'],'large.bin')))[0];
  check($seq>=ceil($size/CSM_Live_Stream::WINDOW_BYTES)&&filesize($file['stage'])===$size&&hash_file('sha256',$file['stage'])===$file['sha256'],'A file larger than 1 GiB streams across bounded windows with an exact final disk checksum');
 }
 CSM_Segments::cleanup_source($job['id']);check(!is_dir($dir),'Existing source cleanup removes live replay journals');
 echo "Live stream fixtures passed.\n";
}finally{CSM_Support::remove_tree($private);CSM_Support::remove_tree($root);}
