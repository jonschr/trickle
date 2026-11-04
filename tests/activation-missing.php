<?php
// Isolated activation and real diagnostic logging; no sites, databases or network.
$fixture=sys_get_temp_dir().'/trickle-activation-'.bin2hex(random_bytes(6));
mkdir($fixture.'/wp-admin/includes',0700,true);file_put_contents($fixture.'/wp-admin/includes/plugin.php','<?php');
define('ABSPATH',$fixture.'/');define('CSM_FILE',$fixture.'/trickle/trickle.php');
$options=['active_plugins'=>['trickle/trickle.php']];
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value){$GLOBALS['options'][$key]=$value;return true;}
function plugin_basename($file){return 'trickle/trickle.php';}
function wp_clean_plugins_cache($clear){}
function get_plugins(){return ['trickle/trickle.php'=>[], 'present/plugin.php'=>[]];}
function validate_plugin_requirements($plugin){return true;}
function is_wp_error($value){return false;}
function add_filter(...$args){}
function remove_filter(...$args){}
function wp_parse_url($text){return parse_url($text);}
function wp_strip_all_tags($text){return strip_tags($text);}
function wp_check_invalid_utf8($text,$strip){return $text;}
class CSM_Support {static function private_dir(){return $GLOBALS['fixture'];}static function json($v){return json_encode($v)."\n";}}
class CSM_Jobs {static function save($job){$GLOBALS['saved']=$job;}}
class CSM_Progress {static function update(...$args){}}
require __DIR__.'/../includes/files.php';require __DIR__.'/../includes/log.php';require __DIR__.'/../includes/activation.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
try{
    $missing=['missing-one/plugin.php','missing-two/plugin.php'];
    $job=['id'=>str_repeat('a',32),'active_plugins_before'=>['trickle/trickle.php'],'source_active_plugins'=>array_merge(['present/plugin.php','trickle/trickle.php'],$missing)];
    CSM_Activation::apply($job);
    check($job['activation_missing']===$missing&&$GLOBALS['saved']['activation_missing']===$missing,'Missing filenames persist in the transfer result');
    check(get_option('active_plugins')===['present/plugin.php','trickle/trickle.php'],'Only installed source plugins are activated');
    $logs=CSM_Log::entries();
    check(array_column(array_column($logs,'context'),'component')===$missing,'Every missing filename survives diagnostic filtering');
    check(count(array_filter($logs,static fn($e)=>$e['context']['transfer_id']===$job['id']))===2,'Missing-plugin diagnostics identify the transfer');
}finally{
    foreach(glob($fixture.'/*')as$file){if(is_file($file))unlink($file);}
    unlink($fixture.'/wp-admin/includes/plugin.php');rmdir($fixture.'/wp-admin/includes');rmdir($fixture.'/wp-admin');rmdir($fixture);
}
