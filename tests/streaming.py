#!/usr/bin/env python3
"""Real signed TLS stream interruption/resume tests, restricted to idle QA-A/C."""
import json, subprocess, shutil, random, tempfile
from pathlib import Path
S,D='trickle-qa-a','trickle-qa-c'; NAME='trickle-stream-fixture'
ROOT={s:Path.home()/'Cove/Sites'/(s+'.localhost/public') for s in [S,D]}
P={s:ROOT[s]/'wp-content/plugins'/NAME for s in ROOT};checks=0
def wp(s,*args):
 p=subprocess.run(['cove','wp',s,*args],capture_output=True,text=True,timeout=180)
 if p.returncode:raise RuntimeError(p.stdout+p.stderr)
 return p.stdout
def ev(s,code):return json.loads(wp(s,'eval',code))
def cli(*args):return json.loads(wp(D,'trickle',*args,'--format=json'))
def op(action,data=None,stop=False):
 return ev(D,('define("CSM_STOP_ON_ERROR",true);' if stop else '')+'$s=CSM_Controller::session();try{$r=CSM_Controller::execute('+json.dumps(action)+',["id"=>$s["id"]]+json_decode('+repr(json.dumps(data or {}))+',true));echo wp_json_encode(["ok"=>true,"result"=>$r]);}catch(Throwable $e){echo wp_json_encode(["ok"=>false,"error"=>$e->getMessage(),"retry_after"=>$e instanceof CSM_Network_Error?$e->retry_after:0]);}')
def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
def prepare(direction='pull',transport='stream'):
 return ev(D,'echo wp_json_encode(CSM_Controller::execute("prepare",["site"=>"Diagnostics QA","direction"=>'+json.dumps(direction)+',"selection"=>["plugins"=>["'+NAME+'"],"themes"=>[],"tables"=>[],"media"=>"none","transport"=>'+json.dumps(transport)+',"sync_active_plugins"=>false]]));')
def finish(restore=False):
 if restore:cli('rollback','--yes')
 cli('discard','--yes')
