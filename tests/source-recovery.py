#!/usr/bin/env python3
"""Read-only-source recovery/old-loader checks on QA-A/C. No migration apply or push."""
import json, shutil, subprocess, tempfile, time
from pathlib import Path
S,D='trickle-qa-a','trickle-qa-c'; NAME='trickle-source-recovery-fixture'
ROOT={s:Path.home()/'Cove/Sites'/(s+'.localhost/public') for s in [S,D]}
checks=0
def wp(s,*args):
 p=subprocess.run(['cove','wp',s,*args],capture_output=True,text=True,timeout=180)
 assert p.returncode==0,p.stdout+p.stderr
 return p.stdout
def ev(s,code):
 return json.loads(wp(s,'eval',code).splitlines()[-1])
def op(action):return ev(D,'$s=CSM_Controller::session();echo wp_json_encode(CSM_Controller::execute('+json.dumps(action)+',["id"=>$s["id"],"kind"=>"files"]));')
def prepare():return ev(D,'echo wp_json_encode(CSM_Controller::execute("prepare",["site"=>"https://trickle-qa-a.localhost:8453","direction"=>"pull","selection"=>["plugins"=>["'+NAME+'"],"transport"=>"stream","sync_active_plugins"=>false]]));')
def preflight():return ev(D,'$calls=[];add_action("csm_request_complete",static function($a,$c)use(&$calls){if($a==="bundle")$calls[]=$c["http_status"];},10,2);$s=CSM_Controller::session();$start=microtime(true);try{$r=CSM_Controller::execute("preflight",["id"=>$s["id"],"kind"=>"files"]);echo wp_json_encode([$r["timeline"]["ready"],$calls]);}catch(CSM_Network_Error $e){echo wp_json_encode(["exhausted"=>$e->exhausted,"calls"=>$calls,"milliseconds"=>(microtime(true)-$start)*1000]);}')

def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
for s in ROOT:
 wp(s,'core','is-installed')
 assert ev(s,'echo wp_json_encode([home_url(),!get_option("_csm_control")&&!get_option("_csm_active_job")]);')==['https://'+s+'.localhost:8453',True]
