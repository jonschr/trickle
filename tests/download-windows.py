#!/usr/bin/env python3
"""Real cURL downloads through the shipped transport/segment code; temporary files only."""
import http.server
import json
import pathlib
import subprocess
import tempfile
import threading
import time

ROOT = pathlib.Path(__file__).resolve().parents[1]
requests = []

class Source(http.server.BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_POST(self):
        data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        requests.append(data)
        size = data.get('length', data.get('bundle', {}).get('size', 4096) - data.get('offset', 0))
        self.send_response(200)
        self.send_header('Content-Type', 'application/octet-stream')
        self.send_header('Content-Length', str(size))
        self.end_headers()
        try:
            if self.path.startswith('/stall'):
                time.sleep(2)
                return
            if self.path.startswith(('/slow', '/broken')):
                self.wfile.write(b'x' * 1024)
                self.wfile.flush()
                if self.path.startswith('/slow'):
                    time.sleep(2)
                return
            chunk = b'x' * 65536
            while size:
                part = chunk[:min(size, len(chunk))]
                self.wfile.write(part)
                size -= len(part)
        except (BrokenPipeError, ConnectionResetError):
            pass

PHP = r'''<?php
// WordPress boundary stubs; the cURL transport and resumable pull are real.
define('ABSPATH','/fixture/');define('WPINC','wp-includes');define('CSM_VERSION','fixture');
function apply_filters($hook,$value,...$args){return $hook==='csm_ca_bundle'?'':$value;}
function get_option($key,$default=false){return $default;}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function add_query_arg($key,$value,$url){return $url.'?'.urlencode($key).'='.urlencode($value);}
function do_action(...$args){}
class CSM_Support {
 static function private_dir(){return $GLOBALS['dir'];}static function step(...$args){}static function due(){return true;}
 static function check_cancel(){if(!empty($GLOBALS['cancel']))throw new RuntimeException('cancelled fixture');}
 static function json($data){return json_encode($data)."\n";}
 static function atomic_json($path,$data){file_put_contents($path,self::json($data));}
 static function write($fp,$bytes){while($bytes!==''){$n=fwrite($fp,$bytes);if(!$n)throw new RuntimeException('Write failed');$bytes=substr($bytes,$n);if(!empty($GLOBALS['cancel_after'])&&($GLOBALS['received']=($GLOBALS['received']??0)+$n)>=$GLOBALS['cancel_after'])$GLOBALS['cancel']=true;}}
}
class CSM_Updates {static function assert_job($job){}}
class CSM_Access {static function id($key){return 'fixture';}static function assert_owner($record){}}
class CSM_Progress {static function update(...$args){}}
class CSM_Log {static $events=[];static function secret($value){}static function clean($value){return $value;}static function write($level,$event,$context=[]){self::$events[]=[$level,$event,$context];}}
class CSM_Plugin {static function kinds($selection){return ['files'];}static function summary($job){return ['id'=>$job['id'],'status'=>'staging'];}}
require $argv[1].'/includes/class-csm-transport.php';require $argv[1].'/includes/class-csm-segments.php';
$dir=$argv[2];$url=$argv[3];$peer=['url'=>$url,'key'=>'fixture-key'];
function check($yes,$label){if(!$yes)throw new RuntimeException($label);echo "PASS $label\n";}
$size=1024*1024*1024+123;$hash=hash_init('sha256');$chunk=str_repeat('x',1024*1024);
for($left=$size;$left>0;$left-=strlen($part)){$part=substr($chunk,0,min($left,strlen($chunk)));hash_update($hash,$part);}
$bundle=['size'=>$size,'sha256'=>hash_final($hash),'download_windows'=>1,'format'=>2];
$id=str_repeat('b',32);$job=['id'=>$id,'status'=>'staging','selection'=>[]];$path=$dir.'/segment-'.$id.'-files.incoming';$calls=0;$previous=0;
do{
 $result=CSM_Segments::pull($peer,$job,'files',$bundle);$next=$result['checkpoint']['offset'];
 check($next>$previous&&$next-$previous<=CSM_Segments::DOWNLOAD_SIZE,'Window advances its durable offset within the byte limit');
 check($result['continue']===true,'Downloading never marks unverified data ready for installation');
 $previous=$next;if(++$calls>100)throw new RuntimeException('No completion');
}while($next<$size);
check($calls===17&&hash_file('sha256',$path)===$bundle['sha256'],'A transfer larger than 1 GiB completes across 17 windows with exact bytes');
check(!array_filter(CSM_Log::$events,fn($e)=>$e[0]==='error'),'Successful windows never consume failure/retry handling');
// Legacy sources omit the capability and must receive no length field.
$legacy=['size'=>12345,'sha256'=>hash('sha256',str_repeat('x',12345))];$old=$job;$old['id']=str_repeat('c',32);
$r=CSM_Segments::pull($peer,$old,'files',$legacy);check($r['checkpoint']['offset']===12345,'A source without window support still downloads correctly');
// The source generator itself respects authenticated byte ranges and rejects invalid ones.
$source=$dir.'/segment-'.$id.'-files.source';file_put_contents($source,'abcdefghij');
$sourceBundle=['size'=>10,'sha256'=>hash('sha256','abcdefghij')];file_put_contents($source.'.json',json_encode($sourceBundle));
check(implode('',iterator_to_array(CSM_Segments::source($id,'files',3,$sourceBundle,4)))==='defg','Real source generator serves only the requested window');
try{iterator_to_array(CSM_Segments::source($id,'files',8,$sourceBundle,4));throw new Exception('accepted invalid range');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'window length'),'Out-of-range source windows are rejected');}
foreach(['slow','stall','broken']as$mode){
 $fp=fopen($dir.'/'.$mode,'w+b');$p=['url'=>$url.'/'.$mode,'key'=>'fixture-key'];$caught=false;CSM_Log::$events=[];
 try{$r=CSM_Transport::request($p,'bundle_stream',['id'=>$id,'length'=>4096],null,$fp,1,4096);}
 catch(CSM_Network_Error $e){$caught=true;}
 fclose($fp);
 if($mode==='slow'){
  check(!$caught&&!empty($r['continue'])&&$r['bytes']===1024,'A time-limited download with progress returns a normal checkpoint');
  check(!array_filter(CSM_Log::$events,fn($e)=>$e[0]==='error'),'Expected time checkpoints do not log failures');
 }else check($caught,$mode==='stall'?'A timeout without any bytes remains a real network failure':'A truncated connection remains a real network failure');
}
$cancel=true;$fp=fopen($dir.'/cancel','w+b');$caught=false;
try{CSM_Transport::request($peer,'bundle_stream',['id'=>$id,'length'=>4096],null,$fp,1,4096);}catch(RuntimeException$e){$caught=$e->getMessage()==='cancelled fixture';}fclose($fp);
check($caught,'Cancellation is never converted into a successful checkpoint');
$cancel=false;$cancel_after=65536;$received=0;$fp=fopen($dir.'/cancel-during','w+b');$caught=false;
try{CSM_Transport::request($peer,'bundle_stream',['id'=>$id,'length'=>262144],null,$fp,1,262144);}catch(RuntimeException$e){$caught=$e->getMessage()==='cancelled fixture';}fclose($fp);
check($caught,'Cancellation arriving during a fast body is observed before acknowledging its checkpoint');
'''

server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Source)
threading.Thread(target=server.serve_forever, daemon=True).start()
try:
    with tempfile.TemporaryDirectory(prefix='trickle-download-windows-') as temp:
        fixture = pathlib.Path(temp) / 'verify.php'
        fixture.write_text(PHP)
        subprocess.run(['php', str(fixture), str(ROOT), temp, f'http://127.0.0.1:{server.server_port}'], check=True, timeout=90)
    assert all(r['length'] <= 64 * 1024 * 1024 for r in requests[:17])
    assert 'length' not in requests[17], 'Legacy source must not receive unsupported window parameters'
    print('PASS Real HTTP range bodies, legacy compatibility, 1 GiB download, timeout checkpoints, stalls, truncation and cancellation')
finally:
    server.shutdown()
    server.server_close()
