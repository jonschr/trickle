<?php
/** Read JSON metadata through PUC and exercise WordPress's native upgrader on idle QA-C. */
if (!defined('ABSPATH') || wp_parse_url(get_option('home'),PHP_URL_HOST)!=='trickle-qa-c.localhost' || get_option('_csm_control') || get_option('_csm_active_job')) { throw new RuntimeException('Requires idle QA-C.'); }
global $wp_filter;
$checker=null;
foreach($wp_filter['site_transient_update_plugins']->callbacks??[] as $callbacks){foreach($callbacks as $callback){
    $fn=$callback['function'];
    if(is_array($fn)&&is_object($fn[0])&&($fn[0]->slug??'')==='trickle'){$checker=$fn[0];}
}}
if(!$checker)throw new RuntimeException('Trickle updater hook is not registered.');
$plugin=plugin_basename(CSM_FILE);$checks=0;$requests=[];
$metadata=json_decode(file_get_contents(dirname(CSM_FILE).'/update.json'),true,512,JSON_THROW_ON_ERROR);
$parts=explode('.',CSM_VERSION);$parts[count($parts)-1]=(string)((int)end($parts)+1);$future=implode('.',$parts);
$fixture=$args[0]??'';if(!is_file($fixture))throw new RuntimeException('Pass the disposable test update ZIP.');
$original_main=file_get_contents(CSM_FILE);$original_metadata=file_get_contents(dirname(CSM_FILE).'/update.json');$original_active=is_plugin_active($plugin);
$original_state=get_site_option($checker->optionName,false);$original_updates=get_site_transient('update_plugins');$original_errors=error_reporting();
$response=$metadata;$status=200;$body=null;$package='https://updates.example.test/trickle-'.$future.'.zip';
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$mock=static function($pre,$options,$url)use(&$response,&$status,&$body,&$requests,$package,$fixture){
 if($url===$package){
  if(!empty($options['stream'])){if(!copy($fixture,$options['filename']))throw new RuntimeException('Fixture download failed');$bytes='';}else{$bytes=file_get_contents($fixture);}
  return ['headers'=>['content-type'=>'application/zip'],'body'=>$bytes,'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>$options['filename']??null];
 }
 $base=strtok($url,'?');if($base!==CSM_UPDATE_URL)return $pre;
 $requests[]=$url;
 return ['headers'=>['content-type'=>'application/json'],'body'=>$body??wp_json_encode($response),'response'=>['code'=>$status,'message'=>$status===200?'OK':'Unavailable'],'cookies'=>[]];
};
add_filter('pre_http_request',$mock,10,3);
try{
 $check($checker instanceof \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\UpdateChecker && !($checker instanceof \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\BaseChecker),'Uses the static JSON checker, not the GitHub repository API');
 $check($checker->metadataUrl==='https://raw.githubusercontent.com/jonschr/trickle/master/update.json'&&$checker->slug==='trickle'&&$checker->pluginFile===$plugin,'Feed URL, slug and plugin basename are correct');
 $header=get_file_data(CSM_FILE,['Version'=>'Version','UpdateURI'=>'Update URI','Requires'=>'Requires at least','PHP'=>'Requires PHP']);
 $check($metadata['version']===CSM_VERSION&&$header['Version']===CSM_VERSION&&$metadata['requires']===$header['Requires']&&$metadata['requires_php']===$header['PHP'],'Feed version and requirements match the installed plugin');
 $check($header['UpdateURI']==='https://github.com/jonschr/trickle'&&$metadata['download_url']==='https://github.com/jonschr/trickle/releases/download/'.CSM_VERSION.'/trickle-'.CSM_VERSION.'.zip','WordPress custom update origin and versioned release ZIP are configured');
 $check(has_filter('site_transient_update_plugins',[$checker,'injectUpdate'])!==false&&has_filter('plugins_api',[$checker,'injectInfo'])!==false,'Registers native update and plugin-details hooks in CLI');
 $check(has_action('puc_cron_check_updates-trickle')!==false&&wp_get_schedule('puc_cron_check_updates-trickle')==='twicedaily','Registers scheduled update checks');
 $info=$checker->requestInfo();
 $check($info&&$info->slug==='trickle'&&$info->version===CSM_VERSION&&$info->download_url===$metadata['download_url'],'Parses the real update.json schema through WordPress HTTP');
 $check($checker->checkForUpdates()===null,'Current release does not offer a redundant update');
 $response['version']='0.0.1';
 $check($checker->checkForUpdates()===null,'Older feed versions do not downgrade the plugin');
 $response=$metadata;$response['version']=$future;$response['download_url']=$package;
 $update=$checker->checkForUpdates();
 $updates=apply_filters('site_transient_update_plugins',(object)['response'=>[],'no_update'=>[],'checked'=>[$plugin=>CSM_VERSION],'last_checked'=>time()]);
 $check($update&&$updates->response[$plugin]->new_version===$future&&$updates->response[$plugin]->package===$package,'Newer JSON metadata creates a native WordPress update');
 require_once ABSPATH.'wp-admin/includes/plugin-install.php';
 $details=plugins_api('plugin_information',(object)['slug'=>'trickle']);
 $check(!is_wp_error($details)&&$details->download_link===$package&&isset($details->sections['changelog']),'Native plugin details display release notes and the download link');
 error_reporting($original_errors&~E_USER_WARNING&~E_USER_NOTICE);
 $status=503;
 $check($checker->checkForUpdates()===null,'Unavailable metadata fails safely without a stale update');
 $status=200;$body='{invalid json';
 $check($checker->checkForUpdates()===null,'Invalid JSON fails safely');
 error_reporting($original_errors);$body=null;
 $checker->checkForUpdates();
 set_site_transient('update_plugins',(object)['response'=>[],'no_update'=>[],'checked'=>[$plugin=>CSM_VERSION],'last_checked'=>time()]);
 require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
 $upgrader=new Plugin_Upgrader(new Automatic_Upgrader_Skin());$result=$upgrader->upgrade($plugin);
 if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
 if(is_wp_error($upgrader->skin->result))throw new RuntimeException($upgrader->skin->result->get_error_message());
 $after=get_file_data(CSM_FILE,['Version'=>'Version']);
 $check($result!==false&&$after['Version']===$future&&is_file(dirname(CSM_FILE).'/vendor/plugin-update-checker/plugin-update-checker.php'),'WordPress downloads and installs the advertised ZIP into the correct plugin folder');
 $check(is_file(WP_PLUGIN_DIR.'/trickle/trickle.php')&&!is_dir(WP_PLUGIN_DIR.'/trickle-'.$future),'Update preserves the stable plugin directory');
 $check(count($requests)>0,'Updater actually requested its configured JSON URL');
 echo "$checks updater checks passed.\n";
}finally{
 file_put_contents(CSM_FILE,$original_main);file_put_contents(dirname(CSM_FILE).'/update.json',$original_metadata);
 if(function_exists('opcache_invalidate')){opcache_invalidate(CSM_FILE,true);}
 if($original_active&&!is_plugin_active($plugin)){activate_plugin($plugin,'',false,true);}
 error_reporting($original_errors);remove_filter('pre_http_request',$mock,10);$checker->resetUpdateState();
 if($original_state===false)delete_site_option($checker->optionName);else update_site_option($checker->optionName,$original_state);
 if($original_updates===false)delete_site_transient('update_plugins');else set_site_transient('update_plugins',$original_updates);
}
