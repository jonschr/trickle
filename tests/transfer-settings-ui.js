const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/../assets/admin.js','utf8');
const controls={},$=id=>controls[id]??=({value:'',disabled:false,valid:true,textContent:'',addEventListener(name,fn){this.change=fn;},reportValidity(){assert(!this.disabled,'Validate before run disables controls');return this.valid;}});
$('transport').value='stream';$('request-interval').value='0';$('setup-request-interval').value='.5';
let pending,calls=0,reject=false,last;
const c={$: $,run(fn){for(const e of Object.values(controls))e.disabled=true;pending=fn().finally(()=>{for(const e of Object.values(controls))e.disabled=false;});},api:async(op,data)=>{assert.equal(op,'transfer_settings');calls++;last=data;if(reject)throw Error('Save failed');return data;}};
vm.createContext(c);vm.runInContext(code.slice(code.indexOf('    const transferPreferences='),code.indexOf("    for (const id of ['transport', 'ignore-active-plugins'])")),c);
(async()=>{
 $('setup-request-interval').value='1.5';$('setup-request-interval').change();await pending;assert.equal(calls,1);assert.equal($('transfer-settings-status').textContent,'Saved.');
 assert.deepEqual(Object.keys(last),['setup_request_interval'],'Global spacing save never submits or changes the retained job transport');
 $('request-interval').valid=false;$('request-interval').change();assert.equal(calls,1,'Invalid global preference sends no save');$('request-interval').valid=true;
 reject=true;$('request-interval').value='2';$('request-interval').change();await assert.rejects(pending,/Save failed/);assert.equal($('request-interval').value,0);assert.equal($('setup-request-interval').value,'1.5','Failed quiet-time save leaves the other global preference unchanged');assert.match($('transfer-settings-status').textContent,/Previous preferences restored/);
 console.log('PASS Global spacing autosave, validation before disabling controls, and failed-save restoration');
})().catch(e=>{console.error(e);process.exitCode=1;});
