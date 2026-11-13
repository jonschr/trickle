#!/usr/bin/env python3
"""Sequential pulls, independent backups and crash recovery on disposable QA-A/C."""
import json,subprocess,shutil,ssl,http.client,time
from pathlib import Path
S='trickle-qa-a';D='trickle-qa-c';NAME='trickle-backup-fixture';BASE=Path.home()/'Cove/Sites';ROOT={x:BASE/(x+'.localhost/public') for x in [S,D]};P={x:ROOT[x]/'wp-content/plugins'/NAME for x in ROOT};checks=0

def wp(site,*args,fail=False):
 p=subprocess.run(['cove','wp',site,*args],capture_output=True,text=True,timeout=180)
 if fail:
  assert p.returncode,p.stdout
  return p.stdout+p.stderr
 if p.returncode:raise RuntimeError(p.stdout+p.stderr)
 return p.stdout

def ev(site,code):return json.loads(wp(site,'eval',code).splitlines()[-1])
def op(action,data=None):return ev(D,'echo wp_json_encode(CSM_Controller::execute('+json.dumps(action)+',json_decode('+repr(json.dumps(data or {}))+',true)));')
def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
def migrate(n,direction='pull'):
 (P[S if direction=='pull' else D]/'code.txt').write_text(str(n))
 src=S if direction=='pull' else D
 wp(src,'eval',f'global $wpdb;$wpdb->update("wp_trickle_backup_fixture",["v"=>{n}],["id"=>1]);')
 result=op('prepare',{'site':'https://trickle-qa-a.localhost:8453','direction':direction,'selection':{'plugins':[NAME],'themes':[],'tables':['wp_trickle_backup_fixture'],'media':'none'},'automatic':True});id=result['id']
 check(op('state')['session']['automatic'],'One-step authorization survives a fresh PHP request')
 for kind in ['database','files']:
  while True:
   r=op('transfer',{'id':id,'kind':kind})
   if not r.get('continue'):break
 result=op('commit',{'id':id});op('finish',{'id':id})
 return id,result
