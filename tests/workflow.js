/* Exercise the actual browser workflow with interrupted requests and retained state. */
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname,'../assets/admin.js'),'utf8');
const controls = new Map();
const selection = {tables:['wp_posts'],plugins:['fixture'],themes:[],media:'none'};
function context(options={}) {
 const calls=[],finished=[];
 const c={activeRequests:new Set(),updateSelection(){},cancellation:0,cancelling:false,cancelled:()=>Object.assign(Error("Transfer cancelled."),{cancelled:true}),esc:String,session:options.session || null,summary:null,sites:{local:{plugin_version:'test'}},previewId:'',previewItems:[],
  $:id=>{if(!controls.has(id))controls.set(id,{value:'remote',removeAttribute(){}});return controls.get(id);},
  currentSelection:()=>selection,destination:()=>({url:'https://destination.test'}),source:()=>({url:'https://source.test'}),direction:()=> 'pull',transferAction:()=> 'Pull',
  transferMeter:{begin(){},update(){},setPhase(){},finish(){}},showSummary(){},renderActivity(){},message(){},load:async()=>{},reviewGitFolders:async()=>[],
  finishTransfer:async(result,route)=>{finished.push([result.status,route.id]);c.session=null;},
  setTimeout:fn=>{if(fn.name!=='poll')fn();return 0;},clearTimeout(){},
  api:async(op,data)=>{
   if(op==='logs')return {entries:[]};
   if(op==='retry_state')return options.retryState?options.retryState():{stopped:false};
   if(op==='cancel')return {cancel_requested:true}; // Read-only telemetry does not affect the migration sequence.
   calls.push(op);
   if(options.handler){const result=options.handler(op,data,calls);if(result!==undefined)return result;}
   if(op==='review')return {git_folders:[]};
   if(op==='prepare')return {id:'transfer',status:'staging',pending:['database','files']};
   if(op==='status')return options.status || {id:'transfer',status:'staging',pending:['files']};
   if(op==='progress')return {};
   if(op==='preflight')return {id:'transfer',status:'staging',pending:['database','files']};
   if(op==='transfer')return {id:'transfer',status:'staging',pending:[],ready:true};
   if(op==='commit')return {id:'transfer',status:'applied'};
   if(op==='recover')return options.recovery || {status:'cancelled'};
   throw Error(op);
  }};
 vm.createContext(c);
 vm.runInContext(source.slice(source.indexOf('    async function transferRequest('),source.indexOf('    async function run(')),c);
 vm.runInContext(source.slice(source.indexOf('    async function migrate('),source.indexOf("    $('stage').addEventListener",source.indexOf('    async function migrate('))),c);
 return {c,calls,finished};
}
(async()=>{
 let x=context();await x.c.migrate();assert.deepEqual(x.calls,['review','prepare','preflight','preflight','transfer','transfer','commit']);assert.deepEqual(x.finished,[['applied','transfer']]);assert.equal(x.c.session,null);
 x=context({handler(op){if(op==='preflight')throw Error('Snapshot failed');}});await assert.rejects(()=>x.c.migrate(),/Snapshot failed/);assert.equal(x.calls.includes('commit'),false);assert.equal(x.calls.includes('transfer'),false);assert.equal(x.calls.at(-1),'recover');
 x=context({handler(op){if(op==='preflight')throw Object.assign(Error('Another transfer is running on this site.'),{busy:true,retryable:true});}});await x.c.migrate();assert.equal(x.calls.includes('recover'),false);assert.equal(x.c.session.id,'transfer');
 let drops=0;x=context({handler(op){if(op==='transfer' && drops++<2)throw Object.assign(Error('Network connection interrupted'),{retryable:true});}});await x.c.migrate();assert.equal(x.calls.filter(op=>op==='transfer').length,4);assert.equal(x.calls.filter(op=>op==='commit').length,1);
 x=context({session:{id:'retained',automatic:true,destination:'https://destination.test'},status:{status:'staging',pending:['files']}});await x.c.migrate();assert.deepEqual(x.calls,['status','preflight','transfer','commit']);
 x=context({session:{id:'retained',automatic:true},status:{status:'applied'}});await x.c.migrate();assert.deepEqual(x.calls,['status']);assert.deepEqual(x.finished,[['applied','retained']]);
 x=context({session:{id:'retained',automatic:true},status:{status:'applying'},handler(op){if(op==='recover')throw Object.assign(Error('Another transfer is running on this site.'),{busy:true,retryable:true});}});await x.c.migrate();assert.deepEqual(x.calls,['status','recover']);assert.equal(x.c.session.id,'retained');
 x=context({session:{id:'retained',automatic:true},status:{status:'applying'},recovery:{status:'restored'}});await x.c.migrate();assert.deepEqual(x.calls,['status','recover']);assert.deepEqual(x.finished,[['restored','retained']]);
 x=context({handler(op){if(op==='commit')throw Error('Connection timed out');},status:{status:'applying'},recovery:{status:'restored'}});await assert.rejects(()=>x.c.migrate(),/timed out/);assert.equal(x.calls.filter(op=>op==='commit').length,1);assert.deepEqual(x.finished,[['restored','transfer']]);
 x=context({handler(op){if(op==='transfer'){const e=Error('Remote server requested a pause');e.retryExhausted=true;e.retryAfter=60;throw e;}}});await x.c.migrate();assert.equal(x.calls.includes('recover'),false);assert.equal(x.calls.includes('commit'),false);assert.equal(x.c.session.id,'transfer');assert(x.c.session.retryAt>Date.now()+50000);
 x=context({handler(op){if(op==='transfer')throw Error('Invalid PHP');}});await assert.rejects(()=>x.c.migrate(),/Invalid PHP/);assert.equal(x.calls.filter(op=>op==='transfer').length,1);assert.equal(x.calls.at(-1),'recover');assert.equal(x.c.session,null);
 x=context({handler(op){if(op==='finish')return {};}});x.c.renderPreview=()=>{};x.c.URL=URL;x.c.feedback=()=>{};x.c.load=async()=>{throw Error('Inventory unavailable');};x.c.$('backup-link').href='https://destination.test/wp-admin/tools.php?page=trickle&view=backups';
 vm.runInContext(source.slice(source.indexOf('    function completionReceipt('),source.indexOf('    async function finishTransfer(')),x.c);
 vm.runInContext(source.slice(source.indexOf('    async function finishTransfer('),source.indexOf('    let previewItems',source.indexOf('    async function finishTransfer('))),x.c);
 await x.c.finishTransfer({status:'applied',tables:0,files:1},{id:'complete',direction:'pull',view_url:'https://frontend.test/'});assert.equal(x.c.$('completion').hidden,false);assert.equal(x.c.$('view-site').href,'https://frontend.test/');assert.equal(x.c.$('completion-title').textContent,'Pull complete');
 let warning='';x.c.load=async()=>{};x.c.feedback=text=>{warning=text;};await x.c.finishTransfer({status:'applied',tables:0,files:1,health:{verified:false},activation_missing:['missing/plugin.php']},{id:'complete',direction:'pull',view_url:'https://frontend.test/'});assert.match(warning,/health check/);assert.match(warning,/missing plugin/);
 // Successful receiver confirmation must survive the progress-rendering adapter.
 const {TransferProgress}=require('../assets/transfer-progress.js');
 const verifiedTimeline={id:'verified-adapter',elapsed:0,ready:true,components:{plugins:100},selected:['plugins'],streams:{files:{size:100,received:100}}};
 x=context({handler(op){if(op==='transfer')return {id:'verified-adapter',pending:[],timeline:verifiedTimeline,progress:{phase:'Files verified',state:'complete'}};}});
 x.c.transferMeter=new TransferProgress(null);x.c.transferMeter.begin({...verifiedTimeline,streams:{files:{size:100,received:0}}});x.c.renderActivity=detail=>x.c.transferMeter.update(detail);
 await x.c.transferRequest('transfer',{id:'verified-adapter',kind:'files'});assert.equal(x.c.transferMeter.verified.has('files'),true);
 // A packaging budget failure is terminal: never rebuild the same oversized selection automatically.
 x=context({handler(op){if(op==='preflight')throw Error('Source packaging exceeded the 45-second limit. Select fewer components or a smaller media date range.');}});
 await assert.rejects(()=>x.c.migrate(),/45-second limit/);assert.equal(x.calls.filter(op=>op==='preflight').length,1);assert.equal(x.calls.includes('commit'),false);assert.equal(x.c.session,null);
 // Cancel remains callable while migration is busy; an in-flight response cannot resume it.
 let release, busyRecoveries=0;
 x=context({handler(op){if(op==='preflight')return new Promise(resolve=>{release=resolve;});if(op==='recover'&&busyRecoveries++===0)throw Object.assign(Error('Another transfer is running on this site.'),{busy:true,retryable:true});}});
 x.c.renderPreview=()=>{};x.c.fail=error=>{throw error;};x.c.busy=true;
 vm.runInContext(source.slice(source.indexOf('    async function cancelTransfer('),source.indexOf("    $('discard').addEventListener",source.indexOf('    async function cancelTransfer('))),x.c);
 const inFlight=x.c.migrate();await new Promise(setImmediate);assert(release);
 await x.c.cancelTransfer();assert.equal(x.c.session,null);assert.equal(busyRecoveries,2);assert.equal(x.c.cancellation,1);
 release({id:'transfer',status:'staging',pending:['files']});await inFlight;
 assert.equal(x.calls.includes('transfer'),false);assert.equal(x.calls.includes('commit'),false);assert.equal(x.c.session,null);
 // Cancelling a completed apply must show its true receipt, not claim an unchanged destination.
 x=context({session:{id:'completed',automatic:true},recovery:{status:'applied'}});x.c.fail=error=>{throw error;};
 vm.runInContext(source.slice(source.indexOf('    async function cancelTransfer('),source.indexOf("    $('discard').addEventListener",source.indexOf('    async function cancelTransfer('))),x.c);
 await x.c.cancelTransfer();assert.deepEqual(x.finished,[['applied','completed']]);
 // A plugin name containing "Connection" must never turn a deterministic PHP error into a network retry.
 x=context({handler(op){if(op==='preflight')throw Error('Plugin Connection Tools: PHP parse error on PHP 8.5');}});
 await assert.rejects(()=>x.c.migrate(),/PHP parse error/);assert.equal(x.calls.filter(op=>op==='preflight').length,1);assert.equal(x.calls.includes('commit'),false);
 // An exhausted-request response cannot restart the browser migration forever.
 let failures=0;
 x=context({retryState:()=>({stopped:++failures>=5}),handler(op){if(op==='preflight')throw Object.assign(Error('HTTP 502'),{retryable:true,retryExhausted:true});}});
 x.c.cancelTransfer=async()=>{x.c.session=null;};
 for(let i=0;i<4;i++)await x.c.migrate();
 await assert.rejects(()=>x.c.migrate(),/stopped after repeated retries/);assert.equal(x.c.session,null);assert.equal(x.calls.includes('commit'),false);
 console.log('Workflow checks passed: one click, checkpoint retry, resume, completed response, interrupted apply, safe timeout recovery and failed-stage cleanup.');
})().catch(e=>{console.error(e);process.exitCode=1;});
