<?php
// Source aliases -> packaged regular files -> staged and installed destination bytes. No sites/network.
$baseline=in_array('--baseline',$argv,true);$root=$baseline?($argv[2]??''):dirname(__DIR__);$dir=sys_get_temp_dir().'/trickle-perf-'.bin2hex(random_bytes(6));mkdir($dir,0700);
define('ABSPATH',$dir.'/public/');define('WP_CONTENT_DIR',ABSPATH.'wp-content');define('WP_PLUGIN_DIR',WP_CONTENT_DIR.'/plugins');define('CSM_FILE',$root.'/trickle.php');
mkdir(WP_PLUGIN_DIR.'/demo',0700,true);mkdir(WP_CONTENT_DIR.'/themes/child',0700,true);mkdir(WP_CONTENT_DIR.'/uploads/2026/01',0700,true);
$hashCalls=0;$budget=8;$cancel=false;$saved=[];
function apply_filters($name,$value,...$args){if($name==='csm_step_seconds')return $GLOBALS['budget'];if($name==='csm_hash_seconds')++$GLOBALS['hashCalls'];return $value;}
function wp_salt($name){return 'performance-fixture';}function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0700,true);}function wp_get_theme($id){return new class{function exists(){return false;}function get($key){return '';}};}function wp_upload_dir(...$a){return ['basedir'=>WP_CONTENT_DIR.'/uploads','error'=>false];}function plugin_basename($p){return 'trickle/trickle.php';}function get_file_data(...$a){return ['php'=>'','wp'=>'','name'=>'Fixture'];}function get_bloginfo($n){return '7.1';}

