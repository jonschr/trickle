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
$a=['url'=>'https://a.test','http_auth'=>['username'=>'fixture-private-user','password'=>'fixture-private-password'],'key'=>'fixture-private-a','name'=>'A','icon'=>'','instance_fingerprint'=>''];
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

$self=['id'=>CSM_Controller::site_id(site_url()),'url'=>site_url(),'key'=>'fixture-private-self','name'=>'Matching URL'];
$alias=['id'=>CSM_Controller::site_id('https://alias.test'),'url'=>'https://alias.test','key'=>'fixture-private-alias','name'=>'Matching identity','instance_fingerprint'=>CSM_Controller::fingerprint()];
$options['_csm_peers']=[$self['id']=>$self,$alias['id']=>$alias,$bid=>$b];$options['_csm_peer']=$self;
$state=CSM_Controller::state();
$check($state['selected']===$self['id'],'A matching URL silently changed the selection.');
$check(count($state['connections'])===3,'Matching URLs or fingerprints disappeared from the selector.');
$check(!str_contains(json_encode($state),'fixture-private'),'Matching connection state exposed credentials.');
$check($call('select',['site'=>$alias['id']])['selected']===$alias['id'],'A matching fingerprint prevented explicit selection.');
$call('rename_connection',['site'=>$alias['id'],'name'=>'Keep this connection']);
$check(CSM_Controller::state()['selected']===$alias['id'],'Renaming a matching connection lost the selection.');
$call('select',['site'=>$bid]);$options['siteurl']=$b['url'];
$check(CSM_Controller::state()['selected']===$bid,'A site URL change silently deselected its saved remote.');
$options['siteurl']=site_url();
foreach([$self,$alias] as $peer){
    $rejected=false;try{CSM_Controller::assert_remote($peer);}catch(RuntimeException $e){$rejected=true;}
    $check($rejected,'Keeping a connection selected removed the transfer identity guard.');
}
$options['_csm_peers']=[];$options['_csm_peer']=$self;
$check(CSM_Controller::state()['selected']===$self['id'],'Legacy matching selections were lost.');
echo "PASS Matching saved URLs, stored identities, URL changes and legacy selections stay visible and selected; transfer identity validation remains intact.\n";