for s in ROOT:
 assert ev(s,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 assert not P[s].exists()
old={s:ev(s,'echo wp_json_encode(["active"=>get_option("active_plugins"),"settings"=>get_option("_csm_settings"),"backups"=>get_option("_csm_backups",[]),"recents"=>get_option("_csm_recents",[])]);') for s in ROOT}
MU=ROOT[S]/'wp-content/mu-plugins/trickle-stream-interruption.php';assert not MU.exists()
with tempfile.TemporaryDirectory(prefix='trickle-stream-test-') as temp:
 cfg=Path(temp)/'mode';events=Path(temp)/'offsets.jsonl'
 try:
  P[S].mkdir();(P[S]/(NAME+'.php')).write_text('<?php\n/* Plugin Name: Streaming test fixture */\n');payload=random.Random(718).randbytes(2600000);(P[S]/'large.bin').write_bytes(payload)
  for s in ROOT:wp(s,'eval','update_option("_csm_backups",[],false);update_option("_csm_settings",["pull"=>true,"push"=>true],false);')
  compare='$q=["plugins"=>["'+NAME+'"],"themes"=>[],"media"=>"none"]; $calls=[];add_action("csm_request_complete",static function($action)use(&$calls){$calls[]=$action;});$r=CSM_Components::compare(CSM_Controller::peer("Diagnostics QA"),true,$q);echo wp_json_encode([$r,$calls]);'
  r,calls=ev(D,compare);check(r['matched']==0 and calls==['component_hashes'],'Absent destination folders need no per-file checksum requests')
  shutil.copytree(P[S],P[D]);(P[D]/'large.bin').write_bytes(b'old destination');(P[D]/'obsolete.txt').write_text('obsolete')
  for i in range(650):
   for s in ROOT:(P[s]/(str(i)+'.txt')).write_text('identical '+str(i))
  r,calls=ev(D,compare);check(r['matched']==651 and calls==['component_hashes','component_manifest'],'Differing components exchange one compressed manifest even beyond 500 files')
  MU.write_text('''<?php
add_action('plugins_loaded',static function(){
 if(($_GET['trickle']??'')!=='bundle_stream'||!is_file(__CFG__))return;
 $mode=file_get_contents(__CFG__);$d=CSM_Transport::authenticate('bundle_stream');
 file_put_contents(__EVENTS__,wp_json_encode($d['offset'])."\n",FILE_APPEND);
 if($mode==='rate'){status_header(429);header('Retry-After: 60');header('Content-Type: application/json');echo wp_json_encode(['ok'=>false,'error'=>'Fixture cooldown']);exit;}
 unlink(__CFG__);$g=CSM_Segments::source($d['id'],$d['kind'],$d['offset'],$d['bundle']);$g->rewind();
 while(ob_get_level())ob_end_clean();header('Content-Type: application/octet-stream');header('Content-Length: '.($d['bundle']['size']-$d['offset']));
 $left=700001;foreach($g as $bytes){$out=substr($bytes,0,$left);echo $out;$left-=strlen($out);if(!$left)break;}exit;
},0);
'''.replace('__CFG__',repr(str(cfg))).replace('__EVENTS__',repr(str(events))))
  prepare();check(op('preflight',{'kind':'files'})['ok'],'Streaming retains measured source snapshots before sending')
  cfg.write_text('cut');failed=op('transfer',{'kind':'files'},True)
  size=ev(D,'$s=CSM_Controller::session();echo wp_json_encode(CSM_Segments::received_bytes($s["id"],"files"));')
  check(not failed['ok'] and size==700001,'A real truncated HTTP response preserves its exact byte offset without applying')
  done=op('transfer',{'kind':'files'});check(done['ok'] and done['result']['ready'] and done['result']['checkpoint']['offset']>2600000,'A new controller request resumes the remaining snapshot bytes and verifies its digest')
  timeline=op('progress')['result']['timeline'];check(timeline['streams']['files']['received']==timeline['streams']['files']['size'],'Live progress reports the actual received stream bytes')
  cli('apply','--yes');check((P[D]/'large.bin').read_bytes()==payload and not (P[D]/'obsolete.txt').exists(),'Resumed pull applies correct bytes and removes obsolete code')
  finish(True);check((P[D]/'large.bin').read_bytes()==b'old destination' and (P[D]/'obsolete.txt').exists(),'Resumed pull keeps a complete rollback')
  prepare();op('preflight',{'kind':'files'});cfg.write_text('cut')
  done=op('transfer',{'kind':'files'});check(done['ok'] and done['result']['ready'],'Transient download failure automatically retries the safe step from its saved offset')
  finish()
  prepare();op('preflight',{'kind':'files'});cfg.write_text('cut');op('transfer',{'kind':'files'},True)
  ev(D,'$s=CSM_Controller::session();$p=CSM_Support::private_dir()."/segment-".$s["id"]."-files.incoming";$f=fopen($p,"c+b");fwrite($f,"corrupt");fclose($f);echo "true";')
  r=op('transfer',{'kind':'files'});check(not r['ok'] and 'checksum mismatch' in r['error'] and (P[D]/'large.bin').read_bytes()==b'old destination','A modified saved prefix fails the complete digest before destination changes')
  finish()
  prepare();op('preflight',{'kind':'files'});cfg.write_text('rate');r=op('transfer',{'kind':'files'})
  check(not r['ok'] and r['retry_after']>=59,'Long Retry-After pauses immediately without an early retry')
  before=len(events.read_text().splitlines());r=op('transfer',{'kind':'files'},True)
  check(not r['ok'] and len(events.read_text().splitlines())==before,'The saved server cooldown blocks subsequent network requests')
  cfg.unlink();wp(D,'eval',"delete_option('_csm_cooldown_'.substr(hash('sha256','trickle-qa-a.localhost'),0,20));");finish()
  push_payload=random.Random(719).randbytes(2100000);(P[D]/'large.bin').write_bytes(push_payload)
  prepare('push');op('preflight',{'kind':'files'})
  cut=ev(D,'''$c=get_option('_csm_control');$b=$c['bundles']['files'];$g=(static function()use($c,$b){$left=700001;foreach(CSM_Segments::source($c['id'],'files',0,$b) as $bytes){$out=substr($bytes,0,$left);yield $out;$left-=strlen($out);if(!$left)break;}})();try{CSM_Transport::request($c['peer'],'stream_data',['id'=>$c['id'],'kind'=>'files','bundle'=>$b,'offset'=>0],$g);echo 'false';}catch(Throwable $e){echo wp_json_encode($e instanceof CSM_Network_Error);}''')
  if not cut:raise AssertionError('Expected incomplete upload')
  done=op('transfer',{'kind':'files'});check(done['ok'] and done['result']['ready'],'Interrupted streamed uploads resume from the remote receiver checkpoint')
  cli('apply','--yes');check((P[S]/'large.bin').read_bytes()==push_payload,'Streaming push applies the controller selection');finish(True)
  check((P[S]/'large.bin').read_bytes()==payload,'Streaming push rollback restores the source fixture')
  prepare(transport='chunks');op('preflight',{'kind':'files'});r=op('transfer',{'kind':'files'})['result'];check(r['continue'] and r['checkpoint']['offset']==524288,'Explicit bounded mode still moves one 512 KiB segment');cli('resume');finish()
  staged=cli('pull','--remote=Diagnostics QA','--plugins='+NAME)
  check(staged['ready'] and ev(D,'echo wp_json_encode(get_option("_csm_control")["selection"]["transport"]);')=='stream','CLI pull defaults to resumable streaming')
  finish()
  plan=cli('run','pull-db','--remote=Diagnostics QA','--transport=chunks')
  check(plan['selection']['transport']=='chunks' and not ev(D,'echo wp_json_encode((bool)get_option("_csm_control"));'),'CLI preset plans retain the explicit transport without staging')
  policy=ev(D,'$calls=0;$r=CSM_Transport::retry(static function()use(&$calls){$calls++;if($calls<3)throw new CSM_Network_Error("fixture");return [true];});echo wp_json_encode([$r,$calls]);')
  check(policy==[[True],3],'Safe retries are bounded and use backoff')
  print(f'{checks} streaming checks passed.',flush=True)
 finally:
  MU.unlink(missing_ok=True);cfg.unlink(missing_ok=True)
  wp(D,'eval',"delete_option('_csm_cooldown_'.substr(hash('sha256','trickle-qa-a.localhost'),0,20));")
  state=ev(D,'echo wp_json_encode(CSM_Controller::session());')
  if state:
   if cli('status')['status'] in ['applying','applied']:cli('rollback','--yes')
   cli('discard','--yes')
  for s in ROOT:
   wp(s,'eval','foreach(get_option("_csm_backups",[]) as $id){CSM_Plugin::discard(CSM_Support::job($id));}')
   for opt,key in [('_csm_backups','backups'),('_csm_settings','settings'),('_csm_recents','recents'),('active_plugins','active')]:
    if old[s][key] is False:wp(s,'option','delete',opt)
    else:wp(s,'option','update',opt,json.dumps(old[s][key]),'--format=json')
   shutil.rmtree(P[s],ignore_errors=True)
