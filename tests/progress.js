/* Deterministic checks of the shipped model; no network or migration writes. */
const assert = require('node:assert/strict');
const {TransferProgress,duration} = require('../assets/transfer-progress.js');
let now = 0;
const timeline = {id:'test',started:1700000000,elapsed:0,ready:true,components:{database:100,plugins:30,themes:20,media:50},streams:{database:{size:100,received:0},files:{size:100,received:0}}};
const meter = new TransferProgress(null, () => now);
meter.begin(timeline); meter.operation('transfer','database');
const checkpoint = (db,files) => ({timeline:{...timeline,elapsed:now/1000,streams:{database:{size:100,received:db},files:{size:100,received:files}}}});
now=4000; meter.update(checkpoint(50,0));
assert.equal(meter.receivedBytes(),50);assert.equal(meter.metrics().stalled,false);
now=6000; meter.update(checkpoint(100,0));
meter.operation('transfer','files');assert.equal(meter.metrics().stalled,false);
now=9000; meter.update(checkpoint(100,50));assert.equal(meter.receivedBytes(),150);
now=26000;assert.equal(meter.metrics().stalled,true);
meter.operation('preflight','files');assert.equal(meter.metrics().stalled,false);
meter.update({phase:'Packaging files',count:0,total:10,unit:'files'});
now=30000;meter.update({phase:'Packaging files',count:5,total:10,unit:'files'});assert.equal(meter.metrics().stalled,false);
now=46000;meter.update({phase:'Packaging files',count:5,total:10,unit:'files'});assert.equal(meter.metrics().stalled,true,'Repeated reports do not disguise a stall');
meter.setPhase('apply');meter.update({phase:'Checking destination state',count:0,total:null,unit:''});assert.equal(meter.metrics().stalled,false);
meter.update(checkpoint(100,100));assert.equal(meter.receivedBytes(),200);assert.equal(meter.phase,'apply'); // Bytes received is not completion.
const events=Array.from({length:25},(_,i)=>({id:String(i),phase:'Exporting file',component:`plugins/x/${i}.php`,updated:i}));
meter.update({phase:'Exporting file',events});meter.update({phase:'Exporting file',events});assert.equal(meter.events.length,1);assert.equal(meter.events[0].component,'plugins/x');assert.equal(meter.events[0].phase,'Exporting files');
meter.finish('applied');const elapsed=meter.metrics().elapsed;now+=10000;assert.equal(meter.metrics().elapsed,elapsed);assert.equal(meter.metrics().stalled,false);
meter.begin({...timeline,id:'another',ready:false,elapsed:25});assert.equal(meter.metrics().elapsed,25);assert.equal(meter.events.length,0);

// Live bytes cannot be confused with packaging counts or another stream's counters.
meter.begin({...timeline,id:'live'});meter.operation('transfer','database');
meter.update({phase:'Receiving bytes',component:'files',unit:'bytes',count:50,total:100,state:'running'});assert.equal(meter.streamReceived('database'),0);
meter.update({phase:'Receiving bytes',component:'database',unit:'bytes',count:50,total:100,state:'running'});assert.equal(meter.streamReceived('database'),50);
meter.update({phase:'Receiving bytes',component:'database',unit:'bytes',count:25,total:100,state:'running'});assert.equal(meter.streamReceived('database'),50);

// Failed attempt, retry, resolution and terminal failure are distinct states.
meter.update({phase:'Receiving bytes',state:'failed',run:'a',updated:1,failure:{phase:'Receiving bytes',error:'Unexpected EOF'}});assert.equal(meter.notice.kind,'warning');assert.equal(meter.phase,'transfer');
meter.retry('Unexpected EOF',2,'retry-1');assert.equal(meter.retryCount,1);assert.equal(meter.notice.kind,'retry');
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
meter.resolved();
const old={...log,time:new Date((timeline.started+2)*1000).toISOString()};meter.ingestLogs([old]);assert.equal(meter.retryCount,2);assert.equal(meter.notice,null);

// Reload preserves history and resumes at the acknowledged offset.
let stored;global.sessionStorage={setItem:(_,value)=>stored=value,getItem:()=>stored};meter.save();
const restored=new TransferProgress(null,()=>now);restored.begin(meter.timeline);assert.equal(restored.retryCount,2);
restored.begin({...timeline,id:'resume',elapsed:60,streams:{files:{size:100,received:80}}});restored.operation('transfer','files');assert.equal(restored.receivedBytes(),80);
now+=16000;restored.update({timeline:{...restored.timeline,streams:{files:{size:100,received:90}}}});assert.equal(restored.receivedBytes(),90);assert.equal(restored.metrics().stalled,false);
delete global.sessionStorage;

