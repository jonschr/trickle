<?php
/** Full apply/backup cycles on a disposable Cove site only.
 * wp --path=/.../trickle-directory-qa-<suffix>.localhost/public eval-file tests/directory-cycle.php
 */
$requested=$args[0] ?? 'run';
if (!preg_match('#/Cove/Sites/trickle-directory-qa-[^/]+\.localhost/public/$#', ABSPATH) || get_option('_csm_control') || ($requested!=='recover' && get_option('_csm_active_job')) || get_option('_csm_backup_restore') || get_option('_csm_backups')) { throw new RuntimeException('Requires an idle disposable trickle-directory-qa-* site without backups.'); }
$root=WP_PLUGIN_DIR.'/directory-fixture';$theme=WP_CONTENT_DIR.'/themes/directory-fixture';$single=WP_PLUGIN_DIR.'/directory-single.php';$media=wp_upload_dir()['basedir'].'/directory-fixture.txt';
if($requested!=='recover' && (file_exists($root)||file_exists($theme)||file_exists($single)||file_exists($media)))throw new RuntimeException('Fixture already exists');
function dir_check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function dir_put($path,$bytes){wp_mkdir_p(dirname($path));file_put_contents($path,$bytes);}
function dir_complete($fn){$n=0;do{$result=$fn();if(++$n>1000)throw new RuntimeException('Cycle did not advance');}while(!empty($result['continue']));return $result;}
$short=static fn()=>.005;add_filter('csm_step_seconds',$short);$id=null;$tamper=null;
try{
 if($requested==='recover'){
  $id=get_option('_csm_active_job');if(!$id)throw new RuntimeException('No interrupted fixture transfer');
  dir_complete(static fn()=>CSM_Plugin::rollback(CSM_Jobs::get($id)));
  dir_check(file_get_contents($root.'/obsolete.php')==='old plugin'&&file_get_contents($theme.'/index.php')==='old theme'&&file_get_contents($media)==='old media','A fresh WordPress process recovers a hard-exited directory apply');
  CSM_Plugin::discard(CSM_Jobs::get($id));$id=null;return;
 }
 foreach($requested==='crash'?['crash']:['success','corrupt']as$mode){
  dir_put($root.'/fixture.php',"<?php\n/* Plugin Name: Directory fixture */\n");dir_put($root.'/same.txt','same');dir_put($root.'/obsolete.php','old plugin');dir_put($root.'/.env','private');mkdir($root.'/obsolete-empty');
  dir_put($theme.'/style.css',"/* Theme Name: Directory fixture */\n");dir_put($theme.'/index.php','old theme');dir_put($theme.'/obsolete.php','old theme extra');dir_put($single,"<?php\n/* Plugin Name: Directory single */\n");dir_put($media,'old media');
  $inode=fileinode($root);$themeInode=fileinode($theme);
  $r=CSM_Plugin::prepare(['plugins'=>['directory-fixture','directory-single.php'],'themes'=>['directory-fixture'],'tables'=>[],'media'=>'all','sync_active_plugins'=>false]);$id=$r['id'];$job=CSM_Jobs::get($id);$stage=CSM_Support::private_dir().'/'.$id;wp_mkdir_p($stage);
  $incoming=['plugins/directory-fixture/fixture.php'=>"<?php\n/* Plugin Name: Directory fixture */\n",'plugins/directory-fixture/same.txt'=>'same','plugins/directory-fixture/new.php'=>str_repeat("<?php /* fixture */ ?>\n",65536),'plugins/directory-single.php'=>"<?php\n/* Plugin Name: Directory single */\n// replaced\n",'themes/directory-fixture/style.css'=>"/* Theme Name: Directory fixture */\n",'themes/directory-fixture/index.php'=>'new theme','uploads/directory-fixture.txt'=>'new media'];
  foreach($incoming as$relative=>$bytes){$p=$stage.'/'.count($job['files']);file_put_contents($p,$bytes);$file=['path'=>$relative,'stage'=>$p,'size'=>strlen($bytes),'mtime'=>1700000000,'mode'=>0644,'sha256'=>hash('sha256',$bytes)];if(str_ends_with($relative,'same.txt'))$file['unchanged']=true;$job['files'][]=$file;}
  $job['received']['files']=true;CSM_Jobs::save($job);
  if($mode==='crash'){add_action('csm_checkpoint',static function($id,$phase){if($phase==='apply'){echo "INTENTIONAL HARD EXIT after component swaps\n";exit(77);}},10,2);}
  if($mode==='corrupt'){$tamper=static function($jobId,$phase)use($root){if($phase==='apply')file_put_contents($root.'/same.txt','corrupted unchanged file');};add_action('csm_checkpoint',$tamper,10,2);}
  $failure='';try{$r=dir_complete(static fn()=>CSM_Plugin::commit(CSM_Jobs::get($id)));}catch(Throwable$e){$failure=$e->getMessage();}
  if($tamper){remove_action('csm_checkpoint',$tamper,10);$tamper=null;}
  if($mode==='success'){
   clearstatcache();dir_check($failure===''&&$r['status']==='applied'&&$r['verification']['verified'],'Real bounded apply finishes with installed verification');
   dir_check(fileinode($root)!==$inode&&fileinode($theme)!==$themeInode&&!file_exists($root.'/obsolete.php')&&!file_exists($root.'/obsolete-empty')&&!file_exists($theme.'/obsolete.php'),'Plugins and themes replace whole directories');
   dir_check(file_get_contents($root.'/same.txt')==='same'&&file_get_contents($root.'/.env')==='private'&&str_contains(file_get_contents($single),'replaced')&&file_get_contents($media)==='new media','Unchanged files, exclusions, single-file plugins and media survive the full apply path');
   $listing=CSM_Backups::listing()['backups'][0];dir_check($listing['plugins']===['directory-fixture','directory-single.php']&&$listing['themes']===['directory-fixture']&&$listing['media'],'Saved backup listing records complete changed components and media');
   foreach([1,2]as$round){dir_put($root.'/later.php','later');$r=dir_complete(static fn()=>CSM_Backups::restore($id));dir_check(!file_exists($root.'/later.php')&&!file_exists($root.'/new.php')&&file_get_contents($root.'/obsolete.php')==='old plugin'&&file_get_contents($theme.'/index.php')==='old theme'&&file_get_contents($media)==='old media','Independent saved backup restore '.$round.' restores full components and media');}
  }else{
   // A bounded rollback may return continue before surfacing the original error.
   $j=CSM_Jobs::get($id);if($j['status']==='restoring')dir_complete(static fn()=>CSM_Plugin::rollback(CSM_Jobs::get($id)));
   dir_check(CSM_Jobs::get($id)['status']==='restored'&&file_get_contents($root.'/same.txt')==='same'&&file_get_contents($root.'/obsolete.php')==='old plugin'&&file_get_contents($media)==='old media','Corruption of a copied unchanged file triggers complete automatic rollback');
  }
  CSM_Plugin::discard(CSM_Jobs::get($id));$id=null;foreach([$root,$theme,$single,$media]as$p)CSM_Support::remove_tree($p);
 }
}finally{
 if($tamper)remove_action('csm_checkpoint',$tamper,10);remove_filter('csm_step_seconds',$short);
 if($id){$j=CSM_Jobs::get($id);if(in_array($j['status'],['applying','applied','restoring'],true))dir_complete(static fn()=>CSM_Plugin::rollback(CSM_Jobs::get($id)));CSM_Plugin::discard(CSM_Jobs::get($id));}
 foreach([$root,$theme,$single,$media]as$p)CSM_Support::remove_tree($p);
}
