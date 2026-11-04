<?php
// Real controller + signed remote estimate routes. Idle QA-A/C only; no transfer or selection changes.
if(realpath(ABSPATH)!==realpath(getenv('HOME').'/Cove/Sites/trickle-qa-c.localhost/public')||get_option('_csm_control')||get_option('_csm_active_job'))throw new RuntimeException('Idle QA-C required');
$before=[get_option('_csm_control'),get_option('_csm_active_job'),get_option('_csm_peer'),get_option('_csm_recents'),get_option('_csm_backups')];
$result=CSM_Controller::execute('estimates',['site'=>'https://trickle-qa-a.localhost:8453']);
foreach(['local','remote']as$site){
 if(!is_array($result[$site]['bytes']??null)||!is_array($result[$site]['items']??null))throw new RuntimeException('Missing estimate response for '.$site);
 if((isset($result[$site]['queue'])||isset($result[$site]['active']))||str_contains(wp_json_encode($result[$site]),'/Users/'))throw new RuntimeException('Private scan state leaked');
 echo "PASS $site estimates return public metadata through actual controller/RPC routes\n";
}
$after=[get_option('_csm_control'),get_option('_csm_active_job'),get_option('_csm_peer'),get_option('_csm_recents'),get_option('_csm_backups')];
if($before!==$after)throw new RuntimeException('Size preview changed migration state');
echo "PASS Size previews do not create a transfer or change selected sites, recents or backups\n";
