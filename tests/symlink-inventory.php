<?php
// Metadata-only inventory and selection against temporary plugin/theme directories.
$dir=sys_get_temp_dir().'/trickle-links-'.bin2hex(random_bytes(6));mkdir($dir,0700);
define('ABSPATH',$dir.'/');define('WP_PLUGIN_DIR',$dir.'/plugins');define('CSM_FILE',$dir.'/plugins/trickle/trickle.php');
mkdir($dir.'/wp-admin/includes',0700,true);file_put_contents($dir.'/wp-admin/includes/plugin.php','<?php');file_put_contents($dir.'/wp-admin/includes/update.php','<?php');mkdir(WP_PLUGIN_DIR);mkdir($dir.'/themes');
function get_plugins(){return array_fill_keys(['plain/main.php','nested/main.php','linked/main.php','broken/main.php','excluded/main.php','trickle/trickle.php'],['Name'=>'Fixture','Version'=>'1']);}
function get_site_transient($key){return (object)[];}function get_option($key,$default=[]){return $default;}function get_template(){return 'child';}function get_stylesheet(){return 'child';}function esc_url_raw($url,$protocols){return $url;}
class TestTheme{function get($key){return $key==='Name'?'Child':'1';}function get_screenshot(){return false;}function get_theme_root(){return $GLOBALS['dir'].'/themes';}}
function wp_get_themes(){return ['child'=>new TestTheme];}
require __DIR__.'/../includes/files.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
try{
 foreach(['plain','nested','broken','excluded']as$name){mkdir(WP_PLUGIN_DIR.'/'.$name);file_put_contents(WP_PLUGIN_DIR.'/'.$name.'/main.php','<?php');}
 mkdir(WP_PLUGIN_DIR.'/nested/deep');symlink(WP_PLUGIN_DIR.'/plain/main.php',WP_PLUGIN_DIR.'/nested/deep/link.php');symlink(WP_PLUGIN_DIR.'/plain',WP_PLUGIN_DIR.'/linked');symlink($dir.'/absent',WP_PLUGIN_DIR.'/broken/missing');
 foreach(['node_modules','.git','.env']as$name)symlink($dir.'/absent',WP_PLUGIN_DIR.'/excluded/'.$name);
 mkdir($dir.'/themes/child');symlink(WP_PLUGIN_DIR.'/plain/main.php',$dir.'/themes/child/link.php');
 $inventory=CSM_Files::inventory(false,false);$plugins=array_column($inventory['plugins'],null,'id');
 check(!$plugins['plain']['protected']&&!$plugins['excluded']['protected'],'Ordinary components and excluded symlinks remain selectable');
 foreach(['nested','linked','broken']as$id)check($plugins[$id]['protected']&&str_contains($plugins[$id]['protected_reason'],'contains symlinks'),$id.' symlinks are detected without following targets');
 foreach(['nested','linked']as$id){$approved=CSM_Files::selection(['plugins'=>[$id],'themes'=>[],'follow_symlinks'=>['plugins/'.$id]]);check($approved['plugins']===[$id],$id.' permits explicit source approval');}
 foreach([['uploads/2026'],['plugins/trickle'],['plugins/../nested']]as$links){$denied=false;try{CSM_Files::selection(['plugins'=>['linked'],'themes'=>[],'follow_symlinks'=>$links]);}catch(RuntimeException$e){$denied=true;}check($denied,'Invalid, unselected and self-update approvals are rejected');}
 check($plugins['trickle']['protected']&&!isset($plugins['trickle']['protected_reason']),'Trickle keeps its separate protection');
 check($inventory['themes'][0]['protected']&&str_contains($inventory['themes'][0]['protected_reason'],'theme'),'Themes receive the same protection');
 check(str_contains(CSM_Files::transfer_block_reason(WP_PLUGIN_DIR.'/plain','plugins/plain',0),'timed out'),'Expired scans fail closed with an honest reason');
 $selection=CSM_Files::selection(['plugins'=>['plain'],'themes'=>[]]);check($selection['plugins']===['plain'],'Safe plugin selection still works');
 foreach(['nested','linked','broken']as$id){$rejected=false;try{CSM_Files::selection(['plugins'=>[$id],'themes'=>[]]);}catch(RuntimeException$e){$rejected=str_contains($e->getMessage(),'contains symlinks');}check($rejected,$id.' is rejected before packaging, including direct API selections');}
 unlink(WP_PLUGIN_DIR.'/nested/deep/link.php');$fresh=array_column(CSM_Files::inventory(false,false)['plugins'],null,'id');check(!$fresh['nested']['protected'],'Removing a symlink unlocks the next fresh inventory');
 symlink($dir.'/absent',WP_PLUGIN_DIR.'/plain/new');$rejected=false;try{CSM_Files::selection(['plugins'=>['plain'],'themes'=>[]]);}catch(RuntimeException$e){$rejected=true;}check($rejected,'A symlink added after listing is caught again before packaging');
}finally{
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($iterator as$file){if($file->isLink()||!$file->isDir())unlink($file->getPathname());else rmdir($file->getPathname());}rmdir($dir);
}
