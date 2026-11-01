/* Deterministic checks of the shipped model; no network or migration writes. */
const assert = require('node:assert/strict');
const {TransferProgress,duration} = require('../assets/transfer-progress.js');
let now = 0;
const timeline = {id:'test',started:1700000000,elapsed:0,ready:true,components:{database:100,plugins:30,themes:20,media:50},streams:{database:{size:100,received:0},files:{size:100,received:0}}};
const meter = new TransferProgress(null, () => now);
meter.begin(timeline); meter.operation('transfer','database');
const checkpoint = (db,files) => ({timeline:{...timeline,elapsed:now/1000,streams:{database:{size:100,received:db},files:{size:100,received:files}}}});
now=4000; meter.update(checkpoint(50,0));
assert.equal(meter.metrics().percent,25);assert.equal(meter.metrics().eta,4); // Current stream, not a whole-transfer guess.
now=6000; meter.update(checkpoint(100,0));assert.equal(meter.metrics().eta,null);
meter.operation('transfer','files');assert.equal(meter.metrics().speed,null);
now=9000; meter.update(checkpoint(100,50));
assert.equal(meter.metrics().percent,75);assert.equal(meter.metrics().eta,3);
now=26000;assert.equal(meter.metrics().eta,null);assert.equal(meter.metrics().stalled,true);assert.equal(meter.metrics().speed,0);
meter.operation('preflight','files');assert.equal(meter.metrics().eta,null);assert.equal(meter.metrics().speed,null);
meter.update({phase:'Packaging files',count:0,total:10,unit:'files'});
now=30000;meter.update({phase:'Packaging files',count:5,total:10,unit:'files'});assert.equal(meter.metrics().eta,4);
meter.update({phase:'Packaging files',count:5,total:null,unit:'files'});assert.equal(meter.metrics().eta,null);
meter.setPhase('apply');meter.update({phase:'Checking frontend health',count:0,total:null,unit:''});assert.equal(meter.metrics().eta,null);
meter.update(checkpoint(100,100));assert.equal(meter.metrics().percent,100);assert.equal(meter.phase,'apply'); // Bytes received is not completion.
const events=Array.from({length:25},(_,i)=>({id:String(i),phase:'Exporting file',component:`plugins/x/${i}.php`,updated:i}));
meter.update({phase:'Exporting file',events});meter.update({phase:'Exporting file',events});assert.equal(meter.events.length,1);assert.equal(meter.events[0].id,'24');assert.equal(meter.events[0].grouped,25);
meter.finish('applied');assert.equal(meter.metrics().percent,100);const elapsed=meter.metrics().elapsed;now+=10000;assert.equal(meter.metrics().elapsed,elapsed);assert.equal(meter.metrics().eta,null);
meter.begin({...timeline,id:'another',ready:false,elapsed:25});assert.equal(meter.metrics().percent,null);assert.equal(meter.metrics().elapsed,25);assert.equal(meter.events.length,0);
meter.update({timeline:{...timeline,id:'another',streams:{files:{size:0,received:0}},components:{}}});assert.equal(meter.metrics().percent,0);

// Never fabricate chunks for a continuous pull; bounded uploads and explicit chunks use their real sizes.
const unit=16777216;
meter.configure({direction:'push',transport:'stream'});
meter.begin({...timeline,id:'windows',streams:{files:{size:unit*2+5,received:unit}}});
assert.equal(meter.windows().count,3);assert.equal(meter.windows().complete,1);assert.equal(meter.windows().items[0].state,'received');assert.equal(meter.windows().items[2].endBytes,unit*2+5);
meter.update({timeline:{...meter.timeline,streams:{files:{size:unit*2+5,received:unit*2+5}}}});assert.equal(meter.windows().complete,3);
meter.configure({direction:'pull',transport:'stream'});assert.equal(meter.windows(),null);
meter.configure({direction:'pull',transport:'chunks'});assert.equal(meter.windows().unit,524288);
meter.update({timeline:{...meter.timeline,streams:{files:{size:unit*1000,received:0}}}});assert(meter.windows().items.length<=120);assert(meter.windows().group>1);

// Live bytes cannot be confused with packaging counts or another stream's counters.
meter.begin({...timeline,id:'live'});meter.operation('transfer','database');
meter.update({phase:'Receiving bytes',component:'files',unit:'bytes',count:50,total:100,state:'running'});assert.equal(meter.streamReceived('database'),0);
meter.update({phase:'Receiving bytes',component:'database',unit:'bytes',count:50,total:100,state:'running'});assert.equal(meter.streamReceived('database'),50);
meter.update({phase:'Receiving bytes',component:'database',unit:'bytes',count:25,total:100,state:'running'});assert.equal(meter.streamReceived('database'),50);

