/* Exercise real row rendering and presentation helpers; no sites or network. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/../assets/admin.js','utf8');
const controls={plugins:{},themes:{}};
const local={plugins:[{id:'up',name:'Upgrade plugin',version:'1.9',active:true},{id:'down',name:'Downgrade plugin',version:'2.1',active:false}],themes:[{id:'parent',version:'1.0',active:false},{id:'child',version:'1.0',parent:'parent',active:true}]};
const remote={plugins:[{id:'up',name:'Upgrade plugin',version:'1.10',version_order:1,active:false},{id:'down',name:'Downgrade plugin',version:'2.0',version_order:-1,active:true},{id:'new',name:'New plugin',version:'1.0',active:true}],themes:[{id:'parent',name:'Parent',version:'1.1',version_order:1,active:false},{id:'child',name:'Child',version:'1.0',version_order:0,parent:'parent',active:true}]};
let pull=true;
const context={$:id=>controls[id],source:()=>pull?remote:local,destination:()=>pull?local:remote,selectAll:()=>'<span>Select all</span>',icon:()=>''};vm.createContext(context);
vm.runInContext(code.slice(code.indexOf('    const esc ='),code.indexOf('    const bytes ='))+code.slice(code.indexOf('    function componentState('),code.indexOf('    function renderTables(')),context);
context.components('plugins');assert.match(controls.plugins.innerHTML,/data-change="upgrade" data-source-active="false"/);assert.match(controls.plugins.innerHTML,/data-change="downgrade"/);assert.match(controls.plugins.innerHTML,/New install/);assert.match(controls.plugins.innerHTML,/is-active/);assert.match(controls.plugins.innerHTML,/is-inactive/);
assert.equal(context.versionChange({version:'1.0',version_order:0},{version:'1.0'}).kind,'same');assert.equal(context.versionChange({version:'branch',version_order:null},{version:'1.0'}).kind,'different');
assert.equal(context.versionChange({version:'1.0',version_order:1,protected:true},{version:'0.9'}).kind,'protected');
assert.equal(context.componentState('themes',remote.themes[0],remote.themes),'Active parent');assert.equal(context.componentState('plugins',{active:false},remote.plugins),'Inactive');
remote.plugins[0].update=local.plugins[0].update='1.11';context.components('plugins');assert.match(controls.plugins.innerHTML,/Update available: 1.11/);assert.match(controls.plugins.innerHTML,/both sites/);
local.plugins[0].version_order=-1;local.plugins[1].version_order=1;pull=false;context.components('plugins');assert.match(controls.plugins.innerHTML,/data-change="downgrade"/);assert.match(controls.plugins.innerHTML,/data-change="upgrade"/);
remote.plugins[0].name='<img src=x onerror=alert(1)>';pull=true;context.components('plugins');assert(!controls.plugins.innerHTML.includes('<img'));assert(controls.plugins.innerHTML.includes('&lt;img'));
context.components('themes');assert.match(controls.themes.innerHTML,/Active parent/);
console.log('PASS Installed → incoming rows: upgrades, downgrades, installs, same/unknown versions, protected Trickle, source/destination activation, active parents, available updates, direction reversal and escaped names.');
