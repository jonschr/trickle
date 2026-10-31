#!/usr/bin/env python3
"""Real signed-TLS version checks and native remote upgrades on idle QA-A/C only."""
import base64,hashlib,hmac,http.client,json,secrets,ssl,subprocess,tempfile,zipfile,shutil
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];BASE=Path.home()/'Cove/Sites';S='trickle-qa-a';D='trickle-qa-c'
SITE=BASE/(S+'.localhost/public');PLUGIN=SITE/'wp-content/plugins/trickle';MAIN=PLUGIN/'trickle.php';MU=SITE/'wp-content/mu-plugins/trickle-remote-update-test.php'
CA=Path.home()/'Library/Application Support/Caddy/pki/authorities/local/root.crt';CTX=ssl.create_default_context(cafile=str(CA))
checks=0;VERSION=json.loads((ROOT/'update.json').read_text())['version'];OLD='0.1.8'

def wp(site,*args,fail=False):
 p=subprocess.run(['cove','wp',site,*args],capture_output=True,text=True,timeout=180)
 if fail:
  assert p.returncode,p.stdout
  return p.stdout+p.stderr
 if p.returncode:raise RuntimeError(p.stdout+p.stderr)
 return p.stdout

def ev(site,code):return json.loads(wp(site,'eval',code))
def cli(*args,fail=False):
 out=wp(D,'trickle',*args,'--format=json',fail=fail)
 return out if fail else json.loads(out)
def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
def raw(action,key,data=None,version=VERSION):
 body=(json.dumps(data or {},separators=(',',':'))+'\n').encode();stamp=str(int(__import__('time').time()));nonce=secrets.token_hex(16)
 digest=hashlib.sha256(body).hexdigest();params=base64.b64encode((json.dumps({'trickle_version':version})+'\n').encode()).decode()
 signature=hmac.new(key.encode(),'\n'.join([action,stamp,nonce,digest,params]).encode(),hashlib.sha256).hexdigest()
 h={'X-CSM-Key-Id':hashlib.sha256(key.encode()).hexdigest()[:24],'X-CSM-Time':stamp,'X-CSM-Nonce':nonce,'X-CSM-Hash':digest,'X-CSM-Params':params,'X-CSM-Signature':signature,'Content-Type':'application/json'}
 c=http.client.HTTPSConnection(S+'.localhost',8453,context=CTX,timeout=120);c.request('POST','/?trickle='+action,body,h);r=c.getresponse();payload=r.read();status=r.status;c.close();return status,json.loads(payload)

