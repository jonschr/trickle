<?php
// Run only on the isolated QA-C site: cove wp trickle-qa-c eval-file tests/self-connections.php
if (wp_parse_url(get_option('siteurl'),PHP_URL_HOST)!=='trickle-qa-c.localhost' || get_option('_csm_control') || get_option('_csm_active_job')) { throw new RuntimeException('Self-connection checks require idle QA-C.'); }
$checks=0;
$check=static function($ok,$message)use(&$checks){if(!$ok){throw new RuntimeException($message);} $checks++;echo "PASS $message\n";};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$url=get_option('siteurl');
$check(CSM_Controller::is_self($url.'/'),'Canonical URL and trailing slash identify this site');
$check(CSM_Controller::is_self(str_replace('https://','http://',$url)),'HTTP and HTTPS variants identify the same site');
$upper=preg_replace_callback('#://([^/]+)#',static fn($m)=>'://'.strtoupper($m[1]),$url);
$check(CSM_Controller::is_self($upper),'Hostname matching is case insensitive');
$check(!CSM_Controller::is_self($url.'/other-wordpress'),'A separate installation path remains a valid remote');
$check(!CSM_Controller::is_self(str_replace(':8453',':8454',$url)),'A different nonstandard port remains a valid remote');
$fake=static fn()=> 'https://Case.Example/wordpress';
add_filter('option_siteurl',$fake);
try {
    $check(CSM_Controller::is_self('http://case.example:80/wordpress/') && CSM_Controller::is_self('https://case.example:443/wordpress'),'Default ports and protocol differences are normalized');
    $check(!CSM_Controller::is_self('https://case.example/other-install'),'Other paths on the same host are not conflated');
} finally {remove_filter('option_siteurl',$fake);}
$alias=['url'=>'https://alias.example','key'=>str_repeat('a',64)];
$check($reject(static fn()=>CSM_Controller::assert_remote($alias,['canonical_url'=>$url])),'A remote alias reporting this canonical installation is rejected');
$check($reject(static fn()=>CSM_Controller::assert_remote($alias,['instance_fingerprint'=>CSM_Controller::fingerprint()])),'An alias resolving to the same installation fingerprint is rejected');
CSM_Controller::assert_remote($alias,['instance_fingerprint'=>str_repeat('b',64)]);
$check(true,'A genuinely different installation fingerprint is allowed');
$settings=['_csm_peers','_csm_peer','_csm_recents','_csm_control'];$saved=[];
foreach($settings as $name){$saved[$name]=get_option($name,null);}
try {
    $id=CSM_Controller::site_id($url);$self=['id'=>$id,'url'=>$url,'key'=>get_option('_csm_key'),'name'=>'Accidental self connection'];
    $peers=get_option('_csm_peers',[]);$peers[$id]=$self;
    $alias_id=CSM_Controller::site_id($alias['url']);$peers[$alias_id]=$alias+['id'=>$alias_id,'name'=>'Self alias','instance_fingerprint'=>CSM_Controller::fingerprint()];
    update_option('_csm_peers',$peers,false);update_option('_csm_peer',$self,false);
    $recents=get_option('_csm_recents',[]);$recents['self-check']=['id'=>'self-check','remote_id'=>$id,'remote_url'=>$url];update_option('_csm_recents',$recents,false);
    $state=CSM_Controller::state();
    $check(!in_array($id,array_column($state['connections'],'id'),true)&&!in_array($alias_id,array_column($state['connections'],'id'),true),'Saved self URLs and known self aliases are hidden from the selector');
    $check(!in_array('self-check',array_column($state['recents'],'id'),true),'Self-transfer presets are hidden from recent transfers');
    $check($state['selected']!==$id,'A saved self selection is not selected by default');
    $check($reject(static fn()=>CSM_Controller::execute('select',['site'=>$id])),'The backend refuses selecting a saved self connection');
    foreach(['push','pull'] as $direction){
        $check($reject(static fn()=>CSM_Controller::execute('prepare',['site'=>$id,'direction'=>$direction,'selection'=>['plugins'=>['csm-fixture']]]))&&!get_option('_csm_active_job'),'Self '.$direction.' is rejected before creating destination staging');
    }
    $check($reject(static fn()=>CSM_Controller::execute('connect',['pairing'=>str_replace('https://','http://',$url)."\n".get_option('_csm_key')])),'Pasting this site’s code through an HTTP variant is refused');
    $job_id=str_repeat('c',32);update_option('_csm_control',['id'=>$job_id,'peer'=>$self,'direction'=>'pull','selection'=>[],'created'=>time()],false);
    foreach(['transfer','commit'] as $op){$check($reject(static fn()=>CSM_Controller::execute($op,['id'=>$job_id,'kind'=>'files'])),'A retained self session cannot run '.$op);}
} finally {
    foreach($saved as $name=>$value){if($value===null){delete_option($name);}else{update_option($name,$value,false);}}
}
echo "$checks self-connection checks passed.\n";
