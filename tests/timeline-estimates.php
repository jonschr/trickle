<?php
// Use real timeline composition: a database snapshot cannot zero pending file estimates.
define('ABSPATH','/fixture/');
class CSM_Plugin {static function kinds($selection){return ['database','files'];}}
require __DIR__.'/../includes/controller.php';
$method=new ReflectionMethod(CSM_Controller::class,'timeline');
$control=['id'=>str_repeat('a',32),'created'=>time(),'direction'=>'push','selection'=>['tables'=>['wp_posts'],'plugins'=>['one'],'themes'=>['one'],'media'=>'all'],'estimates'=>['database'=>100,'plugins'=>30,'themes'=>20,'media'=>200],'bundles'=>[]];
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$r=$method->invoke(null,$control);check($r['components']===$control['estimates']&&count(array_filter($r['estimated']))===4,'Initial component estimates are retained and labeled');
$control['bundles']['database']=['size'=>10,'components'=>['database'=>80,'plugins'=>0,'themes'=>0,'media'=>0]];
$r=$method->invoke(null,$control);check($r['components']===['database'=>80,'plugins'=>30,'themes'=>20,'media'=>200],'A prepared database replaces only its own estimate');
check(!$r['estimated']['database']&&$r['estimated']['plugins']&&$r['estimated']['media'],'Pending file estimates stay approximate');
$control['bundles']['files']=['continue'=>true,'components'=>['database'=>0,'plugins'=>0,'themes'=>0,'media'=>0]];
$r=$method->invoke(null,$control);check($r['components']['media']===200&&!$r['ready'],'Partial packaging does not erase pending sizes');
$control['bundles']['files']=['size'=>5,'components'=>['database'=>0,'plugins'=>0,'themes'=>2,'media'=>0]];
$r=$method->invoke(null,$control);check($r['components']===['database'=>80,'plugins'=>0,'themes'=>2,'media'=>0]&&!array_filter($r['estimated'])&&$r['ready'],'Completed file measurements include legitimate zero-byte payloads without resetting the database');
