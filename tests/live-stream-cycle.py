#!/usr/bin/env python3
"""Real push/pull HTTP cycles. Only named disposable trickle-stream-qa-* Cove peers."""
import hashlib
import json
import pathlib
import subprocess
import tempfile
import os

SOURCE = 'trickle-stream-qa-source-20261121'
DEST = 'trickle-stream-qa-dest-20261121'
SITES = (SOURCE, DEST)
ROOT = {s: pathlib.Path.home() / 'Cove/Sites' / (s + '.localhost/public') for s in SITES}
NAME = 'live-stream-fixture'
CA = pathlib.Path.home() / 'Library/Application Support/Caddy/pki/authorities/local/root.crt'


def ev(site, code):
    assert site in SITES and ROOT[site].is_dir()
    p = subprocess.run(['wp', '--path=' + str(ROOT[site]), 'eval', code], capture_output=True, text=True, timeout=180)
    if p.returncode:
        raise RuntimeError((p.stdout + p.stderr)[-3000:])
    for line in reversed(p.stdout.splitlines()):
        try:
            return json.loads(line)
        except ValueError:
            pass
    raise RuntimeError('Fixture returned no JSON')


def check(yes, label):
    if not yes:
        raise AssertionError(label)
    print('PASS ' + label, flush=True)


def op(site, action, extra=None, injection=''):
    return ev(site, injection + '$c=CSM_Controller::session(); echo wp_json_encode(CSM_Controller::execute(' + json.dumps(action) + ',["id"=>$c["id"]]+json_decode(' + repr(json.dumps(extra or {})) + ',true)));')


def until(site, action, extra=None):
    for n in range(200):
        r = op(site, action, extra)
        if not r.get('continue'):
            return r, n + 1
    raise RuntimeError('Fixture failed to finish ' + action)


for site in SITES:
    check(ev(site, 'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job")&&!get_option("_csm_backups"));'), site + ' is idle and disposable')
    folder=ROOT[site] / 'wp-content/plugins' / NAME
    if folder.exists():
        import shutil
        shutil.rmtree(folder)
    theme=ROOT[site] / 'wp-content/themes' / NAME
    if theme.exists():
        import shutil
        shutil.rmtree(theme)
    mu = ROOT[site] / 'wp-content/mu-plugins/live-stream-test-config.php'
    if mu.exists(): mu.unlink()
    mu.write_text("<?php add_filter('csm_ca_bundle',static fn()=>" + repr(str(CA)) + "); add_filter('csm_step_seconds',static fn()=>1);\n")

