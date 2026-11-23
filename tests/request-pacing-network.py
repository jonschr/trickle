#!/usr/bin/env python3
"""Verify native cURL scheduling across PHP workers against a loopback server only."""
import json, subprocess, tempfile, threading, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
seen=[]; active=0; peak=0; guard=threading.Lock(); slow_started=threading.Event()
class Handler(BaseHTTPRequestHandler):
 def log_message(self,*args):pass
 def run_request(self):
  global active,peak
  with guard:
   active+=1;peak=max(peak,active);seen.append((self.path,time.monotonic(),self.headers.get('User-Agent')))
  try:
   if self.path.startswith('/limited'):
    status=430 if self.path.startswith('/limited430') else 429
    self.send_response(status)
    self.send_header('Retry-After',self.date_time_string(time.time()+90)if status==430 else'2')
    self.send_header('Content-Length','0');self.send_header('Connection','close');self.end_headers();self.close_connection=True;return
   size=int(self.headers.get('Content-Length',0))
   if size:self.rfile.read(size)
   if self.headers.get('Transfer-Encoding')=='chunked':
    while True:
     n=int(self.rfile.readline().strip(),16)
     if not n:self.rfile.readline();break
     self.rfile.read(n);self.rfile.read(2)
   if self.path.startswith('/slow'):
    slow_started.set();time.sleep(.6)
   body=b'fixture stream'if'trickle=live_files'in self.path else b'{"ok":true,"data":{}}'
   self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers()
   if self.command!='HEAD':self.wfile.write(body)
  finally:
   with guard:active-=1
 do_HEAD=do_POST=run_request
server=ThreadingHTTPServer(('127.0.0.1',0),Handler);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
worker=Path(__file__).with_suffix('.php');url=f'http://127.0.0.1:{server.server_port}'
def start(storage,mode='one',action='manifest',path='',interval=None):
 args=['php',str(worker),url+path,storage,mode,action]
 if interval is not None:args.append(str(interval))
 return subprocess.Popen(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
def finish(p):
 out,err=p.communicate(timeout=15);assert p.returncode==0,err
 try:return json.loads(out)
 except Exception:raise AssertionError((out,err))
try:
 with tempfile.TemporaryDirectory(prefix='test-trickle-network-default-')as storage:
  before=len(seen);assert finish(start(storage,'sequence'))['ok']
  records=seen[before:];assert len(records)==4,records
  assert any(b[1]-a[1]<.5 for a,b in zip(records,records[1:])),records
  print('PASS Default zero gap covers JSON, health, push uploads and pull downloads')
 with tempfile.TemporaryDirectory(prefix='test-trickle-network-spacing-')as storage:
  before=len(seen);assert finish(start(storage,'sequence',interval=.1))['ok']
  records=seen[before:];assert len(records)==4,records
  assert all(b[1]-a[1]>=.09 for a,b in zip(records,records[1:])),records
  print('PASS Opt-in spacing spans JSON, health, push uploads and pull downloads')
 with tempfile.TemporaryDirectory(prefix='test-trickle-network-serial-')as storage:
  before=len(seen);slow_started.clear();p=start(storage,action='live_receive',path='/slow',interval=.1)
  assert slow_started.wait(5)
  deferred=finish(start(storage,action='progress',interval=.1))
  assert not deferred['ok']and deferred['exhausted']and len(seen)==before+1,deferred
  cancelled=finish(start(storage,'cancel',interval=.1));assert cancelled['error']=='Cancelled fixture',cancelled
  q=start(storage,interval=.1);assert finish(p)['ok']and finish(q)['ok']
  assert len(seen)==before+2 and seen[-1][1]-seen[-2][1]>=.68 and peak==1,seen[before:]
  print('PASS Separate PHP workers serialize; polls defer; cancellation sends no request')
 for status in [429,430]:
  with tempfile.TemporaryDirectory(prefix=f'test-trickle-network-{status}-')as storage:
   before=len(seen)
   # Exercise an early non-JSON upload rejection as well as a health rejection.
   rejected=finish(start(storage,action='live_receive'if status==429 else'health',path=f'/limited{status}',interval=.01))
   assert not rejected['ok']and rejected['exhausted']and rejected['retry_after']>=(30 if status==429 else 85),rejected
   for action in ['manifest','progress','health','live_receive']:
    paused=finish(start(storage,action=action,interval=.01));assert not paused['ok']and paused['retry_after']>0,paused
   assert len(seen)==before+1,seen[before:]
   print(f'PASS HTTP {status} persists host cooldown across workers/actions without an automatic retry')
 assert all(x[2]=='Trickle/fixture (+https://github.com/jonschr/trickle)'for x in seen),seen
 print('PASS Native user agents unchanged; all traffic stayed on loopback')
finally:server.shutdown();server.server_close();thread.join()
