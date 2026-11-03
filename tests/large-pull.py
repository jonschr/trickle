#!/usr/bin/env python3
"""1 GiB native-plugin pull/repeat/restore, restricted to idle QA-A/C. No live sites."""
import hashlib,json,os,shutil,subprocess,time
from pathlib import Path
S,D='trickle-qa-a','trickle-qa-c';NAME='trickle-large-pull-fixture'
ROOT={s:Path.home()/'Cove/Sites'/(s+'.localhost/public') for s in (S,D)}
SIZE=1024**3

def ev(site,code):
 p=subprocess.run(['cove','wp',site,'eval',code],capture_output=True,text=True,timeout=180)
 if p.returncode:raise RuntimeError((p.stdout+p.stderr)[-2000:])
 for line in reversed(p.stdout.splitlines()):
  try:return json.loads(line)
  except ValueError:pass
 raise RuntimeError(p.stdout[-1000:])

def op(action,data):return ev(D,'echo wp_json_encode(CSM_Controller::execute('+json.dumps(action)+',json_decode('+repr(json.dumps(data))+',true)));')
def complete(action,data):
 for step in range(300):
  r=op(action,data)
  if not r.get('continue'):return r,step+1
 raise RuntimeError('Step budget exhausted: '+action)

def digest(path):
 with path.open('rb') as fp:return hashlib.file_digest(fp,'sha256').hexdigest()

for s in ROOT:
 assert ev(s,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 assert not (ROOT[s]/'wp-content/plugins'/NAME).exists()
old=ev(D,'echo wp_json_encode(["backups"=>get_option("_csm_backups",[]),"recents"=>get_option("_csm_recents",[])]);')
ids=[];start=time.monotonic()
try:
 for s in ROOT:
  folder=ROOT[s]/'wp-content/plugins'/NAME;folder.mkdir();(folder/'main.php').write_text('<?php /* Plugin Name: Large pull fixture */\n');(folder/'version.txt').write_text('source' if s==S else 'destination')
  ev(s,'require_once ABSPATH."wp-admin/includes/plugin.php";wp_clean_plugins_cache(false);echo "true";')
 source=ROOT[S]/'wp-content/plugins'/NAME/'payload.bin';block=os.urandom(1024**2)
 with source.open('wb') as fp:
  for _ in range(SIZE//len(block)):fp.write(block)
 expected=digest(source);ev(D,"update_option('_csm_backups',[],false);echo 'true';")
 selection={'plugins':[NAME],'themes':[],'tables':[],'media':'none','sync_active_plugins':False,'transport':'stream'}
 def prepare():
  r=op('prepare',{'site':'https://trickle-qa-a.localhost:8453','direction':'pull','selection':selection});ids.append(r['id']);return r['id']
 tid=prepare();p,prep_steps=complete('preflight',{'id':tid,'kind':'files'});wire=p['timeline']['streams']['files']['size']
 print(json.dumps({'phase':'prepared','bytes':wire,'steps':prep_steps,'elapsed':round(time.monotonic()-start,2)}),flush=True)
 r,transfer_steps=complete('transfer',{'id':tid,'kind':'files'});assert r['ready'] and r['files']>=2
 r,apply_steps=complete('commit',{'id':tid});assert r['status']=='applied' and r['health']['verified']
 target=ROOT[D]/'wp-content/plugins'/NAME/'payload.bin';assert target.stat().st_size==SIZE and digest(target)==expected
 op('finish',{'id':tid});first_seconds=round(time.monotonic()-start,2)
 print(json.dumps({'phase':'applied','bytes':SIZE,'transfer_steps':transfer_steps,'apply_steps':apply_steps,'elapsed':first_seconds,'hash_verified':True}),flush=True)
 repeat_start=time.monotonic();rid=prepare();p,repeat_prep=complete('preflight',{'id':rid,'kind':'files'});repeat_wire=p['timeline']['streams']['files']['size'];r,repeat_steps=complete('transfer',{'id':rid,'kind':'files'})
 assert r['ready'] and r['files']==0 and r['unchanged_files']==3 and repeat_wire<10000
 op('discard',{'id':rid})
 print(json.dumps({'phase':'repeat_verified','bytes':repeat_wire,'unchanged':r['unchanged_files'],'elapsed':round(time.monotonic()-repeat_start,2)}),flush=True)
 r,restore_steps=complete('restore_backup',{'id':tid});assert r['status']=='restored' and not target.exists() and (target.parent/'version.txt').read_text()=='destination'
 print(json.dumps({'phase':'restored','restore_steps':restore_steps,'total_elapsed':round(time.monotonic()-start,2),'customer_sites_contacted':0}),flush=True)
finally:
 try:
  session=ev(D,'echo wp_json_encode(CSM_Controller::session());')
  if session:
   complete('recover',{'id':session['id'],'cancel':True})
   session=ev(D,'echo wp_json_encode(CSM_Controller::session());')
   if session:op('finish',{'id':session['id']})
  for tid in ids:
   ev(D,"if(get_option('_csm_job_'."+repr(tid)+")){CSM_Support::lock();try{CSM_Plugin::discard(CSM_Support::job("+repr(tid)+"));}finally{CSM_Support::unlock();}}echo 'true';")
 finally:
  for s in ROOT:shutil.rmtree(ROOT[s]/'wp-content/plugins'/NAME,ignore_errors=True)
  ev(D,"update_option('_csm_backups',json_decode("+repr(json.dumps(old['backups']))+",true),false);update_option('_csm_recents',json_decode("+repr(json.dumps(old['recents']))+",true),false);echo 'true';")
