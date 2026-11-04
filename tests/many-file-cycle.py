#!/usr/bin/env python3
"""Many-file pull, repeat and restore in QA-A/C. Restores its fixtures and preserves existing media."""
import json,shutil,subprocess,time
from pathlib import Path
S,D='trickle-qa-a','trickle-qa-c';NAME='trickle-many-file-fixture';COUNT=25000
ROOT={s:Path.home()/'Cove/Sites'/(s+'.localhost/public') for s in (S,D)}
FOLDERS={s:root/'wp-content/plugins'/NAME for s,root in ROOT.items()}
start=time.monotonic()
def ev(site,code):
 p=subprocess.run(['cove','wp',site,'eval',code],capture_output=True,text=True,timeout=180)
 assert p.returncode==0,(p.stdout+p.stderr)[-2500:]
 return json.loads(p.stdout.splitlines()[-1])
def op(action,data):
 return ev(D,'echo wp_json_encode(CSM_Controller::execute('+json.dumps(action)+',json_decode('+repr(json.dumps(data))+',true)));')
def complete(action,data):
 for step in range(200):
  result=op(action,data)
  if not result.get('continue'):return result,step+1
 raise RuntimeError('Step budget exhausted: '+action)
def report(phase,**data):print(json.dumps({'phase':phase,'seconds':round(time.monotonic()-start,2),**data}),flush=True)
for site in ROOT:
 assert ev(site,'echo wp_json_encode([home_url(),!get_option("_csm_control")&&!get_option("_csm_active_job")]);')==['https://'+site+'.localhost:8453',True]
 assert not FOLDERS[site].exists()
old=ev(D,'echo wp_json_encode(["backups"=>get_option("_csm_backups",[]),"recents"=>get_option("_csm_recents",[])]);')
ids=[]
try:
 for folder in FOLDERS.values():
  folder.mkdir();(folder/'main.php').write_text('<?php /* Plugin Name: Many-file fixture */\n')
 (FOLDERS[D]/'destination.txt').write_text('original destination')
 for index in range(COUNT):
  folder=FOLDERS[S]/'payload'/str(index//500);folder.mkdir(parents=True,exist_ok=True)
  (folder/(str(index)+'.txt')).write_text('file '+str(index)+'\n')
 selection={'plugins':[NAME],'tables':[],'themes':[],'media':'none','transport':'stream','sync_active_plugins':False}
 def prepare():
  r=op('prepare',{'site':'https://trickle-qa-a.localhost:8453','direction':'pull','selection':selection});ids.append(r['id']);return r['id']
 tid=prepare();complete('preflight',{'id':tid,'kind':'files'});r,steps=complete('transfer',{'id':tid,'kind':'files'})
 assert r['ready'] and r['files']==COUNT and r['unchanged_files']==1,r
 state=ev(D,'$r=get_option("_csm_job_".'+repr(tid)+');echo wp_json_encode(["bytes"=>strlen(maybe_serialize($r)),"private"=>isset($r["_csm_blob"])]);')
 assert state['private'] and state['bytes']<2048,state
 report('verified',files=COUNT,steps=steps,database_record_bytes=state['bytes'])
 r,steps=complete('commit',{'id':tid});assert r['status']=='applied' and r['health']['verified'],r
 op('finish',{'id':tid})
 assert not (FOLDERS[D]/'destination.txt').exists()
 for source in (FOLDERS[S]/'payload').rglob('*.txt'):
  assert (FOLDERS[D]/source.relative_to(FOLDERS[S])).read_bytes()==source.read_bytes(),source
 report('applied',files=COUNT,steps=steps,exact_bytes=True)
 rid=prepare();complete('preflight',{'id':rid,'kind':'files'});repeat,_=complete('transfer',{'id':rid,'kind':'files'})
 assert repeat['files']==0 and repeat['unchanged_files']==COUNT+1,repeat
 op('discard',{'id':rid});report('repeat',files=0,unchanged=repeat['unchanged_files'])
 restored,steps=complete('restore_backup',{'id':tid});assert restored['status']=='restored',restored
 assert (FOLDERS[D]/'destination.txt').read_text()=='original destination'
 assert not any((FOLDERS[D]/'payload').rglob('*.txt'))
 report('restored',steps=steps)
 print('PASS Many-file pull/repeat/restore; existing QA media preserved.',flush=True)
finally:
 session=ev(D,'echo wp_json_encode(CSM_Controller::session());')
 if session:
  complete('recover',{'id':session['id'],'cancel':True})
  session=ev(D,'echo wp_json_encode(CSM_Controller::session());')
  if session:op('finish',{'id':session['id']})
 for tid in ids:
  ev(D,'if(get_option("_csm_job_".'+repr(tid)+')){CSM_Support::lock();try{CSM_Plugin::discard(CSM_Support::job('+repr(tid)+'));}finally{CSM_Support::unlock();}}echo "true";')
 ev(D,'update_option("_csm_backups",json_decode('+repr(json.dumps(old['backups']))+',true),false);update_option("_csm_recents",json_decode('+repr(json.dumps(old['recents']))+',true),false);echo "true";')
 for folder in FOLDERS.values():shutil.rmtree(folder,ignore_errors=True)
