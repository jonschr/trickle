<?php
// Exercise real AJAX guards and shutdown diagnostics in isolated PHP processes.
if (isset($argv[1])) {
    define('ABSPATH','/fixture/');
    $mode=$argv[1];$dir=$argv[2];
    function sanitize_key($s){return preg_replace('/[^a-z_]/','',$s);}
    function current_user_can($s){return $GLOBALS['mode']!=='anonymous';}
    function wp_verify_nonce($n,$a){return $n==='private-fixture-nonce';}
    function wp_unslash($s){return $s;}
    function nocache_headers(){}
    function status_header($s){file_put_contents($GLOBALS['dir'].'/status',(string)$s);}
    class CSM_Support {static function json($v){return json_encode($v);}}
    class CSM_Progress {static function read($id){return ['phase'=>'Replacing file','component'=>'plugins/example/main.php'];}}
    class CSM_Log {static function write($l,$event,$c){file_put_contents($GLOBALS['dir'].'/log',json_encode(['event'=>$event,'context'=>$c])."\n",FILE_APPEND);}}
    class CSM_Network_Error extends RuntimeException {}
    class CSM_Controller {static function execute($a,$d){
        file_put_contents($GLOBALS['dir'].'/executed','yes');
        if($GLOBALS['mode']==='exit')exit(73);
        if($GLOBALS['mode']==='throw')throw new RuntimeException('Fixture operation failed');
        return ['answer'=>42];
    }}
    require __DIR__.'/../includes/plugin.php';
    $_POST=['op'=>'commit','nonce'=>$mode==='bad_nonce'?'invalid':($mode==='array_nonce'?[]:'private-fixture-nonce'),'data'=>json_encode(['id'=>str_repeat('a',32)])];
    CSM_Plugin::ajax();
}
$checks=0;$base=sys_get_temp_dir().'/trickle-ajax-'.bin2hex(random_bytes(6));mkdir($base,0700);
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
try {
    foreach(['anonymous','bad_nonce','array_nonce','success','throw','exit'] as $mode){
        $dir=$base.'/'.$mode;mkdir($dir,0700);$p=proc_open([PHP_BINARY,__FILE__,$mode,$dir],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $body=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
        $logs=is_file($dir.'/log')?file_get_contents($dir.'/log'):'';$result=json_decode($body,true);
        $check($stderr==='',"$mode produces no PHP warnings");
        if(in_array($mode,['anonymous','bad_nonce','array_nonce'])){
            $check(file_get_contents($dir.'/status')==='403'&&!is_file($dir.'/executed')&&!$result['ok'],"$mode cannot execute a controller action");
            $check(str_contains($logs,'Dashboard control authentication rejected')&&!str_contains($logs,'private-fixture-nonce'),"$mode rejection is logged without credentials");
        }elseif($mode==='success')$check($result['ok']&&$result['data']['answer']===42&&!str_contains($logs,'without a response'),'Successful responses do not report false interruptions');
        elseif($mode==='throw')$check(file_get_contents($dir.'/status')==='400'&&$result['error']==='Fixture operation failed'&&!str_contains($logs,'without a response'),'Operation errors are logged once and returned');
        else $check($code===73&&str_contains($logs,'Dashboard request ended without a response')&&str_contains($logs,'plugins\/example\/main.php'),'Early PHP exit records its last phase and exact component');
    }
    echo "$checks AJAX guard/diagnostic checks passed.\n";
}finally{foreach(glob($base.'/*')as$dir){foreach(glob($dir.'/*')as$file)unlink($file);rmdir($dir);}rmdir($base);}
