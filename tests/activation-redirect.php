<?php
// Real activation/redirect handlers; isolated filesystem, no site or network.
define('ABSPATH','/fixture/');define('CSM_FILE',dirname(__DIR__).'/trickle.php');
define('WPMU_PLUGIN_DIR',sys_get_temp_dir().'/trickle-activation-'.bin2hex(random_bytes(6)));
if(($argv[1]??'')==='cli')define('WP_CLI',true);
$transients=[];$now=100;$user=12;$admin=true;$allowed=true;$ajax=false;$cron=false;
function is_admin(){return $GLOBALS['admin'];}function wp_doing_ajax(){return $GLOBALS['ajax'];}function wp_doing_cron(){return $GLOBALS['cron'];}
function current_user_can($cap){return $GLOBALS['allowed'];}function get_current_user_id(){return $GLOBALS['user'];}
function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=[$value,$GLOBALS['now']+$ttl];}
function get_transient($key){$v=$GLOBALS['transients'][$key]??null;return $v&&$v[1]>$GLOBALS['now']?$v[0]:false;}
function delete_transient($key){unset($GLOBALS['transients'][$key]);}
function admin_url($path){return 'https://fixture.example/wp-admin/'.$path;}
class RedirectCaptured extends RuntimeException{}
function wp_safe_redirect($url){throw new RedirectCaptured($url);}
function plugin_basename($path){return 'trickle/trickle.php';}function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
class CSM_Support {static function atomic_bytes($path,$bytes,$mode){file_put_contents($path,$bytes);}}
require __DIR__.'/../includes/plugin.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
try{
 if(defined('WP_CLI')){CSM_Plugin::activate();check(CSM_Plugin::loader_ready()&&!$transients,'CLI installs the recovery loader without scheduling a browser redirect');CSM_Plugin::activation_redirect();return;}
 CSM_Plugin::activate();check(CSM_Plugin::loader_ready(),'Activation still installs the recovery loader');
 check(get_transient('_csm_activation_redirect_12')===true&&$transients['_csm_activation_redirect_12'][1]===160,'Interactive activation schedules a short-lived redirect for its administrator');
 $user=13;CSM_Plugin::activation_redirect();check(get_transient('_csm_activation_redirect_12')===true,'Another administrator does not consume the activating user’s redirect');$user=12;
 $ajax=true;CSM_Plugin::activation_redirect();$ajax=false;$cron=true;CSM_Plugin::activation_redirect();$cron=false;check(get_transient('_csm_activation_redirect_12')===true,'Background requests do not consume the pending navigation');
 try{CSM_Plugin::activation_redirect();throw new RuntimeException('Redirect missing');}catch(RedirectCaptured $r){check($r->getMessage()===admin_url('tools.php?page=trickle&view=settings'),'Activation redirects to the Settings tab');}
 check(!get_transient('_csm_activation_redirect_12'),'Redirect is consumed before navigation');CSM_Plugin::activation_redirect();
 $_REQUEST['activate-multi']=true;CSM_Plugin::activate();check(!$transients,'Bulk activation does not schedule a redirect');unset($_REQUEST['activate-multi']);
 CSM_Plugin::activate(true);check(!$transients,'Network activation does not schedule a redirect');
 $ajax=true;CSM_Plugin::activate();$ajax=false;check(!$transients,'AJAX activation does not hijack a later request');
 $admin=false;CSM_Plugin::activate();$admin=true;$allowed=false;CSM_Plugin::activate();$allowed=true;check(!$transients,'Non-admin and unauthorized contexts do not schedule navigation');
 CSM_Plugin::activate();$now=161;CSM_Plugin::activation_redirect();check(!get_transient('_csm_activation_redirect_12'),'Abandoned activation does not cause a delayed redirect');$transients=[];
 file_put_contents(WPMU_PLUGIN_DIR.'/0-trickle-loader.php','<?php // unrelated');$caught=false;
 try{CSM_Plugin::activate();}catch(RuntimeException $e){$caught=true;}
 check($caught&&!$transients,'Failed loader installation never schedules a success redirect');
}finally{if(is_file(WPMU_PLUGIN_DIR.'/0-trickle-loader.php'))unlink(WPMU_PLUGIN_DIR.'/0-trickle-loader.php');if(is_dir(WPMU_PLUGIN_DIR))rmdir(WPMU_PLUGIN_DIR);}
