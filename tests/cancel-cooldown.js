const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../assets/admin.js','utf8');
let recovers=0;const waits=[],messages=[],controls={discard:{},stage:{},error:{}};
const context={session:{id:'fixture',automatic:true},cancelling:false,cancellation:0,activeRequests:new Set(),summary:{},transferView:true,
 $:id=>controls[id]||(controls[id]={}),message:text=>messages.push(text),api:async op=>{if(op==='recover'&&++recovers===1)throw Object.assign(new Error('Pause'),{retryable:true,retryAfter:2});return op==='recover'?{status:'cancelled'}:{};},
 transferMeter:{finish(status){assert.equal(status,'cancelled');}},finishTransfer(){throw Error('Unexpected restore');},renderPreview(){},load:async()=>{},fail(error){throw error;},updateSelection(){},Date,Promise,setTimeout(fn,ms){waits.push(ms);fn();}};
vm.createContext(context);vm.runInContext(source.slice(source.indexOf('    async function cancelTransfer()'),source.indexOf("    $('discard').addEventListener('click', cancelTransfer)")),context);
(async()=>{await context.cancelTransfer();assert.equal(recovers,2);assert.deepEqual(waits,[2000]);assert.equal(context.session,null);assert.equal(context.cancelling,false);assert(messages.some(s=>s.includes('Waiting 2s')));console.log('PASS Cancel respects Retry-After and completes recovery without a second click');})().catch(e=>{console.error(e);process.exitCode=1;});
