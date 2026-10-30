#!/usr/bin/env python3
"""Destructive integration tests, restricted to the two disposable Trickle sites."""
import base64
import gzip
import hashlib
import hmac
import http.client
import json
from pathlib import Path
import secrets
import ssl
import subprocess
import time
from urllib.parse import urlparse
from urllib.parse import urlencode

BASE = Path.home() / 'Cove/Sites'
CA = Path.home() / 'Library/Application Support/Caddy/pki/authorities/local/root.crt'
CTX = ssl.create_default_context(cafile=str(CA))
SITES = {name: BASE / f'cove-stream-{name}.localhost/public' for name in ('local', 'remote')}
CHECKS = 0


def php(site, code):
    script = 'require $argv[1] . "/wp-load.php"; ' + code
    result = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'error_reporting=22527', '-r', script, str(SITES[site])], capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError(result.stderr[-2000:])
    return result.stdout


KEYS = {site: php(site, 'echo get_option("_csm_key");').strip() for site in SITES}


def check(condition, text):
    global CHECKS
    if not condition:
        raise AssertionError(text)
    CHECKS += 1
    print('PASS', text, flush=True)


def headers(site, action, body, params=None):
    stamp, nonce = str(int(time.time())), secrets.token_hex(16)
    digest = 'stream' if params is not None else hashlib.sha256(body).hexdigest()
    encoded = base64.b64encode((json.dumps(params or {}, separators=(',', ':')) + '\n').encode()).decode()
    message = '\n'.join((action, stamp, nonce, digest, encoded))
    signature = hmac.new(KEYS[site].encode(), message.encode(), hashlib.sha256).hexdigest()
    return {'X-CSM-Time': stamp, 'X-CSM-Nonce': nonce, 'X-CSM-Hash': digest, 'X-CSM-Params': encoded, 'X-CSM-Signature': signature,
            'Content-Type': 'application/octet-stream' if params is not None else 'application/json', 'User-Agent': 'Trickle-Integration-Test/0.1'}


def raw(site, action, body=b'{}\n', custom_headers=None, chunked=False):
    host = f'cove-stream-{site}.localhost'
    connection = http.client.HTTPSConnection(host, 8453, context=CTX, timeout=90)
    connection.request('POST', '/?trickle=' + action, body, custom_headers or headers(site, action, body), encode_chunked=chunked)
    response = connection.getresponse()
    data = response.read()
    connection.close()
    return response.status, data


def api(site, action, data=None, stream=None, kind=None, chunked=False):
    if stream is not None:
        body = stream
        custom = headers(site, action, b'', {'id': data['id'], 'kind': kind})
        if chunked:
            body = (stream[i:i+17003] for i in range(0, len(stream), 17003))
    else:
        body = (json.dumps(data or {}, separators=(',', ':')) + '\n').encode()
        custom = headers(site, action, body)
    status, result = raw(site, action, body, custom, chunked)
    if action == 'export' and status == 200:
        return result
    try:
        parsed = json.loads(result)
    except ValueError:
        raise RuntimeError(f'{site}/{action} HTTP {status}: {result[:500]!r}')
    if not parsed.get('ok'):
        raise RuntimeError(f'{site}/{action}: {parsed.get("error")}')
    return parsed['data']


def selection(**extra):
    return {'tables': [], 'plugins': [], 'themes': [], 'media': 'none', 'media_ids': [], 'since': 0, 'replace': True, **extra}


def discard_active(site):
    id_ = php(site, 'echo get_option("_csm_active_job", "");').strip()
    if id_:
        info = api(site, 'status', {'id': id_})
        if info['status'] == 'applying':
            api(site, 'rollback', {'id': id_})
        api(site, 'discard', {'id': id_})


for site in SITES:
    assert SITES[site].is_dir()
    discard_active(site)