// Component weights use payload sizes; unknown boundaries never invent individual progress.
const simple=new TransferProgress(null,()=>now);
simple.begin({...timeline,id:'simple',selected:['database','plugins','themes','media'],streams:{database:{size:100,received:100},files:{size:100,received:50}}});
assert.deepEqual(simple.components().map(c=>c.bytes),[100,30,20,50]);
assert.deepEqual(simple.components().map(c=>c.received),[1,null,null,null]);
assert(simple.components().every(c=>c.replaced===0));
simple.operation('commit');
simple.update({phase:'Replacing file',component:'plugins/one/a.php',count:2,total:10,unit:'files',replacement:{plugins:{done:2,total:4},themes:{done:0,total:2},media:{done:0,total:4}}});
assert.equal(simple.components()[1].replaced,.5);assert.equal(simple.components()[2].replaced,0);assert.equal(simple.components()[0].replaced,0);
simple.update({phase:'Checking destination state',component:'Destination state',count:0,total:null});
assert.equal(simple.status(),'Finishing up…');assert(simple.components().every(c=>c.replaced===1));assert.notEqual(simple.phase,'complete');
simple.finish('applied');assert(simple.components().every(c=>c.state==='Applied'));const finishedElapsed=simple.metrics().elapsed;now+=5000;assert.equal(simple.metrics().elapsed,finishedElapsed);
simple.begin({...timeline,id:'simple-new',selected:['plugins']});assert.equal(simple.components()[0].replaced,0,'New transfer cannot inherit replacement');
simple.update({phase:'Replacing file',component:'plugins/one/a.php',replacement:{plugins:{done:1,total:2}}});
let replacementSaved;global.sessionStorage={setItem(_,v){replacementSaved=v;},getItem(){return replacementSaved;}};simple.save();
const resumed=new TransferProgress(null,()=>now);resumed.begin(simple.timeline);assert.equal(resumed.components()[0].replaced,.5);
resumed.setPhase('recover');assert.equal(resumed.components()[0].replaced,0);resumed.finish('restored');assert.equal(resumed.components()[0].state,'Restored');delete global.sessionStorage;

// Old reports cannot move the display back from replacement to preparation.
const current=new TransferProgress(null,()=>now);current.begin({...timeline,id:'current'});current.operation('preflight','files');
current.update({phase:'Replacing file',component:'plugins/old/file.php',run:'old'});assert.equal(current.detail,null);
current.update({phase:'Packaging file',component:'plugins/a/a.php',run:'prep'});current.operation('transfer','files');
now+=16000;current.update({phase:'Packaging file',component:'plugins/a/a.php',run:'prep'});assert.equal(current.detail,null);assert.equal(current.metrics().stalled,true,'Stale reports cannot disguise a stall');
current.update({phase:'Verifying staged file',component:'plugins/a/a.php',run:'transfer',count:2,total:5,unit:'files'});assert.equal(current.phase,'verify');
current.operation('commit');current.update({phase:'Replacing file',component:'plugins/a/a.php',run:'commit',count:2,total:5,unit:'files'});assert.equal(current.phase,'apply');

assert.equal(duration(3661),'1h 1m');assert.equal(duration(65),'1m 05s');assert.equal(duration(-1),'0s');
console.log('Progress checks passed: timing, payload weights, shared stream fill, component replacement, recovery, stale reports, errors, saved history and resume.');

// File chatter becomes component milestones; one plugin cannot erase another or hide a failure.
const activity=new TransferProgress(null,()=>now);activity.begin({...timeline,id:'activity'});
const fileEvents=[];
for(const component of ['plugins/first','plugins/second','themes/child','uploads/2025/01','uploads/2025/02','uploads/2026/01']){
 for(let i=0;i<100;i++)fileEvents.push({id:component+i,phase:'Packaging file',component:component+'/'+i+'.php',state:'running',updated:i});
}
activity.update({phase:'Packaging file',events:fileEvents});activity.update({phase:'Packaging file',events:fileEvents});
assert.deepEqual(activity.events.map(event=>event.component),['plugins/first','plugins/second','themes/child','uploads/2025','uploads/2026']);
activity.addEvent({id:'bad-php',phase:'Checking PHP compatibility',component:'plugins/first/includes/bad.php',state:'failed',error:'Parse error',updated:101});
assert.equal(activity.events.at(-1).component,'plugins/first/includes/bad.php');
for(let i=0;i<100;i++)activity.addEvent({id:'table-'+i,phase:'Staging table',component:'wp_table_'+i,state:'running',updated:102+i});
assert.equal(activity.events.length,65);assert(activity.events.some(event=>event.id==='bad-php'));
let activitySaved;global.sessionStorage={setItem(_,value){activitySaved=value;},getItem(){return activitySaved;}};
activity.save();const activityRestored=new TransferProgress(null,()=>now);activityRestored.begin(activity.timeline);
assert.equal(activityRestored.events.length,65);assert(activityRestored.events.some(event=>event.id==='bad-php'));delete global.sessionStorage;
const cleared=new TransferProgress(null,()=>now);cleared.begin({...timeline,id:'cleared'});cleared.finish('applied');cleared.clear();cleared.render();assert.equal(cleared.metrics(),null,'Closed or new transfers cannot display the previous result');

