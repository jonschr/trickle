<?php
/** Destructive file-apply checks, restricted to the idle QA-C fixture. */
if (!defined('ABSPATH') || realpath(ABSPATH) !== realpath(getenv('HOME').'/Cove/Sites/trickle-qa-c.localhost/public') || get_option('_csm_control') || get_option('_csm_active_job')) { throw new RuntimeException('Requires idle trickle-qa-c.'); }
$component = 'trickle-plan-fixture';
$root = WP_PLUGIN_DIR.'/'.$component;
if (file_exists($root)) { throw new RuntimeException('Fixture already exists.'); }
$original_backups = get_option('_csm_backups', []); update_option('_csm_backups', [], false);
$checks=0;$jobs=[];$stage=CSM_Support::private_dir().'/file-plan-test-'.bin2hex(random_bytes(4));
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$make=static function()use($root,$stage,$component,&$jobs):array{
 wp_mkdir_p($root);wp_mkdir_p($stage);
 file_put_contents($root.'/a.txt','original-a');chmod($root.'/a.txt',0600);touch($root.'/a.txt',1600000000);
 file_put_contents($root.'/obsolete.txt','original-obsolete');
 $summary=CSM_Plugin::prepare(['plugins'=>[$component],'sync_active_plugins'=>false]);$jobs[]=$summary['id'];$job=CSM_Support::job($summary['id']);
 foreach(['a','b','c'] as $name){
  $p=$stage.'/'.$name;file_put_contents($p,'new-'.$name);
  $job['files'][]=['path'=>'plugins/'.$component.'/'.$name.'.txt','stage'=>$p,'size'=>filesize($p),'mtime'=>1700000000,'mode'=>0644,'sha256'=>hash_file('sha256',$p)];
 }
 $job['received']['files']=true;CSM_Support::save($job);return $job;
};
try{
 $job=$make();$writes=0;$per_file=[];
 $watch=static function($sql)use(&$writes,$job){if(str_starts_with($sql,'UPDATE ')&&str_contains($sql,'_csm_job_'.$job['id']))$writes++;return $sql;};
 $checkpoint=static function($id,$phase)use(&$per_file,&$writes,$job){if($id===$job['id']&&$phase==='apply')$per_file[]=$writes;};
 add_filter('query',$watch);
 add_action('csm_checkpoint',$checkpoint,10,2);
 $result=CSM_Plugin::commit($job);
 remove_filter('query',$watch);
 remove_action('csm_checkpoint',$checkpoint,10);
 $job=CSM_Support::job($job['id']);
 $check($result['status']==='applied'&&file_get_contents($root.'/a.txt')==='new-a'&&file_get_contents($root.'/b.txt')==='new-b','Applies replacement and new files');
 $check(!file_exists($root.'/obsolete.txt'),'Removes obsolete component files');
 $check(count($per_file)===4&&count(array_unique($per_file))===1,'Per-file replacement uses its tiny journal without rewriting the database job per file');
 $planpath=$job['backup_dir'].'/operations.json';$plan=file_get_contents($planpath);
 $ops=json_decode($plan,true);
 $check(count($ops)===4&&$job['ops']===[]&&hash_equals($job['ops_hash'],hash('sha256',$plan)),'Complete checksummed plan includes all replaced, new and obsolete files');
 $check((fileperms($planpath)&0777)===0600,'Recovery plan is private');
 file_put_contents($planpath,$plan.' ');$failed=false;
 try{CSM_Plugin::rollback($job);}catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'recovery plan');}
 $check($failed&&file_get_contents($root.'/a.txt')==='new-a','Changed recovery plan is rejected before restoration');
 file_put_contents($planpath,$plan);
 $job['status']='applying';CSM_Support::save($job);
 $result=CSM_Plugin::rollback($job);
 $check($result['status']==='restored'&&file_get_contents($root.'/a.txt')==='original-a'&&file_get_contents($root.'/obsolete.txt')==='original-obsolete','Restores interrupted application from complete plan');
 $check(!file_exists($root.'/b.txt')&&!file_exists($root.'/c.txt'),'Rollback removes new files');
 clearstatcache();$check((fileperms($root.'/a.txt')&0777)===0600&&filemtime($root.'/a.txt')===1600000000,'Rollback restores original mode and timestamp');
 CSM_Plugin::discard(CSM_Support::job($job['id']));
 $job=$make();$injected=false;
 $break=static function($sql)use(&$injected,$job,$stage){
  if(!$injected&&str_contains($sql,'applying')&&str_starts_with($sql,'UPDATE ')&&str_contains($sql,'_csm_job_'.$job['id'])){$injected=true;unlink($stage.'/c');}
  return $sql;
 };
 add_filter('query',$break);$failed=false;
 try{CSM_Plugin::commit($job);}catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'rolled back');}
 remove_filter('query',$break);
 $check($failed&&CSM_Support::job($job['id'])['status']==='restored','A mid-apply failure automatically rolls back');
 $check(file_get_contents($root.'/a.txt')==='original-a'&&file_get_contents($root.'/obsolete.txt')==='original-obsolete'&&!file_exists($root.'/b.txt')&&!file_exists($root.'/c.txt'),'Automatic rollback restores files already changed and leaves untouched originals intact');
 CSM_Plugin::discard(CSM_Support::job($job['id']));
 $job=$make();
 $reject=static function($sql)use($job){return str_starts_with($sql,'UPDATE ')&&str_contains($sql,'_csm_job_'.$job['id'])?'INVALID FILE PLAN TEST QUERY':$sql;};
 global $wpdb;$old=$wpdb->suppress_errors(true);add_filter('query',$reject);$failed=false;
 try{CSM_Plugin::commit($job);}catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'persist');}
 remove_filter('query',$reject);$wpdb->suppress_errors($old);
 $check($failed&&CSM_Support::job($job['id'])['status']==='staging'&&file_get_contents($root.'/a.txt')==='original-a'&&!file_exists($root.'/b.txt'),'A failed recovery-state write stops before changing destination files');
 CSM_Plugin::discard(CSM_Support::job($job['id']));
 echo "$checks file recovery checks passed.\n";
}finally{
 if(isset($checkpoint))remove_action('csm_checkpoint',$checkpoint,10);
 if(isset($watch))remove_filter('query',$watch);if(isset($break))remove_filter('query',$break);if(isset($reject))remove_filter('query',$reject);
 foreach($jobs as $id){if(!get_option('_csm_job_'.$id))continue;$j=CSM_Support::job($id);if(in_array($j['status'],['applying','applied'],true))CSM_Plugin::rollback($j);CSM_Plugin::discard(CSM_Support::job($id));}
 CSM_Support::remove_tree($root);CSM_Support::remove_tree($stage);
 update_option('_csm_backups', $original_backups, false);
}
