/* Shared connection-code copying: every page, current key, and manual fallback. */
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const script=fs.readFileSync(__dirname+'/../assets/app-header.js','utf8');
function page(view){
 const timers=[],copied=[],controls={};let settingsOpened=false;
 for(const id of ['copy',...(view==='backups'?[]:['copy-settings'])]){
  const label={textContent:'Copy connection code'};
  controls[id]={style:{},offsetWidth:40,querySelector:()=>label,addEventListener(_,fn){this.click=fn;}};
 }
 controls.pairing={value:'https://local.test\nprivate-fixture-key',hidden:true,focus(){this.focused=true;},select(){this.selected=true;}};
 controls.feedback={hidden:true};
 if(view!=='backups'){controls.connection={open:false};controls.reveal={setAttribute(name,value){this[name]=value;}};}
 const context={document:{getElementById:id=>controls[id.replace('csm-','')],querySelector:()=>({click(){settingsOpened=true;}})},navigator:{clipboard:{writeText:async value=>copied.push(value)}},setTimeout(fn){timers.push(fn);return timers.length;},clearTimeout(){}};
 vm.runInNewContext(script,context);
 return {controls,context,timers,copied,settingsOpened:()=>settingsOpened};
}
(async()=>{
 for(const view of ['migrate','settings','diagnostics','backups']){
  const p=page(view);
  await p.controls.copy.click();assert.deepEqual(p.copied,[p.controls.pairing.value],view+' copies the local site code');
  assert.equal(p.controls.copy.querySelector().textContent,'Code copied');assert.equal(p.controls.pairing.hidden,true);
  p.timers.at(-1)();assert.equal(p.controls.copy.querySelector().textContent,'Copy connection code');
  p.controls.pairing.value='regenerated-key';await p.controls.copy.click();assert.equal(p.copied.at(-1),'regenerated-key','Copies the current key after regeneration');
  if(p.controls['copy-settings']){await p.controls['copy-settings'].click();assert.equal(p.copied.at(-1),'regenerated-key');}
  p.context.navigator.clipboard.writeText=async()=>{throw Error('Clipboard blocked');};await p.controls.copy.click();
  assert.equal(p.controls.pairing.hidden,false);assert(p.controls.pairing.focused&&p.controls.pairing.selected);assert.equal(p.controls.feedback.hidden,false);assert.match(p.controls.feedback.textContent,/copy it with your keyboard/);
  if(view!=='backups'){assert(p.settingsOpened());assert(p.controls.connection.open);assert.equal(p.controls.reveal['aria-expanded'],'true');}
 }
 const p=page('backups');delete p.context.navigator.clipboard;await p.controls.copy.click();assert(p.controls.pairing.selected,'Missing Clipboard API supports manual copying too');
 console.log('PASS Shared header copy on all four pages, current-key reuse, confirmation reset, and manual-copy fallbacks.');
})().catch(error=>{console.error(error);process.exitCode=1;});
