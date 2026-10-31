#!/usr/bin/env python3
"""A real stalled TLS download must time out and resume, without a push or apply."""
import json
import os
from pathlib import Path
import random
import shutil
import subprocess
import tempfile

S, D = 'trickle-qa-a', 'trickle-qa-c'
NAME = 'trickle-stall-fixture'
ROOT = {s: Path.home() / 'Cove/Sites' / (s + '.localhost/public') for s in [S, D]}
UA = os.environ.get('TRICKLE_TEST_USER_AGENT')

def wp(site, *args):
    p = subprocess.run(['cove', 'wp', site, *args], capture_output=True, text=True, timeout=120)
    assert p.returncode == 0, p.stdout + p.stderr
    return p.stdout

def ev(site, code):
    prefix = 'add_filter("csm_user_agent",static fn($ua)=>' + json.dumps(UA) + ');' if UA else ''
    return json.loads(wp(site, 'eval', prefix + code))

def op(action, data=None):
    return ev(D, '$s=CSM_Controller::session();echo wp_json_encode(CSM_Controller::execute(' + json.dumps(action) + ',["id"=>$s["id"]]+json_decode(' + repr(json.dumps(data or {})) + ',true)));')

for site in ROOT:
    wp(site, 'core', 'is-installed')
    assert ev(site, 'echo wp_json_encode([home_url(),!get_option("_csm_control")&&!get_option("_csm_active_job")]);') == ['https://' + site + '.localhost:8453', True]
folder = ROOT[S] / 'wp-content/plugins' / NAME
mu = ROOT[S] / 'wp-content/mu-plugins/trickle-stall-fixture.php'
assert not folder.exists() and not mu.exists()
old = ev(D, 'echo wp_json_encode(["recents"=>get_option("_csm_recents",[]),"backups"=>get_option("_csm_backups",[])]);')
with tempfile.TemporaryDirectory(prefix='trickle-stall-') as temp:
    flag = Path(temp) / 'stall'
    try:
        folder.mkdir()
        (folder / (NAME + '.php')).write_text('<?php\n/* Plugin Name: Stalled pull fixture */\n')
        (folder / 'data.bin').write_bytes(random.Random(729).randbytes(1600000))
        mu.write_text('''<?php
add_action('plugins_loaded',static function(){
 if(($_GET['trickle']??'')!=='bundle_stream'||!is_file(__FLAG__))return;
 $d=CSM_Transport::authenticate('bundle_stream');unlink(__FLAG__);
 $g=CSM_Segments::source($d['id'],$d['kind'],$d['offset'],$d['bundle']);$g->rewind();
 while(ob_get_level())ob_end_clean();header('Content-Type: application/octet-stream');
 header('Content-Length: '.($d['bundle']['size']-$d['offset']));header('X-Accel-Buffering: no');
 echo substr($g->current(),0,1024);flush();sleep(45);exit;
},0);
'''.replace('__FLAG__', repr(str(flag))))
        ev(D, 'echo wp_json_encode(CSM_Controller::execute("prepare",["site"=>"https://trickle-qa-a.localhost:8453","direction"=>"pull","selection"=>["plugins"=>["' + NAME + '"],"media"=>"none","transport"=>"stream","sync_active_plugins"=>false]]));')
        op('preflight', {'kind': 'files'})
        flag.write_text('stall')
        r = ev(D, '''define('CSM_STOP_ON_ERROR',true);$s=CSM_Controller::session();$start=microtime(true);try{CSM_Controller::execute('transfer',['id'=>$s['id'],'kind'=>'files']);echo 'null';}catch(Throwable $e){echo wp_json_encode(['network'=>$e instanceof CSM_Network_Error,'error'=>$e->getMessage(),'seconds'=>microtime(true)-$start,'offset'=>CSM_Segments::received_bytes($s['id'],'files')]);}''')
        assert r and r['network'] and 'too slow' in r['error'].lower() and 29 <= r['seconds'] < 44 and r['offset'] == 1024, r
        print('PASS Stalled download times out, retaining exactly 1024 received bytes.', flush=True)
        r = op('transfer', {'kind': 'files'})
        assert r['ready'] and r['files'] == 2
        hashes = ev(D, '$s=CSM_Controller::session();$j=CSM_Support::job($s["id"]);$ok=true;foreach($j["files"] as $f){$ok=$ok&&hash_equals($f["sha256"],hash_file("sha256",$f["stage"]));}echo wp_json_encode($ok);')
        assert hashes and not (ROOT[D] / 'wp-content/plugins' / NAME).exists()
        print('PASS Resume verifies the complete snapshot and staged files without applying.', flush=True)
    finally:
        mu.unlink(missing_ok=True)
        flag.unlink(missing_ok=True)
        if ev(D, 'echo wp_json_encode((bool)CSM_Controller::session());'):
            op('discard')
        wp(D, 'option', 'update', '_csm_recents', json.dumps(old['recents']), '--format=json')
        assert ev(D, 'echo wp_json_encode(get_option("_csm_backups",[]));') == old['backups']
        shutil.rmtree(folder)
print('2 stalled-pull checks passed; existing backups preserved.')
