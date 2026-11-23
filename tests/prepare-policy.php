<?php
// Fresh manifest policy and legacy separate policy use the same approval logic.
define('ABSPATH','/fixture/');
$requests=0;
class CSM_Transport {static function request(...$args){++$GLOBALS['requests'];return ['git_folders'=>['plugins/repo']];}}
class CSM_Files {static function git_folders(...$args){return ['themes/local-repo'];}}
require __DIR__.'/../includes/class-csm-controller.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$method=new ReflectionMethod(CSM_Controller::class,'review');$peer=['url'=>'https://remote.example'];$policy=['git_folders'=>['plugins/repo']];
$r=$method->invoke(null,$peer,false,[],[],$policy);
check($requests===0&&$r['skip_paths']===['plugins/repo'],'Manifest policy protects an unapproved destination repository without another request');
$r=$method->invoke(null,$peer,false,[],['plugins/repo'],$policy);
check($requests===0&&$r['git_overrides']===['plugins/repo']&&!$r['skip_paths'],'Explicit destination approval uses the fresh manifest scan');
$r=$method->invoke(null,$peer,true,[],['plugins/repo'],$policy);
check($r['skip_paths']===['themes/local-repo']&&$r['protect_source_git'],'Pull review also retains protection for local destination repositories');
$blocked=false;try{$method->invoke(null,$peer,false,[],['plugins/not-reviewed'],$policy);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'confirmation no longer matches');}
check($blocked,'Approval for a folder absent from the fresh scan is rejected');
$r=$method->invoke(null,$peer,false,[],[]);
check($requests===1&&$r['skip_paths']===['plugins/repo'],'Standalone review keeps its separate policy request');
