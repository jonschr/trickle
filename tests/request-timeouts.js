/* Exercise the shipped request handler and Backups page against stalled responses. */
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const request=fs.readFileSync(path.join(__dirname,'../assets/request.js'),'utf8');
const backups=fs.readFileSync(path.join(__dirname,'../assets/backups.js'),'utf8');
function setup(fetch){
 const timers=new Map();let next=0;
 const c={CSM:{ajax:'fixture',nonce:'fixture',state:{connections:[]}},fetch,URLSearchParams,AbortController,
 setTimeout(fn,ms){const id=++next;timers.set(id,{fn,ms});return id;},clearTimeout(id){timers.delete(id);}};
 vm.createContext(c);vm.runInContext(request,c);return {c,timers};
}
const tick=()=>new Promise(setImmediate);
(async()=>{
 for(const phase of ['headers','body']){
  const {c,timers}=setup((url,{signal})=>new Promise((resolve,reject)=>{
   const stalled=new Promise((_,rejectBody)=>signal.addEventListener('abort',()=>{reject(Error('aborted'));rejectBody(Error('aborted'));}));
   if(phase==='body')resolve({status:200,text:()=>stalled});else stalled.catch(()=>{});
  }));
  const pending=c.CSMRequest('backups');await tick();assert.equal([...timers.values()][0].ms,150000);
  [...timers.values()][0].fn();await assert.rejects(pending,e=>e.retryable&&/timed out/.test(e.message));assert.equal(timers.size,0);
 }
 const {c,timers}=setup((url,{signal})=>new Promise((_,reject)=>signal.addEventListener('abort',()=>reject(Error('aborted')))));
 const elements=new Map();for(const id of ['error','backup-status','backup-items','backup-site','backup-refresh'])elements.set('csm-'+id,{hidden:true,value:'',disabled:false,addEventListener(){},replaceChildren(){}});
 const buttons=[elements.get('csm-backup-refresh'),elements.get('csm-backup-site')];const root={setAttribute(name,value){this[name]=value;}};
 c.document={getElementById:id=>elements.get(id),querySelector:()=>root,querySelectorAll:()=>buttons};c.location={search:''};
 vm.runInContext(backups,c);assert(buttons.every(button=>button.disabled));
 [...timers.values()][0].fn();await tick();assert(buttons.every(button=>!button.disabled));assert.equal(root['aria-busy'],'false');
 assert.equal(elements.get('csm-error').hidden,false);assert.match(elements.get('csm-error').textContent,/timed out/);
 const success=setup(async()=>({status:200,text:async()=>JSON.stringify({ok:true,data:{nonce:'new',answer:42}})}));
 assert.equal((await success.c.CSMRequest('state')).answer,42);assert.equal(success.c.CSM.nonce,'fixture','Transport cannot mutate nonce before cancellation guards');assert.equal(success.timers.size,0);
 const failure=setup(async()=>({status:400,text:async()=>JSON.stringify({ok:false,error:'Busy',busy:true,retryable:true,retry_exhausted:true,retry_after:3})}));
 await assert.rejects(failure.c.CSMRequest('restore_backup'),e=>e.busy&&e.retryable&&e.retryExhausted&&e.retryAfter===3);
 console.log('PASS Request deadlines cover headers and bodies; Backups unlocks on timeout; nonce ownership and retry flags are preserved.');
})().catch(error=>{console.error(error);process.exit(1);});
