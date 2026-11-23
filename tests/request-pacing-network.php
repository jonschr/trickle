<?php
function wp_json_encode($value,$flags=0,$depth=512){return json_encode($value,$flags,$depth);}
// Native transport worker for the disposable loopback scheduling fixture.
define('ABSPATH','/fixture/');define('WPINC','wp-includes');define('CSM_VERSION','fixture');
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function add_query_arg($key,$value,$url){return $url.'?'.$key.'='.$value;}
function apply_filters($name,$value,...$args){return $name==='csm_ca_bundle'?'':($name==='csm_request_interval'&&isset($GLOBALS['argv'][5])?(float)$GLOBALS['argv'][5]:$value);}
function get_option($key,$default=false){$p=$GLOBALS['argv'][2].'/options.json';$data=is_file($p)?json_decode(file_get_contents($p),true):[];return $data[$key]??$default;}
function update_option($key,$value,...$args){$p=$GLOBALS['argv'][2].'/options.json';$data=is_file($p)?json_decode(file_get_contents($p),true):[];$data[$key]=$value;file_put_contents($p,json_encode($data));}
function do_action(...$args){}
class CSM_Log {static function secret($s){}static function write(...$args){}static function clean($s){return $s;}}
class CSM_Support {static function private_dir(){return $GLOBALS['argv'][2];}static function json($v){return json_encode($v);}static function check_cancel(){if(isset($GLOBALS['cancel_at'])&&microtime(true)>$GLOBALS['cancel_at'])throw new RuntimeException('Cancelled fixture');}}
class CSM_Access {static function id($key){return substr(hash('sha256',$key),0,24);}}
class CSM_Progress {static function update(...$args){}}
require __DIR__.'/../includes/class-csm-transport.php';
$peer=['url'=>$argv[1],'key'=>str_repeat('a',64)];
function call($action){
 global $peer;
 if($action==='health')return CSM_Transport::health($peer['url']);
 if($action==='live_receive')return CSM_Transport::request($peer,$action,[],(static function(){yield 'fixture upload';})());
 if($action==='live_files'){$bytes='';CSM_Transport::request($peer,$action,[],null,static function($chunk)use(&$bytes){$bytes.=$chunk;});if($bytes!=='fixture stream')throw new RuntimeException('Download differs');return [];}
 return CSM_Transport::request($peer,$action);
}
try{
 if($argv[3]==='sequence'){foreach(['manifest','health','live_receive','live_files']as$action)call($action);$out=['ok'=>true];}
 else{if($argv[3]==='cancel')$cancel_at=microtime(true)+.1;call($argv[4]);$out=['ok'=>true];}
}catch(CSM_Network_Error $e){$out=['ok'=>false,'retry_after'=>$e->retry_after,'exhausted'=>$e->exhausted,'error'=>$e->getMessage()];}
catch(Throwable $e){$out=['ok'=>false,'error'=>$e->getMessage()];}
echo json_encode($out);
