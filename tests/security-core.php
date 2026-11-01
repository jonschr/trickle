<?php
// Offline auth regression using real core APIs with in-memory database/session fixtures.
// Usage: php tests/security-core.php /path/to/wordpress [admin|ajax|anonymous|invalid|bad-nonce]
$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root . '/wp-includes/pluggable.php')) { throw new RuntimeException('Pass a WordPress core directory; no wp-config or database is loaded.'); }
$core = $root . '/wp-includes/';
$mode = $argv[2] ?? 'admin';
if (!in_array($mode, ['admin','ajax','anonymous','invalid','bad-nonce'], true)) { throw new RuntimeException('Unknown test mode.'); }
define('ABSPATH',dirname($core).'/'); define('WPINC','wp-includes'); define('WP_PLUGIN_URL','https://example.test/wp-content/plugins'); define('HOUR_IN_SECONDS',3600);define('DAY_IN_SECONDS',86400); define('WP_ADMIN',true);
foreach(['AUTH','SECURE_AUTH','LOGGED_IN','NONCE'] as $scheme){define($scheme.'_KEY',$scheme.'-key-test');define($scheme.'_SALT',$scheme.'-salt-test');}
$GLOBALS['blog_id']=1;
$GLOBALS['wpdb']=new class {public $prefix='wp_';public function get_blog_prefix($site=null){return 'wp_';}};

function __($text){return $text;}
function get_site_option($key){return 'https://example.test';}
function get_option($key,$default=false){if($key==='active_plugins')return ['trickle/trickle.php','security/security.php'];if($key==='template'||$key==='stylesheet')return 'theme';if($key==='wp_user_roles')return ['administrator'=>['name'=>'Administrator','capabilities'=>['manage_options'=>true]]];return in_array($key,['siteurl','home'])?'https://example.test':$default;}
function get_metadata($type,$id,$key='',$single=false){if($key==='wp_capabilities')return ['administrator'=>true];if($key==='session_tokens')return [hash('sha256','test-session')=>['expiration'=>time()+3600]];return '';}
function get_user_by($field,$value){return new WP_User((object)['ID'=>1,'user_login'=>'admin','user_pass'=>'$P$Btest1234abcdefghijk','user_email'=>'admin@example.test','user_url'=>'','display_name'=>'Admin']);}
function wp_cache_get($key,$group=''){if($group==='users')return (object)['ID'=>1,'user_login'=>'admin','user_pass'=>'$P$Btest1234abcdefghijk','user_email'=>'admin@example.test','user_url'=>'','display_name'=>'Admin'];return false;}

require $core.'plugin.php'; require $core.'load.php'; require $core.'user.php';require $core.'capabilities.php';require $core.'class-wp-user.php';require $core.'class-wp-roles.php';require $core.'class-wp-role.php';require $core.'class-wp-session-tokens.php';require $core.'class-wp-user-meta-session-tokens.php';require $core.'default-constants.php';require $core.'pluggable.php';
define('WP_PLUGIN_DIR',dirname($core).'/wp-content/plugins');define('__CSM_PLUGIN__','trickle/trickle.php');
add_filter('determine_current_user','wp_validate_auth_cookie');add_filter('determine_current_user','wp_validate_logged_in_cookie',20);
$_SERVER['REQUEST_METHOD']='POST';
$_COOKIE['wordpress_'.md5('https://example.test')]=wp_generate_auth_cookie(1,time()+3600,'auth','test-session');
$_COOKIE['wordpress_logged_in_'.md5('https://example.test')]=wp_generate_auth_cookie(1,time()+3600,'logged_in','test-session');
$_SERVER['SCRIPT_FILENAME']=dirname($core).'/wp-admin/tools.php';$_SERVER['REQUEST_METHOD']='GET';$_GET=['page'=>'trickle'];
if($mode==='anonymous')$_COOKIE=[];
if($mode==='invalid')foreach($_COOKIE as &$value)$value.='x';
if(in_array($mode,['ajax','bad-nonce'],true)){
define('DOING_AJAX',true);$_SERVER['SCRIPT_FILENAME']=dirname($core).'/wp-admin/admin-ajax.php';$_SERVER['REQUEST_METHOD']='POST';$_GET=[];
$nonce=substr(wp_hash(wp_nonce_tick('csm_admin').'|csm_admin|1|test-session','nonce'),-12,10);
$_POST=['action'=>'csm_admin','op'=>'status','nonce'=>$mode==='bad-nonce'?'bad':$nonce];$_REQUEST=$_POST;
}
require __DIR__ . '/../includes/loader.php';
$expected = in_array($mode, ['admin','ajax'], true);
$isolated = apply_filters('option_active_plugins', ['trickle/trickle.php','security/security.php']) === ['trickle/trickle.php'];
if ($isolated !== $expected) { throw new RuntimeException('Unexpected isolation for ' . $mode); }
echo 'PASS real WordPress authentication: ' . $mode . "\n";
