#!/usr/bin/env python3
"""Build and install a disposable next-version update on idle QA-C; never publish it."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

root=Path(__file__).resolve().parents[1]
site=Path.home()/'Cove/Sites/trickle-qa-c.localhost/public'
target=site/'wp-content/plugins/trickle'
probe=subprocess.run(['cove','wp','trickle-qa-c','eval','echo wp_json_encode(["home"=>get_option("home"),"idle"=>!get_option("_csm_control")&&!get_option("_csm_active_job")]);'],capture_output=True,text=True,check=True)
state=json.loads(probe.stdout)
assert state=={'home':'https://trickle-qa-c.localhost:8453','idle':True}
metadata=json.loads((root/'update.json').read_text())
parts=metadata['version'].split('.');parts[-1]=str(int(parts[-1])+1);future='.'.join(parts)
files=[root/'trickle.php',root/'update.json',root/'README.md']
for name in ['assets','includes','vendor']:
    files.extend(p for p in (root/name).rglob('*') if p.is_file() and not any(x.startswith('.') for x in p.relative_to(root).parts))
for p in files:
    dest=target/p.relative_to(root);dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,dest)
with tempfile.TemporaryDirectory(prefix='trickle-updater-test-') as directory:
    fixture=Path(directory)/f'trickle-{future}.zip'
    with zipfile.ZipFile(fixture,'w',zipfile.ZIP_DEFLATED) as archive:
        for p in files:
            data=p.read_bytes()
            if p.name=='trickle.php':data=data.replace(metadata['version'].encode(),future.encode())
            if p==root/'update.json':
                feed={**metadata,'version':future,'download_url':f'https://updates.example.test/trickle-{future}.zip'}
                data=json.dumps(feed).encode()
            archive.writestr('trickle/'+p.relative_to(root).as_posix(),data)
    try:
        result=subprocess.run(['cove','wp','trickle-qa-c','eval-file',str(root/'tests/updater.php'),str(fixture)],capture_output=True,text=True)
        print(result.stdout,end='')
        if result.returncode:raise RuntimeError(result.stderr)
    finally:
        for name in ['trickle.php','update.json']:shutil.copy2(root/name,target/name)
