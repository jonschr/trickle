#!/usr/bin/env python3
"""Compare identical plugin inventories on QA-A/C, without applying migrations."""
import json,subprocess,shutil,time,sys,statistics
from pathlib import Path
S,D='trickle-qa-a','trickle-qa-c';NAME='trickle-performance-fixture';BASE=Path.home()/'Cove/Sites';ROOT={x:BASE/(x+'.localhost/public') for x in [S,D]}
def wp(site,*args):
 p=subprocess.run(['cove','wp',site,*args],capture_output=True,text=True,timeout=180)
 if p.returncode:raise RuntimeError(p.stderr)
 return p.stdout
def ev(site,code):return json.loads(wp(site,'eval',code))
for site in ROOT:
 assert ev(site,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 assert not (ROOT[site]/'wp-content/plugins'/NAME).exists()
try:
 for site in ROOT:
  folder=ROOT[site]/'wp-content/plugins'/NAME;folder.mkdir();(folder/(NAME+'.php')).write_text('<?php\n/* Plugin Name: Performance fixture */\n')
  for i in range(1500):(folder/(str(i)+'.txt')).write_text('identical file '+str(i)+'\n')
 time.sleep(3.1)
 code='''$s=['plugins'=>['trickle-performance-fixture'],'themes'=>[],'media'=>'none'];$path=CSM_Support::private_dir().'/diagnostics.log';clearstatcache(true,$path);$offset=is_file($path)?filesize($path):0;$t=microtime(true);$r=CSM_Components::compare(CSM_Controller::peer('Diagnostics QA'),true,$s);$elapsed=(microtime(true)-$t)*1000;clearstatcache(true,$path);$log=filesize($path)<$offset?substr(file_get_contents($path.'.1'),$offset).file_get_contents($path):substr(file_get_contents($path),$offset);$calls=0;foreach(explode("\\n",$log) as $line){$e=json_decode($line,true);if(($e['event']??'')==='Outgoing request started'&&($e['context']['operation']??'')==='component_hashes')$calls++;}echo wp_json_encode(['milliseconds'=>$elapsed,'remote_requests'=>$calls,'plan_bytes'=>strlen($r['same']),'matched'=>$r['matched']]);'''
 ev(D,code) # Build caches before measuring repeat comparisons.
 samples=[ev(D,code) for _ in range(3)]
 assert all(x['matched']==1501 for x in samples),samples
 result={'files':1501,'samples':samples,'median_ms':statistics.median(x['milliseconds'] for x in samples),'remote_requests':samples[0]['remote_requests'],'plan_bytes':samples[0]['plan_bytes']}
 if len(sys.argv)>1:Path(sys.argv[1]).write_text(json.dumps(result,indent=2)+'\n')
 print(json.dumps(result))
finally:
 for site in ROOT:
  shutil.rmtree(ROOT[site]/'wp-content/plugins'/NAME)
  wp(site,'eval',"$s=['plugins'=>['trickle-performance-fixture'],'themes'=>[],'skip_paths'=>[],'git_overrides'=>[]];$p=CSM_Support::private_dir().'/components-'.substr(hash('sha256',CSM_Support::json(array_values($s))),0,24).'.json';if(is_file($p))unlink($p);")