for x in ROOT:
 assert ev(x,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 assert not P[x].exists()
settings={x:ev(x,'echo wp_json_encode(get_option("_csm_settings"));') for x in ROOT};recents=ev(D,'echo wp_json_encode(get_option("_csm_recents",[]));');history=ev(D,'echo wp_json_encode(get_option("_csm_history",[]));')
original_cron=ev(D,'echo wp_json_encode(defined("DISABLE_WP_CRON")?DISABLE_WP_CRON:null);')
# Frontend health checks must not spawn a competing cron while a crash is deliberately journalled.
wp(D,'config','set','DISABLE_WP_CRON','true','--raw')
original_backups={x:ev(x,'echo wp_json_encode(get_option("_csm_backups",[]));') for x in ROOT};original_active={x:ev(x,'echo wp_json_encode(get_option("active_plugins"));') for x in ROOT}
try:
 for x in ROOT:
  P[x].mkdir();(P[x]/(NAME+'.php')).write_text('<?php\n/* Plugin Name: Backup fixture */\n');(P[x]/'code.txt').write_text('0');(P[x]/'unchanged.txt').write_text('original')
  wp(x,'eval','global $wpdb;$wpdb->query("CREATE TABLE wp_trickle_backup_fixture (id int PRIMARY KEY, v int, doubled int AS (v*2) STORED) ENGINE=InnoDB");$wpdb->insert("wp_trickle_backup_fixture",["id"=>1,"v"=>0]);update_option("_csm_backups",[],false);update_option("_csm_settings",["pull"=>true,"push"=>true],false);')
 ids=[]
 for n in range(1,5):
  if n==3:(P[S]/'later.txt').write_text('introduced later')
  id,result=migrate(n);ids.append(id)
  check(result['status']=='applied' and op('state')['session'] is None and ev(D,'echo wp_json_encode(!get_option("_csm_active_job"));'),'Completed pull releases its active session without deleting its backup')
 backups=op('backups')['backups'];check([b['id'] for b in backups]==list(reversed(ids[1:])),'Fourth successful pull rotates only the oldest backup; newest three retained')
 gone=ev(D,'echo wp_json_encode(get_option("_csm_job_'+ids[0]+'",null));')
 check(gone is None,'Rotated backup metadata is removed')
 for id,expected in [(ids[1],'1'),(ids[3],'3'),(ids[1],'1')]:
  (P[D]/'unchanged.txt').write_text('edited after migration')
  restored=op('restore_backup',{'id':id})
  value=ev(D,'global $wpdb;echo wp_json_encode($wpdb->get_row("SELECT v,doubled FROM wp_trickle_backup_fixture WHERE id=1",ARRAY_A));')
  check(restored['status']=='restored' and (P[D]/'code.txt').read_text()==expected and value=={'v':expected,'doubled':str(int(expected)*2)},'Any retained backup restores files, database and generated columns, in any order')
  check((P[D]/'unchanged.txt').read_text()=='original','Whole-component restore includes files that matched during migration')
 check(not (P[D]/'later.txt').exists(),'Whole-component restore removes files introduced by later pulls')
 check(len(op('backups')['backups'])==3 and ev(D,'echo wp_json_encode(!get_option("_csm_active_job")&&!get_option("_csm_backup_restore"));'),'Restores keep the three original backups reusable and release recovery state')
 denied=ev(D,'$GLOBALS["_csm_credential"]=["id"=>"another-key","write"=>true,"master"=>false];$list=CSM_Backups::listing();try{CSM_Backups::restore("'+ids[1]+'");$code=0;}catch(Throwable $e){$code=$e->getCode();}echo wp_json_encode([count($list["backups"]),$code]);')
 check(denied==[0,403],'Another connection key cannot list or restore private backups')
 denied=ev(D,'try{CSM_Access::authorize(["id"=>"read-only","read"=>true,"write"=>false],"restore_backup");$code=0;}catch(Throwable $e){$code=$e->getCode();}echo wp_json_encode($code);')
 check(denied==403,'Read-only connections cannot restore code or database backups')
 check(all(not b['temporary'] for b in op('backups')['backups']),'QA backups use persistent private storage outside the public site')
 # Failed staging is cancelled automatically; destination and backup history stay intact.
 sel={'site':'https://trickle-qa-a.localhost:8453','direction':'pull','selection':{'plugins':[NAME],'themes':[],'tables':[],'media':'none'},'automatic':True}
 pending=op('prepare',sel);cancel=op('recover',{'id':pending['id'],'cancel':True})
 check(cancel['status']=='cancelled' and op('state')['session'] is None and len(op('backups')['backups'])==3,'Failed staging recovers without blocking the next transfer or rotating good backups')
 # A media backup covers replaced files, with new media removed on restore.
 for x in ROOT:
  upload=ROOT[x]/'wp-content/uploads'/NAME;upload.mkdir()
  (upload/'existing.txt').write_text('source media' if x==S else 'local media')
 (ROOT[S]/'wp-content/uploads'/NAME/'new.txt').write_text('new media')
 media=op('prepare',{'site':'https://trickle-qa-a.localhost:8453','direction':'pull','selection':{'plugins':[],'themes':[],'tables':[],'media':'all'},'automatic':True})
 while op('transfer',{'id':media['id'],'kind':'files'}).get('continue'):pass
 op('commit',{'id':media['id']});op('finish',{'id':media['id']})
 op('restore_backup',{'id':media['id']})
 upload=ROOT[D]/'wp-content/uploads'/NAME
 check((upload/'existing.txt').read_text()=='local media' and not (upload/'new.txt').exists(),'Media restoration recovers replaced bytes and removes newly imported media')
 # Replacing wp_options must retain the entire backup catalog and connection credentials.
 identity=ev(D,'echo wp_json_encode([get_option("blogname"),get_option("_csm_key"),get_option("_csm_peers")]);')
 opts=op('prepare',{'site':'https://trickle-qa-a.localhost:8453','direction':'pull','selection':{'plugins':[],'themes':[],'tables':['wp_options'],'media':'none'},'automatic':True})
 while op('transfer',{'id':opts['id'],'kind':'database'}).get('continue'):pass
 op('commit',{'id':opts['id']});op('finish',{'id':opts['id']});op('restore_backup',{'id':opts['id']})
 after=ev(D,'echo wp_json_encode([get_option("blogname"),get_option("_csm_key"),get_option("_csm_peers")]);')
 check(identity==after and len(op('backups')['backups'])==3,'Options-table migration and restoration preserve identity, keys, connections and all retained backups')
 # Copying a saved database must also retain a recoverable journal before work begins.
 wp(D,'eval','add_filter("query",static function($q){if(str_starts_with($q,"INSERT INTO `_csm_")&&str_contains($q,"_old`"))exit(74);return $q;});CSM_Controller::execute("restore_backup",["id"=>"'+opts['id']+'"]);',fail=True)
 copying=ev(D,'$r=get_option("_csm_backup_restore");echo wp_json_encode(CSM_Support::job($r["job_id"])["status"]);')
 check(copying=='backup_copy' and op('state')['destination_recovery'],'Interrupted backup copying is journalled before any destination replacement')
 recovered=op('recover_destination')
 check(recovered['status']=='restored' and not op('state')['destination_recovery'],'Dashboard recovery restarts a killed backup copy and completes restoration')
 # A real PHP process exits after saving its applying state, leaving the OS lock released.
 staged=op('prepare',sel)
 while op('transfer',{'id':staged['id'],'kind':'files'}).get('continue'):pass
 err=wp(D,'eval',"add_action('csm_checkpoint',static function($id,$phase){if($phase==='apply')exit(73);},10,2);$s=CSM_Controller::session();CSM_Controller::execute('commit',['id'=>$s['id']]);",fail=True)
 check(ev(D,'$j=CSM_Support::job(get_option("_csm_active_job"));echo wp_json_encode($j["status"]);')=='applying','Hard process exit retains a complete recovery plan after component replacement')
 (P[D]/'code.txt').write_text('partial replacement')
 wp(D,'eval','wp_schedule_single_event(time()-10,"trickle_fixture_ordinary");')
 ca=Path.home()/'Library/Application Support/Caddy/pki/authorities/local/root.crt'
 def cron():
  c=http.client.HTTPSConnection(D+'.localhost',8453,context=ssl.create_default_context(cafile=str(ca)),timeout=90)
  try:
   c.request('POST','/wp-cron.php',headers={'User-Agent':'T3Code (user-directed AI agent; agent=Codex; model=gpt-6.1-sol)'})
   r=c.getresponse();r.read();assert r.status==200,r.status
  finally:c.close()
 cron()
 check(ev(D,'echo wp_json_encode(get_option("_csm_active_job"));')==staged['id'] and (P[D]/'code.txt').read_text()=='partial replacement','Fresh heartbeat defers automatic recovery without touching destination files')
 # Simulate the crashed worker's heartbeat expiring; do not wait on wall-clock time.
 ev(D,'$id='+json.dumps(staged['id'])+';foreach(["-heartbeat","-apply.json","-restore.json"] as $suffix){$path=CSM_Support::private_dir()."/".$id.$suffix;if(is_file($path))touch($path,time()-120);}echo "true";')
 cron()
 # wp-cron can finish its HTTP response before bootstrap/recovery runs.
 for _ in range(40):
  if ev(D,'echo wp_json_encode(!get_option("_csm_active_job"));'):break
  time.sleep(.1)
 check((P[D]/'code.txt').read_text()=='1' and ev(D,'echo wp_json_encode(!get_option("_csm_active_job"));'),'Cron automatically restores an abruptly interrupted replacement')
 check(ev(D,'echo wp_json_encode((bool)wp_next_scheduled("trickle_fixture_ordinary"));'),'Isolated recovery leaves other plugins’ scheduled events intact')
 status=op('status',{'id':staged['id']});check(status['status']=='restored','Controller reconnect discovers completed automatic recovery')
 op('finish',{'id':staged['id']})
 # Incoming transfers can recover on a dashboard reconnect without a local controller.
 staged=op('prepare',sel)
 while op('transfer',{'id':staged['id'],'kind':'files'}).get('continue'):pass
 wp(D,'eval',"add_action('csm_checkpoint',static function($id,$phase){if($phase==='apply')exit(73);},10,2);$s=CSM_Controller::session();CSM_Controller::execute('commit',['id'=>$s['id']]);",fail=True)
 wp(D,'eval','delete_option("_csm_control");')
 (P[D]/'code.txt').write_text('interrupted incoming transfer')
 check(op('state')['destination_recovery']==staged['id'],'Dashboard state detects an interrupted incoming transfer without a local session')
 recovered=op('recover_destination')
 check(recovered['status']=='restored' and (P[D]/'code.txt').read_text()=='1','Dashboard reconnect automatically restores an interrupted incoming transfer')
 ev(D,'CSM_Plugin::discard(CSM_Support::job("'+staged['id']+'"));CSM_Transport::request(CSM_Controller::peer(),"bundle_discard",["id"=>"'+staged['id']+'"]);echo "true";')
 # Backup listing and restore are available remotely for a push destination.
 pushed,_=migrate(8,'push')
 remote=op('backups',{'site':'https://trickle-qa-a.localhost:8453'})
 check(remote['backups'][0]['id']==pushed,'Push destination retains its backup and exposes it to the controlling site')
 op('restore_backup',{'site':'https://trickle-qa-a.localhost:8453','id':pushed})
 check((P[S]/'code.txt').read_text()=='4','Local controller restores a remote push backup')
 print(f'{checks} backup/workflow checks passed.',flush=True)
finally:
 try:
  state=op('state')['session']
  if state:
   op('recover',{'id':state['id'],'cancel':True})
   if op('state')['session']:op('finish',{'id':state['id']})
 except Exception as e:print('Recovery cleanup:',str(e)[:200])
 for x in ROOT:
  wp(x,'eval','foreach(get_option("_csm_backups",[]) as $id){CSM_Plugin::discard(CSM_Support::job($id));}global $wpdb;$wpdb->query("DROP TABLE IF EXISTS wp_trickle_backup_fixture");wp_clear_scheduled_hook("trickle_fixture_ordinary");')
  wp(x,'option','update','_csm_backups',json.dumps(original_backups[x]),'--format=json')
  wp(x,'option','update','active_plugins',json.dumps(original_active[x]),'--format=json')
  if settings[x] is False:wp(x,'option','delete','_csm_settings')
  else:wp(x,'option','update','_csm_settings',json.dumps(settings[x]),'--format=json')
  shutil.rmtree(P[x],ignore_errors=True)
  shutil.rmtree(ROOT[x]/'wp-content/uploads'/NAME,ignore_errors=True)
 wp(D,'option','update','_csm_recents',json.dumps(recents),'--format=json');wp(D,'option','update','_csm_history',json.dumps(history),'--format=json')
 if original_cron is None:wp(D,'config','delete','DISABLE_WP_CRON')
 else:wp(D,'config','set','DISABLE_WP_CRON','true' if original_cron else 'false','--raw')
