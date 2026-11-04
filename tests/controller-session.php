<?php
// Actual controller-session preservation with isolated WordPress API doubles.
define('ABSPATH','/fixture/');define('DAY_IN_SECONDS',86400);define('LOGGED_IN_COOKIE','fixture_logged_in');
class WP_User {public $ID;public $user_login='operator';public $user_pass='original-hash';public $display_name='Operator';function __construct($id){$this->ID=$id;}function set_role($r){$GLOBALS['role']=$r;}}
class WP_Session_Tokens {static $updated=[];static $created=0;static function get_instance($id){$GLOBALS['token_user']=$id;return new self;}function get($t){return ['ua'=>'fixture-agent','login'=>123,'custom_policy'=>'retained'];}function update($t,$s){self::$updated[$t]=$s;}function create($e){self::$created++;return 'new-token';}}
class FakeDB {public $users='wp_users',$usermeta='wp_usermeta';function update($t,$data,$where){$GLOBALS['credentials']=$data;return 1;}}
$wpdb=new FakeDB;
function clean_user_cache($id){}
function get_user_by($key,$value){return new WP_User($GLOBALS['destination_id']);}
function wp_get_session_token(){return $GLOBALS['token'];}
function wp_set_current_user($id){$GLOBALS['current_user']=$id;}
function wp_set_auth_cookie($id,$remember,$secure,$token){$GLOBALS['cookie_token']=$token;}
function wp_generate_auth_cookie($id,$expiration,$scheme,$token){return $id.'|'.$token;}
function wp_create_nonce($action){return 'fixture-nonce';}
require __DIR__.'/../includes/plugin.php';
$method=new ReflectionMethod(CSM_Plugin::class,'retain_controller');$actor=new WP_User(7);$job=['database'=>[['target'=>'wp_users'],['target'=>'wp_usermeta']]];
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";};
foreach([7,21] as $id){
 $destination_id=$id;$token='existing-verified-token';WP_Session_Tokens::$updated=[];WP_Session_Tokens::$created=0;
 $result=$method->invoke(null,$actor,$job);
 $check($result['nonce']==='fixture-nonce'&&$cookie_token===$token&&WP_Session_Tokens::$created===0,"User $id retains the authenticated token instead of rotating it");
 $check($token_user===$id&&$current_user===$id&&isset(WP_Session_Tokens::$updated[$token]),"Session and cookie use the destination user ID $id");
 $check(WP_Session_Tokens::$updated[$token]['custom_policy']==='retained'&&WP_Session_Tokens::$updated[$token]['login']===123,'Session metadata survives without resetting the original login');
 $check($credentials['user_pass']==='original-hash'&&$role==='administrator','Authenticated operator credentials and role survive replacement');
}
$token='captured-token';$method->invoke(null,$actor,$job,['custom_policy'=>'captured-before-swap','login'=>456]);$check(WP_Session_Tokens::$updated[$token]['custom_policy']==='captured-before-swap'&&WP_Session_Tokens::$updated[$token]['login']===456,'Pre-swap session metadata takes precedence over imported user metadata');
$token='';$method->invoke(null,$actor,$job);$check($cookie_token==='new-token'&&WP_Session_Tokens::$created===1,'Operators without an existing cookie receive a new session');
$check($method->invoke(null,null,$job)===[]&&$method->invoke(null,$actor,['database'=>[['target'=>'wp_posts']]])===[],'Unauthenticated and non-user transfers do not create a session');
