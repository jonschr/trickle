/* Exercise real component-rail helpers without changing selected contents. */
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/admin.js'),'utf8');
const kinds=['tables','plugins','themes','media'],controls={},panels=[];
for(const kind of kinds){controls[kind+'-body']={hidden:true};controls['enable-'+kind]={checked:false};controls['disclose-'+kind]={setAttribute(k,v){this[k]=v;},removeAttribute(k){delete this[k];}};panels.push({dataset:{componentPanel:kind},toggleAttribute(k,on){this[k]=on;}});}
controls['component-workbench']={dataset:{current:'tables'},querySelectorAll(){return panels;}};
for(const id of ['selection-placeholder','placeholder-title','include-current'])controls[id]={};
const items=[{value:'a',checked:false},{value:'b',checked:false}];
const context={$:id=>controls[id],document:{querySelectorAll(){return items;}},renderMedia(){}};
vm.createContext(context);vm.runInContext(source.slice(source.indexOf('    function setPanelOpen('),source.indexOf('    function resetComponents(')),context);
context.showComponent('tables');assert.equal(controls['selection-placeholder'].hidden,false);
context.setComponent('tables',true,true);assert.equal(controls['selection-placeholder'].hidden,true);assert(items.every(item=>item.checked));
context.showComponent('themes');assert.equal(controls['selection-placeholder'].hidden,false);assert.equal(controls['disclose-tables']['aria-expanded'],'false');assert.equal(controls['tables-body'].hidden,false,'Hidden panes retain expansion preference');assert(items.every(item=>item.checked),'Switching rail leaves selections intact');
context.showComponent('tables');assert.equal(controls['selection-placeholder'].hidden,true);assert.equal(controls['disclose-tables']['aria-current'],'true');
context.setPanelOpen('tables',false);assert(controls['enable-tables'].checked);assert(items.every(item=>item.checked));assert.equal(controls['include-current'].textContent,'Show selection');
context.setPanelOpen('tables',true);assert.equal(controls['selection-placeholder'].hidden,true);
context.setComponent('tables',false,true);assert(items.every(item=>!item.checked));assert.equal(controls['selection-placeholder'].hidden,false);
console.log('Workspace rail checks passed: inclusion, switching, collapse/reopen, ARIA state and selection preservation.');
