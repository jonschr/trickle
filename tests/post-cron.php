<?php
// Core post-ID cron events follow the newly imported posts, without changing unrelated jobs.
define('ABSPATH','/fixture/');define('ARRAY_A','ARRAY_A');
class wpdb {public $posts='wp_posts',$options='wp_options',$last_error='';public $rows=[],$reads=0;function get_results($sql,$format){$this->reads++;if($sql!=="SELECT ID, post_date_gmt FROM `_csm_test_posts` WHERE post_status='future'")throw new RuntimeException('Unexpected SQL');return $this->rows;}}
$wpdb=new wpdb();
class CSM_Support {static function ident($name){return '`'.$name.'`';}}
require __DIR__.'/../includes/class-csm-database.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$job=['database'=>[['target'=>'wp_posts','stage'=>'_csm_test_posts']]];
$base=[100=>['test_global'=>['unchanged'=>['schedule'=>false,'args'=>['keep']]],'upgrader_scheduled_cleanup'=>['old-zip'=>['schedule'=>false,'args'=>[6]]]],200=>['publish_future_post'=>['old-post'=>['schedule'=>false,'args'=>[999]]]],'version'=>2];
$wpdb->rows=[['ID'=>'6','post_date_gmt'=>'2030-01-01 12:34:56']];$method=new ReflectionMethod(CSM_Database::class,'post_cron');$result=$method->invoke(null,$job,$base);
check($result[100]===['test_global'=>$base[100]['test_global']]&&!isset($result[200]),'Stale ZIP deletion and old publication jobs are removed while global jobs remain identical');
$time=strtotime('2030-01-01 12:34:56 UTC');$args=[6];check($result[$time]['publish_future_post'][md5(serialize($args))]===['schedule'=>false,'args'=>$args]&&$result['version']===2,'Imported future post receives the native WordPress cron shape and GMT publication time');
check(isset($base[100]['upgrader_scheduled_cleanup'])&&isset($base[200]['publish_future_post']),'Constructing staged cron does not change the live destination schedule');
$empty=$method->invoke(null,$job,[]);check($empty['version']===2&&isset($empty[$time]),'A destination without a cron option can receive future publication events');
$wpdb->rows=[];for($i=1;$i<=600;$i++)$wpdb->rows[]=['ID'=>(string)$i,'post_date_gmt'=>'2030-01-01 12:34:56'];$wpdb->reads=0;$many=$method->invoke(null,$job,$base);check($wpdb->reads===1&&count($many[$time]['publish_future_post'])===600,'Many future posts use one metadata query and a single staged cron value');
$wpdb->rows=[['ID'=>'0','post_date_gmt'=>'2030-01-01 12:34:56']];$rejected=false;try{$method->invoke(null,$job,$base);}catch(RuntimeException $e){$rejected=true;}check($rejected,'Invalid publication IDs fail before any live cron or database table changes');
$wpdb->rows=[['ID'=>'6','post_date_gmt'=>'invalid date']];$rejected=false;try{$method->invoke(null,$job,$base);}catch(RuntimeException $e){$rejected=true;}check($rejected,'Invalid publication dates fail before any live changes');
