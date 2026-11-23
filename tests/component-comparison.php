<?php
// Isolated dashboard checksum route: no WordPress, network, or transfer writes.
define('ABSPATH', '/fixture/');
$options=['siteurl'=>'https://local.test','home'=>'https://local.test','_csm_peers'=>['remote'=>['id'=>'remote','url'=>'https://remote.test','name'=>'Remote']]];
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function wp_cache_delete(...$args){}
function update_option(...$args){throw new RuntimeException('Comparison changed settings');}
function site_url(){return 'https://local.test';}
function home_url(){return site_url();}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
class CSM_Support {static function is_cancelled($id){return false;}}
class CSM_Files {static function inventory(...$args){return ['plugins'=>[['id'=>'fixture'],['id'=>'protected','protected'=>true]],'themes'=>[['id'=>'theme']]];}}
class CSM_Components {
    static $queries=[];
    static function inventory($query){self::$queries[]=$query;return ['components'=>['plugins/fixture'=>'local-hash','themes/theme'=>'local-theme-hash']];}
}
class CSM_Media {
    static $query;
    static function compare($peer,$pull,$verify,$selection){self::$query=[$pull,$verify,$selection];return ['stats'=>['files_selected'=>3],'delta'=>'private-plan'];}
}
class CSM_Transport {
    static $queries=[],$checkpoint=false;
    static function url($url){return $url;}
    static function request($peer,$action,$query=[]){
        if($action==='manifest')return ['plugins'=>[['id'=>'new-source'],['id'=>'remote-protected','protected'=>true]],'themes'=>[]];
        if($peer['url']!=='https://remote.test'||$action!=='component_hashes')throw new RuntimeException('Unexpected remote request');
        self::$queries[]=$query;
        return self::$checkpoint?['continue'=>true]:['components'=>['plugins/fixture'=>'remote-hash','themes/theme'=>'remote-theme-hash','plugins/new-source'=>'source-only-hash']];
    }
}
require __DIR__.'/../includes/class-csm-controller.php';
$operate=new ReflectionMethod(CSM_Controller::class,'operate');
$base=['site'=>'remote','kind'=>'plugins','component'=>'fixture','comparison'=>str_repeat('a',32),'direction'=>'push'];
function check($yes,$label){if(!$yes)throw new RuntimeException($label);echo "PASS $label\n";}
foreach(['push','pull'] as $direction){
    $result=$operate->invoke(null,'compare_component',array_replace($base,['direction'=>$direction]));
    check($result===['source'=>$direction==='push'?'local-hash':'remote-hash','destination'=>$direction==='push'?'remote-hash':'local-hash'],"$direction preserves source and destination hash order");
    check(end(CSM_Components::$queries)['source']===($direction==='push')&&end(CSM_Transport::$queries)['source']===($direction==='pull'),'Only the source uses source-file policy');
    check(end(CSM_Components::$queries)['selection']===['plugins'=>['fixture'],'media'=>'none','comparison_id'=>str_repeat('a',32),'comparison_only'=>true],'One component and stable checkpoint ID are forwarded, with no media');
}
CSM_Transport::$checkpoint=true;
check($operate->invoke(null,'compare_component',$base)===['continue'=>true],'Remote checkpoints retry without manufacturing matching hashes');
CSM_Transport::$checkpoint=false;
foreach([['direction'=>'other'],['kind'=>'tables'],['component'=>'protected'],['component'=>'absent'],['comparison'=>'../bad'],['comparison'=>[]],['site'=>'absent']] as $invalid){
    $calls=count(CSM_Transport::$queries);
    try{$operate->invoke(null,'compare_component',array_replace($base,$invalid));throw new LogicException('Invalid comparison accepted');}catch(RuntimeException $e){}
    check(count(CSM_Transport::$queries)===$calls,'Invalid or protected comparison never reaches remote hashing');
}
$new=$operate->invoke(null,'compare_component',array_replace($base,['direction'=>'pull','component'=>'new-source']));
check($new===['source'=>'source-only-hash','destination'=>null],'A new pull component is validated against its authenticated source');
$media=$operate->invoke(null,'warm_media',['site'=>'remote','direction'=>'pull','comparison'=>str_repeat('b',32)]);
check($media===['stats'=>['files_selected'=>3]]&&CSM_Media::$query===[true,false,['media'=>'all','comparison_id'=>str_repeat('b',32),'comparison_only'=>true]],'Media warming uses the resumable comparison and returns no private plan');
$options['_csm_active_job']='active';
try{$operate->invoke(null,'warm_media',['site'=>'remote','direction'=>'pull','comparison'=>str_repeat('b',32)]);throw new LogicException('Active destination allowed warmup');}catch(RuntimeException $e){}
unset($options['_csm_active_job']);
$options['_csm_control']=['id'=>'retained','peer'=>['url'=>'https://remote.test'],'direction'=>'push','selection'=>[]];
try{$operate->invoke(null,'compare_component',$base);throw new LogicException('Retained transfer allowed comparison');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'retained transfer'),'Retained transfer prevents file comparison');}
