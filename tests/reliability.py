#!/usr/bin/env python3
"""Destructive checkpoint, incremental-file and preflight checks on idle QA-A/C."""
import json,subprocess,shutil,hashlib,random
from pathlib import Path
BASE=Path.home()/'Cove/Sites';S='trickle-qa-a';D='trickle-qa-c';NAME='trickle-reliability-fixture'
ROOT={x:BASE/(x+'.localhost/public') for x in [S,D]};P={x:ROOT[x]/'wp-content/plugins'/NAME for x in ROOT}
checks=0

def wp(s,*args,fail=False):
 p=subprocess.run(['cove','wp',s,*args],capture_output=True,text=True,timeout=180)
 if fail:
  assert p.returncode,p.stdout
  return p.stdout+p.stderr
 if p.returncode:raise RuntimeError(p.stdout+p.stderr)
 return p.stdout

def ev(s,code):return json.loads(wp(s,'eval',code))
def cli(*args,fail=False):
 out=wp(D,'trickle',*args,'--format=json',fail=fail)
 return out if fail else json.loads(out)
def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
def execute(action,extra=None):
 return ev(D,"$s=CSM_Controller::session();echo wp_json_encode(CSM_Controller::execute("+json.dumps(action)+",['id'=>$s['id']]+json_decode("+repr(json.dumps(extra or {}))+",true)));")
def finish(restore=False):
 if restore:cli('rollback','--yes')
 cli('discard','--yes')
