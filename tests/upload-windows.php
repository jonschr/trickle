<?php
/** Private staged fixture only; never applies or rotates retained backups. */
if(realpath(ABSPATH)!==realpath(getenv('HOME').'/Cove/Sites/trickle-qa-c.localhost/public')||get_option('_csm_control')||get_option('_csm_active_job'))throw new RuntimeException('Requires idle QA-C.');
$name='trickle-upload-window-fixture';$dir=WP_PLUGIN_DIR.'/'.$name;if(file_exists($dir))throw new RuntimeException('Fixture exists.');mkdir($dir);$id=null;$checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
try{
 file_put_contents($dir.'/main.php','<?php /* Plugin Name: Upload window fixture */');file_put_contents($dir.'/data.bin',random_bytes(18*1024*1024));
 $selection=['plugins'=>[$name],'themes'=>[],'tables'=>[],'media'=>'none','file_policy'=>1,'sync_active_plugins'=>false];$s=CSM_Plugin::prepare($selection);$id=$s['id'];$job=CSM_Support::job($id);$bundle=CSM_Segments::bundle($id,'files',$selection);
 $window=implode('',iterator_to_array(CSM_Segments::source($id,'files',0,$bundle,700001)));
 $check(strlen($window)===700001,'Source yields exactly the requested window, without rounding to chunk size');
 $input=fopen('php://temp','w+b');fwrite($input,$window);rewind($input);$failed=false;try{CSM_Segments::receive_stream($job,'files',$bundle,0,$input,1048576);}catch(CSM_Network_Error $e){$failed=true;}fclose($input);
 $check($failed&&CSM_Segments::state($job,'files',$bundle)['offset']===700001,'Interrupted window preserves its exact prefix and requires resume');
 foreach([-1,0,CSM_Segments::UPLOAD_SIZE+1] as $length){$input=fopen('php://temp','w+b');$failed=false;try{CSM_Segments::receive_stream($job,'files',$bundle,700001,$input,$length);}catch(RuntimeException $e){$failed=true;}fclose($input);$check($failed&&CSM_Segments::state($job,'files',$bundle)['offset']===700001,'Invalid window length rejected before checkpoint mutation: '.$length);}
 $peer=['url'=>site_url(),'key'=>get_option('_csm_key')];$offset=700001;$calls=0;
 do{$length=min(CSM_Segments::UPLOAD_SIZE,$bundle['size']-$offset);$r=CSM_Transport::request($peer,'stream_data',['id'=>$id,'kind'=>'files','bundle'=>$bundle,'offset'=>$offset,'length'=>$length],CSM_Segments::source($id,'files',$offset,$bundle,$length));$check($r['checkpoint']['offset']===$offset+$length,'Signed TLS upload acknowledges the exact window and declared Content-Length');$offset=$r['checkpoint']['offset'];$calls++;if($r['continue'])$check(!$r['ready']&&$r['files']===0,'Partial snapshot remains unstaged before the complete digest is available');}while($r['continue']);
 $check($calls===2&&$r['ready']&&$r['files']===2,'Large snapshot stages once after the final window and full digest verification');
 wp_cache_flush();$received=CSM_Support::job($id);foreach($received['files'] as $file)$check(hash_file('sha256',$file['stage'])===$file['sha256'],'Staged file bytes match the source digest');
 $zero=CSM_Transport::request($peer,'stream_data',['id'=>$id,'kind'=>'files','bundle'=>$bundle,'offset'=>$offset,'length'=>0],CSM_Segments::source($id,'files',$offset,$bundle,0));$check($zero['ready']&&!$zero['continue'],'Completed-response retries accept an empty window without restaging');
 echo "$checks upload-window checks passed.\n";
}finally{wp_cache_flush();if($id&&get_option('_csm_job_'.$id)){CSM_Plugin::discard(CSM_Support::job($id));CSM_Segments::cleanup_source($id);}CSM_Support::remove_tree($dir);}
