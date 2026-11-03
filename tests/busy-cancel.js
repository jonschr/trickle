const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const source=fs.readFileSync(__dirname+'/../assets/admin.js','utf8');
const cancel={id:'csm-discard',disabled:false,matches:()=>false,closest:()=>null},other={id:'csm-other',disabled:false,matches:()=>false,closest:()=>null};
const root={setAttribute(){}},feedback={textContent:''};let release;
const context={busy:false,cancelling:false,$:()=>feedback,document:{querySelector:()=>root,querySelectorAll:()=>[cancel,other]},feedback(){},fail(e){throw e;},lockSelection(){},updateSelection(){},transferMeter:{},Promise};
vm.createContext(context);vm.runInContext(source.slice(source.indexOf('    async function run('),source.indexOf('    function lockSelection(')),context);
(async()=>{
 const pending=context.run(()=>new Promise(resolve=>release=resolve));
 assert.equal(cancel.disabled,false,'The real busy wrapper must leave Cancel transfer clickable');assert.equal(other.disabled,true);
 release();await pending;assert.equal(other.disabled,false);
 console.log('PASS Cancel transfer stays enabled during actual busy-state handling');
})().catch(e=>{console.error(e);process.exitCode=1;});
