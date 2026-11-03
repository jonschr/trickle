#!/usr/bin/env python3
"""Opt-in QA-A→QA-C real-file pull. Leaves copied files and a backup on QA-C; never restores the successful payload."""
import json,subprocess,time,sys
from pathlib import Path
if sys.argv[1:]!=['--apply']:raise SystemExit('Requires --apply: copies all QA-A plugins/themes/media to QA-C and leaves them there.')
S,D='trickle-qa-a','trickle-qa-c';started=time.monotonic()
def ev(s,code):
 p=subprocess.run(['cove','wp',s,'eval',code],capture_output=True,text=True,timeout=180)
 assert p.returncode==0,(p.stdout+p.stderr)[-3000:]
 return json.loads(p.stdout.splitlines()[-1])
for s in (S,D):assert ev(s,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
selection=ev(D,'$r=CSM_Transport::request(CSM_Controller::peer("https://trickle-qa-a.localhost:8453"),"manifest");echo wp_json_encode(["tables"=>[],"plugins"=>array_column(array_filter($r["plugins"],static fn($p)=>empty($p["protected"])),"id"),"themes"=>array_column($r["themes"],"id"),"media"=>"all","sync_active_plugins"=>false,"transport"=>"stream"]);')
def op(action,data):return ev(D,'echo wp_json_encode(CSM_Controller::execute('+json.dumps(action)+',json_decode('+repr(json.dumps(data))+',true)));')
def finish(action,data):
 for i in range(250):
  r=op(action,data)
  if not r.get('continue'):return r,i+1
 raise RuntimeError('No completion: '+action)
def prepare():return op('prepare',{'site':'https://trickle-qa-a.localhost:8453','direction':'pull','selection':selection})['id']
tid=prepare();r,ps=finish('preflight',{'id':tid,'kind':'files'});print(json.dumps({'phase':'prepared','wire':r['timeline']['streams']['files']['size'],'steps':ps,'seconds':round(time.monotonic()-started,2)}),flush=True)
r,ts=finish('transfer',{'id':tid,'kind':'files'});assert r['ready'] and r['files']>20000,r
print(json.dumps({'phase':'verified','files':r['files'],'steps':ts,'seconds':round(time.monotonic()-started,2)}),flush=True)
r,aps=finish('commit',{'id':tid});assert r['status']=='applied' and r['health']['verified'],r
op('finish',{'id':tid});print(json.dumps({'phase':'applied','files':r['files'],'steps':aps,'seconds':round(time.monotonic()-started,2)}),flush=True)
rid=prepare();finish('preflight',{'id':rid,'kind':'files'});r,rts=finish('transfer',{'id':rid,'kind':'files'});m=r['media_comparison'];assert m['files_selected']==0,m
op('discard',{'id':rid});print(json.dumps({'phase':'repeat','media_selected':m['files_selected'],'years_skipped':m['years_skipped'],'years_compared':m['years_compared'],'files':r['files'],'unchanged':r['unchanged_files'],'seconds':round(time.monotonic()-started,2)}),flush=True)
print('PASS Full many-file pull completed; repeat selected zero media. Successful payload intentionally remains on QA-C.',flush=True)
