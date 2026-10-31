#!/usr/bin/env python3
"""Signed push/pull exclusion and Git protection checks on idle QA-A/C only."""
import json, subprocess, shutil
from pathlib import Path
BASE=Path.home()/'Cove/Sites'
SITES=['trickle-qa-a','trickle-qa-c']
ROOT={s:BASE/(s+'.localhost/public') for s in SITES}
NAMES=['trickle-policy-plain','trickle-policy-source','trickle-policy-dest','trickle-policy-nested']
checks=0

def wp(site,*args,fail=False):
 p=subprocess.run(['cove','wp',site,*args],capture_output=True,text=True,timeout=120)
 if fail:
  assert p.returncode,p.stdout
  return p.stdout+p.stderr
 if p.returncode:raise RuntimeError(p.stdout+p.stderr)
 return p.stdout

def ev(site,code):return json.loads(wp(site,'eval',code))
def cli(*args,fail=False):
 out=wp(SITES[1],'trickle',*args,'--format=json',fail=fail)
 return out if fail else json.loads(out)
def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
def path(s,n,f):return ROOT[s]/'wp-content/plugins'/n/f
def write(s,n,f,data):
 p=path(s,n,f);p.parent.mkdir(parents=True,exist_ok=True);p.write_text(data)
def finish(restore=False):
 if restore:cli('rollback','--yes')
 cli('discard','--yes')
