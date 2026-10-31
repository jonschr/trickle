#!/usr/bin/env python3
"""Real push/pull activation, opt-out and rollback checks on disposable QA-A/C."""
import json,subprocess,shutil
from pathlib import Path
S='trickle-qa-a';D='trickle-qa-c';BASE=Path.home()/'Cove/Sites';N='trickle-activation-';NAMES=[N+x for x in ['source','destination','target','missing','fatal','dependency']]
ROOT={x:BASE/(x+'.localhost/public') for x in [S,D]};checks=0

def wp(site,*args,fail=False):
 r=subprocess.run(['cove','wp',site,*args],capture_output=True,text=True,timeout=180)
 if fail:
  assert r.returncode,r.stdout
  return r.stdout+r.stderr
 if r.returncode:raise RuntimeError(r.stdout+r.stderr)
 return r.stdout

def ev(site,code):return json.loads(wp(site,'eval',code))
def op(action,data=None):return ev(D,'echo wp_json_encode(CSM_Controller::execute('+json.dumps(action)+',json_decode('+repr(json.dumps(data or {}))+',true)));')
def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
def active(site):return ev(site,'echo wp_json_encode(CSM_Activation::current());')
def setactive(site,plugins):wp(site,'option','update','active_plugins',json.dumps(plugins),'--format=json')
def file(name):return name+'/'+name+'.php'
def migrate(direction='pull',flag=None,tables=None):
 sel={'plugins':[] if tables else [N+'target'],'themes':[],'tables':tables or [],'media':'none'}
 if flag is not None:sel['sync_active_plugins']=flag
 r=op('prepare',{'site':'https://trickle-qa-a.localhost:8453','direction':direction,'selection':sel})
 for kind in ['database' if tables else 'files']:
  while True:
   r=op('transfer',{'id':r['id'],'kind':kind})
   if not r.get('continue'):break
 r=op('commit',{'id':r['id']});op('finish',{'id':r['id']});return r

