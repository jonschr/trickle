#!/usr/bin/env python3
"""Destructive CLI/controller checks restricted to the isolated Trickle QA clones."""
import json
from pathlib import Path
import stat
import subprocess
import tempfile

BASE = Path.home() / 'Cove/Sites'
LOCAL, REMOTE, OTHER = 'trickle-qa-b', 'trickle-qa-a', 'trickle-qa-c'
CHECKS = 0


def command(site, *args, fail=False):
    assert site in (LOCAL, REMOTE, OTHER)
    result = subprocess.run(['cove', 'wp', site, *args], capture_output=True, text=True, timeout=180)
    if fail:
        assert result.returncode != 0, 'Command unexpectedly succeeded'
        return result.stderr + result.stdout
    if result.returncode:
        raise RuntimeError(result.stderr[-2000:] + result.stdout[-1000:])
    return result.stdout


def cli(*args, fail=False):
    return command(LOCAL, 'trickle', *args, fail=fail)


def data(*args):
    return json.loads(cli(*args, '--format=json'))


def php(code):
    result = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'error_reporting=22527', '-r', 'require $argv[1]."/wp-load.php"; ' + code, str(BASE / (LOCAL + '.localhost/public'))], capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError(result.stderr)
    return result.stdout


def check(ok, message):
    global CHECKS
    assert ok, message
    CHECKS += 1
    print('PASS ' + message, flush=True)


assert not php('echo get_option("_csm_control") ? "retained" : "";'), 'Finish the existing QA session first.'
for site in (LOCAL, REMOTE, OTHER):
    assert (BASE / (site + '.localhost/public')).is_dir()

with tempfile.TemporaryDirectory(prefix='trickle-cli-') as directory:
    for site, name in ((REMOTE, 'QA Staging'), (OTHER, 'QA Alternate')):
        path = Path(directory) / (site + '.code')
        command(site, 'trickle', 'code', '--output=' + str(path))
        check(stat.S_IMODE(path.stat().st_mode) == 0o600, 'CLI code file is private')
        check(path.read_text().startswith('https://' + site + '.localhost:8453\n'), 'CLI code uses the canonical HTTPS site URL')
        connected = data('connect', '--code-file=' + str(path), '--name=' + name)
        check(connected['name'] == name and 'key' not in connected, 'CLI saves a named connection without exposing its key')

sites = data('sites')
check(len(sites) == 2, 'Two remote sites are retained independently')
check(all('key' not in item for item in sites), 'CLI site inventory excludes credentials')
site = next(item for item in sites if item['name'] == 'QA Staging')
check('Specify --remote' in cli('push', '--plugins=csm-fixture', fail=True), 'CLI requires an explicit remote when several are saved')

plugin = BASE / (REMOTE + '.localhost/public/wp-content/plugins/csm-fixture/payload.txt')
before = plugin.read_bytes()
summary = data('push', '--remote=QA Staging', '--plugins=csm-fixture')
check(summary['ready'] and summary['status'] == 'staging', 'CLI push stages and verifies without applying by default')
check(plugin.read_bytes() == before, 'CLI staging leaves destination files untouched')
check('retained' in cli('pull', '--remote=QA Alternate', '--plugins=csm-fixture', fail=True), 'A retained transfer cannot be replaced with another remote')
recent = data('recent')[0]
check(recent['remote_id'] == site['id'] and recent['direction'] == 'push' and recent['selection']['plugins'] == ['csm-fixture'], 'Recent transfer stores exact site, direction and selection')
check('key' not in json.dumps(data('recent')), 'Recent-transfer metadata contains no credentials')
check(data('resume')['ready'], 'CLI resume handles an already-complete staging session')
check(data('apply', '--yes')['status'] == 'applied', 'CLI applies the verified transfer explicitly')
recent = data('recent')[0]
check(recent['status'] == 'applied' and recent['completed_at'] >= recent['started_at'], 'Recent transfer records application time')
check(data('rollback', '--yes')['status'] == 'restored', 'CLI restores its retained destination backup')
check(plugin.read_bytes() == before, 'CLI rollback restores the original file bytes')
data('discard', '--yes')
check(any(item['id'] == recent['id'] for item in data('recent')), 'Discarding a backup retains the reusable settings')
replayed = data('push', '--recent=' + recent['id'])
check(replayed['ready'], 'CLI reuses exact recent settings without another connection code')
data('discard', '--yes')
check('other direction' in cli('pull', '--recent=' + recent['id'], fail=True), 'CLI rejects an accidental direction change when replaying settings')

# Options imports must retain the local connection registry and recent-transfer records.
registry_before = sorted(item['id'] for item in data('sites'))
pulled = data('pull', '--remote=' + site['id'], '--plugins=csm-fixture', '--tables=wp_options', '--apply', '--yes')
check(pulled['status'] == 'applied', 'CLI pull uses the same saved site and can explicitly apply both streams')
check(sorted(item['id'] for item in data('sites')) == registry_before, 'Options-table import preserves all saved remote sites')
check(any(item['direction'] == 'pull' and item['id'] == pulled['id'] for item in data('recent')), 'Options-table import preserves its recent-transfer settings')
data('rollback', '--yes')
data('discard', '--yes')

# The CLI previews defaults and combines per-default toggles with custom replacements.
defaults = data('replacements', '--remote=' + site['id'], '--direction=push')
check(any(rule['id'] == 'root' and rule['find'].endswith('/public/') for rule in defaults), 'CLI exposes the exact automatic replacement values and IDs')
with tempfile.TemporaryDirectory(prefix='trickle-rules-') as directory:
    rules_file = Path(directory) / 'rules.json'
    rules_file.write_text(json.dumps([{'find':'sample/','replace':'cli-sample/','enabled':True}]))
    job = data('push', '--remote=' + site['id'], '--tables=wp_csm_fixture', '--skip-default-replacements=root', '--replacements=' + str(rules_file), '--apply', '--yes')
    value = json.loads(command(REMOTE, 'eval', 'global $wpdb; $r=unserialize($wpdb->get_var("SELECT payload FROM {$wpdb->prefix}csm_fixture WHERE id=1")); echo json_encode(["url"=>$r["url"],"nested"=>unserialize($r["nested"])]);'))
    check(value['url'] == site['url']+'/cli-sample/1', 'CLI custom replacements run during actual database import')
    check(value['nested']['root'] == str(BASE / (LOCAL+'.localhost/public'))+'/', 'CLI can disable one default path replacement independently')
    profile = next(item for item in data('recent') if item['id'] == job['id'])
    check(any(rule['kind']=='custom' and rule['find']=='sample/' for rule in profile['selection']['replacements']), 'CLI custom rules are retained in recent-transfer settings')
    data('rollback', '--yes'); data('discard', '--yes')

# Preserve the exact changed-media cutoff and replacement setting in reusable history.
changed = data('push', '--remote=' + site['id'], '--media=changed', '--since=2026-10-01T00:00:23Z', '--skip-replace')
profile = next(item for item in data('recent') if item['id'] == changed['id'])
check(profile['selection']['since'] == 1790812823 and profile['selection']['replace'] is False, 'Recent settings preserve the exact UTC media cutoff and replacement choice')
data('discard', '--yes')
print(f'\n{CHECKS} CLI/controller checks passed.', flush=True)
