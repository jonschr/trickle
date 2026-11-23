<?php
function wp_json_encode($value,$flags=0,$depth=512){return json_encode($value,$flags,$depth);}
// Real incremental media receipt, replay and publication on an isolated filesystem.
$root=sys_get_temp_dir().'/trickle-media-'.bin2hex(random_bytes(6));
define('ABSPATH',$root.'/public/');define('WP_CONTENT_DIR',ABSPATH.'wp-content');define('WP_PLUGIN_DIR',WP_CONTENT_DIR.'/plugins');define('CSM_FILE',dirname(__DIR__).'/trickle.php');
mkdir(WP_CONTENT_DIR.'/uploads/2026',0700,true);
function wp_upload_dir(...$args){return ['basedir'=>WP_CONTENT_DIR.'/uploads','error'=>false];}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
function wp_salt($name){return 'media-publication-fixture';}
function apply_filters($hook,$value,...$args){return $value;}
function wp_is_file_mod_allowed($context){return true;}
class CSM_Activation {static function source(&$job,$header){}}
class CSM_Progress {static function update(...$args){}}
class CSM_Jobs {static function save($job){}}
foreach(['support','package','files']as$part)require __DIR__.'/../includes/class-csm-'.$part.'.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function rejects($fn){try{$fn();}catch(Throwable $e){return true;}return false;}
function job(){return ['id'=>bin2hex(random_bytes(16)),'files'=>[],'selection'=>['media'=>'all','plugins'=>[],'themes'=>[]]];}
function payload($path,$bytes){
 $head=['type'=>'header','version'=>2,'kind'=>'files','started'=>time()];
 $file=['type'=>'file','path'=>$path,'size'=>strlen($bytes),'mtime'=>time()-10];
 $end=['type'=>'file-end','sha256'=>hash('sha256',$bytes)];
 $chain=CSM_Package::chain(CSM_Package::chain('',CSM_Support::json($file)),CSM_Support::json($end));
 return CSM_Support::json($head).CSM_Support::json($file).$bytes.CSM_Support::json($end).CSM_Support::json(['type'=>'end','files'=>1,'sha256'=>$chain]);
}
function receive(&$job,$bytes,?array &$output=null){
 $fp=fopen('php://temp','w+b');fwrite($fp,$bytes);rewind($fp);
 if(isset($job['imports']['files']))$job['imports']['files']['offset']=0;
 try{return CSM_Files::stage_step($fp,$job,false,true,$output);}finally{fclose($fp);}
}
$private=CSM_Support::private_dir();$path='uploads/2026/image.jpg';$target=WP_CONTENT_DIR.'/uploads/2026/image.jpg';$bytes=random_bytes(700000);
try {
 $held=job();$heldOutput=[];$heldWire=payload($path,$bytes);$streams=count(get_resources('stream'));
 receive($held,substr($heldWire,0,200500),$heldOutput);$handle=$heldOutput['handle'];
 receive($held,substr($heldWire,200500,200000),$heldOutput);
 check(is_resource($handle)&&$heldOutput['handle']===$handle,'Consecutive frames reuse one request-local output handle');
 check(receive($held,substr($heldWire,400500),$heldOutput)&&$heldOutput===[]&&!is_resource($handle)&&count(get_resources('stream'))===$streams,'File completion flushes and closes the retained output without leaking a descriptor');
 check(hash_file('sha256',$held['files'][0]['stage'])===hash('sha256',$bytes),'Retained output is fully visible and exact after file completion');CSM_Files::cleanup_media_parts($held);
 $swapped=job();$swappedOutput=[];receive($swapped,substr($heldWire,0,200500),$swappedOutput);$swappedPart=$swappedOutput['path'];
 rename($swappedPart,$swappedPart.'.old');file_put_contents($swappedPart,'substitute');
 check(rejects(function()use(&$swapped,&$swappedOutput,$heldWire){receive($swapped,substr($heldWire,200500),$swappedOutput);})&&file_get_contents($swappedPart)==='substitute','A retained descriptor rejects a replaced path between frames');
 CSM_Files::close_stage_output($swappedOutput);unlink($swappedPart.'.old');CSM_Files::cleanup_media_parts($swapped);
 file_put_contents($target,'original');$job=job();$wire=payload($path,$bytes);$split=200500;
 check(!receive($job,substr($wire,0,$split)),'An incomplete media file retains a resumable checkpoint');
 $part=$job['imports']['files']['current']['stage'];
 check(dirname($part)===dirname($target)&&str_ends_with($part,'.part')&&file_get_contents($target)==='original','Media receives beside its destination without overwriting the original');
 clearstatcache(true,$part);
 check((fileperms($part)&0777)===0600&&CSM_Files::excluded('uploads/2026/'.basename($part)),'Media parts restrict filesystem permissions and are excluded from later transfers');
 file_put_contents($part,'unacknowledged bytes',FILE_APPEND);
 $job=json_decode(json_encode($job),true);
 check(receive($job,substr($wire,$split)),'A new request truncates unacknowledged media bytes and resumes its saved checksum');
 $file=$job['files'][0];check($file['media_part']&&hash_file('sha256',$part)===hash('sha256',$bytes),'Completed media retains the exact source digest');
 $backup=$private.'/original';copy($target,$backup);$inode=fileinode($part);
 CSM_Files::publish_media($job,$file,$target);clearstatcache();
 check(!file_exists($part)&&fileinode($target)===$inode&&file_get_contents($target)===$bytes,'Publication renames the verified part without copying its bytes');
 CSM_Files::publish_media($job,$file,$target);
 check(file_get_contents($target)===$bytes,'Replaying publication after a lost apply acknowledgement preserves verified content');
 file_put_contents($target,'changed');check(rejects(fn()=>CSM_Files::publish_media($job,$file,$target)),'Missing-part recovery rejects a changed final file');
 copy($backup,$target);CSM_Files::cleanup_media_parts($job);
 check(file_get_contents($target)==='original','Part cleanup never deletes a restored original');
 $pending=job();receive($pending,substr($wire,0,$split));$pendingPart=$pending['imports']['files']['current']['stage'];
 CSM_Files::cleanup_media_parts($pending);
 check(!file_exists($pendingPart)&&file_get_contents($target)==='original','Cancellation cleans a partial file without changing live media');
 $bad=job();$badWire=str_replace(hash('sha256',$bytes),str_repeat('0',64),$wire);
 check(rejects(function()use(&$bad,$badWire){receive($bad,$badWire);})&&file_get_contents($target)==='original','Checksum failure cannot publish corrupt media');
 CSM_Files::cleanup_media_parts($bad);
 check(!glob(dirname($target).'/.trickle-'.$bad['id'].'-*.part'),'Cleanup also finds parts created before a job checkpoint was saved');
 $link=job();receive($link,substr($wire,0,$split));$linkPart=$link['imports']['files']['current']['stage'];unlink($linkPart);symlink($target,$linkPart);
 check(rejects(function()use(&$link,$wire,$split){receive($link,substr($wire,$split));})&&file_get_contents($target)==='original','A substituted symlink cannot redirect resumed media writes');
 unlink($linkPart);CSM_Files::cleanup_media_parts($link);
 $hard=job();receive($hard,substr($wire,0,$split));$hardPart=$hard['imports']['files']['current']['stage'];unlink($hardPart);link($target,$hardPart);
 check(rejects(function()use(&$hard,$wire,$split){receive($hard,substr($wire,$split));})&&file_get_contents($target)==='original','A hard-linked part cannot truncate another file during resume');
 unlink($hardPart);CSM_Files::cleanup_media_parts($hard);
 $parent=job();receive($parent,substr($wire,0,$split));rename(dirname($target),dirname($target).'-saved');mkdir(dirname($target).'-outside');symlink(dirname($target).'-outside',dirname($target));
 check(rejects(function()use(&$parent,$wire,$split){receive($parent,substr($wire,$split));}),'A substituted parent symlink cannot redirect resumed writes');
 unlink(dirname($target));rmdir(dirname($target).'-outside');rename(dirname($target).'-saved',dirname($target));CSM_Files::cleanup_media_parts($parent);
 $old=job();receive($old,substr($wire,0,$split));$oldStage=$private.'/'.$old['id'].'/old-media';rename($old['imports']['files']['current']['stage'],$oldStage);$old['imports']['files']['current']['stage']=$oldStage;
 check(receive($old,substr($wire,$split))&&!$old['files'][0]['media_part']&&hash_file('sha256',$oldStage)===hash('sha256',$bytes),'Older private media checkpoints retain their copy-based apply path when resumed');
 CSM_Files::cleanup_media_parts($old);
 check(rejects(function(){ $j=job();receive($j,payload('uploads/2026/unsafe.php','<?php'));}),'Executable media is rejected before a destination part is created');
 $empty=job();receive($empty,payload('uploads/2026/empty.jpg',''));CSM_Files::publish_media($empty,$empty['files'][0],WP_CONTENT_DIR.'/uploads/2026/empty.jpg');
 check(filesize(WP_CONTENT_DIR.'/uploads/2026/empty.jpg')===0,'Zero-byte media follows the same verified publication path');
 $legacy=job();$record=['type'=>'file','path'=>'uploads/2026/legacy.jpg','size'=>6,'mtime'=>time()-10];$end=['type'=>'file-end','sha256'=>hash('sha256','legacy')];
 $raw=CSM_Support::json(['type'=>'header','version'=>1,'kind'=>'files','started'=>time()]).CSM_Support::json($record).'legacy'.CSM_Support::json($end).CSM_Support::json(['type'=>'end','files'=>1,'sha256'=>hash('sha256',CSM_Support::json($record).CSM_Support::json($end))]);
 $fp=fopen('php://temp','w+b');fwrite($fp,$raw);rewind($fp);CSM_Files::stage($fp,$legacy);fclose($fp);
 check($legacy['files'][0]['media_part']&&file_get_contents($legacy['files'][0]['stage'])==='legacy','Legacy snapshot receipt also uses destination-adjacent media parts');
 CSM_Files::cleanup_media_parts($legacy);
} finally {CSM_Support::remove_tree($private);CSM_Support::remove_tree($root);}
