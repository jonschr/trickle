<?php
// Isolated saved-connection persistence; no site bootstrapping or network requests.
define('ABSPATH', '/fixture/');
$options = ['siteurl'=>'https://local.test','home'=>'https://local.test'];
function get_option($key,$default=false){return $GLOBALS['options'][$key] ?? $default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;}
function delete_option($key){unset($GLOBALS['options'][$key]);}
function wp_cache_delete(...$args){}
function wp_parse_url($url){return parse_url($url);}
function site_url(){return 'https://local.test';}
function home_url(){return site_url();}
function wp_salt($scheme){return 'fixture';}
function sanitize_text_field($value){return strip_tags($value);}
class CSM_Transport {
    static function url($url){return rtrim($url,'/');}
    static function request(...$args){throw new LogicException('Connection management must stay local.');}
}
require __DIR__.'/../includes/controller.php';
$operate=new ReflectionMethod(CSM_Controller::class,'operate');
$call=static fn($op,$data)=>$operate->invoke(null,$op,$data);
$check=static function($ok,$message){if(!$ok)throw new LogicException($message);};
$a=['url'=>'https://a.test','key'=>'fixture-private-a','name'=>'A','icon'=>'','instance_fingerprint'=>''];
$b=['url'=>'https://b.test','key'=>'fixture-private-b','name'=>'B','icon'=>'','instance_fingerprint'=>''];
$aid=CSM_Controller::site_id($a['url']);$bid=CSM_Controller::site_id($b['url']);$a['id']=$aid;$b['id']=$bid;
$options['_csm_peers']=[$aid=>$a,$bid=>$b];$options['_csm_peer']=$a;
$options['_csm_backups']=['keep-backup'];$options['_csm_recents']=['keep-history'=>['remote_url'=>$a['url']]];
$result=$call('rename_connection',['site'=>$aid,'name'=>' <b>Harrison dev</b> ','url'=>'https://attacker.test','key'=>'changed']);
$check($options['_csm_peers'][$aid]===array_replace($a,['name'=>'Harrison dev']),'Rename changed immutable connection data.');
$check($options['_csm_peer']===$options['_csm_peers'][$aid],'Selected record lost its new label.');
$check(!str_contains(json_encode($result),'fixture-private'),'Response exposed connection keys.');
$call('rename_connection',['site'=>$bid,'name'=>'Other']);
$check($options['_csm_peer']['id']===$aid,'Renaming another site switched the selection.');
foreach(['', '  ', '<b></b>', [], str_repeat('x',481)] as $name){
    $before=$options;
    try{$call('rename_connection',['site'=>$aid,'name'=>$name]);throw new LogicException('Accepted invalid name.');}catch(RuntimeException $e){}
    $check($options===$before,'Invalid rename mutated state.');
}
foreach(['_csm_control','_csm_active_job'] as $key){
    $options[$key]=['id'=>'retained'];
    foreach(['rename_connection','remove_connection'] as $op){
        $before=$options;
        try{$call($op,['site'=>$aid,'name'=>'Blocked']);throw new LogicException('Managed a connection during retained work.');}catch(RuntimeException $e){}
        $check($options===$before,'Retained-work guard mutated state.');
    }
    unset($options[$key]);
}
$call('remove_connection',['site'=>$bid]);
$check($options['_csm_peer']['id']===$aid && !isset(CSM_Controller::peers()[$bid]),'Nonselected removal changed selection or resurrected peer.');
$options['_csm_peers'][$bid]=$b;
$call('remove_connection',['site'=>$aid]);
$check($options['_csm_peer']===$b && !isset(CSM_Controller::peers()[$aid]),'Selected removal failed to choose remaining site or recreated legacy record.');
$call('remove_connection',['site'=>$bid]);
$check(CSM_Controller::peers()===[] && !get_option('_csm_peer'),'Last connection reappeared.');
$check($options['_csm_backups']===['keep-backup'] && isset($options['_csm_recents']['keep-history']),'Removal changed backup/history.');
try{$call('remove_connection',['site'=>'missing']);throw new LogicException('Removed missing site.');}catch(RuntimeException $e){}
echo "PASS Local rename, immutable URL/key, private responses, validation, retained-work guards, removal, fallback selection and backup/history preservation.\n";
