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
// Bind the actual tab click handlers. A tab always opens; only inclusion changes its checkbox.
const buttons=kinds.map(kind=>Object.assign(controls['disclose-'+kind],{dataset:{disclose:kind},addEventListener(_,fn){this.click=fn;}}));
context.document.querySelectorAll=selector=>selector==='.csm-panel-disclosure'?buttons:items;
vm.runInContext(source.slice(source.indexOf("    document.querySelectorAll('.csm-panel-disclosure').forEach"),source.indexOf("    $('include-current').addEventListener")),context);
context.setComponent('themes',true,true);const selected=items.map(item=>item.checked);
controls['disclose-themes'].click();controls['disclose-themes'].click();
assert.equal(controls['themes-body'].hidden,false);assert.equal(controls['enable-themes'].checked,true);assert.deepEqual(items.map(item=>item.checked),selected);
context.setComponent('plugins',false,true);controls['disclose-plugins'].click();
assert.equal(controls['component-workbench'].dataset.current,'plugins');assert.equal(controls['plugins-body'].hidden,false);assert.equal(controls['enable-plugins'].checked,false);assert(items.every(item=>!item.checked));
console.log('PASS Tab clicks open checked or unchecked components without toggling inclusion or selected items.');
