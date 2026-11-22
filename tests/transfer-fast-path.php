<?php
// Real upload, unpacking, staging and preview in private fixtures. No WordPress or network.
$root=sys_get_temp_dir().'/trickle-fast-'.bin2hex(random_bytes(6));
define('ABSPATH',$root.'/public/');define('WP_CONTENT_DIR',ABSPATH.'wp-content');define('WP_PLUGIN_DIR',WP_CONTENT_DIR.'/plugins');define('CSM_FILE',dirname(__DIR__).'/trickle.php');define('CSM_VERSION','fixture');
mkdir(WP_PLUGIN_DIR.'/demo',0700,true);
$stepStarts=0;$slow=false;$saved=[];
function apply_filters($hook,$value,...$args){if($hook==='csm_step_seconds')++$GLOBALS['stepStarts'];return $value;}
function wp_salt($name){return 'fast-path-fixture';}function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
function wp_get_theme($id){return new class{function exists(){return false;}function get($name){return '';}};}
function wp_upload_dir(...$args){return ['basedir'=>WP_CONTENT_DIR.'/uploads','error'=>false];}
function plugin_basename($path){return 'trickle/trickle.php';}function get_file_data(...$args){return ['php'=>'','wp'=>'','name'=>'Fixture'];}function get_bloginfo($name){return '7.1';}
class CSM_Components {static function unchanged($selection){return [];}}
class CSM_Activation {static function current(){return [];}static function source(&$job,$header){}}
class CSM_Jobs {static function save($job){$GLOBALS['saved'][$job['id']]=$job;}}
class CSM_Network_Error extends RuntimeException {}
class CSM_Transport {
    static function request($peer,$action,$data,$input,$output,...$args){
        if($action!=='bundle_stream')throw new RuntimeException('Unexpected request');
        $fp=fopen($GLOBALS['path'],'rb');fseek($fp,$data['offset']);stream_copy_to_stream($fp,$output);fclose($fp);
    }
}
class CSM_Progress {
    static function update($data,...$args){if($GLOBALS['slow']&&($data['phase']??'')==='Verifying snapshot frames'){$GLOBALS['slow']=false;usleep(30000);}}
    static function read($id){return [];}static function finish(...$args){}static function run(){return '';}
}
foreach(['support','updates','files','package','plugin','segments']as$part)require_once __DIR__.'/../includes/'.$part.'.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function upload($job,$bundle,$path){$fp=fopen($path,'rb');try{return CSM_Segments::receive_stream($job,'files',$bundle,0,$fp,$bundle['size']);}finally{fclose($fp);}}
$private=CSM_Support::private_dir();
try{
    file_put_contents(WP_PLUGIN_DIR.'/demo/data.bin',random_bytes(2*1024*1024));
    $selection=['plugins'=>['demo'],'themes'=>[],'tables'=>[],'media'=>'none','protect_source_git'=>true];
    $path=$private.'/source';do{$bundle=CSM_Package::build($path,'files',$selection);}while(!empty($bundle['continue']));
    $job=['id'=>str_repeat('a',32),'plugin_version'=>CSM_VERSION,'created'=>time(),'status'=>'staging','selection'=>$selection,'files'=>[],'database'=>[],'received'=>[]];
    $stepStarts=0;$result=upload($job,$bundle,$path);
    check($result['ready']&&!$result['continue'],'A small upload unpacks, verifies, imports and previews in one request');
    check($stepStarts===1,'Nested phases share one request budget instead of restarting it');
    $received=$saved[$job['id']];$file=$received['files'][0];
    check(hash_file('sha256',$file['stage'])===hash_file('sha256',WP_PLUGIN_DIR.'/demo/data.bin'),'Fast path still verifies and stages exact source bytes');
    $fp=fopen('php://temp','w+b');$again=CSM_Segments::receive_stream($received,'files',$bundle,$bundle['size'],$fp,0);fclose($fp);
    check($again['ready']&&!$again['continue']&&$again['files']===1,'A completed-response retry does not import files twice');
    $job['id']=str_repeat('c',32);$stepStarts=0;$result=CSM_Segments::pull([],$job,'files',$bundle);
    check($result['ready']&&!$result['continue']&&$stepStarts===1,'Small pulls also download and finish processing under one shared budget');
    $job['id']=str_repeat('d',32);$fp=fopen('php://temp','w+b');fwrite($fp,file_get_contents($path,false,null,0,100));rewind($fp);$interrupted=false;
    try{CSM_Segments::receive_stream($job,'files',$bundle,0,$fp,200);}catch(CSM_Network_Error $e){$interrupted=true;}finally{fclose($fp);}
    check($interrupted&&CSM_Segments::received_bytes($job['id'],'files')===100,'An interrupted upload preserves its exact received prefix');
    $fp=fopen($path,'rb');fseek($fp,100);$result=CSM_Segments::receive_stream($job,'files',$bundle,100,$fp,$bundle['size']-100);fclose($fp);
    check($result['ready']&&!$result['continue'],'Interrupted upload resumes from the actual offset and completes without restaging');
    $job['id']=str_repeat('b',32);$slow=true;
    // Expire a shared budget at a real frame checkpoint, without changing production timeouts.
    CSM_Support::step(.01);$stepStarts=0;
    $fp=fopen($path,'rb');$target=$private.'/segment-'.$job['id'].'-files.incoming';copy($path,$target);fclose($fp);
    $result=CSM_Segments::receive($job,'files',$bundle,$bundle['size'],'',hash('sha256',''),false,true);
    check($result['continue']&&!$result['ready']&&$stepStarts===0,'An exhausted shared budget yields without starting an import');
    $fp=fopen('php://temp','w+b');$result=CSM_Segments::receive_stream($saved[$job['id']]??$job,'files',$bundle,$bundle['size'],$fp,0);fclose($fp);
    check($result['ready']&&!$result['continue'],'Next request resumes the saved unpack checkpoint and completes');
    $live=WP_PLUGIN_DIR.'/demo/data.bin';$original=file_get_contents($live);$mtime=filemtime($live);
    $same=['type'=>'file-same','path'=>'plugins/demo/data.bin','metadata_only'=>true,'size'=>strlen($original),'mtime'=>$mtime];
    $header=['type'=>'header','version'=>1,'kind'=>'files','started'=>time()];
    $end=['type'=>'end','files'=>1,'sha256'=>hash('sha256',CSM_Support::json($same))];
    $fp=fopen('php://temp','w+b');foreach([$header,$same,$end]as$line)fwrite($fp,CSM_Support::json($line));rewind($fp);
    $legacy=['id'=>str_repeat('e',32),'selection'=>$selection,'database'=>[]];CSM_Files::stage($fp,$legacy);fclose($fp);
    check($legacy['files'][0]['sha256']===hash_file('sha256',$live),'Legacy receipt retains the actual skipped-file checksum after the full payload');
    file_put_contents($live,str_repeat('X',strlen($original)));touch($live,$mtime);$rejected=false;
    try{CSM_Plugin::changes($legacy);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'unchanged destination');}
    check($rejected,'Legacy post-receipt mutation cannot silently become a new recovery baseline');
}finally{CSM_Support::remove_tree($private);CSM_Support::remove_tree($root);}
