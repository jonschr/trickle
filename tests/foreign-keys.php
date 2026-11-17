<?php
require_once WP_PLUGIN_DIR . '/trickle/trickle.php';
if (realpath(ABSPATH) !== realpath(getenv('HOME').'/Cove/Sites/trickle-main-dev.localhost/public') || get_option('_csm_control') || get_option('_csm_active_job') || get_option('_csm_backup_restore')) throw new RuntimeException('Idle Main Dev required.');
global $wpdb;
$tables=[$wpdb->prefix.'csm_fk_child',$wpdb->prefix.'csm_fk_parent'];
foreach($tables as $table) if(CSM_Database::table_exists($table))throw new RuntimeException('Fixture exists.');
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";};
$old=get_option('_csm_backups',[]);$job=null;
CSM_Support::lock();
try {
 CSM_Support::query('CREATE TABLE '.CSM_Support::ident($tables[1]).' (id int PRIMARY KEY, value varchar(40)) ENGINE=InnoDB');
 CSM_Support::query('CREATE TABLE '.CSM_Support::ident($tables[0]).' (id int PRIMARY KEY, parent int, value varchar(40), CONSTRAINT csm_fk_fixture FOREIGN KEY (parent) REFERENCES '.CSM_Support::ident($tables[1]).' (id) ON DELETE CASCADE) ENGINE=InnoDB');
 foreach([false,true] as $framed){
  CSM_Support::query('INSERT INTO '.CSM_Support::ident($tables[1])." VALUES (1,'source')");
  CSM_Support::query('INSERT INTO '.CSM_Support::ident($tables[0])." VALUES (1,1,'source')");
  $selection=['tables'=>$tables,'plugins'=>[],'themes'=>[],'media'=>'none','replace'=>false,'sync_active_plugins'=>false];$fp=tmpfile();
  foreach(CSM_Database::stream($selection,$framed) as $line)fwrite($fp,$line);rewind($fp);
  foreach($tables as $table)CSM_Support::query('UPDATE '.CSM_Support::ident($table)." SET value='destination'");
  $summary=CSM_Plugin::prepare($selection);$job=CSM_Jobs::get($summary['id']);
  if($framed){while(!CSM_Database::stage_step($fp,$job)) {}}else CSM_Database::stage($fp,$job);fclose($fp);
  $check(count($job['database'][0]['foreign_keys'])===1,'Child staged before parent with deferred foreign key');
  CSM_Database::replace_tables($job);
  $schema=CSM_Database::rows('SHOW CREATE TABLE '.CSM_Support::ident($tables[0]),ARRAY_N)[0][1];
  $check(str_contains($schema,'REFERENCES '.CSM_Support::ident($tables[1])),'Incoming foreign key references live parent after swap');
  $check(CSM_Database::rows('SELECT value FROM '.CSM_Support::ident($tables[0]))[0]['value']==='source','Incoming child data installed');
  if($framed){$job['status']='applied';$job['applied']=time();CSM_Jobs::save($job);delete_option('_csm_active_job');update_option('_csm_backups',array_merge([$job['id']],$old),false);do{$r=CSM_Backups::restore($job['id']);}while(!empty($r['continue']));}
  else CSM_Database::restore_tables($job);
  $schema=CSM_Database::rows('SHOW CREATE TABLE '.CSM_Support::ident($tables[0]),ARRAY_N)[0][1];
  $check(str_contains($schema,'REFERENCES '.CSM_Support::ident($tables[1])),'Restored foreign key references restored parent');
  $check(CSM_Database::rows('SELECT value FROM '.CSM_Support::ident($tables[0]))[0]['value']==='destination','Restored child data preserved');
  CSM_Plugin::discard(CSM_Jobs::get($job['id']));$job=null;
  CSM_Support::query('DELETE FROM '.CSM_Support::ident($tables[1]));
  $check(!CSM_Database::rows('SELECT * FROM '.CSM_Support::ident($tables[0])),'Cascade still works after rollback/backup restoration');
 }
 $ddl='CREATE TABLE `child` ('."\n".'  `parent` int,'."\n".'  CONSTRAINT `fk_test` FOREIGN KEY (`parent`) REFERENCES `missing` (`id`)'."\n".') ENGINE=InnoDB';
 $caught=false;try{CSM_Database::foreign_schema($ddl,[],'fixture');}catch(RuntimeException $e){$caught=str_contains($e->getMessage(),'related table');}
 $check($caught,'Missing related source table is rejected');
 $caught=false;try{CSM_Database::foreign_schema(str_replace('REFERENCES `missing` (`id`)','REFERENCES `missing` (`id`) ON DELETE CASCADE; DROP TABLE x',$ddl),['missing'=>'_csm_fixture'],'fixture');}catch(RuntimeException $e){$caught=true;}
 $check($caught,'Unsafe foreign key SQL rejected');
}finally{
 if($job)CSM_Plugin::discard(CSM_Jobs::get($job['id']));
 CSM_Support::query('DROP TABLE IF EXISTS '.CSM_Support::ident($tables[0]));CSM_Support::query('DROP TABLE IF EXISTS '.CSM_Support::ident($tables[1]));update_option('_csm_backups',$old,false);CSM_Support::unlock();
}
