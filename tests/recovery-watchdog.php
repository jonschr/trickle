<?php
/** Recovery failures are bounded without ever exposing an incomplete site. QA-C only. */
if(realpath(ABSPATH)!==realpath(getenv('HOME').'/Cove/Sites/trickle-qa-c.localhost/public')||get_option('_csm_control')||get_option('_csm_active_job'))throw new RuntimeException('Requires idle QA-C.');
$id=bin2hex(random_bytes(16));$base=CSM_Support::private_dir();$backup=$base.'/'.$id.'-watchdog-backup';$relative='uploads/trickle-watchdog-fixture.txt';$target=CSM_Files::destination($relative,['media'=>'all']);if(file_exists($target))throw new RuntimeException('Fixture exists.');
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";};
$job=['id'=>$id,'status'=>'restoring','created'=>time(),'selection'=>['plugins'=>[],'themes'=>[],'tables'=>[],'media'=>'all'],'database'=>[],'files'=>[],'received'=>[],'ops'=>[['path'=>$relative,'backup'=>$backup,'existed'=>true,'mtime'=>time(),'mode'=>0644,'sha256'=>hash('sha256','restored')]]];
try {
 CSM_Support::save($job);update_option('_csm_active_job',$id,false);update_option('_csm_maintenance',['id'=>$id,'until'=>time()-3600],false);
 CSM_Plugin::recover_interrupted($id);
 $check(!get_option('_csm_recovery_attempts_'.$id),'Fresh heartbeat keeps the watchdog from competing with a working controller');
 for($i=0;$i<5;$i++){touch($base.'/'.$id.'-heartbeat',time()-120);clearstatcache();CSM_Plugin::recover_interrupted($id);}
 $check((get_option('_csm_recovery_attempts_'.$id)['count']??0)===5 && get_option('_csm_recovery_blocked_'.$id),'Five recovery failures without progress pause the watchdog');
 $check(CSM_Controller::state()['recovery_error']!=='' && CSM_Transport::health(home_url())['status']===503,'Paused recovery is reported to the administrator while normal visitors stay protected');
 file_put_contents($backup,'restored');
 $result=CSM_Controller::execute('recover_destination');
 $check($result['status']==='restored' && file_get_contents($target)==='restored','Manual recovery can resume after the underlying problem is fixed');
 $check(!get_option('_csm_recovery_blocked_'.$id) && !get_option('_csm_maintenance'),'Successful restoration clears the failure budget and maintenance');
} finally {
 if(is_file($target))unlink($target);if(is_file($backup))unlink($backup);
 foreach(['-heartbeat','-restore.json','-progress.json'] as $suffix){if(is_file($base.'/'.$id.$suffix))unlink($base.'/'.$id.$suffix);}
 foreach(['_csm_job_','_csm_cursor_','_csm_recovery_attempts_','_csm_recovery_blocked_'] as $prefix)delete_option($prefix.$id);
 if(get_option('_csm_active_job')===$id)delete_option('_csm_active_job');if((get_option('_csm_maintenance',[])['id']??'')===$id)delete_option('_csm_maintenance');wp_clear_scheduled_hook('trickle_recover',[$id]);
}