manifest = {site: api(site, 'manifest') for site in SITES}
check(manifest['local']['protocol'] == 1, 'TLS-verified signed manifest request')
check(manifest['local']['isolation'] and manifest['remote']['isolation'], 'Recovery isolation loaders installed at both ends')
check(next(p for p in manifest['local']['plugins'] if p['id']=='classic-editor')['active'], 'Isolation preserves accurate installed-plugin activation information')
check(manifest['local']['media_total'] >= 3, 'Real attachment records and image derivatives present')
local_classic = next(p for p in manifest['local']['plugins'] if p['id'] == 'classic-editor')
remote_classic = next(p for p in manifest['remote']['plugins'] if p['id'] == 'classic-editor')
check(local_classic['version'] != remote_classic['version'], 'Different installed plugin versions exposed on both ends')
check(local_classic['icon'].startswith('https://ps.w.org/'), 'Cached WordPress plugin artwork included in inventory')
check(next(p for p in manifest['local']['plugins'] if p['id'] == 'trickle')['icon'] == '', 'Plugins without artwork leave the category fallback available')
check(bool(next(t for t in manifest['local']['themes'] if t['id'] == 'twentytwentyfive')['icon']), 'Installed theme screenshot included in inventory')
check(all(isinstance(m['icon'], str) and m['name'] for m in manifest.values()), 'Site identity metadata is available with or without a configured site icon')
refresh = api('remote', 'manifest', {'refresh': True})
check(bool(next(p for p in refresh['plugins'] if p['id'] == 'classic-editor')['update']), 'Available upstream plugin update exposed in manifest')
check(bool(next(t for t in refresh['themes'] if t['id'] == 'twentytwentyfive')['update']), 'Available upstream theme update exposed in manifest')

body = b'{}\n'
signed = headers('remote', 'manifest', body)
status, _ = raw('remote', 'manifest', body, signed)
check(status == 200, 'First signed nonce accepted')
status, _ = raw('remote', 'manifest', body, signed)
check(status == 403, 'Replayed signed nonce rejected')
signed['X-CSM-Signature'] = '0' * 64
status, _ = raw('remote', 'manifest', body, signed)
check(status == 403, 'Invalid authentication signature rejected')
signed = headers('remote', 'manifest', body)
status, _ = raw('remote', 'manifest', b'{"changed":true}', signed)
check(status == 403, 'Modified signed control body rejected')

for source, target in [('local', 'remote'), ('remote', 'local')]:
    prefix = manifest[source]['prefix']
    selected = selection(tables=[prefix + 'csm_fixture'], plugins=['csm-fixture'], themes=['csm-child'], media='selected', media_ids=[manifest[source]['media'][0]['id']])
    before = php(target, 'global $wpdb; echo $wpdb->get_var("SELECT payload FROM {$wpdb->prefix}csm_fixture WHERE id=1");')
    before_file = (SITES[target] / 'wp-content/plugins/csm-fixture/payload.txt').read_bytes()
    job = api(target, 'prepare', {'selection': selected})
    try:
        database = api(source, 'export', {'kind': 'database', 'selection': selected})
        files = api(source, 'export', {'kind': 'files', 'selection': selected})
        check(gzip.decompress(database).startswith(b'{"type":"header"'), f'{source} database is one valid gzip stream')
        check(len(gzip.decompress(files)) > 0, f'{source} files are one valid gzip stream')
        api(target, 'receive', {'id': job['id']}, database, 'database', chunked=True)
        staged = api(target, 'receive', {'id': job['id']}, files, 'files', chunked=True)
        check(staged['ready'] and staged['rows'] == 613, f'{source} → {target}: complete 613-row stream staged')
        check(php(target, 'global $wpdb; echo $wpdb->get_var("SELECT payload FROM {$wpdb->prefix}csm_fixture WHERE id=1");') == before, 'Staging leaves live database unchanged')
        check((SITES[target] / 'wp-content/plugins/csm-fixture/payload.txt').read_bytes() == before_file, 'Staging leaves live plugin files unchanged')
        applied = api(target, 'commit', {'id': job['id']})
        check(applied['status'] == 'applied', f'{source} → {target}: apply succeeded')
        check((SITES[target] / 'wp-content/plugins/csm-fixture/payload.txt').read_bytes() == (SITES[source] / 'wp-content/plugins/csm-fixture/payload.txt').read_bytes(), 'Selected plugin bytes match source')
        if source == 'local':
            check(not (SITES[target] / 'wp-content/plugins/csm-fixture/obsolete.php').exists(), 'Obsolete plugin file removed by replacement')
        payload = php(target, 'global $wpdb; $r=unserialize($wpdb->get_var("SELECT payload FROM {$wpdb->prefix}csm_fixture WHERE id=1")); echo json_encode([$r["url"],unserialize($r["nested"])["root"],$r["object"]->url]);')
        values = json.loads(payload)
        check(values[0].startswith(manifest[target]['url']) and values[1] == str(SITES[target]) + '/', 'Serialized and nested serialized URL/path replacements are valid')
        check(values[2].startswith(manifest[target]['url']), 'Serialized object rewritten without instantiation in importer')
        binary = php(target, 'global $wpdb; echo bin2hex($wpdb->get_var("SELECT binary_data FROM {$wpdb->prefix}csm_fixture WHERE id=1"));')
        check(binary == '00fffe010a', 'Binary database values preserved')
        restored = api(target, 'rollback', {'id': job['id']})
        check(restored['status'] == 'restored', 'Destination rollback succeeded')
        check(php(target, 'global $wpdb; echo $wpdb->get_var("SELECT payload FROM {$wpdb->prefix}csm_fixture WHERE id=1");') == before, 'Rollback restores original database bytes')
        check((SITES[target] / 'wp-content/plugins/csm-fixture/payload.txt').read_bytes() == before_file, 'Rollback restores original plugin bytes')
    finally:
        api(target, 'discard', {'id': job['id']})

