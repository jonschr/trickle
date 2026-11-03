#!/usr/bin/env python3
"""Real signed-TLS cascade transfers, restricted to disposable uploads on QA-A/C."""
import json, subprocess, time, shutil
from pathlib import Path
BASE=Path.home()/'Cove/Sites'
SOURCE,DEST='trickle-qa-a','trickle-qa-c'
ROOTS={s:BASE/(s+'.localhost/public') for s in [SOURCE,DEST]}
FIX={s:ROOTS[s]/'wp-content/uploads/trickle-cascade-fixture' for s in ROOTS}
MU={s:ROOTS[s]/'wp-content/mu-plugins/csm-cascade-fixture.php' for s in ROOTS}
checks=0

def wp(site,*args,fail=False):
 assert site in ROOTS
 p=subprocess.run(['cove','wp',site,*args],capture_output=True,text=True,timeout=180)
 if fail:
  assert p.returncode, 'Expected failure';return p.stdout+p.stderr
 if p.returncode:raise RuntimeError(p.stdout+p.stderr)
 return p.stdout

def cli(*args):return json.loads(wp(DEST,'trickle',*args,'--format=json').splitlines()[-1])
def evaluate(site,code):return json.loads(wp(site,'eval',code).splitlines()[-1])
def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
def write(site,rel,data):
 p=FIX[site]/rel;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
def finish(rollback=False):
 if rollback:cli('rollback','--yes')
 cli('discard','--yes')

