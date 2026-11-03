#!/usr/bin/env python3
"""Reuse folder caches across selections; test QA-C only, with no migration traffic."""
import json, shutil, subprocess, time
from pathlib import Path
SITE='trickle-qa-c'; ROOT=Path.home()/'Cove/Sites'/f'{SITE}.localhost/public/wp-content'
NAMES=['trickle-folder-cache-a','trickle-folder-cache-b']; checks=0
def ev(code):
    p=subprocess.run(['cove','wp',SITE,'eval',code],capture_output=True,text=True,timeout=60)
    assert p.returncode==0,p.stdout+p.stderr
    return json.loads(p.stdout.splitlines()[-1])
def inventory(plugins,verify=False,skip=None,themes=None):
    q={'selection':{'plugins':plugins,'themes':themes or [],'skip_paths':skip or []},'verify':verify}
    return ev('echo wp_json_encode(CSM_Components::inventory(json_decode('+repr(json.dumps(q))+',true)));')
def check(ok,label):
    global checks
    assert ok,label
    checks+=1;print('PASS '+label,flush=True)
assert ev('echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
dirs=[ROOT/'plugins'/name for name in NAMES];theme=ROOT/'themes'/NAMES[0]
assert all(not p.exists() for p in dirs)
assert not theme.exists()
try:
    for p in dirs:
        p.mkdir();(p/'a.txt').write_text('original');(p/'b.txt').write_text('unchanged')
    theme.mkdir();(theme/'style.css').write_text('/* Theme Name: Folder cache fixture */');(theme/'index.php').write_text('<?php')
    time.sleep(3.1)
    full=inventory(NAMES);single=inventory(NAMES[:1])
    check(full['hashed']==4 and single['hashed']==0,'A single-folder pull reuses hashes from a prior multi-folder selection')
    check(full['components']['plugins/'+NAMES[0]]==single['components']['plugins/'+NAMES[0]],'Folder digest stays identical when the selection changes')
    reordered=inventory(list(reversed(NAMES)))
    check(reordered['hashed']==0 and reordered['components']==full['components'],'Selection order does not force content rehashing')
    mixed=inventory(NAMES[:1],themes=NAMES[:1]);theme_only=inventory([],themes=NAMES[:1])
    check(mixed['hashed']==2 and theme_only['hashed']==0 and mixed['components']['themes/'+NAMES[0]]==theme_only['components']['themes/'+NAMES[0]],'Theme-only pulls reuse the theme cache from a combined plugin/theme selection')
    forced=inventory(NAMES[:1],True)
    check(forced['hashed']==2 and forced['components']==single['components'],'Explicit checksum recalculation still reads every selected file')
    before=(dirs[0]/'a.txt').stat();(dirs[0]/'a.txt').write_text('modified')
    import os
    os.utime(dirs[0]/'a.txt',ns=(before.st_atime_ns,before.st_mtime_ns))
    time.sleep(3.1);edited=inventory(NAMES[:1])
    check(edited['hashed']==1 and edited['components']!=single['components'],'Same-size edits with restored mtime invalidate the cache through ctime')
    (dirs[0]/'a.txt').unlink();(dirs[0]/'new.txt').write_text('added');time.sleep(3.1)
    changed=inventory(NAMES[:1])
    check(changed['files']==2 and changed['hashed']==1 and changed['components']!=edited['components'],'Additions and deletions rebuild only the affected content hashes')
    skipped=inventory(NAMES[:1],skip=['plugins/'+NAMES[0]+'/b.txt'])
    check(skipped['files']==1 and skipped['components']!=changed['components'],'Exclusion changes cannot reuse an incompatible folder digest')
    (dirs[0]/'new.txt').rename(dirs[0]/'renamed.txt');time.sleep(3.1)
    renamed=inventory(NAMES[:1]);check(renamed['components']!=changed['components'],'Renaming a file changes its containing folder digest')
    print(f'{checks} folder-cache checks passed; no migration applied.')
finally:
    for p in dirs+[theme]:shutil.rmtree(p,ignore_errors=True)