for s in SITES:
 assert ev(s,'echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
 for n in NAMES:assert not path(s,n,'').exists()
settings={s:ev(s,'echo wp_json_encode(get_option("_csm_settings"));') for s in SITES}
recents=ev(SITES[1],'echo wp_json_encode(get_option("_csm_recents",[]));')
backups={s:ev(s,'echo wp_json_encode(get_option("_csm_backups",[]));') for s in SITES}
try:
 for s in SITES:
  wp(s,'eval',"update_option('_csm_backups',[],false);update_option('_csm_settings',['pull'=>true,'push'=>true],false);")
  for n in NAMES:
   write(s,n,n+'.php','<?php\n/* Plugin Name: Trickle policy fixture */\n')
   write(s,n,'code.txt','source' if s==SITES[0] else 'destination')
   for f in ['.env','.env.production','.env.example','config/service.env','.gitignore','node_modules/pkg/index.js','nested/node_modules/x.js','nested/.env.local']:
    write(s,n,f,'KEEP PRIVATE '+s)
  write(s,'trickle-policy-nested','lib/dev/dev.txt','source-dev' if s==SITES[0] else 'destination-dev')
  write(s,'trickle-policy-nested','lib/dev/.git','gitdir: /not-accessed/worktree')
 write(SITES[0],'trickle-policy-source','.git/HEAD','ref: refs/heads/main')
 for s in SITES:
  write(s,'trickle-policy-source','lib/sub/.git','gitdir: /nested-fixture')
  write(s,'trickle-policy-source','lib/sub/nested.txt',s)
 write(SITES[1],'trickle-policy-dest','.git','gitdir: /not-accessed/worktree')
 write(SITES[1],'trickle-policy-plain','obsolete.txt','old')
 source=SITES[0];dest=SITES[1];peer='--remote=https://trickle-qa-a.localhost:8453';plugins='--plugins='+','.join(NAMES)
 pulled=cli('pull',peer,plugins)
 check(pulled['ready'] and set(pulled['skipped_folders'])=={'plugins/trickle-policy-source','plugins/trickle-policy-dest','plugins/trickle-policy-nested/lib/dev','plugins/trickle-policy-source/lib/sub'},'Pull protects source repos, destination worktrees and nested repos by default')
 cli('apply','--yes')
 check(path(dest,'trickle-policy-plain','code.txt').read_text()=='source' and not path(dest,'trickle-policy-plain','obsolete.txt').exists(),'Normal code pulls and obsolete code is removed')
 check(path(dest,'trickle-policy-source','code.txt').read_text()=='destination' and path(dest,'trickle-policy-dest','code.txt').read_text()=='destination','Both protected top-level components remain untouched')
 check(path(dest,'trickle-policy-nested','lib/dev/dev.txt').read_text()=='destination-dev','Nested development code remains untouched')
 check(all(path(dest,n,f).read_text()=='KEEP PRIVATE '+dest for n in NAMES for f in ['.env','.env.production','.env.example','config/service.env','.gitignore','node_modules/pkg/index.js','nested/node_modules/x.js','nested/.env.local']),'Apply preserves excluded destination files at every depth')
 finish(True)
 check(path(dest,'trickle-policy-plain','code.txt').read_text()=='destination' and path(dest,'trickle-policy-plain','obsolete.txt').exists(),'Rollback restores transferred and removed code')
 allowed=cli('pull',peer,plugins,'--include-git=plugins/trickle-policy-dest,plugins/trickle-policy-source,plugins/trickle-policy-nested/lib/dev,plugins/trickle-policy-source/lib/sub')
 check(allowed['ready'] and not allowed['skipped_folders'],'Explicit folder-specific approval permits Git working directories')
 cli('apply','--yes')
 check(path(dest,'trickle-policy-dest','code.txt').read_text()=='source' and path(dest,'trickle-policy-nested','lib/dev/dev.txt').read_text()=='source-dev','Approved code transfers include nested working directories')
 check(path(dest,'trickle-policy-dest','.git').read_text()=='gitdir: /not-accessed/worktree' and not path(dest,'trickle-policy-source','.git').exists(),'Approval never transfers or deletes Git metadata')
 recent=cli('recent')[0]
 check('git_overrides' not in recent['selection'] and 'skip_paths' not in recent['selection'],'Recent settings cannot silently replay a Git overwrite approval')
 finish(True)
 # Push from a source with a repository is allowed; only its code may travel.
 pushed=cli('push',peer,'--plugins=trickle-policy-dest')
 check(pushed['ready'] and pushed['files']==1 and pushed['unchanged_files']==1,'Push exports code from a local worktree without development dependencies or secrets')
 cli('apply','--yes')
 check(path(source,'trickle-policy-dest','code.txt').read_text()=='destination' and not path(source,'trickle-policy-dest','.git').exists(),'Push installs code while withholding the source worktree marker')
 check(path(source,'trickle-policy-dest','.env').read_text()=='KEEP PRIVATE '+source,'Push preserves destination environment')
 finish(True)
 # Repositories created after review/staging must block application before code changes.
 fresh=cli('pull',peer,'--plugins=trickle-policy-plain')
 write(dest,'trickle-policy-plain','.git','gitdir: /not-accessed/new-worktree')
 error=cli('apply','--yes',fail=True)
 check('Git working directory protected' in error and path(dest,'trickle-policy-plain','code.txt').read_text()=='destination','New Git marker after staging blocks apply before mutation')
 path(dest,'trickle-policy-plain','.git').unlink();finish()
 # A sender cannot bypass exclusions by manually constructing a file record.
 blocked=ev(dest,"$r=[];foreach(['plugins/trickle-policy-plain/.env','plugins/trickle-policy-plain/.env.prod','plugins/trickle-policy-plain/node_modules/a.js','plugins/trickle-policy-plain/.gitignore','plugins/trickle-policy-plain/.git/HEAD'] as $p){try{CSM_Files::destination($p,['plugins'=>['trickle-policy-plain']]);$r[]=false;}catch(Throwable $e){$r[]=true;}}echo wp_json_encode($r);")
 check(all(blocked),'Receiver rejects forbidden paths independently of exporter and UI')
 # A repository above wp-content components protects their entire selected roots.
 parent=ev(dest,"$p=WP_CONTENT_DIR.'/.git';if(file_exists($p))throw new RuntimeException('Unexpected repository marker');try{file_put_contents($p,'gitdir: /fixture');$r=CSM_Files::git_folders(['plugins'=>['trickle-policy-plain'],'themes'=>['twentytwentyfive'],'media'=>'all']);$blocked=false;try{CSM_Files::destination('plugins/trickle-policy-plain/code.txt',['plugins'=>['trickle-policy-plain']]);}catch(Throwable $e){$blocked=true;}echo wp_json_encode([$r,$blocked]);}finally{unlink($p);}")
 check(parent[1] is True and {'plugins','themes','uploads'}.issubset(set(parent[0])),'Repository markers above component folders protect every selected descendant root')
 # Exercise Git-aware media comparison, not just component folders.
 media={s:ROOT[s]/'wp-content/uploads/trickle-policy-media-fixture' for s in SITES}
 mu={s:ROOT[s]/'wp-content/mu-plugins/trickle-policy-media.php' for s in SITES}
 try:
  for s in SITES:
   assert not media[s].exists() and not mu[s].exists();media[s].mkdir()
   mu[s].write_text("<?php add_filter('upload_dir',static function($u){$u['basedir']=WP_CONTENT_DIR.'/uploads/trickle-policy-media-fixture';$u['path']=$u['basedir'];$u['error']=false;return $u;});")
   (media[s]/'plain.jpg').write_text(s)
   (media[s]/'.env.local').write_text('PRIVATE '+s)
   (media[s]/'dev').mkdir();(media[s]/'dev/picture.jpg').write_text(s)
  (media[dest]/'dev/.git').write_text('gitdir: /fixture')
  r=cli('pull',peer,'--media=compare');check(r['files']==1 and r['skipped_folders']==['uploads/dev'],'Media comparison skips Git working directories on the destination')
  cli('apply','--yes');check((media[dest]/'dev/picture.jpg').read_text()==dest and (media[dest]/'.env.local').read_text()=='PRIVATE '+dest,'Media apply preserves development images and environment files');finish(True)
  r=cli('pull',peer,'--media=compare','--include-git=uploads/dev');check(r['files']==2,'Explicit Git inclusion works with checksum-based media transfers');finish()
 finally:
  for s in SITES:
   mu[s].unlink(missing_ok=True);shutil.rmtree(media[s],ignore_errors=True)
 missing_parent=ev(dest,"$fp=fopen('php://temp','w+b');fwrite($fp,CSM_Support::json(['type'=>'header','version'=>1,'kind'=>'files','theme_parents'=>['trickle-policy-child'=>'trickle-policy-missing-parent']]));rewind($fp);$j=['id'=>bin2hex(random_bytes(16)),'selection'=>['themes'=>['trickle-policy-child','trickle-policy-missing-parent'],'skip_paths'=>['themes/trickle-policy-missing-parent']]];$ok=false;try{CSM_Files::stage($fp,$j);}catch(Throwable $e){$ok=str_contains($e->getMessage(),'Include the required parent theme');}finally{fclose($fp);CSM_Support::remove_tree(CSM_Support::private_dir().'/'.$j['id']);}echo wp_json_encode($ok);")
 check(missing_parent,'A skipped Git parent theme cannot mask a missing required destination parent')
 print(f'{checks} file policy checks passed.',flush=True)
finally:
 state=ev(SITES[1],'echo wp_json_encode(CSM_Controller::session());')
 if state:
  status=cli('status')
  for n in NAMES:
   p=path(SITES[1],n,'.git')
   # Keep original fixture markers until rollback; a newly added plain marker is removable.
   if n=='trickle-policy-plain' and p.is_file():p.unlink()
  if status['status'] in ['applying','applied']:cli('rollback','--yes')
  cli('discard','--yes')
 wp(SITES[1],'option','update','_csm_recents',json.dumps(recents),'--format=json')
 for s in SITES:
  wp(s,'eval','foreach(get_option("_csm_backups",[]) as $id){CSM_Plugin::discard(CSM_Support::job($id));}')
  wp(s,'option','update','_csm_backups',json.dumps(backups[s]),'--format=json')
  if settings[s] is False:wp(s,'option','delete','_csm_settings')
  else:wp(s,'option','update','_csm_settings',json.dumps(settings[s]),'--format=json')
  for n in NAMES:shutil.rmtree(path(s,n,''),ignore_errors=True)