def restore(id,remote=False):return op('restore_backup',{'id':id,**({'site':'https://trickle-qa-a.localhost:8453'} if remote else {})})
for x in ROOT:
 assert ev(x,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 for name in NAMES:assert not (ROOT[x]/'wp-content/plugins'/name).exists()
old={x:ev(x,'echo wp_json_encode(["active"=>get_option("active_plugins"),"settings"=>get_option("_csm_settings"),"backups"=>get_option("_csm_backups",[]),"name"=>get_option("blogname"),"recents"=>get_option("_csm_recents",[]),"history"=>get_option("_csm_history",[])]);') for x in ROOT}
baseline={x:list(old[x]['active'].values()) if isinstance(old[x]['active'],dict) else old[x]['active'] for x in ROOT}
source=baseline[S]+[file(N+'source'),file(N+'dependency')];dest=baseline[D]+[file(N+'destination')]
try:
 for x in ROOT:
  for name in NAMES:
   if x==D and name==N+'missing':continue
   path=ROOT[x]/'wp-content/plugins'/name;path.mkdir();(path/(name+'.php')).write_text('<?php\n/* Plugin Name: '+name+' */\n')
  dependency_plugin=ROOT[x]/'wp-content/plugins'/(N+'source')/(N+'source.php')
  dependency_plugin.write_text('<?php\n/* Plugin Name: '+N+'source\nRequires Plugins: '+N+'dependency\n*/\n')
  wp(x,'eval','update_option("_csm_backups",[],false);update_option("_csm_settings",["pull"=>true,"push"=>true],false);global $wpdb;$wpdb->query("CREATE TABLE wp_trickle_activation_fixture (id int PRIMARY KEY) ENGINE=InnoDB");$wpdb->insert("wp_trickle_activation_fixture",["id"=>1]);')
 setactive(S,source);setactive(D,dest)
 r=migrate();first=r['id']
 check(active(D)==source,'Partial plugin pull copies the full active list, including inactive destination dependencies outside its file selection')
 check(ev(D,'echo wp_json_encode(get_option("_csm_recents")["'+first+'"]["selection"]["sync_active_plugins"]);') is True,'New recent settings explicitly retain the source-activation default')
 setactive(D,baseline[D]);second=migrate()['id']
 restore(first);check(active(D)==dest,'Saved partial-transfer backup restores the complete prior active list')
 restore(second);check(active(D)==baseline[D],'A newer backup has its own independent activation snapshot')
 restore(first);check(active(D)==dest,'An older activation snapshot remains reusable after restoring another backup')
 r=migrate(flag=False)
 check(active(D)==dest and ev(D,'echo wp_json_encode(get_option("_csm_recents")["'+r['id']+'"]["selection"]["sync_active_plugins"]);') is False,'Explicit opt-out preserves destination activation and is retained in recent settings')
 raw=wp(D,'trickle','pull','--remote=https://trickle-qa-a.localhost:8453','--plugins='+N+'target','--keep-destination-plugins','--apply','--yes','--format=json');r=json.loads(raw);op('finish',{'id':r['id']})
 check(active(D)==dest,'CLI --keep-destination-plugins preserves the destination list')
 r=migrate(direction='push')
 check(active(S)==dest,'Partial plugin push copies the controlling source full active list')
 restore(r['id'],True);check(active(S)==source,'Remote backup restore recovers the remote destination original active list')
 r=migrate(tables=['wp_trickle_activation_fixture'])
 check(active(D)==source,'Database-only transfer copies activation even without selecting the options table')
 restore(r['id']);check(active(D)==dest,'Database-only backup restores activation independently of the options table')
 key=ev(D,'echo wp_json_encode(get_option("_csm_key"));')
 r=migrate(tables=['wp_options'])
 check(active(D)==source and ev(D,'echo wp_json_encode(get_option("_csm_key"));')==key,'Options-table transfer takes source activation while preserving destination connection credentials')
 restore(r['id']);check(active(D)==dest and ev(D,'echo wp_json_encode(get_option("blogname"));')==old[D]['name'],'Options-table backup restores previous activation and site settings')
 r=migrate(flag=False,tables=['wp_options']);check(active(D)==dest,'Options-table opt-out preserves destination activation too');restore(r['id'])
 setactive(S,source+[file(N+'missing')]);r=migrate()
 check(r['activation_missing']==[file(N+'missing')] and active(D)==source,'Unavailable source plugins are reported and skipped instead of storing invalid activation')
 restore(r['id']);setactive(S,source)
 # Keep Trickle active even when the source installation uses another plugin directory.
 mapped=ev(D,'$j=["selection"=>["sync_active_plugins"=>true]];CSM_Activation::source($j,["active_plugins"=>["upstream-trickle/trickle.php"],"trickle_plugin"=>"upstream-trickle/trickle.php"]);echo wp_json_encode($j["source_active_plugins"]);')
 check(mapped==['trickle/trickle.php'],'Different Trickle install directories map to the destination recovery plugin')
 invalid=ev(D,'$j=["selection"=>["sync_active_plugins"=>true]];try{CSM_Activation::source($j,["active_plugins"=>["../outside.php"]]);$ok=false;}catch(Throwable $e){$ok=true;}echo wp_json_encode($ok);')
 check(invalid,'Untrusted activation metadata cannot reference paths outside the plugin directory')
 # Changing activation between the two immutable streams must fail before replacement.
 r=op('prepare',{'site':'https://trickle-qa-a.localhost:8453','direction':'pull','selection':{'plugins':[N+'target'],'themes':[],'tables':['wp_trickle_activation_fixture'],'media':'none'}})
 while op('transfer',{'id':r['id'],'kind':'database'}).get('continue'):pass
 setactive(S,source+[file(N+'destination')])
 err=wp(D,'eval','$s=CSM_Controller::session();do{$r=CSM_Controller::execute("transfer",["id"=>$s["id"],"kind"=>"files"]);}while(!empty($r["continue"]));',fail=True)
 check('activation changed between streams' in err and active(D)==dest,'Source activation changing between streams stops staging without changing destination activation')
 op('recover',{'id':r['id'],'cancel':True});setactive(S,source)
 # Valid PHP can fail only on the destination normal frontend after activation.
 fatal='''if (!defined('WP_CLI') && !isset($_GET['trickle']) && !is_admin() && str_contains(home_url(),'trickle-qa-c.localhost')) { throw new RuntimeException('Intentional activation QA failure'); }\n'''
 for x in ROOT:
  path=ROOT[x]/'wp-content/plugins'/(N+'fatal')/(N+'fatal.php');path.write_text(path.read_text()+fatal)
 setactive(S,source+[file(N+'fatal')])
 err=wp(D,'trickle','pull','--remote=https://trickle-qa-a.localhost:8453','--plugins='+N+'target','--apply','--yes','--format=json',fail=True)
 check('health check returned HTTP 500' in err and active(D)==dest,'Failed source-plugin activation automatically rolls back the destination full active list')
 session=op('state')['session'];op('finish',{'id':session['id']});setactive(S,source)
 # Hard process exit immediately after the activation write still has a durable prior list.
 r=op('prepare',{'site':'https://trickle-qa-a.localhost:8453','direction':'pull','selection':{'plugins':[N+'target'],'themes':[],'tables':[],'media':'none'}})
 while op('transfer',{'id':r['id'],'kind':'files'}).get('continue'):pass
 wp(D,'eval','add_action("updated_option",static function($name){if($name==="active_plugins")exit(73);});$s=CSM_Controller::session();CSM_Controller::execute("commit",["id"=>$s["id"]]);',fail=True)
 check(active(D)==source and op('state')['destination_recovery']==r['id'],'Activation is journalled before a hard exit and Trickle remains active for recovery')
 op('recover_destination');check(active(D)==dest,'Hard-exit recovery restores the previous activation list')
 op('finish',{'id':r['id']})
 check('trickle/trickle.php' in active(S) and 'trickle/trickle.php' in active(D),'Trickle remains active on both sites throughout transfers and recovery')
 print(f'{checks} activation checks passed.',flush=True)
finally:
 # Remove source fatal activation before any ordinary bootstrap is needed.
 setactive(S,old[S]['active']);setactive(D,old[D]['active'])
 state=op('state')['session']
 if state:
  op('recover',{'id':state['id'],'cancel':True})
  if op('state')['session']:op('finish',{'id':state['id']})
 for x in ROOT:
  wp(x,'eval','foreach(get_option("_csm_backups",[]) as $id){CSM_Plugin::discard(CSM_Support::job($id));}global $wpdb;$wpdb->query("DROP TABLE IF EXISTS wp_trickle_activation_fixture");')
  for option,key_ in [('_csm_backups','backups'),('_csm_recents','recents'),('_csm_history','history'),('blogname','name')]:wp(x,'option','update',option,json.dumps(old[x][key_]),'--format=json')
  if old[x]['settings'] is False:wp(x,'option','delete','_csm_settings')
  else:wp(x,'option','update','_csm_settings',json.dumps(old[x]['settings']),'--format=json')
  for name in NAMES:shutil.rmtree(ROOT[x]/'wp-content/plugins'/name,ignore_errors=True)
