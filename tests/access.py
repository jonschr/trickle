#!/usr/bin/env python3
"""Real signed TLS requests for scoped/expired/revoked keys on idle QA-A only."""
import base64,hashlib,hmac,http.client,json,secrets,ssl,subprocess,time
from pathlib import Path
SITE='trickle-qa-a';HOST=SITE+'.localhost';CA=Path.home()/'Library/Application Support/Caddy/pki/authorities/local/root.crt'
CTX=ssl.create_default_context(cafile=str(CA));checks=0

def wp(*args):
 p=subprocess.run(['cove','wp',SITE,*args],capture_output=True,text=True,timeout=120)
 if p.returncode:raise RuntimeError(p.stdout+p.stderr)
 return p.stdout

def ev(code):return json.loads(wp('eval',code))
def request(action,key,data=None):
 body=(json.dumps(data or {},separators=(',',':'))+'\n').encode();stamp=str(int(time.time()));nonce=secrets.token_hex(16)
 digest=hashlib.sha256(body).hexdigest();params=base64.b64encode((json.dumps({'trickle_version':VERSION})+'\n').encode()).decode();sig=hmac.new(key.encode(),'\n'.join([action,stamp,nonce,digest,params]).encode(),hashlib.sha256).hexdigest()
 headers={'X-CSM-Key-Id':hashlib.sha256(key.encode()).hexdigest()[:24],'X-CSM-Time':stamp,'X-CSM-Nonce':nonce,'X-CSM-Hash':digest,'X-CSM-Params':params,'X-CSM-Signature':sig,'Content-Type':'application/json'}
 c=http.client.HTTPSConnection(HOST,8453,context=CTX,timeout=90);c.request('POST','/?trickle='+action,body,headers);r=c.getresponse();raw=r.read();status=r.status;c.close()
 if action=='bundle_chunk' and status==200:return status,raw
 return status,json.loads(raw)
def check(ok,label):
 global checks
 assert ok,label
 checks+=1;print('PASS '+label,flush=True)
assert ev('echo wp_json_encode(!get_option("_csm_control")&&!get_option("_csm_active_job"));')
VERSION=ev('echo wp_json_encode(CSM_VERSION);')
settings=ev('echo wp_json_encode(get_option("_csm_settings"));');tokens=ev('echo wp_json_encode(get_option("_csm_tokens",[]));')
master=ev('echo wp_json_encode(get_option("_csm_key"));');job=None;bundle=secrets.token_hex(16)
try:
 wp('eval',"update_option('_csm_settings',['pull'=>true,'push'=>true],false);")
 issued=[ev("echo wp_json_encode(CSM_Access::issue('Test workstation',true,"+('true' if write else 'false')+",1));") for write in [False,True,True]]
 keys=[i['pairing'].splitlines()[1] for i in issued];read,write,other=keys
 check(request('export',master,{'kind':'files','selection':{'plugins':['classic-editor'],'media':'none'}})[0]==400,'Legacy file exporters must opt into mandatory protection before sending bytes')
 check(request('prepare',master,{'selection':{'plugins':['classic-editor'],'media':'none'}})[0]==400,'Legacy incoming file transfers require an updated controller')
 check(request('manifest',read)[0]==200,'Read-only named key can inspect the source')
 check(request('prepare',read,{'selection':{'tables':['wp_csm_fixture']}})[0]==403,'Read-only key cannot prepare destination writes')
 status,r=request('prepare',write,{'selection':{'tables':['wp_csm_fixture']}});job=r['data']['id']
 check(status==200,'Read/write key can create an owned transfer')
 check(request('status',other,{'id':job})[0]==403,'Another valid key cannot inspect the owned transfer')
 check(request('progress',other,{'id':job})[0]==403,'Another key cannot inspect private transfer progress')
 check(request('discard',other,{'id':job})[0]==403,'Another write key cannot discard the owned transfer')
 check(request('status',master,{'id':job})[0]==200,'Administrator key retains recovery access')
 wp('eval',"update_option('_csm_settings',['pull'=>true,'push'=>false],false);")
 check(request('status',write,{'id':job})[0]==200,'Disabling incoming writes preserves read-only status access')
 check(request('commit',write,{'id':job})[0]==403,'Site-wide incoming-write setting still overrides key scope')
 wp('eval',"update_option('_csm_settings',['pull'=>true,'push'=>true],false);")
 request('discard',write,{'id':job});job=None
 data={'id':bundle,'kind':'database','selection':{'tables':['wp_csm_fixture'],'plugins':[],'themes':[],'media':'none'}}
 check(request('bundle',read,data)[0]==200,'Read key can build a private resumable source snapshot')
 check(request('bundle_chunk',read,{'id':bundle,'kind':'database','offset':0})[0]==200,'Owner can read its source snapshot')
 check(request('bundle_chunk',other,{'id':bundle,'kind':'database','offset':0})[0]==403,'Different key cannot read another connection snapshot')
 check(request('bundle_discard',other,{'id':bundle})[0]==403,'Different key cannot delete another connection snapshot')
 request('bundle_discard',read,{'id':bundle})
 wp('eval',"CSM_Access::revoke('"+issued[0]['id']+"');")
 check(request('manifest',read)[0]==403,'Revocation takes effect on the next signed request')
 wp('eval',"$t=get_option('_csm_tokens');$t['"+issued[1]['id']+"']['expires']=time()-1;update_option('_csm_tokens',$t,false);")
 check(request('manifest',write)[0]==403,'Expired key is rejected even with a valid signature')
 listed=ev('echo wp_json_encode(CSM_Access::list());')
 check(all('key' not in x and 'pairing' not in x for x in listed),'Key listing never includes private connection codes')
 print(f'{checks} access checks passed.',flush=True)
finally:
 wp('eval',"update_option('_csm_settings',['pull'=>true,'push'=>true],false);")
 if job:request('discard',master,{'id':job})
 request('bundle_discard',master,{'id':bundle})
 wp('option','update','_csm_tokens',json.dumps(tokens),'--format=json')
 if settings is False:wp('option','delete','_csm_settings')
 else:wp('option','update','_csm_settings',json.dumps(settings),'--format=json')
