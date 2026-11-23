<?php
function wp_json_encode($value,$flags=0,$depth=512){return json_encode($value,$flags,$depth);}
// Real comparison-marker cleanup and controller filtering. Isolated private files; no network or sites.
$root=sys_get_temp_dir().'/trickle-comparison-cleanup-'.bin2hex(random_bytes(5));mkdir($root.'/public',0700,true);
define('ABSPATH',$root.'/public/');define('DAY_IN_SECONDS',86400);
$options=['siteurl'=>'https://local.test','home'=>'https://local.test'];$calls=[];$remote=[];$write=true;
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}function update_option($k,$v,...$args){$GLOBALS['options'][$k]=$v;}
function wp_cache_delete(...$args){}
function wp_salt($x){return 'comparison-fixture';}function wp_parse_url($s,$component=-1){return parse_url($s,$component);}function site_url(){return 'https://local.test';}function home_url(){return site_url();}
class CSM_Transport{
 static function url($s){return rtrim($s,'/');}
 static function request($peer,$op,$data=[],...$args){$GLOBALS['calls'][]=[$op,$data['id']??''];if($op==='version')return ['can_update_trickle'=>$GLOBALS['write']];if($op==='progress')return $GLOBALS['remote'][$data['id']]??[];if($op==='cancel')return [];if($op==='bundle_discard')return ['comparison_cleared'=>CSM_Segments::release_comparison($data['id'])];throw new RuntimeException('Unexpected operation');}
}
foreach(['support','access','segments','controller']as$file)require __DIR__.'/../includes/class-csm-'.$file.'.php';
$dir=CSM_Support::private_dir();
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function owner($id,$extra=[]){file_put_contents($GLOBALS['dir'].'/segment-'.$id.'-files.source.owner',json_encode($extra+['credential'=>'local','created'=>time()]));}
try{
 $id=str_repeat('a',32);owner($id);check(CSM_Segments::release_comparison($id)&&!file_exists($dir.'/segment-'.$id.'-files.source.owner'),'Completed read-only marker can be removed');
 $GLOBALS['_csm_credential']=['id'=>'other'];$denied=false;try{CSM_Segments::assert_progress_owner($id);}catch(RuntimeException $e){$denied=$e->getCode()===403;}check($denied,'Releasing a comparison retains its progress access ownership');$denied=false;try{CSM_Segments::lease($id);}catch(RuntimeException $e){$denied=$e->getCode()===403;}unset($GLOBALS['_csm_credential']);check($denied,'Another credential cannot reuse a released comparison ID');
 foreach(['','.json','.part','.build']as$suffix){owner($id);$path=$dir.'/segment-'.$id.'-database.source'.$suffix;file_put_contents($path,'retained');check(!CSM_Segments::release_comparison($id)&&file_get_contents($path)==='retained','Source artifact '.$suffix.' blocks cleanup even on the other stream');unlink($path);}
 owner($id,['selection'=>'pinned']);check(!CSM_Segments::release_comparison($id),'Pinned source ownership is retained');
 owner($id);$options['_csm_job_'.$id]=['status'=>'applying'];check(!CSM_Segments::release_comparison($id),'Destination recovery job blocks cleanup');unset($options['_csm_job_'.$id]);
 $options['_csm_control']=['id'=>$id];check(!CSM_Segments::release_comparison($id),'Controller session blocks cleanup');unset($options['_csm_control']);
 owner($id,['credential'=>'foreign']);$denied=false;try{CSM_Segments::release_comparison($id);}catch(RuntimeException $e){$denied=$e->getCode()===403;}check($denied,'Another credential cannot release the marker');
 owner($id);$lock=fopen($dir.'/segment-'.$id.'-files.source.lock','c');flock($lock,LOCK_EX);check(!CSM_Segments::release_comparison($id),'Active source lock prevents cleanup');flock($lock,LOCK_UN);fclose($lock);
 $options['_csm_peers']=['remote'=>['id'=>'remote','url'=>'https://remote.test','name'=>'Remote']];
 $ids=array_map(static fn($c)=>str_repeat($c,32),['b','c','d','e']);
 foreach($ids as$i){file_put_contents($dir.'/component-folder-fixture.comparison-'.$i.'.json','{}');owner($i);$remote[$i]=['id'=>$i,'state'=>'complete','phase'=>'Plugin and theme checksums ready','events'=>[['phase'=>'Comparing file checksums']]];}
 $remote[$ids[1]]['events'][]=['phase'=>'Building resumable source stream'];$remote[$ids[2]]['state']='running';$options['_csm_recents']=[$ids[3]=>['status'=>'staging']];
 $method=new ReflectionMethod(CSM_Controller::class,'clear_update_comparisons');$result=$method->invoke(null,['site'=>'remote']);check($result['cleared']===1&&$result['continue'],'Only completed comparison state is released in a bounded batch');
 $result=$method->invoke(null,['site'=>'remote','offset'=>$result['checked']]);check($result['cleared']===0&&!$result['continue'],'Running comparisons are retained');
 $discarded=array_values(array_map(static fn($c)=>$c[1],array_filter($calls,static fn($c)=>$c[0]==='bundle_discard')));check($discarded===[$ids[0]],'Migration IDs and any source-stream history never reach discard');
 $write=false;$denied=false;try{$method->invoke(null,['site'=>'remote']);}catch(RuntimeException $e){$denied=true;}check($denied,'Read-only update credentials cannot run the maintenance action');
 $options['_csm_control']=['id'=>$id];$before=count($calls);try{$method->invoke(null,['site'=>'remote']);}catch(RuntimeException $e){}check(count($calls)===$before,'Retained local transfers block maintenance before sending remote requests');
 unset($options['_csm_control']);$foreign=str_repeat('f',32);owner($foreign,['credential'=>'remote-owner','selection'=>'retained']);file_put_contents($dir.'/segment-'.$foreign.'-files.source','snapshot');file_put_contents($dir.'/segment-'.$foreign.'-files.incoming','destination staging');mkdir($dir.'/retained-backup');file_put_contents($dir.'/retained-backup/original','backup');
 check(!in_array($foreign,array_column(CSM_Segments::retained_sources(),'id'),true),'Other credentials cannot inspect retained source preparations');
 $operate=new ReflectionMethod(CSM_Controller::class,'operate');$listed=$operate->invoke(null,'retained_sources',[]);check(in_array($foreign,array_column($listed['sources'],'id'),true),'Native administrator maintenance can inspect incoming source preparations');
 $options['_csm_active_job']=$foreign;$denied=false;try{$operate->invoke(null,'discard_source_preparation',['id'=>$foreign]);}catch(RuntimeException $e){$denied=true;}check($denied&&is_file($dir.'/segment-'.$foreign.'-files.source'),'Source management refuses to discard a destination recovery job');unset($options['_csm_active_job']);
 $operate->invoke(null,'discard_source_preparation',['id'=>$foreign]);check(!is_file($dir.'/segment-'.$foreign.'-files.source'),'An explicit source cancellation discards its export');check(file_get_contents($dir.'/segment-'.$foreign.'-files.incoming')==='destination staging'&&file_get_contents($dir.'/retained-backup/original')==='backup','Source cancellation keeps destination staging and backups');check(!isset($GLOBALS['_csm_credential']),'Administrator maintenance restores its authentication context');
 $GLOBALS['_csm_credential']=['id'=>'remote-owner'];check(CSM_Support::is_cancelled($foreign),'Cancellation is visible to the original remote credential');unset($GLOBALS['_csm_credential']);
}finally{CSM_Support::remove_tree($dir);CSM_Support::remove_tree($root);}