with tempfile.TemporaryDirectory(prefix='trickle-live-cycle-') as temp:
    secrets = pathlib.Path(temp) / 'keys.json'
    secrets.write_text(json.dumps({s: ev(s, 'echo wp_json_encode(get_option("_csm_key"));') for s in SITES}))
    secrets.chmod(0o600)
    for site in SITES:
        plugin = ROOT[site] / 'wp-content/plugins' / NAME
        plugin.mkdir()
        (plugin / 'main.php').write_text('<?php /* Plugin Name: Live stream fixture */\n// ' + site)
        (plugin / '.env').write_text('private-' + site)
        if site == DEST:
            (plugin / 'obsolete.php').write_text('old')
        theme = ROOT[site] / 'wp-content/themes' / NAME
        theme.mkdir()
        (theme / 'style.css').write_text('/* Theme Name: Live stream fixture */')
        (theme / 'index.php').write_text('<?php // ' + site)
        uploads = ROOT[site] / 'wp-content/uploads'
        uploads.mkdir(exist_ok=True)
        (uploads / 'live-stream-fixture.txt').write_text(site)
        ev(site, 'global $wpdb;$t=$wpdb->prefix."csm_live_fixture";$wpdb->query("DROP TABLE IF EXISTS `$t`");$wpdb->query("CREATE TABLE `$t` (id bigint PRIMARY KEY, value text) ENGINE=InnoDB");$wpdb->insert($t,["id"=>1,"value"=>' + json.dumps(site) + ']);echo "true";')
    large = ROOT[SOURCE] / 'wp-content/plugins' / NAME / 'large.bin'
    with large.open('wb') as f:
        for _ in range(70):
            f.write(os.urandom(1024 * 1024))
    (ROOT[DEST] / 'wp-content/plugins' / NAME / 'large.bin').write_bytes(b'old destination')
    selection = {'plugins': [NAME], 'themes': [NAME], 'tables': ['wp_csm_live_fixture'], 'media': 'all', 'sync_active_plugins': False, 'transport': 'stream'}
    for direction in ('pull', 'push'):
        controller = DEST if direction == 'pull' else SOURCE
        other = SOURCE if direction == 'pull' else DEST
        if direction == 'push':
            with large.open('r+b') as f:
                f.write(b'changed source bytes')
            selection['plugins_force'] = True
            (ROOT[DEST] / 'wp-content/plugins' / NAME / 'obsolete-again.php').write_text('old')
        ev(controller, '$keys=json_decode(file_get_contents(' + repr(str(secrets)) + '),true);echo wp_json_encode(CSM_Controller::execute("connect",["pairing"=>' + json.dumps('https://' + other + '.localhost:8453') + '.chr(10).$keys[' + json.dumps(other) + ']]));')
        r = ev(controller, 'echo wp_json_encode(CSM_Controller::execute("prepare",["site"=>' + json.dumps('https://' + other + '.localhost:8453') + ',"direction"=>' + json.dumps(direction) + ',"selection"=>json_decode(' + repr(json.dumps(selection)) + ',true)]));')
        tid = r['id']
        check(ev(controller, '$c=get_option("_csm_control",[]);echo wp_json_encode(!empty($c["live_files"]));'), direction + ' negotiates the streaming capability')
        until(controller, 'transfer', {'kind': 'database'})
        # Lose a response after real cURL delivery. Pull replays unacknowledged staged bytes;
        # push discovers that the remote receiver already committed the sequence.
        action = 'live_files' if direction == 'pull' else 'live_receive'
        injection = '$once=true;add_action("csm_request_complete",static function($op)use(&$once){if($once&&$op===' + json.dumps(action) + '){$once=false;throw new CSM_Network_Error("Fixture lost HTTP response");}},10,1);'
        first = op(controller, 'transfer', {'kind': 'files'}, injection)
        check(first.get('continue'), direction + ' survives a lost HTTP response without completing the whole stream')
        if direction == 'pull':
            partial = ev(controller, '$c=get_option("_csm_control");$selection=$c["exports"]["files"];$j=CSM_Jobs::get($c["id"]);$writer=new CSM_Live_Stream($j,hash("sha256",CSM_Support::json($selection)));$n=0;try{CSM_Transport::request($c["peer"],"live_files",["id"=>$c["id"],"seq"=>$j["live_files"]["seq"],"selection"=>$selection],null,static function($bytes)use($writer,&$n){$writer->write($bytes);$n+=strlen($bytes);if($n>2097152)throw new CSM_Network_Error("Fixture interrupted HTTP body");},30,41943040);}catch(CSM_Network_Error $error){if(!$writer->save_partial())throw $error;}echo wp_json_encode(($j["live_partial"]["offset"]??0)>0);')
        else:
            partial = ev(controller, '$c=get_option("_csm_control");$selection=$c["exports"]["files"];$state=CSM_Transport::request($c["peer"],"live_state",["id"=>$c["id"]]);$input=(static function()use($c,$selection,$state){$n=0;foreach(CSM_Live_Stream::source($c["id"],$selection,$state["seq"],$state["offset"]??0)as$frame){yield $frame;if(($n+=strlen($frame))>2097152)break;}})();$r=CSM_Transport::request($c["peer"],"live_receive",["id"=>$c["id"],"seq"=>$state["seq"],"offset"=>$state["offset"]??0,"selection_hash"=>hash("sha256",CSM_Support::json($selection))],$input);echo wp_json_encode(($r["live_files"]["offset"]??0)>0);')
        check(partial, direction + ' checkpoints a verified prefix after an interrupted HTTP body')
        evidence = ev(DEST, '$j=CSM_Jobs::get(' + json.dumps(tid) + ');$base=CSM_Support::private_dir()."/segment-".$j["id"]."-files";echo wp_json_encode(["written"=>$j["imports"]["files"]["current"]["written"]??0,"files"=>count($j["files"]),"done"=>!empty($j["live_files"]["done"]),"archives"=>glob($base.".incoming*")]);')
        check((evidence['written'] > 0 or evidence['files'] > 0) and not evidence['done'] and not evidence['archives'], direction + ' stages file bytes before source completion, without destination archives')
        streamed, steps = until(controller, 'transfer', {'kind': 'files'})
        check(streamed['ready'] and streamed['live_files']['seq'] >= 3, direction + ' completes across bounded windows and previews its staged data')
        applied, _ = until(controller, 'commit')
        check(applied['status'] == 'applied' and applied['verification']['verified'], direction + ' installs and verifies real files, media and a database table')
        op(controller, 'finish')
        installed = ROOT[DEST] / 'wp-content/plugins' / NAME
        check(hashlib.sha256(large.read_bytes()).digest() == hashlib.sha256((installed / 'large.bin').read_bytes()).digest(), direction + ' installs the exact large source file after retry')
        check(not (installed / 'obsolete.php').exists() and not (installed / 'obsolete-again.php').exists(), direction + ' removes obsolete component files')
        check((installed / '.env').read_text() == 'private-' + DEST, direction + ' preserves destination exclusions')
        check((ROOT[DEST] / 'wp-content/uploads/live-stream-fixture.txt').read_text() == SOURCE, direction + ' installs selected media')
        check(ev(DEST, 'global $wpdb;echo wp_json_encode($wpdb->get_var("SELECT value FROM {$wpdb->prefix}csm_live_fixture WHERE id=1"));') == SOURCE, direction + ' keeps the legacy database stream correct')
        check(ev(SOURCE, 'echo wp_json_encode(!is_dir(CSM_Support::private_dir()."/segment-' + tid + '-files.source.live"));'), direction + ' cleans the final source replay journal')
        print(direction + ' integration complete', flush=True)
