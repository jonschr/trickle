<?php
/** Capture actual request headers without replacing the plugin's user agent. */
if(realpath(ABSPATH)!==realpath(getenv('HOME').'/Cove/Sites/trickle-qa-c.localhost/public')||get_option('_csm_control')||get_option('_csm_active_job'))throw new RuntimeException('Requires idle QA-C.');
$mu=WPMU_PLUGIN_DIR.'/trickle-user-agent-fixture.php';$log=CSM_Support::private_dir().'/user-agent-fixture-'.bin2hex(random_bytes(5));
if(file_exists($mu))throw new RuntimeException('Fixture exists.');
try{
 $code='<?php add_action("plugins_loaded",static function(){if(($_GET["trickle"]??"")!=="manifest"&&!isset($_GET["trickle_health"]))return;file_put_contents('.var_export($log,true).',json_encode(["user_agent"=>$_SERVER["HTTP_USER_AGENT"]??"","health"=>isset($_GET["trickle_health"])])."\n",FILE_APPEND|LOCK_EX);},0);';
 file_put_contents($mu,$code);
 CSM_Transport::request(['url'=>site_url(),'key'=>get_option('_csm_key')],'manifest');
 $health=CSM_Transport::health(home_url());
 $rows=array_map(static fn($line)=>json_decode($line,true,512,JSON_THROW_ON_ERROR),file($log,FILE_IGNORE_NEW_LINES));
 if(count($rows)!==2||$rows[0]!==['user_agent'=>'Trickle/'.CSM_VERSION.' (WordPress site migration)','health'=>false]||$rows[1]!==['user_agent'=>'Trickle/'.CSM_VERSION.' health check','health'=>true]||!$health['verified'])throw new RuntimeException('Actual request identity does not match ordinary plugin use.');
 echo "PASS Actual signed API request uses the normal Trickle user agent\nPASS Actual frontend health request uses the normal Trickle health user agent\n";
 echo wp_json_encode($rows)."\n";
}finally{if(file_exists($mu))unlink($mu);if(file_exists($log))unlink($log);}
