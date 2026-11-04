<?php
// Large inventories must never depend on MySQL's packet size. Idle QA-C only.
if (realpath(ABSPATH)!==realpath('/Users/jonschroeder/Cove/Sites/trickle-qa-c.localhost/public') || get_option('_csm_control') || get_option('_csm_active_job')) { throw new RuntimeException('Idle QA-C required.'); }
$id=bin2hex(random_bytes(16));$dir=CSM_Support::private_dir().'/job-records/'.$id;
$check=static function($ok,$label) { if (!$ok) { throw new RuntimeException($label); } echo 'PASS '.$label."\n"; };
CSM_Support::lock();
try {
    $job=['id'=>$id,'status'=>'staging','created'=>time(),'credential'=>'local','database'=>[],'files'=>[],'imports'=>[],'rows'=>0];
    for($i=0;$i<25001;$i++) { $job['files'][]=['path'=>'plugins/example/'.str_repeat('nested/',12).$i.'.php','stage'=>'/private/stage/'.$i,'size'=>100,'mtime'=>time(),'sha256'=>hash('sha256',(string)$i),'mode'=>0644]; }
    $job['preview_work']=['phase'=>'files','index'=>19000,'new'=>array_column($job['files'],null,'path')];
    $check(strlen(maybe_serialize($job))>16777216,'Fixture exceeds the 16 MB packet size that caused the verification loop');
    CSM_Support::save($job);$record=get_option('_csm_job_'.$id);
    $check(strlen(maybe_serialize($record))<1024 && $record['status']==='staging','Database stores a small recovery status and immutable private checkpoint reference');
    $check(CSM_Support::job($id)===$job,'All 25,001 file records and the verification cursor survive exact round-trip');
    $old=$record['_csm_blob'];$job['preview_work']['index']=20000;CSM_Support::save($job);
    $check(CSM_Support::job($id)['preview_work']['index']===20000 && get_option('_csm_job_'.$id)['_csm_blob']!==$old,'Advancing verification persists a new checkpoint instead of restarting');
    CSM_Support::query('START TRANSACTION');$job['rows']=250;CSM_Support::save_transactional($job);CSM_Support::query('COMMIT');
    $check(CSM_Support::job($id)['rows']===250,'Committed database cursors still merge with file-backed job state');
    $reject_read=static fn($sql)=>str_starts_with($sql,'SELECT option_value FROM ')&&str_contains($sql,'_csm_cursor_'.$id)?'INVALID CURSOR READ TEST QUERY':$sql;
    add_filter('query',$reject_read);$errors=$GLOBALS['wpdb']->suppress_errors(true);$caught=false;
    try { CSM_Support::job($id); } catch (RuntimeException $e) { $caught=str_contains($e->getMessage(),'Could not read transfer checkpoint'); }
    finally { remove_filter('query',$reject_read);$GLOBALS['wpdb']->suppress_errors($errors); }
    $check($caught && CSM_Support::job($id)['rows']===250,'A failed cursor read stops instead of replaying rows from an older job record');
    global $wpdb;
    CSM_Support::query($wpdb->prepare('UPDATE '.CSM_Support::ident($wpdb->options).' SET option_value=%s WHERE option_name=%s','broken','_csm_cursor_'.$id));$caught=false;
    try { CSM_Support::job($id); } catch (RuntimeException $e) { $caught=str_contains($e->getMessage(),'Invalid transactional'); }
    $check($caught,'Corrupt cursors cannot masquerade as missing checkpoints');
    CSM_Support::query('START TRANSACTION');CSM_Support::save_transactional($job);CSM_Support::query('COMMIT');
    $job['backup_dir']=CSM_Support::backup_dir().'/'.$id.'-backup';CSM_Support::save($job);
    $durable=get_option('_csm_job_'.$id);CSM_Support::remove_tree($dir);
    $check($durable['_csm_blob_dir']===$job['backup_dir'].'/job-records' && CSM_Support::job($id)===$job,'Recovery metadata follows the durable backup directory and survives staging-record cleanup');
    $reject=static fn($sql)=>str_starts_with($sql,'UPDATE ')&&str_contains($sql,'_csm_job_'.$id)?'INVALID CHECKPOINT TEST QUERY':$sql;
    add_filter('query',$reject);$job['status']='applying';$caught=false;$errors=$GLOBALS['wpdb']->suppress_errors(true);
    try { CSM_Support::save($job); } catch (RuntimeException $e) { $caught=str_contains($e->getMessage(),'Could not persist transfer checkpoint'); }
    finally { remove_filter('query',$reject);$GLOBALS['wpdb']->suppress_errors($errors); }
    $check($caught && CSM_Support::job($id)['status']==='staging','Failed database writes raise an error and preserve the last acknowledged checkpoint');
    $record=get_option('_csm_job_'.$id);$path=$record['_csm_blob_dir'].'/'.$record['_csm_blob'].'.json.gz';file_put_contents($path,'corrupt');$caught=false;
    try { CSM_Support::job($id); } catch (RuntimeException $e) { $caught=str_contains($e->getMessage(),'missing or changed'); }
    $check($caught,'Missing or corrupted private state is rejected instead of silently starting over');
} finally {
    delete_option('_csm_job_'.$id);delete_option('_csm_cursor_'.$id);CSM_Support::remove_tree($dir);@unlink(CSM_Support::private_dir().'/'.$id.'-heartbeat');CSM_Support::unlock();
    if(isset($job['backup_dir']))CSM_Support::remove_tree($job['backup_dir']);
}
