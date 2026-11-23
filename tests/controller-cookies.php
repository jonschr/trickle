<?php
// Real WordPress cookie signatures and session-token implementation, with isolated storage.
// php tests/controller-cookies.php /path/to/wordpress
$core=realpath($argv[1]??'').'/wp-includes/';
if(!is_file($core.'pluggable.php'))throw new RuntimeException('Pass a WordPress core directory.');
define('ABSPATH','/fixture/');define('DAY_IN_SECONDS',86400);define('HOUR_IN_SECONDS',3600);define('LOGGED_IN_COOKIE','fixture_logged_in');define('AUTH_COOKIE','fixture_auth');define('SECURE_AUTH_COOKIE','fixture_secure_auth');
$_SERVER['REQUEST_METHOD']='GET';$users=[];$sessions=[];
function apply_filters($hook,$value,...$args){return $value;}
function do_action(...$args){}
function wp_salt($scheme){return 'fixture-cookie-salt-'.$scheme;}
function wp_doing_ajax(){return false;}
function is_ssl(){return true;}
function get_user_meta($id,$key,$single=false){return $GLOBALS['sessions'][$id]??[];}
function update_user_meta($id,$key,$value){$GLOBALS['sessions'][$id]=$value;}
function delete_user_meta($id,$key){unset($GLOBALS['sessions'][$id]);}
function clean_user_cache($id){}
function wp_set_current_user($id){$GLOBALS['current_id']=$id;}
function wp_set_auth_cookie(...$args){throw new RuntimeException('Existing cookies must not be replaced.');}
function wp_create_nonce($action){return 'nonce-'.$GLOBALS['current_id'];}
function wp_get_session_token(){return wp_parse_auth_cookie('', 'logged_in')['token']??'';}
class WP_User{public $ID,$user_login,$user_pass,$display_name='Operator';function __construct($id){$this->ID=$id;foreach($GLOBALS['users'][$id] as $k=>$v)$this->$k=$v;}function set_role($role){}}
function get_user_by($field,$value){foreach($GLOBALS['users']as$id=>$user){if(($field==='login'?$user['user_login']:$id)===$value)return new WP_User($id);}return false;}
function get_userdata($id){return isset($GLOBALS['users'][$id])?new WP_User($id):false;}
class FakeCookieDB{public $users='wp_users',$usermeta='wp_usermeta';function update($table,$data,$where){$GLOBALS['users'][$where['ID']]=array_merge($GLOBALS['users'][$where['ID']],$data);return 1;}}
$wpdb=new FakeCookieDB;
require $core.'class-wp-session-tokens.php';require $core.'class-wp-user-meta-session-tokens.php';require $core.'pluggable.php';require __DIR__.'/../includes/class-csm-plugin.php';
$preserve=new ReflectionMethod(CSM_Plugin::class,'retain_controller');$job=['database'=>[['target'=>'wp_users'],['target'=>'wp_usermeta']]];
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
foreach([7,21]as$destination){foreach([1,14]as$days){
 $users=[7=>['user_login'=>'operator','user_pass'=>'local-password-hash-1234']];$sessions=[];$actor=new WP_User(7);$expiration=time()+$days*DAY_IN_SECONDS;$token='local-browser-token';
 WP_Session_Tokens::get_instance(7)->update($token,['expiration'=>$expiration,'login'=>123,'ua'=>'local-browser']);
 $_COOKIE=[LOGGED_IN_COOKIE=>wp_generate_auth_cookie(7,$expiration,'logged_in',$token),AUTH_COOKIE=>wp_generate_auth_cookie(7,$expiration,'auth',$token),SECURE_AUTH_COOKIE=>wp_generate_auth_cookie(7,$expiration,'secure_auth',$token)];$cookies=$_COOKIE;
 $metadata=WP_Session_Tokens::get_instance(7)->get($token);
 $users=[$destination=>['user_login'=>'operator','user_pass'=>'imported-password-hash-5678']];$sessions=[];
 $preserve->invoke(null,$actor,$job,$metadata);
 foreach(['logged_in'=>LOGGED_IN_COOKIE,'auth'=>AUTH_COOKIE,'secure_auth'=>SECURE_AUTH_COOKIE]as$scheme=>$name)check(wp_validate_auth_cookie($cookies[$name],$scheme)===$destination,"Original $scheme cookie authenticates matching local user $destination after import");
 check($_COOKIE===$cookies,'Browser cookies and their Remember Me behavior remain untouched');
 check(WP_Session_Tokens::get_instance($destination)->get($token)['expiration']===$expiration,"Original $days-day session lifetime survives");
}}