class CSM_Activation{static function current(){return [];}static function source(&$j,$h){}}
class CSM_Progress{static function update(...$a){}static function read($id){return [];}}
class CSM_Jobs{static function save($job){$GLOBALS['saved'][$job['id']]=$job;}}
foreach(['support','files','components','package','plugin']as$part)require $root.'/includes/'.$part.'.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function countFrames($path){$fp=fopen($path,'rb');$count=0;while(!feof($fp)){ $h=fread($fp,36);if($h==='')break;if(strlen($h)!==36)throw new RuntimeException('bad frame');$len=unpack('N',substr($h,0,4))[1];fseek($fp,$len,SEEK_CUR);++$count;}fclose($fp);return $count;}
function packFixture($path,$selection){$steps=0;do{$bundle=CSM_Package::build($path,'files',$selection);if(++$steps>400)throw new RuntimeException('packaging did not advance');$state=json_decode(file_get_contents($path.'.build'),true);$roundtrip=json_decode(json_encode($state),true);if($state!==$roundtrip)throw new RuntimeException('Checkpoint not serializable');}while(!empty($bundle['continue']));return [$bundle,$steps];}
$private=CSM_Support::private_dir();
function do_action(...$args){}
function rejected(callable $action,string $contains):bool{try{$action();}catch(RuntimeException$e){return str_contains(strtolower($e->getMessage()),strtolower($contains));}return false;}
try{
 $repo=$dir.'/checkout';mkdir($repo);mkdir($repo.'/assets');file_put_contents($repo.'/main.php','<?php // linked plugin');file_put_contents($repo.'/assets/data.txt','shared asset');file_put_contents($repo.'/large.bin',random_bytes(2*1024*1024));
 for($i=0;$i<128;$i++)file_put_contents($repo.'/tiny-'.$i.'.txt','file '.$i);
 symlink('main.php',$repo.'/alias.php');symlink('assets',$repo.'/shared');
 foreach(['.git','node_modules','.idea','.vscode','.ssh']as$folder){mkdir($repo.'/'.$folder);file_put_contents($repo.'/'.$folder.'/private','must not transfer');}
 foreach(['.env','.env.local','.npmrc','.netrc','auth.json','.DS_Store']as$name)file_put_contents($repo.'/'.$name,'must not transfer');
 symlink('.env',$repo.'/innocent.txt');symlink('.ssh',$repo.'/innocent-folder');
 symlink($repo,WP_PLUGIN_DIR.'/linked');
 $selection=['plugins'=>['linked'],'themes'=>[],'media'=>'none','follow_symlinks'=>['plugins/linked']];
 check(rejected(fn()=>iterator_to_array(CSM_Files::scan(WP_PLUGIN_DIR.'/linked','plugins/linked',[],null,true)),'approval'),'Unapproved source root links remain blocked');
 $budget=8;CSM_Support::step();$cursor=[];$mapped=iterator_to_array(CSM_Files::walk($cursor,[[WP_PLUGIN_DIR.'/linked','plugins/linked']],$selection,true));
 check(count($mapped)===133,'Approved root, file and directory aliases produce logical file paths');
 check(!array_filter($mapped,fn($entry)=>str_contains($entry[0],'innocent')||str_contains($entry[0],'private')),'Resolved-target exclusions prevent secret files hidden behind innocent alias names');
 check(array_reduce($mapped,fn($ok,$entry)=>$ok&&is_file($entry[1])&&!is_link($entry[1]),true),'Source walker pins regular resolved files');
 $index=CSM_Components::inventory(['selection'=>$selection,'source'=>true]);check($index['files']===133,'Source checksum inventory follows the same approved aliases');
 check(rejected(fn()=>CSM_Components::inventory(['selection'=>$selection]),'symbolic'),'Destination checksum comparison never follows source approvals');
 $budget=.00001;[$bundle,$steps]=packFixture($private.'/linked',$selection);check($steps>1,'Linked source packaging survives serialized checkpoints');
 // A real destination has a normal component directory, not the source symlink.
 unlink(WP_PLUGIN_DIR.'/linked');mkdir(WP_PLUGIN_DIR.'/linked');$n=0;while(!CSM_Package::unpack($private.'/linked',$bundle)){if(++$n>500)throw new RuntimeException('unpack stalled');}
 $job=['id'=>str_repeat('d',32),'selection'=>$selection+['tables'=>[]],'files'=>[],'database'=>[]];$fp=fopen($private.'/linked.raw','rb');do{$done=CSM_Files::stage_step($fp,$job);}while(!$done);fclose($fp);check(count($job['files'])===133,'Linked package imports only ordinary destination files');
 $budget=8;CSM_Support::step();$preview=CSM_Plugin::preview_step($job);check($preview['counts']['add']===133,'Destination preview accepts linked source files without following destination links');
 $job['apply_work']=['cursor'=>0];$replace=new ReflectionMethod(CSM_Plugin::class,'replace_files');check($replace->invokeArgs(null,[&$job,$private.'/apply.json']),'Actual replacement installs the staged package');
 foreach($job['files']as$file){$target=CSM_Files::destination($file['path'],$selection);if(is_link($target)||hash_file('sha256',$target)!==$file['sha256'])throw new RuntimeException('Installed bytes differ');}
 check(file_get_contents(WP_PLUGIN_DIR.'/linked/alias.php')===file_get_contents($repo.'/main.php'),'Installed alias is an ordinary file containing the source bytes');
 $budget=8;CSM_Support::step();$again=CSM_Components::inventory(['selection'=>$selection]);check($again['components']===$index['components'],'Repeat destination folder hashes match the linked source');
 symlink($repo.'/main.php',WP_PLUGIN_DIR.'/linked/destination-link.php');check(rejected(fn()=>CSM_Files::destination('plugins/linked/destination-link.php',$selection),'symbolic'),'Destination path checks still reject links even with source approval');unlink(WP_PLUGIN_DIR.'/linked/destination-link.php');
 symlink('.', $repo.'/loop');$cursor=[];check(rejected(fn()=>iterator_to_array(CSM_Files::walk($cursor,[[$repo,'plugins/linked']],$selection,true)),'leaves the approved'),'Unapproved external source mapping is rejected');
 // Restore the original source mapping to test cycles, escapes and broken links.
 CSM_Support::remove_tree(WP_PLUGIN_DIR.'/linked');symlink($repo,WP_PLUGIN_DIR.'/linked');$cursor=[];check(rejected(fn()=>iterator_to_array(CSM_Files::walk($cursor,[[WP_PLUGIN_DIR.'/linked','plugins/linked']],$selection,true)),'circular'),'Resumable traversal rejects directory cycles');
 check(rejected(fn()=>iterator_to_array(CSM_Files::scan(WP_PLUGIN_DIR.'/linked','plugins/linked',$selection,null,true)),'circular'),'Recursive checksum traversal rejects directory cycles');unlink($repo.'/loop');
 mkdir($dir.'/outside');file_put_contents($dir.'/outside/data','outside');symlink($dir.'/outside',$repo.'/escape');check(rejected(fn()=>CSM_Files::source_path($repo.'/escape','plugins/linked/escape',$selection),'leaves the approved'),'Nested links cannot escape the resolved component');unlink($repo.'/escape');
 symlink($dir.'/missing',$repo.'/broken');check(rejected(fn()=>CSM_Files::source_path($repo.'/broken','plugins/linked/broken',$selection),'broken'),'Broken links fail with a specific error');CSM_Support::remove_tree($repo.'/broken');check(!is_link($repo.'/broken'),'Cleanup removes dangling links without following them');
 $git=CSM_Files::git_folders($selection,true);check(in_array('plugins/linked',$git,true),'Git review recognizes the resolved checkout');
 $protected=$selection+['protect_source_git'=>true,'skip_paths'=>['plugins/linked']];check(count(iterator_to_array(CSM_Files::paths($protected)))===0,'Pulls still skip a linked Git checkout without separate Git approval');
 $protected['git_overrides']=['plugins/linked'];$protected['skip_paths']=[];check(count(iterator_to_array(CSM_Files::paths($protected)))===133,'Explicit Git approval includes code but never Git metadata');
 $manifest=CSM_Components::manifest(['selection'=>$selection,'token'=>$index['token'],'components'=>['plugins/linked']]);file_put_contents($repo.'/replacement.php','different content');unlink($repo.'/alias.php');symlink('replacement.php',$repo.'/alias.php');check(!CSM_Components::verify($repo.'/alias.php',$manifest['files']['plugins/linked/alias.php']),'Retargeted file links invalidate cached source checksums');
 $themeRepo=$dir.'/theme-checkout';mkdir($themeRepo);file_put_contents($themeRepo.'/style.css','/* Theme Name: Linked */');file_put_contents($themeRepo.'/index.php','<?php // theme');symlink($themeRepo,WP_CONTENT_DIR.'/themes/linked');
 $themeSelection=['plugins'=>[],'themes'=>['linked'],'media'=>'none','follow_symlinks'=>['themes/linked']];$budget=8;[$themeBundle]=packFixture($private.'/theme-linked',$themeSelection);unlink(WP_CONTENT_DIR.'/themes/linked');mkdir(WP_CONTENT_DIR.'/themes/linked');while(!CSM_Package::unpack($private.'/theme-linked',$themeBundle)){}
 $themeJob=['id'=>str_repeat('e',32),'selection'=>$themeSelection+['tables'=>[]],'files'=>[],'database'=>[]];$fp=fopen($private.'/theme-linked.raw','rb');while(!CSM_Files::stage_step($fp,$themeJob)){}fclose($fp);check(count($themeJob['files'])===2,'Linked themes also package and stage as regular files');

}finally{
 CSM_Support::remove_tree($private);
 $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($entries as$entry){if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());}rmdir($dir);
}
