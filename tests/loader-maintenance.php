<?php
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
// Offline loader checks: no plugin-specific fixtures and no real WordPress/database.
$mode=$argv[1]??'admin';
$root=sys_get_temp_dir().'/trickle-maintenance-'.bin2hex(random_bytes(6));
mkdir($root.'/wp-includes',0700,true);mkdir($root.'/wp-admin',0700,true);
foreach(['wp-includes/pluggable.php','wp-admin/admin-ajax.php','wp-admin/tools.php','wp-admin/plugins.php','index.php'] as $f)file_put_contents($root.'/'.$f,'<?php');
define('ABSPATH',$root.'/');define('WPINC','wp-includes');define('WP_PLUGIN_DIR',$root.'/plugins');define('__CSM_PLUGIN__','trickle/trickle.php');
$active=!str_starts_with($mode,'idle');$blocked=false;$isolated=false;$status=null;
function get_option($key,$default=false){global $active;if($key==='active_plugins')return ['trickle/trickle.php','example/example.php'];if($key==='_csm_active_job')return $active?'fixture':false;if($key==='_csm_job_fixture')return ['status'=>'applying'];if($key==='_csm_maintenance')return $active?['id'=>'fixture','until'=>time()+60]:[];return $default;}
function wp_cookie_constants(){}
function current_user_can($cap){return true;}
function wp_verify_nonce($nonce,$action){return $nonce==='valid';}
function add_filter($hook,$callback,...$args){if($hook==='option_active_plugins')$GLOBALS['isolated']=true;}
function status_header($code){$GLOBALS['status']=$code;}
function wp_next_scheduled(...$args){return true;}
function is_admin(){return $GLOBALS['mode']!=='frontend'&&$GLOBALS['mode']!=='rest';}
function wp_die(...$args){throw new RuntimeException('maintenance');}
class CSM_Support{static function runtime_lock($exclusive){return $GLOBALS['mode']!=='idle-busy';}}
$_SERVER['SCRIPT_FILENAME']=$root.'/wp-admin/plugins.php';$_SERVER['REQUEST_METHOD']='GET';$_GET=[];$_POST=[];$_REQUEST=[];
if(in_array($mode,['ajax','bad-nonce','idle-bad-nonce'],true)){
 define('DOING_AJAX',true);$_SERVER['SCRIPT_FILENAME']=$root.'/wp-admin/admin-ajax.php';$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'csm_admin','op'=>'progress','nonce'=>$mode==='ajax'?'valid':'stale'];$_REQUEST=$_POST;
}elseif($mode==='control'){$_SERVER['SCRIPT_FILENAME']=$root.'/wp-admin/tools.php';$_GET=['page'=>'trickle'];}
elseif(in_array($mode,['frontend','rest'],true)){$_SERVER['SCRIPT_FILENAME']=$root.'/index.php';}
try{
 try{require __DIR__.'/../includes/loader.php';}catch(RuntimeException $e){if($e->getMessage()!=='maintenance')throw $e;$blocked=true;}
 $expected=!in_array($mode,['control','ajax','idle-bad-nonce'],true);
 if($blocked!==$expected||($blocked&&$status!==503))throw new RuntimeException('Unexpected protection for '.$mode);
 if($isolated!==in_array($mode,['control','ajax'],true))throw new RuntimeException('Unexpected auth bypass for '.$mode);
 echo 'PASS loader maintenance: '.$mode."\n";
}finally{
 foreach(['wp-includes/pluggable.php','wp-admin/admin-ajax.php','wp-admin/tools.php','wp-admin/plugins.php','index.php']as$f)unlink($root.'/'.$f);rmdir($root.'/wp-includes');rmdir($root.'/wp-admin');rmdir($root);
}