for x in ROOT:
 assert ev(x,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 assert not P[x].exists()
active={x:ev(x,'echo wp_json_encode(get_option("active_plugins"));') for x in ROOT};settings={x:ev(x,'echo wp_json_encode(get_option("_csm_settings"));') for x in ROOT};recents=ev(D,'echo wp_json_encode(get_option("_csm_recents",[]));');backups={x:ev(x,'echo wp_json_encode(get_option("_csm_backups",[]));') for x in ROOT}
try:
 for x in ROOT:
  P[x].mkdir();(P[x]/(NAME+'.php')).write_text('<?php\n/* Plugin Name: Reliability fixture */\n')
  (P[x]/'code.txt').write_text('source' if x==S else 'destination')
  wp(x,'eval',"update_option('_csm_backups',[],false);update_option('_csm_settings',['pull'=>true,'push'=>true],false);")
 (P[D]/'obsolete.txt').write_text('old code')
 payload=random.Random(712).randbytes(1600000);(P[S]/'large.bin').write_bytes(payload)
 selection={'plugins':[NAME],'themes':[],'tables':[],'media':'none'}
 first=ev(D,'echo wp_json_encode(CSM_Controller::execute("prepare",["site"=>"https://trickle-qa-a.localhost:8453","direction"=>"pull","selection"=>json_decode('+repr(json.dumps(selection))+',true)]));')
 planned=execute('preflight',{'kind':'files'})
 check(planned['timeline']['ready'] and planned['timeline']['components']['plugins']==len(payload)+len('source'),'Preflight measures only changed plugin payloads without an extra file-content pass')
 check(planned['timeline']['streams']['files']['received']==0 and (P[D]/'code.txt').read_text()=='destination','Preflight prepares the snapshot without sending or replacing destination data')
 part=execute('transfer',{'kind':'files'})
 check(part['continue'] and part['checkpoint']['offset']==524288 and not part['ready'],'First HTTP operation checkpoints exactly one bounded segment')
 check('state' not in part and ev(D,'$r=get_option("_csm_recents");echo wp_json_encode(reset($r)["status"]);')=='staging','Intermediate checkpoints omit redundant UI state while keeping the active recent entry')
 check(part['timeline']['streams']['files']['received']==524288,'Timeline exposes acknowledged compressed bytes for resumable progress')
 state=ev(D,'$c=get_option("_csm_control");echo wp_json_encode(CSM_Segments::state(CSM_Support::job($c["id"]),"files",$c["bundles"]["files"]));')
 check(state['offset']==524288,'Checkpoint survives a completely new PHP process')
 corrupt=ev(D,'$c=get_option("_csm_control");$ok=false;try{CSM_Segments::receive(CSM_Support::job($c["id"]),"files",$c["bundles"]["files"],524288,"corrupt",str_repeat("0",64));}catch(Throwable $e){$ok=true;}echo wp_json_encode([$ok,CSM_Segments::state(CSM_Support::job($c["id"]),"files",$c["bundles"]["files"])["offset"]]);')
 check(corrupt==[True,524288],'Corrupt segment is rejected without advancing acknowledged bytes')
 duplicate=ev(D,'$c=get_option("_csm_control");$fp=fopen("php://temp","w+b");CSM_Transport::request($c["peer"],"bundle_chunk",["id"=>$c["id"],"kind"=>"files","offset"=>0],null,$fp);rewind($fp);$b=stream_get_contents($fp);fclose($fp);$r=CSM_Segments::receive(CSM_Support::job($c["id"]),"files",$c["bundles"]["files"],0,$b,hash("sha256",$b));echo wp_json_encode($r["checkpoint"]["offset"]);')
 check(duplicate==524288,'Lost acknowledgement can retry the identical segment without duplication')
 (P[S]/'code.txt').write_text('source changed after snapshot')
 done=cli('resume')
 check(done['ready'] and done['files']==2 and done['unchanged_files']==1,'Resume completes only outstanding bytes and skips identical component file contents')
 detail=execute('progress');check(0<len(detail['events'])<=12 and all('phase' in event and 'updated' in event for event in detail['events']),'Progress retains a bounded timestamped activity list across segment requests')
 check(done['preview']['counts']=={'add':1,'replace':1,'delete':1,'unchanged':1},'Preview distinguishes additions, replacements, obsolete deletions and unchanged files')
 maintenance=ev(D,'''$seen=[];$probe=static function($name,$old,$new)use(&$seen){if($name!=='_csm_maintenance')return;$c=curl_init(home_url('/'));curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CAINFO=>CSM_Transport::ca_bundle(home_url('/')),CURLOPT_TIMEOUT=>10]);curl_exec($c);$seen[]=curl_getinfo($c,CURLINFO_RESPONSE_CODE);};add_action('updated_option',$probe,10,3);add_action('added_option',static function($name,$value)use($probe){$probe($name,null,$value);},10,2);$s=CSM_Controller::session();$r=CSM_Controller::execute('commit',['id'=>$s['id']]);echo wp_json_encode([$r['status'],$seen,get_option('_csm_maintenance',null)]);''')
 check(maintenance==['applied',[503],None],'Normal visitors see maintenance during apply and maintenance clears afterward')
 check((P[D]/'code.txt').read_text()=='source' and (P[D]/'large.bin').read_bytes()==payload,'Retained immutable source stream applies the original complete snapshot')
 check(not (P[D]/'obsolete.txt').exists(),'Incremental application still removes obsolete code')
 finish(True)
 check((P[D]/'code.txt').read_text()=='destination' and not (P[D]/'large.bin').exists() and (P[D]/'obsolete.txt').exists(),'Checkpointed incremental transfer retains complete rollback coverage')
 # Destination edits after review may not be silently overwritten.
 staged=cli('pull','--remote=https://trickle-qa-a.localhost:8453','--plugins='+NAME)
 (P[D]/'code.txt').write_text('new local work')
 err=cli('apply','--yes',fail=True)
 check('Destination code changed' in err and (P[D]/'code.txt').read_text()=='new local work','Pre-apply content comparison blocks newer destination work')
 finish();(P[D]/'code.txt').write_text('destination')
 # Source syntax and compatibility checks fail while live destination is untouched.
 (P[S]/(NAME+'.php')).write_text('<?php\n/* Plugin Name: Reliability fixture */\nfunction invalid( {')
 err=cli('pull','--remote=https://trickle-qa-a.localhost:8453','--plugins='+NAME,fail=True)
 check('PHP syntax check failed' in err and (P[D]/'code.txt').read_text()=='destination','Invalid PHP is rejected during staging before destination code changes')
 finish()
 (P[S]/(NAME+'.php')).write_text('<?php\n/* Plugin Name: Reliability fixture\nRequires PHP: 99.0\n*/\n')
 err=cli('pull','--remote=https://trickle-qa-a.localhost:8453','--plugins='+NAME,fail=True)
 check('requires PHP 99.0' in err,'Plugin PHP compatibility is checked before apply')
 finish()
 (P[S]/(NAME+'.php')).write_text('<?php\n/* Plugin Name: Reliability fixture */\n')
 # Both directions use the same segmented transport.
 pushed=cli('push','--remote=https://trickle-qa-a.localhost:8453','--plugins='+NAME)
 check(pushed['ready'] and pushed['files']==2 and pushed['unchanged_files']==1,'Push uses checkpoints and incremental components too')
 push_plan=execute('preflight',{'kind':'files'});check(push_plan['timeline']['ready'] and push_plan['timeline']['streams']['files']['received']==push_plan['timeline']['streams']['files']['size'],'Push reports the same measured timeline and verified checkpoints')
 cli('apply','--yes');check((P[S]/'code.txt').read_text()=='destination' and not (P[S]/'large.bin').exists(),'Push applies new code and destination-only code removal')
 finish(True);check((P[S]/'large.bin').read_bytes()==payload,'Push rollback restores destination-only files')
 # Valid syntax can still fail during normal plugin bootstrap; isolate recovery and restore.
 (P[S]/(NAME+'.php')).write_text('<?php\n/* Plugin Name: Reliability fixture */\nthrow new RuntimeException("intentional QA health failure");\n')
 wp(D,'plugin','activate',NAME)
 cli('pull','--remote=https://trickle-qa-a.localhost:8453','--plugins='+NAME,'--keep-destination-plugins')
 err=cli('apply','--yes',fail=True)
 check('health check returned HTTP 500' in err and cli('status')['status']=='restored','Frontend HTTP failure triggers automatic destination rollback')
 check('intentional QA health failure' not in (P[D]/(NAME+'.php')).read_text(),'Runtime-failing active plugin is replaced with its working backup')
 wp(D,'plugin','deactivate',NAME);finish()
 # Database streams use the same checkpoints and retain binary/serialized behavior.
 before=ev(D,'global $wpdb;echo wp_json_encode($wpdb->get_row("SELECT id,payload,HEX(binary_data) AS binary_data FROM wp_csm_fixture WHERE id=1",ARRAY_A));')
 db=cli('pull','--remote=https://trickle-qa-a.localhost:8453','--tables=wp_csm_fixture')
 check(db['ready'] and db['tables']==1 and db['rows']>0,'Database-only pull stages through checkpointed transport')
 db_plan=execute('status')['timeline'];check(db_plan['components']['database']>0 and db_plan['selected']==['database'],'Database timeline reports serialized payload size without inventing file totals')
 cli('apply','--yes');finish(True)
 after=ev(D,'global $wpdb;echo wp_json_encode($wpdb->get_row("SELECT id,payload,HEX(binary_data) AS binary_data FROM wp_csm_fixture WHERE id=1",ARRAY_A));')
 check(before==after,'Database rollback restores the original row including binary and serialized values')
 print(f'{checks} reliability checks passed.',flush=True)
finally:
 state=ev(D,'echo wp_json_encode(CSM_Controller::session());')
 if state:
  status=cli('status')
  if status['status'] in ['applying','applied']:cli('rollback','--yes')
  cli('discard','--yes')
 wp(D,'option','update','_csm_recents',json.dumps(recents),'--format=json')
 for x in ROOT:
  if settings[x] is False:wp(x,'option','delete','_csm_settings')
  else:wp(x,'option','update','_csm_settings',json.dumps(settings[x]),'--format=json')
  wp(x,'option','update','_csm_backups',json.dumps(backups[x]),'--format=json')
  wp(x,'option','update','active_plugins',json.dumps(active[x]),'--format=json')
  shutil.rmtree(P[x],ignore_errors=True)
