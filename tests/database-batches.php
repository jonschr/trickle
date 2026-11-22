<?php
// Real SQL batching and lost-request recovery on the named disposable import receivers only.
require_once WP_PLUGIN_DIR.'/trickle/trickle.php';
$mode=$args[0]??'run';
if(!in_array(realpath(ABSPATH),array_map(static fn($site)=>realpath(getenv('HOME').'/Cove/Sites/'.$site.'.localhost/public'),['trickle-dual-dest-20261121','trickle-flywheel-push-check']),true)||get_option('_csm_control')||get_option('_csm_backup_restore')||($mode!=='recover'&&get_option('_csm_active_job')))throw new RuntimeException('Idle disposable import receiver required');
global $wpdb;
$table=$wpdb->prefix.'csm_batch_fixture';$base=CSM_Support::private_dir().'/database-batch-fixture';$id=null;$fp=null;$inject=null;$short=null;
function batch_check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function batch_digest($table){$h=hash_init('sha256');foreach(CSM_Database::rows('SELECT * FROM '.CSM_Support::ident($table).' ORDER BY id')as$r)hash_update($h,serialize($r));return hash_final($h);}
try{
 if($mode==='recover'){
  $id=json_decode(file_get_contents($base.'.json'),true)['id'];$job=CSM_Jobs::get($id);
 }else{
  if(CSM_Database::table_exists($table)||is_file($base.'.json'))throw new RuntimeException('Fixture exists');
  CSM_Support::query('CREATE TABLE '.CSM_Support::ident($table).' (id int PRIMARY KEY,signed_value bigint,unsigned_value bigint unsigned,decimal_value decimal(20,5),label text,payload longblob,optional_value text NULL) ENGINE=InnoDB');
  for($i=0;$i<1103;++$i){$value=$i===1102?str_repeat('big',140000):str_repeat("\0\xffbinary-",48);$wpdb->insert($table,['id'=>$i,'signed_value'=>-123,'unsigned_value'=>'18446744073709551615','decimal_value'=>'1234567890123.45678','label'=>serialize(['url'=>site_url().'/fixture','id'=>$i]),'payload'=>$value,'optional_value'=>$i%2?null:'value']);}
  $selection=['tables'=>[$table],'plugins'=>[],'themes'=>[],'media'=>'none','replace'=>false,'sync_active_plugins'=>false];
  $fp=fopen($base.'.stream','w+b');chmod($base.'.stream',0600);foreach(CSM_Database::stream($selection,true)as$bytes)CSM_Support::write($fp,$bytes);fclose($fp);$fp=null;
  $r=CSM_Plugin::prepare($selection);$id=$r['id'];$job=CSM_Jobs::get($id);CSM_Support::atomic_json($base.'.json',['id'=>$id]);
  $committed=false;$wide_batch=false;
  $inject=static function($sql)use(&$committed,&$wide_batch,$mode){if(str_starts_with($sql,'INSERT INTO `_csm_')&&strlen($sql)>262144&&str_contains($sql,'),('))$wide_batch=true;if($sql==='COMMIT')$committed=true;if($committed&&str_starts_with($sql,'INSERT INTO `_csm_')){if($mode==='crash'){echo "INTENTIONAL HARD EXIT during uncommitted insert batch\n";exit(77);}throw new RuntimeException('Injected batch failure');}return $sql;};
  add_filter('query',$inject);$caught=false;$fp=fopen($base.'.stream','rb');
  try{while(!CSM_Database::stage_step($fp,$job)){}}catch(RuntimeException $e){$caught=$e->getMessage()==='Injected batch failure';}
  finally{remove_filter('query',$inject);$inject=null;fclose($fp);$fp=null;}
  batch_check($caught,'Injected SQL failure rolls back its uncommitted batch');batch_check($wide_batch,'Packet-safe multi-row inserts can exceed the old 256 KiB cap');$job=CSM_Jobs::get($id);
 }
 batch_check($job['rows']===250&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.CSM_Support::ident($job['database'][0]['stage']))===250,'Committed data and durable cursor both remain at 250 rows after interruption');
 $queries=0;$large_single=false;
 $inject=static function($sql)use(&$queries,&$large_single){if($sql==='SELECT @@max_allowed_packet AS packet')return 'SELECT 4096 AS packet';if(str_starts_with($sql,'INSERT INTO `_csm_')){++$queries;if(strlen($sql)>3072){if(str_contains($sql,'),('))throw new RuntimeException('Batch exceeded simulated packet allowance');$large_single=true;}}return $sql;};
 $short=static fn()=>.002;add_filter('query',$inject);add_filter('csm_step_seconds',$short);$fp=fopen($base.'.stream','rb');$steps=0;
 do{$done=CSM_Database::stage_step($fp,$job);if(++$steps>3000)throw new RuntimeException('Import did not advance');}while(!$done);
 fclose($fp);$fp=null;remove_filter('query',$inject);$inject=null;remove_filter('csm_step_seconds',$short);$short=null;
 batch_check($job['rows']===1103&&batch_digest($table)===batch_digest($job['database'][0]['stage']),'Resume preserves every row, binary value, null and final partial batch without duplicates');
 batch_check($steps>1&&$queries>1&&$large_single,'Bounded continuations respect a small packet allowance and retain oversized single-row compatibility');
 $selection=$job['selection'];CSM_Plugin::discard(CSM_Jobs::get($id));$id=null;
 $fp=fopen($base.'.stream','w+b');foreach(CSM_Database::stream($selection)as$bytes)CSM_Support::write($fp,$bytes);rewind($fp);
 $r=CSM_Plugin::prepare($selection);$id=$r['id'];$job=CSM_Jobs::get($id);CSM_Database::stage($fp,$job);fclose($fp);$fp=null;
 batch_check(batch_digest($table)===batch_digest($job['database'][0]['stage']),'Legacy and bounded import preserve numeric limits, decimals, binary bytes and NULL exactly');
}finally{
 if($inject)remove_filter('query',$inject);if($short)remove_filter('csm_step_seconds',$short);if(is_resource($fp))fclose($fp);
 if($id)CSM_Plugin::discard(CSM_Jobs::get($id));CSM_Support::query('DROP TABLE IF EXISTS '.CSM_Support::ident($table));
 foreach([$base.'.json',$base.'.stream']as$p)if(is_file($p))unlink($p);
}