for s in ROOTS:
 assert evaluate(s,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 assert not FIX[s].exists() and not MU[s].exists()
settings={s:evaluate(s,'echo wp_json_encode(get_option("_csm_settings"));') for s in ROOTS}
recents=evaluate(DEST,'echo wp_json_encode(get_option("_csm_recents",[]));')
backups={s:evaluate(s,'echo wp_json_encode(get_option("_csm_backups",[]));') for s in ROOTS}
try:
 for s in ROOTS:
  FIX[s].mkdir()
  MU[s].write_text("<?php\nadd_filter('upload_dir',static function($u){$u['basedir']=WP_CONTENT_DIR.'/uploads/trickle-cascade-fixture';$u['baseurl']=content_url('/uploads/trickle-cascade-fixture');$u['path']=$u['basedir'];$u['url']=$u['baseurl'];$u['error']=false;return $u;});\n")
  wp(s,'eval',"update_option('_csm_backups',[],false);update_option('_csm_settings',['pull'=>true,'push'=>true],false);")
  for rel,data in {'2030/01/a.jpg':b'first','2030/01/b.jpg':b'second','2030/02/c.jpg':b'third','2031/01/d.jpg':b'fourth','logo.svg':b'<svg/>','custom/styles/x.css':b'body{}'}.items():write(s,rel,data)
 # Source fixture mutations are old enough for metadata caches to be reusable.
 time.sleep(3.1)
 first=cli('pull','--remote=https://trickle-qa-a.localhost:8453','--media=compare')
 c=first['media_comparison']
 check(first['ready'] and first['files']==0,'Matching media stages an empty verified stream')
 check(c['years_skipped']==c['years_compared']==4 and c['months_compared']==0 and c['files_compared']==0,'Matching years stop all month and file comparisons')
 check(c['source_hashing']['hashed_files']==6 and c['destination_hashing']['hashed_files']==6,'Cold caches hash each fixture once')
 finish()
 warm=cli('pull','--remote=https://trickle-qa-a.localhost:8453','--media=compare')
 c=warm['media_comparison']
 check(c['source_hashing']['hashed_files']==c['destination_hashing']['hashed_files']==0,'Warm comparisons read no unchanged file contents')
 check(c['source_hashing']['months_rebuilt']==0 and c['source_hashing']['years_rebuilt']==0,'Matching cached years do not rebuild month or year checksums')
 finish()
 # One same-length changed file, one missing file in a differing month, one new month/year.
 write(SOURCE,'2030/01/a.jpg',b'FIRST');write(SOURCE,'2030/01/new.jpg',b'new');write(SOURCE,'2032/03/e.jpg',b'new year')
 changed=cli('pull','--remote=https://trickle-qa-a.localhost:8453','--media=compare')
 c=changed['media_comparison']
 check(changed['files']==3 and c['files_selected']==3,'Transfers only the changed file and two missing files')
 check(c['years_compared']==5 and c['years_skipped']==3,'Only mismatching years descend')
 check(c['months_compared']==3 and c['months_skipped']==1 and c['files_compared']==3,'Matching month stops; only differing existing month compares files')
 check(c['source_hashing']['hashed_files']==3,'Only new or changed source contents are rehashed')
 cli('apply','--yes')
 check((FIX[DEST]/'2030/01/a.jpg').read_bytes()==b'FIRST' and (FIX[DEST]/'2032/03/e.jpg').read_bytes()==b'new year','Applies the media delta')
 cli('rollback','--yes')
 check((FIX[DEST]/'2030/01/a.jpg').read_bytes()==b'first' and not (FIX[DEST]/'2030/01/new.jpg').exists() and not (FIX[DEST]/'2032/03/e.jpg').exists(),'Rollback restores changed media and removes newly transferred files')
 finish()
 # Push has the same cascade, under the same saved remote and ordinary receiving endpoint.
 write(DEST,'2031/01/d.jpg',b'PUSHED');write(DEST,'2031/01/local.jpg',b'local-only')
 pushed=cli('push','--remote=https://trickle-qa-a.localhost:8453','--media=compare')
 check(pushed['files']==3 and pushed['media_comparison']['files_selected']==3,'Push compares the correct source and destination')
 cli('apply','--yes')
 check((FIX[SOURCE]/'2031/01/d.jpg').read_bytes()==b'PUSHED' and (FIX[SOURCE]/'2031/01/local.jpg').exists(),'Push applies changed and missing media')
 check((FIX[SOURCE]/'2032/03/e.jpg').exists() and (FIX[SOURCE]/'2030/01/new.jpg').exists(),'Destination-only files remain intact')
 finish(True)
 verified=cli('pull','--remote=https://trickle-qa-a.localhost:8453','--media=compare','--rehash-media')
 c=verified['media_comparison']
 check(cli('recent')[0]['selection']['media_verify'] is True and cli('recent')[0]['selection']['media']=='all','Recent transfers retain comparison mode and full-verification setting')
 check(c['source_hashing']['hashed_files']==8 and c['destination_hashing']['hashed_files']==7,'Explicit full verification bypasses cached file hashes')
 finish()
 # Paginated file manifests stay bounded, and invalid plans never widen the selected filesystem.
 for i in range(505):write(SOURCE,f'2033/01/{i:04}.jpg',str(i).encode())
 q=evaluate(SOURCE,"$x=CSM_Media::inventory(['level'=>'years']);$a=CSM_Media::inventory(['level'=>'files','token'=>$x['token'],'year'=>'2033','month'=>'01']);$b=CSM_Media::inventory(['level'=>'files','token'=>$x['token'],'year'=>'2033','month'=>'01','offset'=>500]);echo wp_json_encode([count($a['items']),$a['more'],count($b['items']),$b['more']]);")
 check(q==[500,True,5,False],'Large month manifests paginate without truncation')
 bad=evaluate(DEST,"$ok=[];foreach(['not-base64',base64_encode(gzencode('{}'))] as $p){try{iterator_to_array(CSM_Media::paths($p));$ok[]=false;}catch(Throwable $e){$ok[]=true;}}try{CSM_Media::inventory(['level'=>'files','token'=>str_repeat('0',64),'year'=>'2030','month'=>'01']);$ok[]=false;}catch(Throwable $e){$ok[]=true;}echo wp_json_encode($ok);")
 check(bad==[True,True,True],'Invalid or stale comparison plans are rejected')
 plan_check=evaluate(SOURCE,"$i=CSM_Media::inventory(['level'=>'years']);$p='2030/01/a.jpg';$delta=base64_encode(gzencode(wp_json_encode(['token'=>$i['token'],'buckets'=>[],'paths'=>[$p]])));$file=wp_upload_dir()['basedir'].'/'.$p;$mtime=filemtime($file);file_put_contents($file,'OTHER');touch($file,$mtime);$stopped=false;try{iterator_to_array(CSM_Files::stream(['plugins'=>[],'themes'=>[],'media'=>'compare','media_delta'=>$delta]));}catch(Throwable $e){$stopped=true;}$i=CSM_Media::inventory(['level'=>'years']);$bad=base64_encode(gzencode(wp_json_encode(['token'=>$i['token'],'buckets'=>[],'paths'=>['../wp-config.php']])));$blocked=false;try{iterator_to_array(CSM_Media::paths($bad));}catch(Throwable $e){$blocked=true;}echo wp_json_encode([$stopped,$blocked,$i['stats']['hashed_files']>0]);")
 check(plan_check==[True,True,True],'Changed source contents, preserved modification times, and traversal plans are handled safely')
 print(f'{checks} cascade checks passed.',flush=True)
finally:
 try:
  state=evaluate(DEST,'echo wp_json_encode(CSM_Controller::session());')
  if state:
   status=cli('status')
   if status['status'] in ['applying','applied']:cli('rollback','--yes')
   cli('discard','--yes')
 finally:
  wp(DEST,'option','update','_csm_recents',json.dumps(recents),'--format=json')
  for s in ROOTS:
   wp(s,'eval',"$root=wp_upload_dir()['basedir'];if($root!==WP_CONTENT_DIR.'/uploads/trickle-cascade-fixture')throw new RuntimeException('Wrong fixture root');$path=CSM_Support::private_dir().'/media-hashes-'.substr(hash('sha256',$root),0,16).'.json';if(is_file($path))unlink($path);")
   wp(s,'eval','foreach(get_option("_csm_backups",[]) as $id){CSM_Plugin::discard(CSM_Support::job($id));}')
   wp(s,'option','update','_csm_backups',json.dumps(backups[s]),'--format=json')
   if settings[s] is False:wp(s,'option','delete','_csm_settings')
   else:wp(s,'option','update','_csm_settings',json.dumps(settings[s]),'--format=json')
   MU[s].unlink(missing_ok=True)
   shutil.rmtree(FIX[s],ignore_errors=True)