selected = selection(tables=[manifest['local']['prefix'] + 'csm_fixture'])
job = api('remote', 'prepare', {'selection': selected})
try:
    database = api('local', 'export', {'kind': 'database', 'selection': selected})
    bad = gzip.compress(gzip.decompress(database)[:-200])
    failed = False
    try:
        api('remote', 'receive', {'id': job['id']}, bad, 'database')
    except RuntimeError:
        failed = True
    check(failed, 'Truncated database stream rejected')
    info = api('remote', 'status', {'id': job['id']})
    check(not info['ready'], 'Incomplete stream cannot be applied')
    api('remote', 'receive', {'id': job['id']}, database, 'database')
    check(api('remote', 'status', {'id': job['id']})['ready'], 'Failed database stream can be retried cleanly')
    try:
        api('remote', 'receive', {'id':job['id']}, bad, 'database')
    except RuntimeError:
        pass
    check(not api('remote', 'status', {'id':job['id']})['ready'], 'Retrying a completed stream clears readiness before any replacement staging work')
finally:
    api('remote', 'discard', {'id': job['id']})

# Source-side changed-media selection is based on actual modification times, not attachment dates.
uploads = SITES['local'] / 'wp-content/uploads'
old = uploads / 'csm-old.txt'; new = uploads / 'csm-changed.txt'
old.write_text('old'); new.write_text('new')
import os
os.utime(old, (100, 100)); os.utime(new, (time.time(), time.time()))
selected = selection(media='changed', since=int(time.time()) - 30)
archive = gzip.decompress(api('local', 'export', {'kind': 'files', 'selection': selected}))
check(b'uploads/csm-changed.txt' in archive and b'uploads/csm-old.txt' not in archive, 'Changed-media filter includes new files and excludes old files')

# File path trust boundary: signed peer cannot write outside the selected folders.
selected = selection(plugins=['csm-fixture'])
job = api('remote', 'prepare', {'selection': selected})
try:
    attack = gzip.compress((json.dumps({'type':'header','version':1,'kind':'files','started':int(time.time())})+'\n'+json.dumps({'type':'file','path':'plugins/csm-fixture/../../wp-config.php','size':0,'mtime':0})+'\n').encode())
    failed = False
    try:
        api('remote', 'receive', {'id':job['id']}, attack, 'files')
    except RuntimeError:
        failed = True
    check(failed, 'Path traversal blocked even for an authenticated peer')
finally:
    api('remote', 'discard', {'id': job['id']})

