const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/admin.js'),'utf8');
const code=source.slice(source.indexOf('    async function api('),source.indexOf('    function message('));
function context(fetch){const c={CSM:{ajax:'fixture',nonce:'fixture'},cancellation:0,activeRequests:new Set(),cancelled:()=>Object.assign(Error('cancelled'),{cancelled:true}),renderConnections(){throw Error('Stale response changed connection state');},fetch,URLSearchParams,AbortController,setTimeout,clearTimeout};vm.createContext(c);vm.runInContext(code,c);return c;}
(async()=>{
 let c=context((url,{signal})=>new Promise((resolve,reject)=>signal.addEventListener('abort',()=>reject(Error('aborted')))));
 const pending=c.api('transfer',{id:'fixture'});await Promise.resolve();assert.equal(c.activeRequests.size,1);c.cancellation++;for(const controller of c.activeRequests)controller.abort();await assert.rejects(pending,e=>e.cancelled===true);assert.equal(c.activeRequests.size,0);
 let release;c=context(async()=>({status:200,text:()=>new Promise(resolve=>{release=resolve;})}));
 const stale=c.api('status',{id:'fixture'});await new Promise(setImmediate);c.cancellation++;release(JSON.stringify({ok:true,data:{state:{connections:[]}}}));await assert.rejects(stale,e=>e.cancelled===true);
 c=context(async()=>({status:400,text:async()=>JSON.stringify({ok:false,error:'Plugin Connection Tools: PHP parse error'})}));await assert.rejects(c.api('preflight',{id:'fixture'}),e=>!e.retryable);
 c=context(async()=>({status:502,text:async()=>'gateway'}));await assert.rejects(c.api('transfer',{id:'fixture'}),e=>e.retryable===true);
 console.log('API cancellation checks passed: pending fetch aborts, stale status cannot resurrect session, deterministic errors do not retry, 502 does.');
})().catch(e=>{console.error(e);process.exit(1);});
