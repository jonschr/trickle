/* Exercise real row rendering and presentation helpers; no sites or network. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/../assets/admin.js','utf8');
const controls={plugins:{},themes:{}};
const local={name:'This site',plugins:[{id:'up',name:'Upgrade plugin',version:'1.9',active:true},{id:'down',name:'Downgrade plugin',version:'2.1',active:false}],themes:[{id:'parent',version:'1.0',active:false},{id:'child',version:'1.0',parent:'parent',active:true}]};
const remote={name:'Saved remote',plugins:[{id:'up',name:'Upgrade plugin',version:'1.10',version_order:1,active:false},{id:'down',name:'Downgrade plugin',version:'2.0',version_order:-1,active:true},{id:'new',name:'New plugin',version:'1.0',active:true}],themes:[{id:'parent',name:'Parent',version:'1.1',version_order:1,active:false},{id:'child',name:'Child',version:'1.0',version_order:0,parent:'parent',active:true}]};
let pull=true;
const context={$:id=>controls[id],direction:()=>pull?'pull':'push',source:()=>pull?remote:local,destination:()=>pull?local:remote,selectAll:()=>'<span>Select all</span>',icon:()=>'',busy:false,session:null};vm.createContext(context);
vm.runInContext(code.slice(code.indexOf('    const esc ='),code.indexOf('    const bytes ='))+code.slice(code.indexOf('    function siteLabel('),code.indexOf('    function renderTables(')),context);
context.components('plugins');assert.match(controls.plugins.innerHTML,/data-change="upgrade"/);assert.match(controls.plugins.innerHTML,/data-change="downgrade"/);assert.match(controls.plugins.innerHTML,/New install/);assert(!controls.plugins.innerHTML.includes('csm-activation'));
assert.equal(context.versionChange({version:'1.0',version_order:0},{version:'1.0'}).kind,'same');assert.equal(context.versionChange({version:'branch',version_order:null},{version:'1.0'}).kind,'different');
assert.equal(context.versionChange({version:'1.0',version_order:1,protected:true},{version:'0.9'}).kind,'protected');
remote.plugins[0].update=local.plugins[0].update='1.11';context.components('plugins');assert(!controls.plugins.innerHTML.includes('Update available:'));
local.plugins[0].version_order=-1;local.plugins[1].version_order=1;pull=false;context.components('plugins');assert.match(controls.plugins.innerHTML,/data-change="downgrade"/);assert.match(controls.plugins.innerHTML,/data-change="upgrade"/);
remote.plugins[0].name='<img src=x onerror=alert(1)>';pull=true;context.components('plugins');assert(!controls.plugins.innerHTML.includes('<img'));assert(controls.plugins.innerHTML.includes('&lt;img'));
context.components('themes');assert.match(controls.themes.innerHTML,/Parent/);
const markup=controls.themes.innerHTML;
assert.match(markup,/csm-destination-version/);
assert(!markup.includes('csm-change-label'));
assert.match(markup,/Upgrade/);
console.log('PASS Source → destination cells: upgrades, downgrades, installs, protected rows, no available-update noise, direction reversal and escaped names.');
for(const isPull of [true,false]){
 pull=isPull;const src=isPull?remote:local;
 src.plugins.push({id:'symlink',name:'Linked plugin',version:'1',protected:true,protected_reason:"This plugin contains symlinks and can't be transferred."});
 context.components('plugins');const row=controls.plugins.innerHTML.match(/<div class="csm-component csm-code-component" data-change="protected"[^]*?<\/div>/)[0];
 assert.match(row,/data-protected="true"[^>]* disabled/);assert(!row.includes(' checked'));assert.match(row,/This plugin contains symlinks/);assert.match(row,/Protected/);assert(!row.includes('csm-version-endpoint'));
}
console.log('PASS Symlink rows lock and deselect in both directions with a visible reason.');
// Recent settings and retained selections cannot re-check protected rows.
const inputs=[{value:'linked',dataset:{kind:'plugins',protected:'true'},checked:true},{value:'ordinary',dataset:{kind:'plugins'},checked:false}];
const restoreContext={document:{querySelectorAll:selector=>selector==='input[data-kind]'?inputs:selector==='input[data-kind="plugins"]'?inputs:[]},$:()=>({}),componentKinds:[],showComponent(){},renderReplacements(){},renderMedia(){},updateSelection(){},sites:{},session:null,source:()=>({plugins:[],themes:[]}),direction:()=> 'push'};
vm.createContext(restoreContext);vm.runInContext(code.slice(code.indexOf('    function restoreSelection('),code.indexOf('    let mediaAnchor')),restoreContext);
for(const strict of [true,false]){restoreContext.restoreSelection({plugins:['linked','ordinary'],themes:[],tables:[],media:'none'},strict);assert(!inputs[0].checked);assert(inputs[1].checked);}
assert.throws(()=>restoreContext.restoreSelection({plugins:['absent']},true),/absent/);
console.log('PASS Restoring settings deselects protected rows and still rejects missing components.');

remote.plugins.push({id:'linked-approved',name:'Approved linked plugin',version:'1.0',symlinks:true,protected:true,protected_reason:'This plugin contains symlinks.'});
pull=true;context.components('plugins');assert.match(controls.plugins.innerHTML,/data-follow-links="linked-approved"/);
remote.plugins.at(-1).follow_symlinks=true;context.components('plugins');const approved=controls.plugins.innerHTML.match(/<label[^]*?value="linked-approved"[^]*?<\/label>/)[0].split('<label').at(-1);assert.match(approved,/data-protected="false"/);assert(!approved.includes('data-follow-links="linked-approved"'));
console.log('PASS Only explicit per-component approval unlocks a linked source row.');
let rebuilt=[];restoreContext.source=()=>remote;restoreContext.components=kind=>rebuilt.push(kind);remote.plugins.at(-1).follow_symlinks=true;restoreContext.restoreSelection({plugins:['linked','ordinary'],themes:[],tables:[],media:'none'},false);assert.equal(remote.plugins.at(-1).follow_symlinks,undefined);assert(rebuilt.includes('plugins'));
console.log('PASS Loading saved settings revokes previous source-link approval.');

for(const isPull of [false,true]){
 pull=isPull;
 for(const kind of ['plugins','themes']){
  context.components(kind);const html=controls[kind].innerHTML;
  const headings=html.slice(0,html.indexOf('<div class="csm-component'));
  const firstRole=isPull?'Destination':'Source',secondRole=isPull?'Source':'Destination';
  assert(headings.indexOf('>'+firstRole+'<')<headings.indexOf('>'+secondRole+'<'),'Comparison headers follow local then remote');
  assert(headings.indexOf('This site')<headings.indexOf('Saved remote'),'Site labels follow the site cards');
  const row=html.slice(html.indexOf('<div class="csm-component'));
  assert((row.indexOf('class="csm-destination-version"')<row.indexOf('class="csm-source-version"'))===isPull,'Version cells follow their headers in both directions');
 }
}
console.log('PASS Plugin and theme columns keep this site first and remote second for push and pull.');