folder=ROOT[S]/'wp-content/plugins'/NAME;mu=ROOT[S]/'wp-content/mu-plugins/trickle-source-recovery-fixture.php';loader=ROOT[S]/'wp-content/mu-plugins/0-trickle-loader.php'
assert not folder.exists() and not mu.exists()
original=loader.read_text();old_active=ev(S,'echo wp_json_encode(get_option("active_plugins"));');old=ev(D,'echo wp_json_encode(["recents"=>get_option("_csm_recents",[]),"backups"=>get_option("_csm_backups",[])]);')
holder=None
with tempfile.TemporaryDirectory(prefix='trickle-source-recovery-') as temp:
 ready=Path(temp)/'ready';legacy_flag=Path(temp)/'legacy'
 try:
  folder.mkdir();(folder/(NAME+'.php')).write_text('<?php\n/* Plugin Name: Source recovery fixture */\n');(folder/'code.txt').write_text('fixture')
  wp(S,'plugin','activate',NAME)
  expected=ev(S,'echo wp_json_encode(get_option("active_plugins"));')
  # Emulate the older anonymous isolation filter left by a native plugin upgrade.
  first="$GLOBALS['_csm_active_plugins_filter'] = static fn($plugins) => array_values(array_intersect($plugins, [$csm_plugin]));"
  second="add_filter('option_active_plugins', $GLOBALS['_csm_active_plugins_filter'], PHP_INT_MAX);"
  assert first in original and second in original
  loader.write_text(original.replace(first,'').replace(second,"add_filter('option_active_plugins', static fn($plugins) => array_values(array_intersect($plugins, [$csm_plugin])), PHP_INT_MAX);"))
  unauth=ev(D,'$p=CSM_Controller::peer("https://trickle-qa-a.localhost:8453");$p["key"]=str_repeat("0",64);try{CSM_Transport::request($p,"manifest");echo "false";}catch(Throwable $e){echo "true";}')
  check(unauth and loader.read_text()!=original,'Unauthenticated calls cannot refresh the recovery loader')
  manifest=ev(D,'echo wp_json_encode(CSM_Transport::request(CSM_Controller::peer("https://trickle-qa-a.localhost:8453"),"manifest"));')
  check(manifest['active_plugins']==expected and len(expected)>1 and loader.read_text()==original,'First authenticated call preserves the full activation list under an older loader and refreshes it')
  mu.write_text('''<?php
add_action('plugins_loaded',static function(){if(($_GET['trickle']??'')!=='bundle'||!is_file(__FLAG__))return;CSM_Transport::authenticate('bundle');unlink(__FLAG__);CSM_Plugin::respond([],'Another transfer is running on this site.',400);},0);
'''.replace('__FLAG__',repr(str(legacy_flag))))
  prepare()
  code='CSM_Support::lock();file_put_contents('+repr(str(ready))+',"ready");sleep(3);CSM_Support::unlock();'
  holder=subprocess.Popen(['cove','wp',S,'eval',code],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  until=time.monotonic()+10
  while not ready.exists() and time.monotonic()<until:time.sleep(.05)
  assert ready.exists(),'Lock holder did not start'
  pending=preflight();check(pending['exhausted'] and pending['calls']==[409] and pending['milliseconds']<2500,'A busy source releases the controller request promptly instead of sleeping through repeated waits')
  holder.wait(timeout=10);holder=None
  check(preflight()==[True,[200]],'A later request resumes preparation once the source lock is released')
  no_cooldown=ev(D,'$p=CSM_Controller::peer("https://trickle-qa-a.localhost:8453");$r=CSM_Transport::request($p,"version");echo wp_json_encode($r["plugin_version"]===CSM_VERSION);')
  check(no_cooldown,'A busy build does not impose an unrelated host-wide rate-limit cooldown');op('discard')
  prepare();legacy_flag.write_text('busy')
  pending=preflight();check(pending['exhausted'] and pending['calls']==[400] and preflight()==[True,[200]],'Older unclassified source-lock errors retain a resumable preparation')
  immediate=ev(D,'CSM_Support::lock();$t=microtime(true);try{$s=CSM_Controller::session();CSM_Controller::execute("commit",["id"=>$s["id"]]);echo "false";}catch(Throwable $e){echo wp_json_encode($e instanceof CSM_Busy_Error&&!$e->exhausted&&microtime(true)-$t<1);}finally{CSM_Support::unlock();}')
  check(immediate,'A busy apply is returned immediately and is never blindly replayed');op('discard')
  policy=ev(D,'$calls=0;try{CSM_Transport::retry(static function()use(&$calls){$calls++;if($calls===1)throw new CSM_Network_Error("Fixture EOF");$e=new CSM_Busy_Error("Fixture building");$e->retry_after=0;throw $e;});echo "false";}catch(CSM_Busy_Error $e){echo wp_json_encode([$e->exhausted,$calls]);}')
  check(policy==[True,3],'Network and busy retry counts remain independently bounded inside one short request')
  bounded=ev(D,'$calls=0;try{CSM_Transport::retry(static function()use(&$calls){$calls++;$e=new CSM_Busy_Error("Fixture building");$e->retry_after=0;throw $e;});echo "false";}catch(CSM_Busy_Error $e){echo wp_json_encode($e->exhausted&&$calls===2);}')
  check(bounded,'A permanently busy source eventually returns a retained, resumable failure')
  print(f'{checks} source-recovery checks passed; no migration apply or push.',flush=True)
 finally:
  if holder:holder.terminate();holder.wait(timeout=10)
  legacy_flag.unlink(missing_ok=True);mu.unlink(missing_ok=True);loader.write_text(original)
  if ev(D,'echo wp_json_encode((bool)CSM_Controller::session());'):op('discard')
  wp(D,'option','update','_csm_recents',json.dumps(old['recents']),'--format=json')
  wp(S,'option','update','active_plugins',json.dumps(old_active),'--format=json')
  shutil.rmtree(folder)
  assert ev(D,'echo wp_json_encode(get_option("_csm_backups",[]));')==old['backups']
