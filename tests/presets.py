#!/usr/bin/env python3
"""Agent presets over signed HTTPS on disposable QA-A/C, preserving existing backups."""
import json,subprocess,shutil
from pathlib import Path
S='trickle-qa-a';D='trickle-qa-c';BASE=Path.home()/'Cove/Sites';ROOT={x:BASE/(x+'.localhost/public') for x in [S,D]};NAME='trickle-agent-custom';MISSING='trickle-agent-missing';PARENT='trickle-agent-parent';CHILD='trickle-agent-child';checks=0;pending_restore=None

def wp(site,*args,fail=False):
 r=subprocess.run(['cove','wp',site,'--user=admin',*args],capture_output=True,text=True,timeout=240)
 if fail:
  assert r.returncode,r.stdout
  return r
 if r.returncode:raise RuntimeError(r.stdout+r.stderr)
 return r.stdout

def ev(site,code):return json.loads(wp(site,'eval',code))
def cli(*args,fail=False):
 result=wp(D,'trickle',*args,'--format=json',fail=fail)
 return json.loads(result.stdout if fail else result)
def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
def run(preset,*flags,fail=False):return cli('run',preset,'--remote=Diagnostics QA',*flags,fail=fail)
def restore(id):return cli('restore-backup',id,'--yes')
for x in ROOT:
 assert ev(x,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 for path in [ROOT[x]/'wp-content/plugins'/NAME,ROOT[x]/'wp-content/plugins'/MISSING,ROOT[x]/'wp-content/themes'/PARENT,ROOT[x]/'wp-content/themes'/CHILD]:assert not path.exists()
old={x:ev(x,'echo wp_json_encode(["active"=>get_option("active_plugins"),"settings"=>get_option("_csm_settings"),"backups"=>get_option("_csm_backups",[]),"recents"=>get_option("_csm_recents",[]),"history"=>get_option("_csm_history",[])]);') for x in ROOT}
fixture_marker=ROOT[D]/'wp-content/plugins/trickle-ui-review/.git';held_marker=fixture_marker.with_name('.trickle-agent-git-held')
assert not held_marker.exists()
paths={x:ROOT[x]/'wp-content/plugins'/NAME for x in ROOT};styles={x:ROOT[x]/'wp-content/themes'/PARENT/'style.css' for x in ROOT}
try:
 if fixture_marker.exists():
  assert fixture_marker.is_file() and fixture_marker.read_text().strip()=='gitdir: /fixture', 'Do not hide a real repository'
  fixture_marker.rename(held_marker)
 for x in ROOT:
  p=paths[x];p.mkdir();(p/(NAME+'.php')).write_text('<?php\n/* Plugin Name: Agent custom fixture\nVersion: '+('1.0' if x==S else '2.0')+'\n*/\n');(p/'code.txt').write_text('remote code' if x==S else 'local code');(p/'.env').write_text('remote environment' if x==S else 'local environment')
  styles[x].parent.mkdir();styles[x].write_text('/* Theme Name: Agent vendor parent\nVersion: 1.0\n*/\n/* '+x+' */');(styles[x].parent/'index.php').write_text('<?php')
  child=ROOT[x]/'wp-content/themes'/CHILD;child.mkdir();(child/'style.css').write_text('/* Theme Name: Agent custom child\nTemplate: '+PARENT+'\nVersion: '+('1.0' if x==S else '2.0')+'\n*/');(child/'functions.php').write_text('<?php')
  wp(x,'eval','update_option("_csm_backups",[],false);update_option("_csm_settings",["pull"=>true,"push"=>true],false);global $wpdb;$wpdb->query("CREATE TABLE wp_trickle_agent_fixture (id int PRIMARY KEY, v varchar(50)) ENGINE=InnoDB");$wpdb->insert("wp_trickle_agent_fixture",["id"=>1,"v"=>"'+('remote' if x==S else 'local')+'"]);')
 (ROOT[D]/'wp-content/themes'/CHILD/'.git').write_text('gitdir: /child-fixture');
 (paths[D]/'.git').write_text('gitdir: /fixture');(paths[D]/'.gitignore').write_text('local fixture');(paths[D]/'node_modules').mkdir();(paths[D]/'node_modules'/'skip.js').write_text('never transfer')
 catalog=cli('presets');check({x['id'] for x in catalog}=={'pull-db','pull-db-missing','push-git'},'Preset catalog exposes exactly the three deterministic workflows')
 unknown=run('unknown',fail=True);check(unknown['ok'] is False and unknown['schema_version']==1,'Workflow errors return one JSON object with a nonzero exit status')
 code=cli('inventory')['version_controlled'];check(code=={'plugins':[NAME],'themes':[CHILD]},'Version-controlled folders are detected automatically from Git worktree markers')
 root_marker=paths[D]/'.git';root_marker.rename(paths[D]/'.git-held');nested=paths[D]/'vendor/repo/.git';nested.mkdir(parents=True)
 try:check(cli('inventory')['version_controlled']==code,'Nested Git directories also select their containing plugin folder')
 finally:shutil.rmtree(paths[D]/'vendor');(paths[D]/'.git-held').rename(root_marker)
 ancestor=ROOT[D]/'wp-content/.git'
 if not ancestor.exists():
  ancestor.write_text('gitdir: /ancestor-fixture')
  try:check(cli('inventory')['version_controlled']==code,'An ancestor repository alone does not add unmarked vendor folders')
  finally:ancestor.unlink()
 inventory=cli('inventory','--remote=Diagnostics QA');key=ev(S,'echo wp_json_encode(get_option("_csm_key"));')
 check('key' not in inventory and key not in json.dumps(inventory) and inventory['remote']['can_receive'],'Inventory exposes versions, components and capabilities without connection secrets')
 plan=run('push-git');check(plan['action']=='plan' and plan['can_run'] and plan['selection']['plugins']==[NAME] and plan['selection']['themes']==[CHILD] and plan['selection']['tables']==[] and plan['selection']['media']=='none','Default run only plans version-controlled files and never silently adds the unmarked parent')
 check(plan['activation']['mode']=='source_full_list','Presets preserve the explicit full-source activation default')
 check(run('push-git','--keep-destination-plugins')['activation']['mode']=='keep_destination','Agent plans expose the destination-activation opt-out')
 check(run('push-git','--yes','--dry-run')['action']=='plan','Dry run remains read-only even when --yes is supplied')
 check(ev(D,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));') and (paths[S]/'code.txt').read_text()=='remote code','Planning creates no transfer and leaves destination code unchanged')
 wp(S,'eval','update_option("_csm_settings",["pull"=>true,"push"=>false],false);')
 blocked=run('push-git');check(not blocked['can_run'] and any(x['code']=='writes_disabled' for x in blocked['blockers']),'Plan reports disabled remote writes before staging')
 err=run('push-git','--yes',fail=True);check(not err['ok'] and not err['transfer_id'],'Blocked execution returns a structured error without creating a transfer')
 wp(S,'eval','update_option("_csm_settings",["pull"=>true,"push"=>true],false);')
 styles[S].parent.rename(styles[S].parent.with_name(PARENT+'-held'))
 try:check(any(x['code']=='missing_parent_theme' for x in run('push-git')['blockers']),'Missing unmarked parent is reported instead of deploying vendor code')
 finally:styles[S].parent.with_name(PARENT+'-held').rename(styles[S].parent)
 parent=styles[S].read_text();r=run('push-git','--yes');check(r['ok'] and r['completed'] and r['status']=='applied' and r['backup_id']==r['id'],'One command stages, verifies, applies and reports the retained backup')
 check((paths[S]/'code.txt').read_text()=='local code' and styles[S].read_text()==parent and r['tables']==0,'Version-controlled deployment changes only selected code and preserves the vendor parent and database')
 check((paths[S]/'.env').read_text()=='remote environment' and not (paths[S]/'node_modules').exists() and not (paths[S]/'.gitignore').exists() and not (paths[S]/'.git').exists(),'Source Git metadata, environment files and node_modules never transfer')
 check(ev(D,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));') and ev(S,'echo wp_json_encode(!get_option("_csm_active_job"));'),'Completed workflow releases both active sessions without deleting its backup')
 check(run('push-git','--yes')['completed'],'A repeated workflow starts without manual discard or cleanup')
 (paths[S]/'.git').write_text('gitdir: /fixture');(paths[D]/'code.txt').write_text('next local code')
 protected=run('push-git');check('plugins/'+NAME in protected['selection']['skip_paths'],'Plan shows destination development folders that will be skipped')
 r=run('push-git','--yes');check((paths[S]/'code.txt').read_text()=='local code','--yes never authorizes overwriting a destination Git repository')
 r=run('push-git','--yes','--include-git=plugins/'+NAME);check((paths[S]/'code.txt').read_text()=='next local code' and (paths[S]/'.git').exists(),'Explicit per-transfer Git override deploys code while preserving repository metadata')
 (paths[S]/'.git').unlink()
 # Missing-only pull leaves installed development plugins untouched.
 m=ROOT[S]/'wp-content/plugins'/MISSING;m.mkdir();(m/(MISSING+'.php')).write_text('<?php\n/* Plugin Name: Agent missing fixture */\n')
 active=ev(S,'echo wp_json_encode(array_values(get_option("active_plugins")));');wp(S,'option','update','active_plugins',json.dumps(active+[MISSING+'/'+MISSING+'.php']),'--format=json')
 db=run('pull-db');missing=run('pull-db-missing')
 check(db['selection']['plugins']==[] and db['selection']['themes']==[] and db['selection']['media']=='none','Database preset selects no files or media')
 check(missing['selection']['plugins']==[MISSING] and missing['selection']['themes']==[] and missing['selection']['media']=='none','Missing-plugin preset selects only absent plugin folders')
 (paths[D]/'code.txt').write_text('local development retained')
 first=run('pull-db','--yes');pending_restore=first['id'];check(first['files']==0 and not (ROOT[D]/'wp-content/plugins'/MISSING).exists(),'Database-only execution does not fetch plugin files');restore(first['id']);pending_restore=None
 pulled=run('pull-db-missing','--yes');pending_restore=pulled['id'];check((ROOT[D]/'wp-content/plugins'/MISSING/(MISSING+'.php')).exists() and (paths[D]/'code.txt').read_text()=='local development retained','Database-plus-missing pull installs absent plugins and preserves installed development code')
 check(cli('inventory')['version_controlled']==code,'Database pull preserves local version-controlled code discovery')
 check(MISSING+'/'+MISSING+'.php' in ev(D,'echo wp_json_encode(array_values(get_option("active_plugins")));'),'Imported missing source-active plugin is activated after its files arrive')
 restore(pulled['id']);pending_restore=None
 # A staging failure automatically recovers and returns an actionable machine result.
 source=paths[D]/(NAME+'.php');valid=source.read_text();source.write_text('<?php\n/* Plugin Name: Agent custom fixture */\nfunction broken( {')
 err=run('push-git','--yes',fail=True);check(not err['ok'] and err['recovery']=='cancelled' and err['transfer_id'],'Failed staging returns its transfer ID and automatic recovery status')
 source.write_text(valid);check(run('push-git','--yes')['completed'],'The next agent command succeeds after automatic failed-stage cleanup')
 print(f'{checks} agent preset checks passed.',flush=True)
finally:
 if held_marker.exists():held_marker.rename(fixture_marker)
 if pending_restore:restore(pending_restore)
 # Restore a completed database pull before removing fixture tables, including after assertions fail.
 wp(S,'option','update','active_plugins',json.dumps(old[S]['active']),'--format=json');wp(D,'option','update','active_plugins',json.dumps(old[D]['active']),'--format=json')
 session=ev(D,'echo wp_json_encode(CSM_Controller::session());')
 if session:
  ev(D,'$s=CSM_Controller::session();$r=CSM_Controller::execute("recover",["id"=>$s["id"],"cancel"=>true]);if(CSM_Controller::session()){CSM_Controller::execute("finish",["id"=>$s["id"]]);}echo "true";')
 for x in ROOT:
  wp(x,'eval','foreach(get_option("_csm_backups",[]) as $id){CSM_Plugin::discard(CSM_Support::job($id));}global $wpdb;$wpdb->query("DROP TABLE IF EXISTS wp_trickle_agent_fixture");')
  for opt,key_ in [('_csm_settings','settings'),('_csm_backups','backups'),('_csm_recents','recents'),('_csm_history','history')]:
   if old[x][key_] is False:wp(x,'option','delete',opt)
   else:wp(x,'option','update',opt,json.dumps(old[x][key_]),'--format=json')
  for p in [ROOT[x]/'wp-content/plugins'/NAME,ROOT[x]/'wp-content/plugins'/MISSING,ROOT[x]/'wp-content/themes'/PARENT,ROOT[x]/'wp-content/themes'/(PARENT+'-held'),ROOT[x]/'wp-content/themes'/CHILD]:shutil.rmtree(p,ignore_errors=True)
