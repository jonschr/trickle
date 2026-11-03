<?php
// Real boot and generated loader, entirely inside an isolated temporary directory.
define('ABSPATH', '/fixture/'); define('CSM_FILE', dirname(__DIR__).'/trickle.php');
preg_match("/define\\('CSM_VERSION', '([^']+)'\\)/",file_get_contents(CSM_FILE),$match);define('CSM_VERSION',$match[1]);
define('WPMU_PLUGIN_DIR',sys_get_temp_dir().'/trickle-loader-refresh-'.bin2hex(random_bytes(6)));
$options=['_csm_key'=>'fixture-key'];$allowed=true;
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;}
function wp_is_file_mod_allowed($context){return $GLOBALS['allowed'];}
function add_action(...$args){}
function wp_next_scheduled(...$args){return true;}
function plugin_basename($file){return 'trickle/trickle.php';}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
class CSM_Log {public static array $errors=[];public static function write($level,$event,$context){self::$errors[]=$event;}}
require __DIR__.'/../includes/plugin.php';
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";};
try{
 mkdir(WPMU_PLUGIN_DIR,0700);$path=WPMU_PLUGIN_DIR.'/0-trickle-loader.php';file_put_contents($path,'<?php // TRICKLE_LOADER old');
 CSM_Plugin::boot();$check(CSM_Plugin::loader_ready(),'First boot replaces an older loader with the current authenticated loader');
 $check(get_option('_csm_loader_version')===CSM_VERSION,'Successful refresh records the installed version');
 touch($path,1000000000);CSM_Plugin::boot();clearstatcache(true,$path);$check(filemtime($path)===1000000000,'Subsequent boots do not rewrite the loader');
 unset($options['_csm_loader_version']);file_put_contents($path,'<?php // unrelated file');CSM_Plugin::boot();
 $check(file_get_contents($path)==='<?php // unrelated file'&&!get_option('_csm_loader_version')&&count(CSM_Log::$errors)===1,'Failure keeps unrelated files intact and does not mark a successful refresh');
 $allowed=false;file_put_contents($path,'<?php // TRICKLE_LOADER old');CSM_Plugin::boot();
 $check(!CSM_Plugin::loader_ready()&&!get_option('_csm_loader_version'),'File modification policy prevents refresh');
}finally{if(is_file(WPMU_PLUGIN_DIR.'/0-trickle-loader.php'))unlink(WPMU_PLUGIN_DIR.'/0-trickle-loader.php');rmdir(WPMU_PLUGIN_DIR);}