// Failed attempt, retry, resolution and terminal failure are distinct states.
meter.update({phase:'Receiving bytes',state:'failed',run:'a',updated:1,failure:{phase:'Receiving bytes',error:'Unexpected EOF'}});assert.equal(meter.notice.kind,'warning');assert.equal(meter.phase,'transfer');
meter.retry('Unexpected EOF',2,'retry-1');assert.equal(meter.retryCount,1);assert.equal(meter.notice.kind,'retry');assert.equal(meter.metrics().eta,null);
meter.retry('Unexpected EOF',2,'retry-1');assert.equal(meter.retryCount,1);
meter.resolved();assert.equal(meter.notice,null);
meter.update({phase:'Receiving bytes',state:'failed',run:'a',updated:1});assert.equal(meter.notice,null); // Stale poll cannot reinstate the warning.
meter.fail(new Error('Invalid PHP in plugins/example.php'));assert.equal(meter.notice.kind,'error');assert.match(meter.notice.error,/Invalid PHP/);
const empty=new TransferProgress(null,()=>now);empty.fail('Connection failed');empty.retry('Offline'); // Generic pre-transfer errors remain safe.

// Diagnostics are scoped to the transfer and deduplicated. Historical retries don't pause a healthy transfer.
now=5000;meter.begin({...timeline,id:'logs',elapsed:5});meter.operation('transfer','database');
const log={time:new Date((timeline.started+3)*1000).toISOString(),event:'Connection interrupted; retrying safe transfer step',context:{transfer_id:'logs',duration_ms:4000,error:'HTTP 502'}};
meter.ingestLogs([{...log,context:{...log.context,transfer_id:'other'}}, {...log,event:'Unrelated event'}]);assert.equal(meter.retryCount,0);
meter.ingestLogs([log,log]);assert.equal(meter.retryCount,1);assert.equal(meter.notice.kind,'retry');assert.equal(meter.notice.until,now+2000);
meter.resolved();meter.rate=100;
const old={...log,time:new Date((timeline.started+2)*1000).toISOString()};meter.ingestLogs([old]);assert.equal(meter.retryCount,2);assert.equal(meter.notice,null);assert.equal(meter.rate,100);

