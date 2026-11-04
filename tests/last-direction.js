/* Run the real one-time direction initialization against saved transfer history. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/../assets/admin.js','utf8');
const start=code.indexOf("    $('direction').value = session?.direction ||");
assert(start>=0,'Startup must restore the saved direction');
const init=code.slice(start,code.indexOf('\n',start));
function direction(recents,session=null){const field={value:'pull'};vm.runInNewContext(init,{$:()=>field,session,connectionState:{recents}});return field.value;}
assert.equal(direction([{direction:'push'},{direction:'pull'}]),'push');
assert.equal(direction([{direction:'pull'},{direction:'push'}]),'pull');
assert.equal(direction([]),'pull');
assert.equal(direction([{direction:'invalid'},{direction:'push'}]),'push');
assert.equal(direction([{direction:'pull'}],{direction:'push'}),'push');
assert.equal(direction([{direction:'push'}],{direction:'pull'}),'pull');
assert.equal(code.indexOf(init),code.lastIndexOf(init),'History is applied once at startup, not during refreshes or manual direction changes');
console.log('PASS Last transfer sets initial push/pull; active sessions take precedence; fresh sites default to pull; refresh/manual choices are not overwritten.');