// Compressed offsets, not relative payload sizes, determine individual transfer progress.
const mapped=new TransferProgress(null,()=>now);
const ranges={plugins:{start:10,end:40},themes:{start:40,end:80},media:{start:80,end:100}};
function mappedAt(offset){mapped.begin({...timeline,id:'mapped',selected:['plugins','themes','media'],streams:{files:{size:110,received:offset,component_ranges:ranges}}});mapped.operation('transfer','files');mapped.update({timeline:{...mapped.timeline,streams:{files:{size:110,received:offset,component_ranges:ranges}}}});return mapped.components();}
assert.deepEqual(mappedAt(25).map(c=>c.received),[.5,0,0]);
assert.deepEqual(mappedAt(60).map(c=>c.received),[1,.5,0]);assert.equal(mapped.components()[0].state,'Transferred');
assert.deepEqual(mappedAt(90).map(c=>c.received),[1,1,.5]);
mapped.update({phase:'Receiving bytes',component:'files',count:110,total:110,unit:'bytes',state:'running'});assert.equal(mapped.components()[2].received,.5,'Unacknowledged live bytes cannot inflate component receipts');
mapped.update({pending:[],timeline:{...mapped.timeline,streams:{files:{size:110,received:110,component_ranges:ranges}}}});assert(mapped.components().every(c=>c.state==='Verified'));
const unknown=new TransferProgress(null,()=>now);unknown.begin({...timeline,id:'unknown',selected:['plugins','themes'],streams:{files:{size:100,received:23}}});unknown.operation('transfer','files');assert.deepEqual(unknown.components().map(c=>c.received),[null,null]);assert.match(unknown.status(),/23%/);
unknown.fail(new Error('Connection lost'));assert.equal(unknown.notice.text,'Transfer needs attention');unknown.finish('cancelled');assert(unknown.components().every(c=>c.state==='Cancelled'));
const orderly=new TransferProgress(null,()=>now);orderly.begin({...timeline,id:'ordered'});
orderly.addEvent({id:'new',updated:20,phase:'Transferring files stream',component:'files',state:'running'});
orderly.addEvent({id:'old',updated:10,phase:'Preparing files',component:''});
orderly.addEvent({id:'duplicate',updated:21,phase:'Transferring files stream',component:''});assert.equal(orderly.events.length,2);assert.equal(orderly.events.at(-1).updated,20);
orderly.addEvent({id:'bulk',updated:22,phase:'Replacing database tables atomically',component:'wp_posts, wp_options'});assert.equal(orderly.events.at(-1).phase,'Replacing 2 tables');assert.equal(orderly.events.at(-1).component,'');
orderly.addEvent({id:'activate-one',updated:23,phase:'Checking plugin activation',component:'first/first.php'});orderly.addEvent({id:'activate-two',updated:24,phase:'Updating plugin activation',component:''});assert.equal(orderly.events.filter(e=>e.phase==='Updating plugin activation').length,1);
orderly.addEvent({id:'server-done',updated:25,phase:'Transfer complete',state:'complete'});orderly.finish('applied');assert.equal(orderly.events.filter(e=>e.state==='complete').length,1);
console.log('PASS Measured component offsets, conservative acknowledgements, honest legacy fallback, chronological grouped activity, and accurate cancellation/error labels.');

// Late polls cannot rewind acknowledged receipts, metadata, or replacement.
const monotonic=new TransferProgress(null,()=>now);
const base={...timeline,id:'monotonic',ready:true,estimated:{database:false,plugins:false,themes:false,media:false},selected:['database','plugins','themes','media'],streams:{database:{size:100,received:100},files:{size:110,received:90,component_ranges:ranges}}};
monotonic.begin(base);monotonic.operation('transfer','files');
const first=monotonic.components().map(c=>c.received);
monotonic.update({timeline:{...base,streams:{database:{size:100,received:0},files:{size:110,received:30}}}});
assert.deepEqual(monotonic.components().map(c=>c.received),first);assert.deepEqual(monotonic.timeline.streams.files.component_ranges,ranges);
monotonic.update({timeline:{...base,ready:false,estimated:{plugins:true},components:{plugins:999},streams:{files:{size:null,received:0}}}});
assert.equal(monotonic.timeline.components.plugins,30);assert.equal(monotonic.timeline.streams.files.size,110);
monotonic.operation('commit');monotonic.update({phase:'Replacing file',replacement:{plugins:{done:8,total:10}}});
monotonic.update({phase:'Checking destination',replacement:{plugins:{done:2,total:10}}});assert.equal(monotonic.phase,'apply');assert.equal(monotonic.components()[1].replaced,.8);
monotonic.update({replacement:{plugins:{done:0,total:0}}});assert.equal(monotonic.components()[1].replaced,.8);
let savedMonotonic;global.sessionStorage={setItem(_,v){savedMonotonic=v;},getItem(){return savedMonotonic;}};monotonic.save();
const reopened=new TransferProgress(null,()=>now);reopened.begin({...base,streams:{files:{size:110,received:20}}});assert.equal(reopened.timeline.streams.files.received,90);assert.equal(reopened.components()[1].replaced,.8);
reopened.begin({...base,id:'different-job',streams:{files:{size:110,received:0,component_ranges:ranges}}});assert.equal(reopened.components()[1].received,0);delete global.sessionStorage;
console.log('PASS Monotonic acknowledged progress, pinned metadata, replacement counts, stage transitions, reloads, and reset for new transfers.');
