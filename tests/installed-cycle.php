<?php
/**
 * Native integrity/rollback cycle on Main Dev and a disposable trickle-integrity-peer.
 * Run: cove wp trickle-main-dev eval-file /path/to/tests/installed-cycle.php /private/peer.code
 * Peer prerequisites: Trickle; only Trickle active; plugins/trickle-integrity-fixture/fixture.php
 * (valid plugin, version 2.0.0), payload.txt = str_repeat("incoming-fixture\n", 1048576);
 * wp_trickle_integrity_fixture(id BIGINT PRIMARY KEY, payload LONGTEXT) InnoDB, row 1 = "incoming row".
 * The private connection file must name that local peer. No production destinations are accepted.
 */
global $wpdb;
$codePath = $args[0] ?? '';
if (!is_file($codePath) || !is_readable($codePath)) { throw new RuntimeException('Pass the disposable peer connection file as the first argument.'); }
$pairing = file_get_contents($codePath);
if (CSM_Plugin::parse_pairing($pairing)['url'] !== 'https://trickle-integrity-peer.localhost:8453') { throw new RuntimeException('Only the disposable local integrity peer is allowed.'); }
if(home_url()!=='https://trickle-main-dev.localhost:8453'||get_option('_csm_control')||get_option('_csm_active_job')||get_option('_csm_backup_restore')||count(get_option('_csm_backups',[]))>=3)throw new RuntimeException('Requires idle Main Dev');
$fixture=WP_PLUGIN_DIR.'/trickle-integrity-fixture';$table=$wpdb->prefix.'trickle_integrity_fixture';
if(file_exists($fixture)||CSM_Database::table_exists($table))throw new RuntimeException('Fixture path/table already exists');
$admins=get_users(['role'=>'administrator','number'=>1]);wp_set_current_user($admins[0]->ID);
$saved=[];foreach(['_csm_peer','_csm_peers','_csm_recents','_csm_history']as$key)$saved[$key]=get_option($key,null);$active=get_option('active_plugins');
$ca=getenv('HOME').'/Library/Application Support/Caddy/pki/authorities/local/root.crt';if(!is_readable($ca))throw new RuntimeException('Test peer needs its explicit local CA');
$trust=static function($bundle,$url)use($ca){$host=wp_parse_url($url,PHP_URL_HOST);if($host==='trickle-integrity-peer.localhost')return $ca;if(in_array($host,['trickle-main-dev.localhost','127.0.0.1'],true))throw new RuntimeException('Unexpected HTTP self-probe');return $bundle;};
add_filter('csm_ca_bundle',$trust,10,2);$short=static fn()=>.01;add_filter('csm_step_seconds',$short);
$id=null;$created=false;$tamper=null;$renames=null;$unreachable=null;
function verify_assert($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
try{
 mkdir($fixture,0700);file_put_contents($fixture.'/fixture.php',"<?php\n/* Plugin Name: Integrity Fixture\nVersion: 1.0.0\n*/\n");file_put_contents($fixture.'/payload.txt','original fixture payload');$original=hash_file('sha256',$fixture.'/fixture.php');
 require_once ABSPATH.'wp-admin/includes/upgrade.php';dbDelta("CREATE TABLE $table (id BIGINT UNSIGNED NOT NULL, payload LONGTEXT NOT NULL, PRIMARY KEY  (id)) ENGINE=InnoDB");$created=true;$wpdb->insert($table,['id'=>1,'payload'=>'original row']);
 $r=CSM_Controller::execute('connect',['pairing'=>$pairing,'name'=>'Disposable integrity peer']);$peer=$r['saved'];
 foreach(['success','corrupt','cancel']as$mode){
  $r=CSM_Controller::execute('prepare',['site'=>$peer,'direction'=>'pull','selection'=>['plugins'=>['trickle-integrity-fixture'],'themes'=>[],'tables'=>[$table],'media'=>'none','transport'=>'stream','sync_active_plugins'=>true,'replace'=>false]]);$id=$r['id'];
  foreach(['database','files']as$kind){$n=0;do{$r=CSM_Controller::execute('transfer',['id'=>$id,'kind'=>$kind]);if(++$n>150)throw new RuntimeException('Staging did not advance');}while(!empty($r['continue']));}
  $renameCount=0;$renames=static function($sql)use(&$renameCount){if(str_starts_with($sql,'RENAME TABLE '))++$renameCount;return $sql;};add_filter('query',$renames);
  $unreachable=static fn()=>'https://127.0.0.1:1';add_filter('site_url',$unreachable);
  if($mode==='corrupt'){$tamper=static function($jobId,$phase,$count)use($fixture){if($phase==='apply')file_put_contents($fixture.'/fixture.php','corrupted installed bytes');};add_action('csm_checkpoint',$tamper,10,3);}
  $steps=0;$yieldedAfterSwap=false;$failure='';$cancelled=false;
  try{do{
   $r=CSM_Controller::execute('commit',['id'=>$id]);$j=CSM_Jobs::get($id);if(!empty($r['continue'])&&!empty($j['db_applied'])&&isset($j['verification_work'])){$yieldedAfterSwap=true;if($mode==='cancel'){CSM_Controller::execute('cancel',['id'=>$id]);do{$r=CSM_Controller::execute('recover',['id'=>$id,'cancel'=>true]);}while(!empty($r['continue']));$cancelled=true;break;}}
   if(++$steps>150)throw new RuntimeException('Commit did not advance');
  }while(!empty($r['continue']));}catch(Throwable $e){$failure=$e->getMessage();}
  remove_filter('site_url',$unreachable);$unreachable=null;remove_filter('query',$renames);$renames=null;if($tamper){remove_action('csm_checkpoint',$tamper,10);$tamper=null;}
  if($mode==='success'){
   verify_assert($failure===''&&$r['status']==='applied'&&$r['verification']['verified'],'File/database pull completes with an unreachable self URL and no self-probe');
   verify_assert($yieldedAfterSwap&&$renameCount===1,'Final verification resumes after the table swap without replaying it');
   verify_assert(hash_file('sha256',$fixture.'/payload.txt')===hash('sha256',str_repeat("incoming-fixture\n",1048576))&&$wpdb->get_var("SELECT payload FROM $table WHERE id=1")==='incoming row','Installed files and replacement rows match their source');
   verify_assert(CSM_Activation::current()===['trickle/trickle.php'],'Full source activation is copied and verified');
   CSM_Controller::execute('finish',['id'=>$id]);do{$r=CSM_Controller::execute('restore_backup',['id'=>$id]);}while(!empty($r['continue']));
  }elseif($mode==='corrupt'){verify_assert(str_contains($failure,'Installed file checksum failed')&&CSM_Jobs::get($id)['status']==='restored','Actual installed corruption causes automatic rollback');}
  else verify_assert($failure===''&&$cancelled&&$yieldedAfterSwap&&CSM_Jobs::get($id)['status']==='restored','Cancel during final verification restores the destination');
  if(CSM_Controller::session())CSM_Controller::execute('finish',['id'=>$id]);
  verify_assert(hash_file('sha256',$fixture.'/fixture.php')===$original&&$wpdb->get_var("SELECT payload FROM $table WHERE id=1")==='original row'&&CSM_Activation::current()===$active,'Original files, table contents and activation are restored');
  CSM_Support::lock();try{if(get_option('_csm_job_'.$id))CSM_Plugin::discard(CSM_Jobs::get($id));}finally{CSM_Support::unlock();}$id=null;
 }
}finally{
 if($unreachable)remove_filter('site_url',$unreachable);if($renames)remove_filter('query',$renames);if($tamper)remove_action('csm_checkpoint',$tamper,10);
 if($id&&($control=get_option('_csm_control'))&&$control['id']===$id){$job=CSM_Jobs::get($id);if(in_array($job['status'],['applied','applying','restoring'],true)){do{$r=CSM_Controller::execute('rollback',['id'=>$id]);}while(!empty($r['continue']));}if(CSM_Jobs::get($id)['status']==='staging')CSM_Controller::execute('discard',['id'=>$id]);elseif(CSM_Controller::session())CSM_Controller::execute('finish',['id'=>$id]);}
 remove_filter('csm_ca_bundle',$trust);remove_filter('csm_step_seconds',$short);
 foreach($saved as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}
 CSM_Activation::restore(['active_plugins_before'=>$active]);
 if($created)$wpdb->query('DROP TABLE IF EXISTS '.CSM_Support::ident($table));
 if(is_dir($fixture)){foreach(new FilesystemIterator($fixture)as$file)unlink($file->getPathname());rmdir($fixture);}
}
