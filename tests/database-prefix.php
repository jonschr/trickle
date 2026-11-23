<?php
// Prefix changes must preserve plugin settings while keeping WordPress roles/user options usable.
define('ABSPATH','/fixture/');$wpdb=(object)['prefix'=>'wp_'];
require __DIR__.'/../includes/database.php';
$map=new ReflectionMethod(CSM_Database::class,'prefix_key');
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$names=['wp_migrate_addon_schema','wp_fly_user_roles','wp_fly_migrate_addon_schema'];
$mapped=array_map(static fn($key)=>$map->invoke(null,$key,'option_name','wp_fly_options','wp_fly_'),$names);
check($mapped===['wp_migrate_addon_schema','wp_user_roles','wp_fly_migrate_addon_schema']&&count(array_unique($mapped))===3,'Plugin option names remain distinct during a Flywheel-prefix pull');
check($map->invoke(null,'wp_fly_capabilities','meta_key','wp_fly_usermeta','wp_fly_')==='wp_capabilities','User roles retain the destination blog prefix');
check($map->invoke(null,'wp_fly_custom_preference','meta_key','wp_fly_usermeta','wp_fly_')==='wp_custom_preference','WordPress per-site user options retain prefix remapping');
check($map->invoke(null,'wp_fly_plugin_key','meta_key','wp_fly_postmeta','wp_fly_')==='wp_fly_plugin_key','Post and plugin metadata keys are not renamed');
check($map->invoke(null,'wp_fly_user_roles','option_name','wp_fly_plugin_options','wp_fly_')==='wp_fly_user_roles','Custom tables with option_name columns are not WordPress options');
$wpdb->prefix='wp_fly_';check($map->invoke(null,'wp_fly_user_roles','option_name','wp_fly_options','wp_fly_')==='wp_fly_user_roles','Matching prefixes remain unchanged');
