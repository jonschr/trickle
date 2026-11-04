<?php
// PHP's version comparator drives both transfer directions; no WordPress or network.
define('ABSPATH','/fixture/');
require __DIR__.'/../includes/controller.php';
$method=new ReflectionMethod(CSM_Controller::class,'compare_components');$checks=0;
function check($ok,$label){if(!$ok)throw new RuntimeException($label);++$GLOBALS['checks'];echo "PASS $label\n";}
foreach([
 ['1.10','1.9',1],['1.9','1.10',-1],['2.0.0','2.0.0',0],['2.0.0-rc1','2.0.0',-1],['2.0.0','2.0.0-beta.2',1],['1.2.3-dev','1.2.3',-1],['1.2.3-pl1','1.2.3',1],['branch-main','1.0',null],['1.0+custom','1.0',null],['','1.0',null]
]as[$incoming,$installed,$expected]){
 foreach(['plugins','themes']as$kind){
  $source=[$kind=>[['id'=>'fixture','version'=>$incoming]],'sentinel'=>'kept'];$dest=[$kind=>[['id'=>'fixture','version'=>$installed]]];
  $result=$method->invoke(null,$source,$dest);
  check($result[$kind][0]['version_order']===$expected&&$result['sentinel']==='kept',"$kind: $installed → $incoming ordered without changing inventory");
  if($expected!==null){$reverse=$method->invoke(null,$dest,$source);check($reverse[$kind][0]['version_order']===-$expected,'Reverse direction reverses version order');}
 }
}
$result=$method->invoke(null,['plugins'=>[['id'=>'new','version'=>'1.0']]],['plugins'=>[]]);check($result['plugins'][0]['version_order']===null,'New installation has no invented destination version');
echo "$checks component version checks passed.\n";
