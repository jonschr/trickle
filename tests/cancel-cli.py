#!/usr/bin/env python3
"""Native cancel command and post-cron confirmation on QA-C only; no payload transferred."""
import json,subprocess
SITE='trickle-qa-c'
def call(*args):
 p=subprocess.run(['cove','wp',SITE,*args],capture_output=True,text=True,timeout=60)
 assert p.returncode==0,p.stdout+p.stderr
 return json.loads(p.stdout.splitlines()[-1])
def ev(code):return call('eval',code)
assert ev('echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
old=ev('echo wp_json_encode(get_option("_csm_recents",[]));')
try:
 r=ev('echo wp_json_encode(CSM_Controller::execute("prepare",["site"=>"https://trickle-qa-a.localhost:8453","direction"=>"pull","automatic"=>true,"selection"=>["tables"=>["wp_options"],"plugins"=>[],"themes"=>[],"media"=>"none"]]));')
 result=call('trickle','cancel','--yes','--format=json')
 assert result['id']==r['id'] and result['status']=='cancelled',result
 assert ev('echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 print('PASS Native CLI cancellation clears an idle staged transfer without applying any payload')
finally:
 ev("update_option('_csm_recents',json_decode("+repr(json.dumps(old))+",true),false);echo 'true';")