// Reload preserves history, never a stale speed estimate. A resumed offset isn't a new download.
let stored;global.sessionStorage={setItem:(_,value)=>stored=value,getItem:()=>stored};meter.save();
const restored=new TransferProgress(null,()=>now);restored.begin(meter.timeline);assert.equal(restored.retryCount,2);assert.equal(restored.metrics().speed,null);
restored.begin({...timeline,id:'resume',elapsed:60,streams:{files:{size:100,received:80}}});restored.operation('transfer','files');assert.equal(restored.metrics().speed,null);
now+=4000;restored.update({timeline:{...restored.timeline,streams:{files:{size:100,received:90}}}});assert.equal(restored.metrics().speed,2.5);assert.equal(restored.metrics().eta,4);
for(let i=0;i<200;i++){now+=1000;restored.recordSample();}assert.equal(restored.samples.length,120);
const savedTimes=restored.stageTimes();restored.save();const reloaded=new TransferProgress(null,()=>now);reloaded.begin(restored.timeline);assert.equal(reloaded.stageTimes().transfer,savedTimes.transfer);
delete global.sessionStorage;
// Tabs follow actual stage transitions, retain prior stage details, and don't fight inspection on each poll.
now=0;const staged=new TransferProgress(null,()=>now);staged.begin({...timeline,id:'stage-tabs'});
staged.operation('preflight','files');staged.update({phase:'Packaging files',component:'uploads/example.jpg',count:2,total:10,unit:'files'});
assert.equal(staged.presentation().stage,'preflight');assert.equal(staged.presentation().percent,20);
staged.operation('transfer','files');assert.equal(staged.selectedStage,'transfer');
staged.selectStage('preflight');assert.equal(staged.presentation().detail.component,'uploads/example.jpg');assert.equal(staged.presentation().finished,true);
staged.update({phase:'Receiving stream',component:'files',unit:'bytes',count:50,total:100,state:'running'});assert.equal(staged.selectedStage,'preflight');
staged.selectStage('health');assert.equal(staged.selectedStage,'preflight'); // Unreached tabs are unavailable.
staged.selectStage('transfer');staged.update({phase:'Receiving and verifying file',component:'plugins/example.php',count:2,total:10,unit:'files'});assert.equal(staged.selectedStage,'transfer');
staged.operation('commit');assert.equal(staged.selectedStage,'transfer');
staged.update({phase:'Checking frontend health',component:'WordPress frontend',unit:'',count:0,total:null});assert.equal(staged.selectedStage,'health');assert.equal(staged.presentation().percent,null);
staged.finish('applied',{health:{verified:true,status:200}});assert.equal(staged.terminalHealth.status,200);
staged.selectStage('transfer');assert.equal(staged.presentation().stage,'transfer');assert.equal(staged.presentation().live,false);
staged.begin({...timeline,id:'fresh-tabs'});assert.equal(staged.selectedStage,'preflight');assert.equal(staged.stages.transfer,undefined);
// Stage clocks accumulate repeated visits, freeze at completion, and never invent an unseen health duration.
now=0;const timed=new TransferProgress(null,()=>now);timed.begin({...timeline,id:'timed'});
now=2000;timed.operation('transfer','database');now=5000;timed.setPhase('verify');
now=7000;timed.operation('transfer','files');now=11000;timed.selectStage('preflight');
assert.equal(timed.stageTimes().preflight,2);assert.equal(timed.stageTimes().verify,undefined);assert.equal(timed.stageTimes().transfer,9);
now=13000;timed.finish('applied');assert.equal(timed.stageTimes().transfer,11);assert.equal(timed.stageTimes().health,undefined);
now=20000;assert.equal(timed.stageTimes().transfer,11);
const combined=new TransferProgress(null,()=>now);combined.configure({direction:'push',transport:'stream'});
combined.begin({...timeline,id:'combined',streams:{database:{size:5,received:5},files:{size:unit*2+5,received:unit}}});
assert.equal(combined.windows().count,4);assert.equal(combined.windows().complete,2);
assert.equal(combined.windows().items[1].startBytes,5);assert.equal(combined.windows().items.at(-1).endBytes,combined.metrics().total);
combined.operation('transfer','files');combined.rate=10;combined.recordSample();assert.equal(combined.samples.at(-1).position,combined.metrics().received);
// Verification during commit is still verification; known counts never inherit compressed transfer totals.
const verification=new TransferProgress(null,()=>now);verification.begin({...timeline,id:'verification'});verification.operation('commit');
verification.update({phase:'Verifying staged file',component:'plugins/example.php',count:25,total:100,unit:'files'});
assert.equal(verification.presentation().stage,'transfer');assert.equal(verification.presentation().detail.count,25);assert.equal(verification.presentation().detail.total,100);assert.equal(verification.presentation().verifying,true);
verification.update({phase:'Replacing file',component:'plugins/example.php',count:2,total:10,unit:'files'});assert.equal(verification.presentation().stage,'apply');
verification.update({phase:'Receiving and verifying file',component:'uploads/photo.jpg',count:2,total:null,unit:'files'});assert.equal(verification.presentation().detail.total,null);assert.equal(verification.presentation().total,200);assert.equal(verification.presentation().percent,0);
// Source preparation cannot be switched to replacement by a stale progress file.
const ordered=new TransferProgress(null,()=>now);ordered.begin({...timeline,id:'ordered'});ordered.operation('preflight','database');
ordered.update({phase:'Replacing file',run:'older',state:'running',component:'old.php',count:3,total:10,unit:'files'});assert.equal(ordered.selectedStage,'preflight');assert.equal(ordered.detail,null);
ordered.update({phase:'Exporting table',run:'source-db',state:'running',count:2,unit:'rows'});
ordered.operation('preflight','files');ordered.update({phase:'Checking frontend health',run:'source-db',state:'running'});assert.equal(ordered.selectedStage,'preflight');
ordered.update({phase:'Exporting file',run:'source-files',state:'running'});ordered.operation('transfer','database');
ordered.update({phase:'Verifying staged file',run:'source-files',state:'running'});assert.equal(ordered.selectedStage,'transfer');
ordered.update({phase:'Receiving and verifying table',run:'receive-db',state:'running'});assert.equal(ordered.selectedStage,'transfer');
ordered.operation('transfer','files');ordered.update({phase:'Receiving and verifying table',run:'receive-db',state:'running'});assert.equal(ordered.selectedStage,'transfer');
ordered.update({phase:'Replacing file',run:'stale-replace',state:'running'});assert.equal(ordered.selectedStage,'transfer');
ordered.operation('commit');assert.equal(ordered.selectedStage,'transfer');ordered.update({phase:'Replacing file',run:'apply',state:'running'});assert.equal(ordered.selectedStage,'apply');
// A received checkpoint does not mean verified. Only completed receiver responses (or applied status) do.
const verified=new TransferProgress(null,()=>now);verified.configure({direction:'push',transport:'stream'});
const full={...timeline,id:'verified',streams:{database:{size:100,received:100},files:{size:100,received:100}}};verified.begin(full);
verified.update({timeline:full,pending:['database','files']});assert.equal(verified.verified.size,0);
verified.operation('transfer','database');verified.update({phase:'Receiving and verifying table',count:1,total:2,unit:'tables'});assert.equal(verified.verified.size,0);assert.equal(verified.selectedStage,'transfer');
verified.update({timeline:full,pending:['files']});assert.equal(verified.verified.has('database'),true);assert.equal(verified.verified.has('files'),false);assert.equal(verified.byteRanges()[0].verified,true);assert.equal(verified.byteRanges()[1].verified,false);
verified.operation('transfer','files');assert.equal(verified.selectedStage,'transfer');verified.update({timeline:full,pending:[]});assert.equal(verified.verified.size,2);
verified.configure({direction:'pull',transport:'stream'});assert(verified.byteRanges().every(range=>range.verified));
let snapshot;global.sessionStorage={setItem(_,value){snapshot=value;},getItem(){return snapshot;}};verified.save();
snapshot=JSON.stringify({...JSON.parse(snapshot),stageDurations:{preflight:1,transfer:3,verify:2}});
const verifiedReload=new TransferProgress(null,()=>now);verifiedReload.begin(full);assert.equal(verifiedReload.verified.size,2);assert.equal(verifiedReload.stageTimes().transfer,5);assert.equal(verifiedReload.stageTimes().verify,undefined);assert.equal(verifiedReload.stages.verify,undefined);delete global.sessionStorage;

