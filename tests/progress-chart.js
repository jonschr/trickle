/* Check the shipped Chart.js adapter, including keyboard access and reduced motion. */
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/progress-chart.js'),'utf8');
let config,handler,active=[],updates=[],destroyed=false;
class Chart {
 constructor(_,c){config=c;this.data=c.data;this.options=c.options;this.tooltip={setActiveElements:items=>active=items};}
 update(mode){updates.push(mode);}
 setActiveElements(items){active=items;}
 getDatasetMeta(){return {data:[{x:10,y:20},{x:30,y:40}]};}
 destroy(){destroyed=true;}
}
const ctx={CSMChart:Chart,matchMedia:()=>({matches:true})};vm.createContext(ctx);vm.runInContext(source,ctx);
const reading={},chart=new ctx.CSMProgressChart({addEventListener:(_,fn)=>handler=fn},reading);
assert.equal(config.options.animation,false);assert.equal(config.options.interaction.axis,'x');
const points=[{at:1000,x:1,y:2,bytes:1048576,kind:'files'},{at:2000,x:2,y:3,bytes:2097152,kind:'files'}];
chart.update(points,'transfer',{total:4194304,received:2097152});assert.equal(updates.at(-1),'none');
assert.equal(chart.chart.data.datasets[0].data[1].x,2097152);assert.equal(chart.chart.options.scales.x.max,4194304);
assert.equal(chart.chart.data.datasets[0].data[1].elapsed,2);
const before=updates.length;chart.update(points,'transfer',{total:4194304,received:2097152});assert.equal(updates.length,before);
let prevented=false;handler({key:'End',preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(active[0].index,1);assert.match(reading.textContent,/3.0 MB\/s.*2.0 MiB/);
handler({key:'Home',preventDefault(){}});assert.equal(active[0].index,0);
handler({key:'Escape',preventDefault(){}});assert.equal(active.length,0);
config.options.onHover(null,[{index:1}]);assert.match(reading.textContent,/3.0 MB\/s/);
assert.match(config.options.plugins.tooltip.callbacks.title([{raw:{x:2097152}}]),/50.0%/);
assert.equal(config.options.plugins.tooltip.callbacks.label({raw:points[0]}),'2.0 MB/s');
chart.update(points,'transfer',{total:4194304,received:2097152,windows:[{kind:'files',startBytes:0,endBytes:4194304,receivedEnd:2097152,state:'active'}]});
assert.match(chart.rangeLabel(1048576),/Files.*0.0 MiB–4.0 MiB.*active/);
chart.describePosition(1048576);assert.match(reading.textContent,/1.0 MiB.*2.0 MB\/s/);
chart.describePosition(3145728);assert.match(reading.textContent,/Not received yet/);assert.equal(reading.textContent.includes('MB/s'),false);
const draws=[],paints=[];
const drawContext={save(){},restore(){},beginPath(){},rect(){},clip(){},fillRect(...args){draws.push(args);paints.push(this.fillStyle);},moveTo(){},lineTo(){},stroke(){}};
config.plugins[0].beforeDatasetsDraw({ctx:drawContext,chartArea:{left:0,right:100,top:0,bottom:50},scales:{x:{getPixelForValue:v=>v/4194304*100}}});
assert.deepEqual(draws[0],[0,0,100,50]);assert.deepEqual(draws[1],[0,0,50,50]); // Received shading and speed use exactly the same byte scale.
chart.update(points,'transfer',{total:4194304,received:2097152,windows:[{kind:'files',startBytes:0,endBytes:4194304,receivedEnd:2097152,state:'received',verified:true}]});
config.plugins[0].beforeDatasetsDraw({ctx:drawContext,chartArea:{left:0,right:100,top:0,bottom:50},scales:{x:{getPixelForValue:v=>v/4194304*100}}});
assert.equal(paints.at(-1),'#eee3f4');assert.match(chart.rangeLabel(1048576),/verified/);
chart.update(points,'failed');assert.equal(chart.chart.data.datasets[0].borderColor,'#b32d2e');chart.update([],'verify',{stage:'verify',unit:'files',total:100,received:25,color:'#7b4397',wash:'#eee3f4'});
assert.equal(chart.chart.options.scales.y.display,false);assert.equal(chart.chart.data.datasets[0].borderColor,'#7b4397');assert.equal(chart.chart.options.scales.x.max,100);
chart.describePosition(10);assert.match(reading.textContent,/10 files.*processed/);assert.equal(reading.textContent.includes('MB/s'),false);
chart.destroy();assert.equal(destroyed,true);
const fallback={};vm.createContext(fallback);vm.runInContext(source,fallback);const absent=new fallback.CSMProgressChart({},{});absent.update([], 'preflight');absent.destroy();
console.log('Chart checks passed: configuration, reduced motion, unchanged samples, hover, keyboard tooltip, error color, byte alignment, progress shading and optional-library fallback.');
