<?php
// Real signature verification across separate core/public front controllers.
$cases=['root','index','split-core','wrapped','subdirectory','bad-signature','login','admin','rest','other-path','manifest','missing-uri'];
if (!isset($argv[1])) {
    foreach ($cases as $case) { passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case),$status); if($status)exit($status); }
    exit;
}
$mode=$argv[1];$root=sys_get_temp_dir().'/trickle-endpoint-'.bin2hex(random_bytes(6));mkdir($root,0700);
foreach(['index.php','public-index.php','host-router.php'] as $file)file_put_contents($root.'/'.$file,'<?php');
define('ABSPATH',$root.'/');define('WP_CLI',true);define('CSM_VERSION','fixture');
define('WP_PLUGIN_DIR',dirname(__DIR__,2));define('__CSM_PLUGIN__',basename(dirname(__DIR__)).'/trickle.php');
function get_option($key,$default=false){return ['active_plugins'=>[__CSM_PLUGIN__,'other/plugin.php'],'_csm_key'=>'fixture-secret','siteurl'=>'https://example.test'.($GLOBALS['mode']==='subdirectory'?'/site':'')][$key]??$default;}
function add_filter(...$args){}
require __DIR__.'/../includes/support.php';require __DIR__.'/../includes/access.php';require __DIR__.'/../includes/transport.php';
$_SERVER=['REQUEST_METHOD'=>'POST','SCRIPT_FILENAME'=>$root.'/index.php','REQUEST_URI'=>'/?trickle=version'];$_GET=['trickle'=>'version'];
if($mode==='split-core')$_SERVER['SCRIPT_FILENAME']=$root.'/public-index.php';
if($mode==='wrapped')$_SERVER['SCRIPT_FILENAME']=$root.'/host-router.php';
if($mode==='index')$_SERVER['REQUEST_URI']='/index.php?trickle=version';
if($mode==='subdirectory'){$_SERVER['SCRIPT_FILENAME']=$root.'/public-index.php';$_SERVER['REQUEST_URI']='/site/?trickle=version';}
foreach(['login'=>'/wp-login.php','admin'=>'/wp-admin/admin-ajax.php','rest'=>'/wp-json/','other-path'=>'/unrelated/'] as $case=>$path)if($mode===$case)$_SERVER['REQUEST_URI']=$path.'?trickle=version';
if($mode==='manifest')$_GET['trickle']='manifest';
if($mode==='missing-uri')unset($_SERVER['REQUEST_URI']);
foreach(CSM_Transport::headers($_GET['trickle'],'fixture-secret','fixture-hash') as $header){if(!str_contains($header,': '))continue;[$name,$value]=explode(': ',$header,2);$_SERVER['HTTP_'.str_replace('-','_',strtoupper($name))]=$value;}
if($mode==='bad-signature')$_SERVER['HTTP_X_CSM_SIGNATURE']=str_repeat('0',64);
try {
    require __DIR__.'/../includes/loader.php';
    $expected=in_array($mode,['root','index','split-core','wrapped','subdirectory'],true);
    if(defined('CSM_ORIGINAL_ACTIVE_PLUGINS')!==$expected)throw new RuntimeException('Unexpected endpoint isolation: '.$mode);
    echo 'PASS signed endpoint isolation: '.$mode."\n";
}finally{foreach(['index.php','public-index.php','host-router.php'] as $file)unlink($root.'/'.$file);rmdir($root);}