assert.equal(duration(3661),'1h 1m');assert.equal(duration(65),'1m 05s');assert.equal(duration(-1),'0s');
console.log('Progress checks passed: stream speed/ETA, stalls, phases, windows, live bytes, retry/error states, log scoping, bounded history and resume.');

// Browsing history stays put across stage transitions until Follow live is chosen.
const follow = new TransferProgress(null,()=>now);follow.begin({...timeline,id:'follow'});
follow.operation('transfer','files');follow.selectStage('preflight');follow.operation('commit');
follow.update({phase:'Replacing file',count:1,total:2,unit:'files'});
assert.equal(follow.selectedStage,'preflight');assert.equal(follow.following,false);
follow.followLive();assert.equal(follow.selectedStage,'apply');assert.equal(follow.following,true);
// Routine file chatter cannot evict errors and retries.
follow.addEvent({id:'important',phase:'Retry',state:'retry',error:'HTTP 502',updated:1});
for(let i=0;i<25;i++)follow.addEvent({id:'routine'+i,phase:'File phase '+i,component:'file'+i,updated:i+2});
assert(follow.events.some(event=>event.id==='important'));assert(follow.events.length<=16);

// Pipeline uses actual stream bytes; receipt alone cannot imply checksum verification.
const pipe = new TransferProgress(null,()=>now);pipe.begin({...timeline,id:'pipeline',streams:{database:{size:10,received:10},files:{size:100,received:50}}});
assert.equal(pipe.pipelineRows()[0].status,'Awaiting verification');assert.equal(pipe.pipelineRows()[1].percent,50);
pipe.update({pending:['files'],timeline:pipe.timeline});assert.equal(pipe.pipelineRows()[0].status,'Verified');
pipe.retry('connection interrupted',2);assert.equal(pipe.pipelineRows()[1].status,'Retrying');pipe.resolved();
pipe.finish('cancelled');assert.equal(pipe.pipelineRows()[1].status,'Stopped');
pipe.begin({...timeline,id:'unknown-pipeline',streams:{files:{size:null,received:0}}});assert.equal(pipe.pipelineRows()[0].percent,null);assert.equal(pipe.pipelineRows()[0].status,'Preparing');
pipe.finish('applied');assert.equal(pipe.pipelineRows().find(row=>row.name==='Health').status,'Not confirmed');
assert.equal(pipe.pipelineRows().find(row=>row.name==='Replace').percent,100);
pipe.terminalHealth={verified:true,status:200};pipe.stages.health={detail:{count:3,total:5,unit:'bytes'}};
assert.equal(pipe.pipelineRows().find(row=>row.name==='Health').percent,100);
assert.equal(pipe.pipelineRows().find(row=>row.name==='Health').detail,'Destination homepage · HTTP 200');
pipe.terminalHealth={verified:false,status:503};assert.equal(pipe.pipelineRows().find(row=>row.name==='Health').percent,null);