# Exercise the real local-admin controller, including PHP's streamed cURL upload.
login = json.loads(php('local', '''
$token = WP_Session_Tokens::get_instance(1)->create(time()+3600);
$secure = wp_generate_auth_cookie(1,time()+3600,'secure_auth',$token);
$logged = wp_generate_auth_cookie(1,time()+3600,'logged_in',$token);
$_COOKIE[LOGGED_IN_COOKIE] = $logged; wp_set_current_user(1);
echo json_encode(['cookie'=>SECURE_AUTH_COOKIE.'='.$secure.'; '.LOGGED_IN_COOKIE.'='.$logged,'nonce'=>wp_create_nonce('csm_admin')]);
'''))


def admin(op, data=None):
    connection = http.client.HTTPSConnection('cove-stream-local.localhost', 8453, context=CTX, timeout=120)
    payload = urlencode({'action':'csm_admin','nonce':login['nonce'],'op':op,'data':json.dumps(data or {})})
    connection.request('POST', '/wp-admin/admin-ajax.php', payload, {'Content-Type':'application/x-www-form-urlencoded','Cookie':login['cookie']})
    response = connection.getresponse(); raw_ = response.read()
    cookies = response.getheaders(); connection.close()
    try:
        result = json.loads(raw_)
    except ValueError:
        raise RuntimeError(f'Admin {op}: HTTP {response.status}: {raw_[:500]!r}')
    if not isinstance(result, dict) or not result.get('ok'):
        raise RuntimeError(f'Admin {op}: {result}')
    if isinstance(result['data'], dict) and result['data'].get('nonce'):
        login['nonce'] = result['data']['nonce']
        jar = dict(item.strip().split('=',1) for item in login['cookie'].split(';'))
        for key, value in cookies:
            if key.lower() == 'set-cookie':
                part = value.split(';',1)[0]
                name_, value_ = part.split('=',1)
                jar[name_] = value_
        login['cookie'] = '; '.join(key+'='+value for key,value in jar.items())
    return result['data']


pairing = base64.b64encode(json.dumps({'url':manifest['remote']['url'],'key':KEYS['remote']}).encode()).decode()
connected = admin('connect', {'pairing':pairing})
check(connected['remote']['url'] == manifest['remote']['url'], 'Admin connection uses verified PHP cURL with a legacy base64 code')
readable_code = php('remote', '$_SERVER["HTTPS"]="on"; echo CSM_Plugin::pairing();').strip()
check(readable_code.splitlines() == [manifest['remote']['url'], KEYS['remote']], 'New connection code visibly starts with the correct site URL')
connected = admin('connect', {'pairing':readable_code.replace('\n', '\r\n')})
check(connected['remote']['url'] == manifest['remote']['url'], 'Readable URL/key connection code connects with copied Windows line endings')
rejected = False
try:
    admin('connect', {'pairing':manifest['remote']['url']+'\nnot-a-valid-key'})
except RuntimeError:
    rejected = True
check(rejected, 'Incomplete readable connection code rejected before contacting the remote site')

for direction_ in ('push', 'pull'):
    selected = selection(tables=['wp_csm_fixture','wp_options'], plugins=['csm-fixture'])
    target = 'remote' if direction_ == 'push' else 'local'
    old_url = php(target, 'echo site_url();')
    old_key = php(target, 'echo get_option("_csm_key");')
    job = admin('prepare', {'direction':direction_,'selection':selected})
    try:
        admin('transfer', {'id':job['id'],'kind':'database'})
        staged = admin('transfer', {'id':job['id'],'kind':'files'})
        check(staged['ready'], f'Admin {direction_}: both continuous streams staged through PHP transport')
        applied = admin('commit', {'id':job['id']})
        check(applied['status'] == 'applied', f'Admin {direction_}: real controller applied selected tables/files')
        check(php(target, 'echo site_url();') == old_url, 'Options-table transfer preserves destination URL')
        check(php(target, 'echo get_option("_csm_key");') == old_key, 'Options-table transfer preserves destination connection secret')
        check(admin('status', {'id':job['id']})['status'] == 'applied', 'Local admin control survives options-table replacement')
        check(admin('rollback', {'id':job['id']})['status'] == 'restored', f'Admin {direction_}: rollback works through the local controller')
    finally:
        admin('discard', {'id':job['id']})

