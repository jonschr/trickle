<?php
// Read-only manifest checks on the disposable benchmark source; never contacts a remote.
require_once WP_PLUGIN_DIR.'/trickle/trickle.php';
if(realpath(ABSPATH)!==realpath(getenv('HOME').'/Cove/Sites/trickle-dual-source-20261121.localhost/public')||get_option('_csm_control')||get_option('_csm_active_job'))throw new RuntimeException('Idle benchmark source required');
$media_queries=0;
$watch=static function($sql)use(&$media_queries){if(str_contains($sql,'attachment'))++$media_queries;return $sql;};
add_filter('query',$watch);
try{
 $scoped=CSM_Plugin::manifest(false,false);
 if(isset($scoped['media'])||$media_queries)throw new RuntimeException('Scoped manifest queried media browsing data');
 echo "PASS Preparation manifest omits attachment queries and browsing data\n";
 $full=CSM_Plugin::manifest();
 if(!isset($full['media'],$full['media_total'])||!$media_queries)throw new RuntimeException('Default dashboard manifest lost media browsing');
 foreach(['tables','plugins','themes','home','trickle_build','file_policy','instance_fingerprint']as$key)if($scoped[$key]!==$full[$key])throw new RuntimeException('Required manifest field changed: '.$key);
 echo "PASS Default dashboard inventory retains media and shared identity, build and policy fields\n";
}finally{remove_filter('query',$watch);}
