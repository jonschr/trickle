#!/usr/bin/env python3
"""Fault tests restricted to disposable QA-A/C; never contacts a live customer site."""
import json,subprocess,shutil,time,hashlib,random
from pathlib import Path
S,D='trickle-qa-a','trickle-qa-c';NAME='trickle-bounded-fixture'
ROOT={s:Path.home()/'Cove/Sites'/(s+'.localhost/public') for s in (S,D)}
checks=0

def ev(site,code):
 p=subprocess.run(['cove','wp',site,'eval',code],capture_output=True,text=True,timeout=170)
 if p.returncode:raise RuntimeError((p.stdout+p.stderr)[-2500:])
 for line in reversed(p.stdout.splitlines()):
  try:return json.loads(line)
  except ValueError:pass
 raise RuntimeError(p.stdout[-1000:])

def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)

def op(action,extra=None,slow=True,inject=''):
 code=("add_filter('csm_step_seconds',static fn()=>.02);" if slow else '')+inject
 code+='$c=CSM_Controller::session();echo wp_json_encode(CSM_Controller::execute('+json.dumps(action)+',['+'"id"=>$c["id"]]+json_decode('+repr(json.dumps(extra or {}))+',true)));'
 return ev(D,code)

def until(action,extra=None):
 records=[]
 for i in range(150):
  r=op(action,extra);records.append(r)
  if not r.get('continue'):return r,records
 raise RuntimeError('No terminal state: '+action)

