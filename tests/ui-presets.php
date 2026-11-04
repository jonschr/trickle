<?php
// Exercise dashboard presets through the real controller, without a WordPress site or writes.
define('ABSPATH', '/fixture/');
$options = ['siteurl'=>'https://local.test', 'home'=>'https://local.test', '_csm_peers'=>['remote'=>['id'=>'remote','url'=>'https://remote.test','name'=>'Production','key'=>'fixture']]];
$writes = [];
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function update_option($k,$v,$autoload=null){$GLOBALS['writes'][]=$k;}
function wp_cache_delete(...$args){}
function site_url(){return 'https://local.test';}
function home_url(){return site_url();}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function wp_salt($scheme){return 'fixture-salt';}
class CSM_Transport {
    public static function url($url){return $url;}
    public static function request($peer,$action,$data=[]){if($action!=='manifest')throw new RuntimeException('Unexpected request');return $GLOBALS['remote'];}
}
class CSM_Plugin {public static function manifest($refresh=false){return $GLOBALS['local'];}}
class CSM_Files {
    public static function inventory(){return $GLOBALS['local'];}
    public static function git_folders($selection){return ['plugins/git-plugin','themes/git-theme'];}
    public static function beneath($path,$folders){foreach($folders as $folder)if($path===$folder||str_starts_with($path,$folder.'/'))return true;return false;}
}
require __DIR__.'/../includes/support.php';
require __DIR__.'/../includes/presets.php';
require __DIR__.'/../includes/controller.php';
$local=['url'=>site_url(),'tables'=>[['id'=>'local_posts']], 'plugins'=>[['id'=>'common'],['id'=>'git-plugin'],['id'=>'third-party'],['id'=>'trickle','protected'=>true]], 'themes'=>[['id'=>'git-theme'],['id'=>'vendor-theme']]];
$remote=['url'=>'https://remote.test','tables'=>[['id'=>'wp_posts'],['id'=>'wp_options']], 'plugins'=>[['id'=>'common'],['id'=>'missing'],['id'=>'trickle','protected'=>true]], 'themes'=>[]];
$operate = new ReflectionMethod(CSM_Controller::class, 'operate');
foreach (['pull-db','pull-db-missing','push-git'] as $name) {
    $result=$operate->invoke(null,'preset',['preset'=>$name,'site'=>'remote']);
    if($name==='push-git') {
        if($result['direction']!=='push'||$result['selection']['tables']!==[]||$result['selection']['plugins']!==['git-plugin']||$result['selection']['themes']!==['git-theme'])throw new RuntimeException('Git preset selected wrong scope');
    } else {
        if($result['direction']!=='pull'||$result['selection']['tables']!==['wp_posts','wp_options']||$result['selection']['plugins']!==($name==='pull-db'?[]:['missing']))throw new RuntimeException('Pull preset selected wrong scope');
    }
    if($result['selection']['media']!=='none')throw new RuntimeException('Preset included media');
}
try{$operate->invoke(null,'preset',['preset'=>'unknown','site'=>'remote']);throw new LogicException('Unknown preset accepted');}catch(RuntimeException $e){}
if($writes)throw new RuntimeException('Loading presets wrote site settings or staged a transfer');
echo "Dashboard preset checks passed: database, missing plugins, Git code only, invalid names, no staging or writes.\n";
