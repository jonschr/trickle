#!/usr/bin/env python3
"""All-media defaults, force and saved settings on isolated QA uploads; pulls only."""
import json
from pathlib import Path
import shutil
import subprocess
import time

S, D = 'trickle-qa-a', 'trickle-qa-c'
ROOT = {s: Path.home() / 'Cove/Sites' / (s + '.localhost/public') for s in [S, D]}
FIX = {s: ROOT[s] / 'wp-content/uploads/trickle-media-all-fixture' for s in ROOT}
MU = {s: ROOT[s] / 'wp-content/mu-plugins/trickle-media-all-fixture.php' for s in ROOT}
checks = 0

def wp(site, *args, fail=False):
    p = subprocess.run(['cove', 'wp', site, *args], capture_output=True, text=True, timeout=180)
    if fail:
        assert p.returncode, p.stdout
        return p.stdout + p.stderr
    assert p.returncode == 0, p.stdout + p.stderr
    return p.stdout

def ev(site, code):
    return json.loads(wp(site, 'eval', code))

def cli(*args, fail=False):
    out = wp(D, 'trickle', *args, '--format=json', fail=fail)
    return out if fail else json.loads(out)

def pull(*args):
    return cli('pull', '--remote=https://trickle-qa-a.localhost:8453', '--keep-destination-plugins', *args)

def check(ok, label):
    global checks
    assert ok, label
    checks += 1
    print('PASS ' + label, flush=True)

for site in ROOT:
    wp(site, 'core', 'is-installed')
    assert ev(site, 'echo wp_json_encode([home_url(),!get_option("_csm_control")&&!get_option("_csm_active_job")]);') == ['https://' + site + '.localhost:8453', True]
    assert not FIX[site].exists() and not MU[site].exists()
old = ev(D, 'echo wp_json_encode(["recents"=>get_option("_csm_recents",[]),"history"=>get_option("_csm_history",[]),"backups"=>get_option("_csm_backups",[])]);')
try:
    wp(D, 'option', 'update', '_csm_backups', '[]', '--format=json')
    for site in ROOT:
        FIX[site].mkdir()
        hook = "<?php\nadd_filter('upload_dir',static function($u){$u['basedir']=WP_CONTENT_DIR.'/uploads/trickle-media-all-fixture';$u['baseurl']=content_url('/uploads/trickle-media-all-fixture');$u['path']=$u['basedir'];$u['url']=$u['baseurl'];$u['error']=false;return $u;});\n"
        MU[site].write_text(hook)
        for relative, data in {'2030/01/a.jpg': b'first', '2030/01/b.jpg': b'second', '2031/02/c.jpg': b'third', '.env': b'excluded', 'node_modules/asset.jpg': b'excluded', 'protected/code.jpg': b'protected'}.items():
            path = FIX[site] / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        (FIX[site] / 'protected/.git').mkdir()
    (FIX[D] / 'keep').mkdir()
    (FIX[D] / 'keep/local-only.jpg').write_bytes(b'local')
    time.sleep(3.1)
    r = pull('--media=all')
    c = r['media_comparison']
    check(r['files'] == 0 and c['years_skipped'] == c['years_compared'] == 2 and c['months_compared'] == 0, 'All media skips matching years without month or file comparisons')
    check(cli('recent')[0]['selection']['media'] == 'all' and not cli('recent')[0]['selection']['media_force'], 'Default all-media settings stay explicit in saved transfers')
    cli('discard', '--yes')
    r = pull('--media=all', '--force-media')
    check(r['files'] == 3 and r['bytes'] == 16 and r['media_comparison'] is None, 'Force retransfer sends identical media without checksum comparison')
    paths = ev(D, '$c=get_option("_csm_control");echo wp_json_encode(array_column(CSM_Support::job($c["id"])["files"],"path"));')
    check(all('protected/' not in p and '.env' not in p and 'node_modules' not in p for p in paths), 'Force retransfer preserves Git and environment-file exclusions')
    recent = cli('recent')[0]
    check(recent['selection']['media_force'] is True, 'Saved settings retain explicit force retransfer')
    cli('discard', '--yes')
    r = cli('pull', '--recent=' + recent['id'])
    check(r['files'] == 3, 'Reusing a forced recent transfer preserves its exact file selection')
    cli('discard', '--yes')
    r = pull('--media=compare', '--rehash-media')
    check(r['files'] == 0 and cli('recent')[0]['selection']['media'] == 'all' and r['media_comparison']['source_hashing']['hashed_files'] == 3, 'Legacy compare alias maps to all media and retains full checksum verification')
    cli('discard', '--yes')
    (FIX[S] / '2030/01/a.jpg').write_bytes(b'FIRST')
    (FIX[S] / '2030/01/new.jpg').write_bytes(b'new')
    r = pull('--media=all')
    check(r['files'] == 2 and r['media_comparison']['files_selected'] == 2, 'All media transfers only changed and missing files')
    cli('apply', '--yes')
    check((FIX[D] / '2030/01/a.jpg').read_bytes() == b'FIRST' and (FIX[D] / '2030/01/new.jpg').read_bytes() == b'new' and (FIX[D] / 'keep/local-only.jpg').read_bytes() == b'local', 'Delta apply updates intended files and keeps destination-only media')
    check(ev(D, 'echo wp_json_encode(get_option("_csm_history",[]));') == old['history'], 'Comparison does not advance a date watermark past its earlier scan')
    cli('rollback', '--yes')
    check((FIX[D] / '2030/01/a.jpg').read_bytes() == b'first' and not (FIX[D] / '2030/01/new.jpg').exists(), 'Rollback restores changed files and removes newly received files')
    cli('discard', '--yes')
    check('--force-media requires --media=all' in cli('pull', '--remote=https://trickle-qa-a.localhost:8453', '--media=selected', '--force-media', fail=True), 'CLI rejects force retransfer for a narrower media scope')
    strict = ev(D, '$ok=false;try{CSM_Files::media_selection(["media"=>"all","media_force"=>"yes"]);}catch(Throwable $e){$ok=true;}echo wp_json_encode($ok&&!CSM_Files::media_selection(["media"=>"selected","media_force"=>true])["media_force"]);')
    check(strict, 'API validates the force flag and prevents a stale force option widening a narrow selection')
    print(f'{checks} all-media checks passed; pulls only.', flush=True)
finally:
    if ev(D, 'echo wp_json_encode((bool)CSM_Controller::session());'):
        if cli('status')['status'] in ['applying', 'applied']:
            cli('rollback', '--yes')
        cli('discard', '--yes')
    for option, key in [('_csm_recents', 'recents'), ('_csm_history', 'history')]:
        wp(D, 'option', 'update', option, json.dumps(old[key]), '--format=json')
    wp(D, 'option', 'update', '_csm_backups', json.dumps(old['backups']), '--format=json')
    assert ev(D, 'echo wp_json_encode(get_option("_csm_backups",[]));') == old['backups']
    for site in ROOT:
        ev(site, '$root=wp_upload_dir()["basedir"];$p=CSM_Support::private_dir()."/media-hashes-".substr(hash("sha256",$root),0,16).".json";if(is_file($p))unlink($p);echo "true";')
        MU[site].unlink()
        shutil.rmtree(FIX[site])
