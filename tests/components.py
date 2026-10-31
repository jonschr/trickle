#!/usr/bin/env python3
"""Compact unchanged-component plans, real pulls and independent verification on QA-A/C."""
import json,subprocess,shutil
from pathlib import Path
S,D='trickle-qa-a','trickle-qa-c';NAME='trickle-component-fixture';ROOT={x:Path.home()/'Cove/Sites'/(x+'.localhost/public') for x in [S,D]};checks=0

def wp(site,*args,fail=False):
 p=subprocess.run(['cove','wp',site,*args],capture_output=True,text=True,timeout=180)
 if fail:assert p.returncode;return p.stdout+p.stderr
 if p.returncode:raise RuntimeError(p.stderr)
 return p.stdout

def ev(site,code):return json.loads(wp(site,'eval',code))
def cli(*args,fail=False):
 x=wp(D,'trickle',*args,'--format=json',fail=fail)
 return x if fail else json.loads(x)
def op(action,data=None):return ev(D,'$s=CSM_Controller::session();echo wp_json_encode(CSM_Controller::execute('+json.dumps(action)+',["id"=>$s["id"]]+json_decode('+repr(json.dumps(data or {}))+',true)));')
def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
def finish(restore=False):
 if restore:cli('rollback','--yes')
 cli('discard','--yes')
for site in ROOT:
 assert ev(site,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 for kind in ['plugins','themes']:assert not (ROOT[site]/'wp-content'/kind/NAME).exists()
old={x:ev(x,'echo wp_json_encode(["active"=>get_option("active_plugins"),"settings"=>get_option("_csm_settings"),"backups"=>get_option("_csm_backups",[]),"recents"=>get_option("_csm_recents",[])]);') for x in ROOT}
try:
 for site in ROOT:
  plugin=ROOT[site]/'wp-content/plugins'/NAME;theme=ROOT[site]/'wp-content/themes'/NAME;plugin.mkdir();theme.mkdir()
  (plugin/(NAME+'.php')).write_text('<?php\n/* Plugin Name: Component verification fixture */\n');(plugin/'code.txt').write_text('same code')
  (theme/'style.css').write_text('/* Theme Name: Component verification fixture */');(theme/'index.php').write_text('<?php')
  wp(site,'eval','update_option("_csm_backups",[],false);update_option("_csm_settings",["pull"=>true,"push"=>true],false);')
 r=cli('pull','--remote=Diagnostics QA','--plugins='+NAME,'--themes='+NAME)
 plan=ev(D,'$s=get_option("_csm_control");echo gzdecode(base64_decode($s["exports"]["files"]["component_same"]));')
 check(list(plan)==['component_token'] and r['files']==0 and r['unchanged_files']==4,'Identical plugin/theme pull uses a compact pinned inventory and transmits no file payload')
 check(r['preview']['counts']=={'add':0,'replace':0,'delete':0,'unchanged':4},'Compact comparisons preserve exact staged previews')
 local_progress=ev(D,'$c=get_option("_csm_control");$c["peer"]["key"]="unusable-key";$m=new ReflectionMethod(CSM_Controller::class,"progress");$r=$m->invoke(null,$c);echo wp_json_encode(!isset($r["poll_error"])&&$r["timeline"]["ready"]);')
 check(local_progress,'Prepared pulls report local progress without a redundant remote request')
 cli('apply','--yes');finish(True)
 check((ROOT[D]/'wp-content/plugins'/NAME/'code.txt').read_text()=='same code','Applying and restoring a fully unchanged pull preserves selected code')
 r=cli('pull','--remote=Diagnostics QA','--plugins='+NAME,'--themes='+NAME)
 target=ROOT[D]/'wp-content/plugins'/NAME/'code.txt';target.write_text('new local development')
 check('unchanged destination file changed' in cli('apply','--yes',fail=True),'A compact match never bypasses independent destination verification before apply')
 finish();target.write_text('same code')
 (ROOT[D]/'wp-content/plugins'/NAME/'obsolete.txt').write_text('obsolete')
 r=cli('pull','--remote=Diagnostics QA','--plugins='+NAME,'--themes='+NAME)
 check(r['preview']['counts']['delete']==1 and r['unchanged_files']==4,'A destination-only file breaks the aggregate match and remains an explicit deletion')
 cli('apply','--yes');check(not (ROOT[D]/'wp-content/plugins'/NAME/'obsolete.txt').exists(),'Partial comparison still removes obsolete destination code');finish(True)
 # Source-side validation binds the compact token to its exact selection and original metadata.
 code='''$s=['plugins'=>['trickle-component-fixture'],'themes'=>[],'media'=>'none'];$i=CSM_Components::inventory(['selection'=>$s]);$plan=base64_encode(gzencode(wp_json_encode(['component_token'=>$i['token']])));$s['component_same']=$plan;$files=CSM_Components::unchanged($s);$checks=[count($files)===2];$legacy=$s;$legacy['component_same']=base64_encode(gzencode(wp_json_encode($files)));$checks[]=CSM_Components::unchanged($legacy)===$files;$wrong=$s;$wrong['themes']=['trickle-component-fixture'];try{CSM_Components::unchanged($wrong);$checks[]=false;}catch(Throwable $e){$checks[]=true;}$bad=$s;$bad['component_same']=base64_encode(gzencode(wp_json_encode(['component_token'=>str_repeat('0',64)])));try{CSM_Components::unchanged($bad);$checks[]=false;}catch(Throwable $e){$checks[]=true;}file_put_contents(WP_PLUGIN_DIR.'/trickle-component-fixture/code.txt','changed source');try{iterator_to_array(CSM_Files::stream($s));$checks[]=false;}catch(Throwable $e){$checks[]=str_contains($e->getMessage(),'changed after comparison');}echo wp_json_encode($checks);'''
 values=ev(S,code)
 for ok,label in zip(values,['Pinned source inventory expands to the selected files','Existing per-file plans remain supported','Compact tokens cannot be reused for a different selection','Forged or stale compact tokens are refused','Source edits after comparison still stop snapshot generation']):check(ok,label)
 print(f'{checks} compact component checks passed.',flush=True)
finally:
 state=ev(D,'echo wp_json_encode(CSM_Controller::session());')
 if state:
  if cli('status')['status'] in ['applying','applied']:cli('rollback','--yes')
  cli('discard','--yes')
 for site in ROOT:
  wp(site,'eval','foreach(get_option("_csm_backups",[]) as $id){CSM_Plugin::discard(CSM_Support::job($id));}')
  for opt,key in [('_csm_backups','backups'),('_csm_settings','settings'),('_csm_recents','recents'),('active_plugins','active')]:
   if old[site][key] is False:wp(site,'option','delete',opt)
   else:wp(site,'option','update',opt,json.dumps(old[site][key]),'--format=json')
  for kind in ['plugins','themes']:shutil.rmtree(ROOT[site]/'wp-content'/kind/NAME)