for site in ROOT:
 assert ev(site,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 assert not (ROOT[site]/'wp-content/plugins'/NAME).exists()
mu=ROOT[S]/'wp-content/mu-plugins/trickle-bounded-fixture.php'
assert not mu.exists()
old={s:ev(s,'echo wp_json_encode(["recents"=>get_option("_csm_recents",[]),"backups"=>get_option("_csm_backups",[])]);') for s in ROOT}
try:
 mu.write_text("<?php add_filter('csm_step_seconds',static fn()=>.02);\n")
 for site in ROOT:
  folder=ROOT[site]/'wp-content/plugins'/NAME;folder.mkdir();(folder/'main.php').write_text('<?php /* Plugin Name: Bounded fixture */\n');(folder/'version.txt').write_text('source' if site==S else 'destination')
  ev(site,"global $wpdb; $t=$wpdb->prefix.'csm_bounded_fixture';$wpdb->query('CREATE TABLE '.$t.' (id bigint NOT NULL, value longtext) ENGINE=InnoDB');for($i=1;$i<=1100;$i++)$wpdb->insert($t,['id'=>$i,'value'=>str_repeat('row-'.$i,5)]);echo 'true';")
  # Isolate backup retention from pre-existing QA backups.
  ev(site,"update_option('_csm_backups',[],false);echo 'true';")
 data=random.Random(482).randbytes(8*1024*1024)
 (ROOT[S]/'wp-content/plugins'/NAME/'large.bin').write_bytes(data)
 destination_data=b'd'*(32*1024*1024)
 (ROOT[D]/'wp-content/plugins'/NAME/'large.bin').write_bytes(destination_data)
 (ROOT[D]/'wp-content/plugins'/NAME/'old.txt').write_text('obsolete')
 selection={'plugins':[NAME],'themes':[],'tables':['wp_csm_bounded_fixture'],'media':'none','sync_active_plugins':False,'transport':'stream'}
 def prepare(sel=selection):return ev(D,'echo wp_json_encode(CSM_Controller::execute("prepare",["site"=>"https://trickle-qa-a.localhost:8453","direction"=>"pull","selection"=>json_decode('+repr(json.dumps(sel))+',true)]));')
 started=prepare();tid=started['id']
 # Database export stays consistent; destination parsing is deliberately interrupted inside a transaction.
 until('preflight',{'kind':'database'})
 interrupted=None
 for _ in range(10):
  interrupted=op('transfer',{'kind':'database'},False,"$n=0;add_filter('query',static function($sql)use(&$n){if(str_starts_with($sql,'INSERT INTO `_csm_')&&++$n===321){echo wp_json_encode(['interrupted'=>true]);exit;}return $sql;});")
  if interrupted.get('interrupted'):break
 check(interrupted.get('interrupted'),'Worker interrupted partway through an uncommitted database import batch')
 done,records=until('transfer',{'kind':'database'})
 staged=ev(D,'$c=CSM_Controller::session();$j=CSM_Support::job($c["id"]);global $wpdb;echo wp_json_encode([$j["rows"],(int)$wpdb->get_var("SELECT COUNT(*) FROM `".$j["database"][0]["stage"]."`")]);')
 check(staged==[1100,1100],'Database import resumes at its committed cursor with no duplicates or lost rows')
 pre,prepared=until('preflight',{'kind':'files'})
 check(len(prepared)>1 and pre['timeline']['ready'],'Source packaging finishes across short saved steps')
 # Append unacknowledged junk; next source build checkpoint must not include it (source completed here so test receiver below).
 first=op('transfer',{'kind':'files'})
 done,received=until('transfer',{'kind':'files'})
 check(done['ready'] and done['files']>=2 and len(received)>1,'Frame verification, file staging and preview continue across requests')
 job=ev(D,'$c=CSM_Controller::session();$j=CSM_Support::job($c["id"]);echo wp_json_encode(["hashes"=>array_column($j["files"],"sha256","path"),"preview"=>$j["preview"]["counts"]]);')
 check(job['hashes']['plugins/'+NAME+'/large.bin']==hashlib.sha256(data).hexdigest(),'Resumed staged binary matches the full source digest')
 # Kill apply immediately after one persisted replacement, then recover in fresh processes.
 killed=op('commit',{},False,"add_action('csm_checkpoint',static function($id,$phase,$cursor){if($phase==='apply'&&$cursor>=1){echo wp_json_encode(['interrupted'=>true]);exit;}},10,3);")
 check(killed.get('interrupted'),'Worker interrupted after a real destination replacement')
 ev(D,'$m=get_option("_csm_maintenance");$m["until"]=time()-3600;update_option("_csm_maintenance",$m,false);echo "true";')
 # Native plugin HTTP health request uses the normal Trickle User-Agent.
 protected=ev(D,'echo wp_json_encode(CSM_Transport::health(home_url())["status"]);')
 check(protected==503,'Expired maintenance TTL never exposes an interrupted replacement')
 recovered,restores=until('recover')
 check(len(restores)>1,'A partial rollback resumes through the ordinary recovery action without requiring cancellation')
 check(recovered['status']=='restored' and (ROOT[D]/'wp-content/plugins'/NAME/'version.txt').read_text()=='destination' and (ROOT[D]/'wp-content/plugins'/NAME/'large.bin').read_bytes()==destination_data,'Interrupted replacement rolls back to the original code')
 op('finish')
 # Fresh complete migration, followed by independently batched saved-backup restoration.
 prepare();until('preflight',{'kind':'database'});until('preflight',{'kind':'files'});until('transfer',{'kind':'database'});until('transfer',{'kind':'files'})
 applied,applies=until('commit')
 check(applied['status']=='applied' and applied['verification']['verified'] and len(applies)>1,'Fresh complete pull applies through short backup/replacement steps and verifies installed data')
 check((ROOT[D]/'wp-content/plugins'/NAME/'large.bin').read_bytes()==data and not (ROOT[D]/'wp-content/plugins'/NAME/'old.txt').exists(),'Successful apply installs exact bytes and removes obsolete code')
 backup=applied['id'];op('finish')
 restore=[]
 for _ in range(150):
  r=ev(D,"add_filter('csm_step_seconds',static fn()=>.02);echo wp_json_encode(CSM_Controller::execute('restore_backup',['id'=>"+repr(backup)+"]));")
  restore.append(r)
  if not r.get('continue'):break
 check(r['status']=='restored' and len(restore)>1,'Saved backup table copy and file restore finish across bounded requests')
 check((ROOT[D]/'wp-content/plugins'/NAME/'version.txt').read_text()=='destination','Saved-backup restoration returns the original code')
 # Cancellation remains durable while the same process already owns the mutation lock.
 cancel_job=prepare({'plugins':[NAME],'themes':[],'tables':[],'media':'none','sync_active_plugins':False})
 cancelled=ev(D,"$s=CSM_Controller::session();CSM_Support::lock();try{$r=CSM_Controller::execute('cancel',['id'=>$s['id']]);echo wp_json_encode([$r['cancel_requested'],CSM_Controller::session()['automatic'],CSM_Controller::session()['cancel_requested']]);}finally{CSM_Support::unlock();}")
 check(cancelled==[True,False,True],'Cancel is persisted immediately even with the mutation lock held')
 cancel_id=cancel_job['id']
 check(ev(D,"echo wp_json_encode(CSM_Support::is_cancelled("+repr(cancel_id)+") && (!CSM_Controller::session() || !CSM_Controller::session()['automatic']));"),'Cancellation survives a fresh PHP process / browser reload')
 ev(D,"CSM_Controller::retry_cancel("+repr(cancel_id)+");echo wp_json_encode(!CSM_Controller::session());")
 check(not ev(D,'echo wp_json_encode((bool)CSM_Controller::session());'),'Background cancellation finishes even after the controlling page disappears')
 check(ev(D,"echo wp_json_encode(CSM_Controller::execute('recover',['id'=>"+repr(cancel_id)+",'cancel'=>true]));")['status']=='cancelled','A returning browser can confirm cancellation after background cleanup')
 # The interrupted worker also finishes cancellation itself after releasing its lock.
 worker_job=prepare({'plugins':[NAME],'themes':[],'tables':[],'media':'none','sync_active_plugins':False})
 stopped=ev(D,"$id="+repr(worker_job['id'])+";$marked=false;add_action('csm_request_complete',static function($action)use($id,&$marked){if($action==='bundle'&&!$marked){$marked=true;CSM_Support::cancel($id);}},10,1);try{CSM_Controller::execute('transfer',['id'=>$id,'kind'=>'files']);}catch(Throwable $error){}echo wp_json_encode(!CSM_Controller::session()&&!get_option('_csm_active_job'));" )
 check(stopped,'A cancelled worker completes safe cleanup when it releases its own lock')
 # Explicit same-named connection plugin errors are tested in workflow.js; server no-progress budget here.
 prepare({'plugins':[NAME],'themes':[],'tables':[],'media':'none','sync_active_plugins':False})
 states=[op('retry_state',{'failed':True},False) for _ in range(5)]
 check(states[-1]['stopped'] and ev(D,'echo wp_json_encode(CSM_Controller::session()["cancel_requested"]);'),'Five failures without acknowledged progress stop automatic retries durably')
 until('recover',{'cancel':True})
 # Early estimates finish before any migration and never read file content.
 estimates=ev(D,'$r=CSM_Estimates::read(true,true);for($i=0;$i<100&&(!$r["complete"]||!empty($r["refreshing"]));$i++)$r=CSM_Estimates::read(true);echo wp_json_encode($r);')
 check(estimates['complete'] and estimates['bytes']['database']>0 and estimates['items']['plugins'][NAME]>0,'Database and per-component file estimates are available before migration')
 print(json.dumps({'checks':checks,'prepare_steps':len(prepared),'import_steps':len(records),'file_steps':len(received),'apply_steps':len(applies),'restore_steps':len(restore)}),flush=True)
finally:
 mu.unlink(missing_ok=True)
 try:
  if ev(D,'echo wp_json_encode((bool)CSM_Controller::session());'):
   until('recover',{'cancel':True})
   if ev(D,'echo wp_json_encode((bool)CSM_Controller::session());'):op('finish')
 except Exception as e:print('Cleanup needs attention',e,flush=True)
 for site in ROOT:
  ev(site,"global $wpdb;$wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'csm_bounded_fixture');echo 'true';")
  shutil.rmtree(ROOT[site]/'wp-content/plugins'/NAME,ignore_errors=True)
  ev(site,"update_option('_csm_recents',json_decode("+repr(json.dumps(old[site]['recents']))+",true),false);update_option('_csm_backups',json_decode("+repr(json.dumps(old[site]['backups']))+",true),false);echo 'true';")
