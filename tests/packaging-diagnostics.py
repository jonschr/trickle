#!/usr/bin/env python3
"""Exercise real HTTP 502 diagnostics against a local mock source; no migrations."""
import http.server, json, secrets, subprocess, threading, urllib.parse

run='1234567890abcdef'; attempt=0
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_POST(self):
        global attempt
        self.rfile.read(int(self.headers.get('Content-Length',0)))
        action=urllib.parse.parse_qs(urllib.parse.urlparse(self.path).query)['trickle'][0]
        if action=='bundle':
            attempt+=1; self.send_response(502); self.end_headers(); self.wfile.write(b'Gateway failure'); return
        assert action=='progress'
        self.send_response(200 if attempt<3 else 500); self.end_headers()
        payload={'ok':True,'data':{'run':run if attempt==1 else '9999999999999999','phase':'Exporting file','component':['','plugins/example/a.js','uploads/2026/03/b.png'][min(attempt,2)],'count':7151 if attempt==1 else 20038,'total':None,'unit':'files','updated':0}}
        self.wfile.write(json.dumps(payload).encode())

server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
threading.Thread(target=server.serve_forever,daemon=True).start()
tid=secrets.token_hex(16)
code='''$out=[];for($n=0;$n<3;$n++){try{CSM_Transport::request(['url'=>'http://127.0.0.1:PORT','key'=>str_repeat('b',64)],'bundle',['id'=>'TID','kind'=>'files','progress_run'=>'RUN']);}catch(Throwable $e){$out[]=$e->getMessage();}}$entries=array_values(array_filter(CSM_Log::entries(),static fn($e)=>($e['context']['transfer_id']??'')==='TID'));echo wp_json_encode(['errors'=>$out,'entries'=>$entries]);'''.replace('PORT',str(server.server_port)).replace('TID',tid).replace('RUN',run)
try:
    p=subprocess.run(['cove','wp','trickle-qa-c','eval',code],capture_output=True,text=True,timeout=40)
    assert p.returncode==0,p.stdout+p.stderr
    d=json.loads(p.stdout[p.stdout.index('{'):]); checkpoints=[e['context'] for e in d['entries'] if e['event']=='Last observed source activity after packaging failure']
    assert len(checkpoints)==2 and checkpoints[0]['component']=='plugins/example/a.js' and checkpoints[1]['component']=='uploads/2026/03/b.png'
    assert checkpoints[0]['count']==7151 and checkpoints[1]['count']==20038
    assert checkpoints[0]['progress_matches_request'] and not checkpoints[1]['progress_matches_request']
    assert all(c['http_status']==502 and 'duration_ms' in c and c['request_run']==run for c in checkpoints)
    assert all('HTTP 502' in e for e in d['errors']),d['errors']
    assert any(e['event']=='Source activity unavailable after packaging failure' for e in d['entries'])
    print('PASS real 502 logs preserve distinct last files, counts, request/run correlation and duration')
    print('PASS stale source progress is marked; failed progress probe preserves the original 502')
finally:
    server.shutdown();server.server_close()
