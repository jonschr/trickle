#!/usr/bin/env python3
"""Exercise real native cURL Basic Auth, health, uploads, downloads and redirects locally."""
import base64,json,subprocess,threading,time,sys,tempfile
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
seen=[]
class Handler(BaseHTTPRequestHandler):
 def log_message(self,*args): pass
 def run_request(self):
  seen.append((self.path,self.headers.get('Authorization'),self.headers.get('User-Agent')))
  size=int(self.headers.get('Content-Length','0'));body=self.rfile.read(size) if size else b''
  if self.headers.get('Transfer-Encoding')=='chunked':
   body=b''
   while True:
    n=int(self.rfile.readline().strip(),16)
    if not n:self.rfile.readline();break
    body+=self.rfile.read(n);self.rfile.read(2)
  if self.headers.get('Authorization')!='Basic '+base64.b64encode(b'demo:secret:with spaces').decode():
   status,payload=401,b'private rejected response'
  elif 'trickle=progress' in self.path or 'trickle=throttle' in self.path:
   self.send_response(429 if 'trickle=throttle' in self.path else 503);self.send_header('Retry-After','60');self.send_header('Content-Length','0');self.end_headers();return
  elif self.path.startswith('/redirect'):
   self.send_response(302);self.send_header('Location','/leak');self.send_header('Content-Length','0');self.end_headers();return
  elif 'trickle=live_files' in self.path:status,payload=200,b'fixture stream'
  else:status,payload=200,json.dumps({'ok':True,'data':{'received':len(body)}}).encode()
  if self.path.startswith('/slow'):time.sleep(.35)
  self.send_response(status);self.send_header('Content-Length',str(len(payload)));self.end_headers()
  if self.command!='HEAD':self.wfile.write(payload)
 do_POST=do_HEAD=run_request
server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
try:
 php=['php']+(['-d','disable_functions=curl_multi_init']if'--no-multi'in sys.argv else[])
 with tempfile.TemporaryDirectory(prefix='test-trickle-http-auth-') as storage:
  result=subprocess.run(php+[str(Path(__file__).with_suffix('.php')),f'http://127.0.0.1:{server.server_port}',storage],capture_output=True,text=True)
 print(result.stdout,end='');print(result.stderr,end='')
 if result.returncode:raise SystemExit(result.returncode)
 assert len(seen)==12 and not any(x[0].startswith('/leak') for x in seen),seen
 assert all(x[2]=='Trickle/fixture (+https://github.com/jonschr/trickle)' for x in seen),seen
 print('PASS Twelve real cURL requests; look-ahead adds no requests; redirects never followed; native user agent preserved')
finally:server.shutdown();server.server_close();thread.join()
