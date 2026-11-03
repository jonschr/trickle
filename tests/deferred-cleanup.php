<?php
// Offline queue tests; no WordPress installation or remote files are touched.
define('ABSPATH', '/fixture/'); define('DAY_IN_SECONDS', 86400);
$options=[]; $events=[]; $calls=0; $fail=true;
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function add_option($key,$value,...$args){if(isset($GLOBALS['options'][$key]))return false;$GLOBALS['options'][$key]=$value;return true;}
function delete_option($key){unset($GLOBALS['options'][$key]);}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
function wp_next_scheduled($hook,$args){return $GLOBALS['events'][json_encode([$hook,$args])]??false;}
function wp_schedule_single_event($time,$hook,$args){$GLOBALS['events'][json_encode([$hook,$args])]=$time;}
function wp_clear_scheduled_hook($hook,$args){unset($GLOBALS['events'][json_encode([$hook,$args])]);}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function site_url(){return 'https://local.test';} function home_url(){return site_url();}
class CSM_Transport{static function url($url){return $url;}static function request(...$args){$GLOBALS['calls']++;if($GLOBALS['fail'])throw new RuntimeException('Source is busy');return [];}}
class CSM_Log{static function write(...$args){}}
require __DIR__.'/../includes/controller.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$id=str_repeat('a',32);$peer=['url'=>'https://source.test'];$pid=CSM_Controller::site_id($peer['url']);
$options['_csm_peers']=[$pid=>$peer];
CSM_Controller::defer_source_cleanup($peer,$id);CSM_Controller::defer_source_cleanup($peer,$id);
check(count($events)===1&&isset($options['_csm_cleanup_'.$id]),'Cleanup is durable and deduplicated');
$events=[];CSM_Controller::retry_source_cleanup($pid,$id);
check(count($events)===1&&isset($options['_csm_cleanup_'.$id]),'Busy source preserves cleanup and schedules another attempt');
$before=$calls;CSM_Controller::retry_source_cleanup('wrong',$id);check($calls===$before,'Wrong peer cannot run queued cleanup');
$events=[];$fail=false;CSM_Controller::retry_source_cleanup($pid,$id);
check(!isset($options['_csm_cleanup_'.$id])&&!$events,'Successful cleanup removes the pending record');
$fail=true;CSM_Controller::defer_source_cleanup($peer,$id);$options['_csm_cleanup_'.$id]['created']=time()-86401;$events=[];
CSM_Controller::retry_source_cleanup($pid,$id);check(!$events,'Cleanup does not retry indefinitely beyond one day');
$rejected=false;try{CSM_Controller::defer_source_cleanup($peer,'../bad');}catch(RuntimeException $e){$rejected=true;}
check($rejected,'Invalid job IDs cannot enter the cleanup queue');
