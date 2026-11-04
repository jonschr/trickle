#!/usr/bin/env python3
"""Exact local Trickle build installation over normal signed RPC. Idle QA-A/C only."""
import base64,hashlib,io,json,os,shutil,subprocess,tempfile,time,zipfile
from pathlib import Path
VERSION=json.loads((Path(__file__).resolve().parents[1]/'update.json').read_text())['version']
S,D='trickle-qa-a','trickle-qa-c';ROOT={s:Path.home()/'Cove/Sites'/(s+'.localhost/public') for s in [S,D]};P={s:ROOT[s]/'wp-content/plugins/trickle' for s in ROOT};checks=0

def ev(site,code):
 p=subprocess.run(['cove','wp',site,'eval',code],capture_output=True,text=True,timeout=150)
 if p.returncode:raise RuntimeError((p.stdout+p.stderr)[-2500:])
 return json.loads(p.stdout.splitlines()[-1])
def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
def op(action):return ev(D,'echo wp_json_encode(CSM_Controller::execute('+json.dumps(action)+',["site"=>"https://'+S+'.localhost:8453"]));')
def fingerprint(site):return ev(site,'echo wp_json_encode(CSM_Updates::fingerprint());')
for site in ROOT:assert ev(site,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
assert not (P[S]/'.git').exists()
with tempfile.TemporaryDirectory(prefix='trickle-build-update-') as temp:
 temp=Path(temp);config=temp/'config.json';payload=temp/'payload.json';snapshot=temp/'original';shutil.copytree(P[S],snapshot)
 old=ev(S,'echo wp_json_encode(["settings"=>get_option("_csm_settings"),"tokens"=>get_option("_csm_tokens",[]),"active"=>get_option("active_plugins"),"key_hash"=>hash("sha256",get_option("_csm_key"))]);')
 config.write_text(json.dumps({'locked':False,'old':old}));config.chmod(0o600)
 hooks=[]
 for site in ROOT:
  mu=ROOT[site]/'wp-content/mu-plugins/trickle-build-update-fixture.php';assert not mu.exists();hooks.append(mu)
  mu.write_text('''<?php
opcache_invalidate(WP_PLUGIN_DIR.'/trickle/trickle.php',true);
add_filter('file_mod_allowed',static function($allow){$c=json_decode(file_get_contents(__CONFIG__),true);return empty($c['locked'])?$allow:false;});
add_filter('pre_http_request',static function($pre,$args,$url){if(str_starts_with($url,'https://raw.githubusercontent.com/jonschr/trickle/'))return new WP_Error('fixture_feed_disabled','Published feed unavailable for local-build test');return $pre;},10,3);
'''.replace('__CONFIG__',repr(str(config))))
 def configure(locked):config.write_text(json.dumps({'locked':locked,'old':old}))
 def request(data,read_only=False):
  payload.write_text(json.dumps(data));payload.chmod(0o600)
  code='$d=json_decode(file_get_contents('+repr(str(payload))+'),true);$peer=CSM_Controller::peer("https://'+S+'.localhost:8453");'
  if read_only:code+='$peer["key"]=json_decode(file_get_contents('+repr(str(config))+'),true)["read_key"];'
  return ev(D,code+'try{$r=CSM_Transport::request($peer,"update_trickle_build",$d);echo wp_json_encode(["ok"=>true,"result"=>$r]);}catch(Throwable $e){echo wp_json_encode(["ok"=>false,"error"=>$e->getMessage()]);}')
 def prepare(data=None):
  data=data or ev(D,'echo wp_json_encode(CSM_Updates::package());')
  data['from']=ev(S,'echo wp_json_encode(CSM_VERSION);');data['from_build']=fingerprint(S);return data
 def altered(package,entries):
  with zipfile.ZipFile(io.BytesIO(base64.b64decode(package['package']))) as z:files={n:z.read(n) for n in z.namelist()}
  files.update(entries);blob=io.BytesIO()
  with zipfile.ZipFile(blob,'w',zipfile.ZIP_DEFLATED)as z:
   for name,content in files.items():z.writestr(name,content)
  raw=blob.getvalue();digest=hashlib.sha256()
  for name in sorted(files):
   relative=name.removeprefix('trickle/')
   if relative=='trickle.php' or relative.startswith(('includes/','assets/','vendor/')):digest.update((relative+'\n'+hashlib.sha256(files[name]).hexdigest()+'\n').encode())
  return {**package,'package':base64.b64encode(raw).decode(),'sha256':hashlib.sha256(raw).hexdigest(),'build':digest.hexdigest()}
 try:
  expected=fingerprint(D);check(expected==fingerprint(S),'Both installed builds begin identical')
  css=P[S]/'assets/admin.css';css.write_bytes(css.read_bytes()+b'\n/* remote-only build fixture */\n')
  check(op('remote_update_info')['can_update_to_target'],'Same-version different code can install the local build without a release')
  blocked=ev(D,'try{CSM_Updates::assert_build(CSM_Transport::request(CSM_Controller::peer("https://'+S+'.localhost:8453"),"version"));echo "false";}catch(RuntimeException $e){echo "true";}')
  check(blocked,'Different builds are rejected before a migration')
  started=time.monotonic();r=op('update_remote');elapsed=round(time.monotonic()-started,2)
  check(r['updated'] and fingerprint(S)==expected,'Signed installation replaces same-version code and verifies its exact build')
  print(json.dumps({'same_version_install_seconds':elapsed}),flush=True)
  check(not op('update_remote')['updated'],'An already matching build is a fast no-op')
  main=P[S]/'trickle.php';original=main.read_bytes()
  parts=VERSION.split('.');newer='.'.join(parts[:-1]+[str(int(parts[-1])+1)])
  for version in ['0.1.18',newer]:
   main.write_bytes(original.replace(VERSION.encode(),version.encode()))
   check(op('update_remote')['updated'] and fingerprint(S)==expected,'Exact local installation replaces remote '+version+' without consulting the feed')
  data=prepare();before=fingerprint(S)
  r=request({**data,'from_build':'0'*64});check(not r['ok'] and fingerprint(S)==before,'A stale remote-build snapshot cannot overwrite newer remote work')
  bad={**data,'sha256':'0'*64};r=request(bad);check(not r['ok'] and 'checksum' in r['error'].lower() and fingerprint(S)==before,'Corrupt uploads leave the working plugin intact')
  r=request({**data,'build':'f'*64});check(not r['ok'] and fingerprint(S)==before,'Incorrect build fingerprints are rejected before replacement')
  for name in ['../outside.php','trickle/.env','trickle/unrelated.php']:
   r=request(altered(data,{name:b'fixture'}));check(not r['ok'] and fingerprint(S)==before,'Archive rejects '+name+' without changing code')
  r=request(altered(data,{'trickle/includes/broken-fixture.php':b'<?php function ('}));check(not r['ok'] and 'PHP parse error' in r['error'] and fingerprint(S)==before,'Destination PHP parse errors are detected before replacement')
  read=ev(S,'echo wp_json_encode(CSM_Access::issue("Build fixture",true,false,1));');c=json.loads(config.read_text());c['read_key']=read['pairing'].rsplit('\n',1)[1];config.write_text(json.dumps(c))
  r=request(data,True);check(not r['ok'] and 'read/write' in r['error'],'Read-only keys cannot install code')
  configure(True);r=request(data);check(not r['ok'] and 'disallows file' in r['error'],'Host file-modification restrictions are honored');configure(False)
  (P[S]/'.git').write_text('gitdir: /fixture\n');r=request(data);check(not r['ok'] and 'Git working' in r['error'],'Remote Git checkouts are protected');(P[S]/'.git').unlink()
  ev(S,'update_option("_csm_control",["id"=>str_repeat("a",32)],false);echo "true";');r=request(data);check(not r['ok'] and 'retained transfers' in r['error'],'Retained migrations prevent plugin replacement');ev(S,'delete_option("_csm_control");echo "true";')
  ev(S,'update_option("_csm_settings",["pull"=>false,"push"=>false],false);echo "true";');css.write_bytes(css.read_bytes()+b'\n/* update with migrations disabled */\n')
  check(op('update_remote')['updated'],'Write-scoped code updates work when migration switches are disabled')
  final=ev(S,'echo wp_json_encode(["active"=>get_option("active_plugins"),"key_hash"=>hash("sha256",get_option("_csm_key")),"loader"=>CSM_Plugin::loader_ready()]);')
  check(final['active']==old['active'] and final['key_hash']==old['key_hash'] and final['loader'],'Activation, connection credentials and recovery loader survive updates')
  print(str(checks)+' local-build update checks passed.',flush=True)
 finally:
  ev(S,'delete_option("_csm_control");$o=json_decode(file_get_contents('+repr(str(config))+'),true)["old"];update_option("_csm_tokens",$o["tokens"],false);update_option("active_plugins",$o["active"],false);if($o["settings"]===false)delete_option("_csm_settings");else update_option("_csm_settings",$o["settings"],false);echo "true";')
  shutil.rmtree(P[S]);shutil.copytree(snapshot,P[S])
  for mu in hooks:mu.unlink(missing_ok=True)
  ev(S,'CSM_Plugin::install_loader();if(function_exists("opcache_reset"))opcache_reset();echo "true";')