full = selection(tables=[t['id'] for t in manifest['remote']['tables']])
job = admin('prepare', {'direction':'pull','selection':full})
try:
    check(admin('transfer', {'id':job['id'],'kind':'database'})['ready'], 'Full database including users and usermeta stages successfully')
    check(admin('commit', {'id':job['id']})['status'] == 'applied', 'Full database pull applies through local admin')
    check(admin('status', {'id':job['id']})['status'] == 'applied', 'Administrator remains authenticated after users-table replacement')
    check(admin('rollback', {'id':job['id']})['status'] == 'restored', 'Full database rollback succeeds without losing admin access')
finally:
    admin('discard', {'id':job['id']})

# Recovery endpoints must work even when an active plugin fatals on ordinary requests.
php('remote', 'require_once ABSPATH."wp-admin/includes/plugin.php"; activate_plugin("csm-fixture/csm-fixture.php");')
fixture = SITES['remote'] / 'wp-content/plugins/csm-fixture/csm-fixture.php'
original = fixture.read_bytes()
recovery_job = admin('prepare', {'direction':'push','selection':selection(plugins=['csm-fixture'])})
try:
    fixture.write_text('<?php throw new RuntimeException("Intentional disposable plugin failure");\n')
    check(api('remote','status',{'id':recovery_job['id']})['status'] == 'staging', 'Signed recovery endpoint survives an active plugin fatal error')
    check(admin('status',{'id':recovery_job['id']})['status'] == 'staging', 'Local controller retains recovery access when another active plugin fatals')
finally:
    fixture.write_bytes(original)
    php('remote', 'require_once ABSPATH."wp-admin/includes/plugin.php"; deactivate_plugins("csm-fixture/csm-fixture.php");')
    admin('discard',{'id':recovery_job['id']})

# Restoring an options-table backup must not resurrect an obsolete authentication key.
vendor_fixture = SITES['local'] / 'wp-content/plugins/csm-fixture/csm-fixture.php'
vendor_original = vendor_fixture.read_bytes()
try:
    vendor_fixture.write_bytes(vendor_original + b'''\nadd_filter('site_transient_update_plugins', static function($value) {
        if (!is_object($value)) $value = new stdClass;
        $value->response['csm-fixture/csm-fixture.php'] = (object)['new_version'=>'1.3.0','slug'=>'csm-fixture','plugin'=>'csm-fixture/csm-fixture.php'];
        return $value;
    });
    add_filter('site_transient_update_themes', static function($value) {
        if (!is_object($value)) $value = new stdClass;
        $value->response['csm-child'] = ['new_version'=>'1.2.0','theme'=>'csm-child'];
        return $value;
    });\n''')
    php('local','require_once ABSPATH."wp-admin/includes/plugin.php"; activate_plugin("csm-fixture/csm-fixture.php");')
    custom = api('local','manifest')
    check(next(p for p in custom['plugins'] if p['id']=='csm-fixture')['update'] == '1.3.0', 'Vendor-supplied plugin update filters remain loaded during inventory requests')
    check(next(t for t in custom['themes'] if t['id']=='csm-child')['update'] == '1.2.0', 'Vendor-supplied theme update filters remain loaded during inventory requests')
finally:
    vendor_fixture.write_bytes(vendor_original)
    php('local','require_once ABSPATH."wp-admin/includes/plugin.php"; deactivate_plugins("csm-fixture/csm-fixture.php");')

selected = selection(tables=['wp_options'])
job = api('remote','prepare',{'selection':selected})
old_key = KEYS['remote']
try:
    stream = api('local','export',{'kind':'database','selection':selected})
    api('remote','receive',{'id':job['id']},stream,'database')
    api('remote','commit',{'id':job['id']})
    new_key = secrets.token_hex(32)
    php('remote', 'update_option("_csm_key", '+json.dumps(new_key)+', false);')
    KEYS['remote'] = new_key
    api('remote','rollback',{'id':job['id']})
    check(php('remote','echo get_option("_csm_key");').strip() == new_key, 'Database rollback preserves a connection key rotated after the transfer')
finally:
    api('remote','discard',{'id':job['id']})
    php('remote', 'update_option("_csm_key", '+json.dumps(old_key)+', false);')
    KEYS['remote'] = old_key

print(f'\n{CHECKS} integration checks passed.', flush=True)