for site in [S,D]:assert ev(site,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
assert not MU.exists();original=MAIN.read_bytes();settings=ev(S,'echo wp_json_encode(get_option("_csm_settings"));');tokens=ev(S,'echo wp_json_encode(get_option("_csm_tokens",[]));');active=ev(S,'echo wp_json_encode(get_option("active_plugins"));');recents=ev(D,'echo wp_json_encode(get_option("_csm_recents",[]));')
master=ev(S,'echo wp_json_encode(get_option("_csm_key"));');peer='--remote=https://trickle-qa-a.localhost:8453'
with tempfile.TemporaryDirectory(prefix='trickle-remote-update-') as tmp:
 tmp=Path(tmp);config=tmp/'config.json';events=tmp/'activation-events.jsonl';good=tmp/'valid.zip';bad=tmp/'wrong.zip'
 package_url=f'https://updates.example.test/trickle-{VERSION}.zip'
 files=[ROOT/n for n in ['trickle.php','update.json','README.md','AI-USAGE.md']]
 for folder in ['includes','assets','vendor']:files.extend(p for p in (ROOT/folder).rglob('*') if p.is_file() and not any(x.startswith('.') for x in p.relative_to(ROOT).parts))
 for path,wrong in [(good,False),(bad,True)]:
  with zipfile.ZipFile(path,'w',zipfile.ZIP_DEFLATED) as z:
   for p in files:
    content=p.read_bytes()
    if wrong and p==ROOT/'trickle.php':content=content.replace(VERSION.encode(),OLD.encode())
    z.writestr('trickle/'+p.relative_to(ROOT).as_posix(),content)
 def configure(**changes):
  state={'version':VERSION,'package':str(good),'status':200,'locked':False};state.update(changes);config.write_text(json.dumps(state));config.chmod(0o600)
 def source_version(version):MAIN.write_bytes(original.replace(VERSION.encode(),version.encode()))
 hook="""<?php
if(realpath(ABSPATH)!==realpath(getenv('HOME').'/Cove/Sites/trickle-qa-a.localhost/public'))return;
opcache_invalidate(WP_PLUGIN_DIR.'/trickle/trickle.php',true);
add_action('updated_option',static function($name,$old,$new){if($name==='active_plugins')file_put_contents(__EVENTS__,wp_json_encode(array_values($new))."\n",FILE_APPEND);},10,3);
add_filter('file_mod_allowed',static function($allow){$c=json_decode(file_get_contents(__CONFIG__),true);return empty($c['locked'])?$allow:false;});
add_filter('pre_http_request',static function($pre,$args,$url){
$c=json_decode(file_get_contents(__CONFIG__),true);
if(strtok($url,'?')==='https://raw.githubusercontent.com/jonschr/trickle/master/update.json'){
$feed=['name'=>'Trickle','slug'=>'trickle','version'=>$c['version'],'download_url'=>__URL__,'requires'=>'6.4','requires_php'=>'8.1'];
return ['headers'=>[],'body'=>wp_json_encode($feed),'response'=>['code'=>$c['status'],'message'=>'Fixture'],'cookies'=>[]];}
if($url===__URL__){if(!empty($args['stream'])){copy($c['package'],$args['filename']);$body='';}else{$body=file_get_contents($c['package']);}
return ['headers'=>[],'body'=>$body,'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>$args['filename']??null];}return $pre;},10,3);
""".replace('__CONFIG__',repr(str(config))).replace('__URL__',repr(package_url)).replace('__EVENTS__',repr(str(events)))
 configure();MU.write_text(hook)
 try:
  source_version(OLD)
  result=cli('pull',peer,'--tables=wp_csm_fixture',fail=True)
  check('versions must match' in result and ev(D,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));'),'Mismatch blocks even database-only pulls before destination staging')
  status,_=raw('prepare',master,{'selection':{'tables':['wp_csm_fixture']}})
  check(status==400 and ev(S,'echo wp_json_encode(!get_option("_csm_active_job"));'),'Signed requests cannot bypass receiver version checks')
  check('updates on the remote site' in cli('update-remote',peer,'--yes',fail=True),'Remote updating requires its separate explicit permission')
  wp(S,'eval',"update_option('_csm_settings',['pull'=>true,'push'=>false,'updates'=>true],false);")
  read=ev(S,"echo wp_json_encode(CSM_Access::issue('Read-only update test',true,false,1));")
  status,_=raw('update_trickle',read['pairing'].splitlines()[1],{'target':VERSION,'from':OLD})
  check(status==403,'Read-only scoped credentials cannot update remote code')
  info=cli('update-remote',peer,'--check')
  check(info['installed']==OLD and info['release']==VERSION and info['can_update_to_target'],'Local CLI can inspect matching remote JSON release without migrating')
  wp(S,'eval',"update_option('_csm_settings',['pull'=>false,'push'=>false,'updates'=>true],false);")
  check(cli('update-remote',peer,'--check')['can_update_to_target'] and raw('manifest',master)[0]==403,'Update permission allows version/release checks while application reads and writes stay disabled')
  wp(S,'eval',"update_option('_csm_settings',['pull'=>true,'push'=>false,'updates'=>true],false);")
  configure(version='9.9.9')
  check('currently publishes Trickle 9.9.9' in cli('update-remote',peer,'--yes',fail=True),'A different feed version cannot update one side into another mismatch')
  configure(status=503)
  check('JSON feed' in cli('update-remote',peer,'--yes',fail=True),'Unavailable metadata fails before altering remote files')
  configure(locked=True)
  check('disallows file modifications' in cli('update-remote',peer,'--yes',fail=True),'Remote WordPress file-modification restrictions remain enforced')
  configure();gitmarker=PLUGIN/'.git';assert not gitmarker.exists();gitmarker.write_text('gitdir: /test-fixture')
  try:check('Git working directory' in cli('update-remote',peer,'--yes',fail=True),'Remote updates never replace a Git-managed Trickle checkout')
  finally:gitmarker.unlink()
  configure(package=str(bad))
  check('expected Trickle version' in cli('update-remote',peer,'--yes',fail=True) and OLD in MAIN.read_text(),'Wrong-version ZIP is rejected before replacing the remote plugin')
  check(ev(S,'echo wp_json_encode(get_option("active_plugins"));')==active,'Rejected update preserves every original active plugin')
  configure()
  wp(S,'eval',"CSM_Plugin::prepare(['tables'=>['wp_csm_fixture']]);")
  check('retained transfers' in cli('update-remote',peer,'--yes',fail=True),'Remote updating is blocked while destination recovery data is retained')
  wp(S,'eval',"CSM_Plugin::discard(CSM_Support::job(get_option('_csm_active_job')));")
  source_version('9.9.9')
  check('remote Trickle is newer' in cli('update-remote',peer,'--yes',fail=True),'Remote downgrade is refused')
  source_version(VERSION)
  cli('pull',peer,'--tables=wp_csm_fixture')
  wp(D,'eval',"$c=get_option('_csm_control');$c['plugin_version']='0.1.8';update_option('_csm_control',$c,false);")
  check('changed since this transfer was prepared' in cli('apply','--yes',fail=True),'Changing the controlling version invalidates an already prepared transfer')
  wp(D,'eval',"$c=get_option('_csm_control');$c['plugin_version']='"+VERSION+"';update_option('_csm_control',$c,false);");source_version(OLD)
  check('versions must match' in cli('apply','--yes',fail=True),'Version changes after staging block apply')
  check(cli('status')['status']=='staging','Version failure leaves existing recovery status available')
  cli('discard','--yes')
  check(ev(D,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));'),'Cross-version discard remains available')
  source_version(OLD)
  updated=cli('update-remote',peer,'--yes')
  check(updated['installed']==VERSION and updated['updated'],'Local controller performs the real native WordPress remote upgrade')
  status,r=raw('version',master)
  check(status==200 and r['data']['plugin_version']==VERSION,'A fresh remote request verifies the new running version')
  observed=[json.loads(line) for line in events.read_text().splitlines()]
  check(observed and all('classic-editor/classic-editor.php' in row for row in observed),'Other application plugins remain active throughout native upgrade deactivation and reactivation')
  check(ev(S,'echo wp_json_encode(get_option("active_plugins"));')==active,'Native upgrade retains the full active list despite recovery-request isolation')
  check(ev(S,'echo wp_json_encode([get_option("_csm_settings"),get_option("_csm_key")]);')==[{'pull':True,'push':False,'updates':True},master],'Remote upgrade preserves connection key and permissions without enabling migration writes')
  check(ev(D,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));'),'Updating the plugin does not silently begin a migration')
  check(cli('update-remote',peer,'--yes')['updated'] is False,'Retrying an already-completed update is a no-op')
  ready=cli('pull',peer,'--tables=wp_csm_fixture');check(ready['ready'],'Matching versions permit a fresh pull after upgrade');cli('discard','--yes')
  print(f'{checks} remote update checks passed.',flush=True)
 finally:
  MAIN.write_bytes(original)
  control=ev(D,'echo wp_json_encode(CSM_Controller::session());')
  if control:cli('discard','--yes')
  job=ev(S,'echo wp_json_encode(get_option("_csm_active_job"));')
  if job:wp(S,'eval',"CSM_Plugin::discard(CSM_Support::job(get_option('_csm_active_job')));")
  wp(S,'option','update','active_plugins',json.dumps(active),'--format=json')
  wp(S,'option','update','_csm_settings',json.dumps(settings),'--format=json')
  wp(S,'option','update','_csm_tokens',json.dumps(tokens),'--format=json')
  wp(D,'option','update','_csm_recents',json.dumps(recents),'--format=json')
  MU.unlink(missing_ok=True)
