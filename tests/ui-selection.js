/* Execute real UI filters/setup helpers with fixture controls. */
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const code=fs.readFileSync(require('node:path').join(__dirname,'../assets/admin.js'),'utf8');
const controls={};const $=id=>controls[id]??=( {value:'',hidden:false,textContent:'',dataset:{},setAttribute(){},querySelectorAll(){return []}} );
const source={prefix:'wp_',plugins:[{id:'one',name:'First Plugin',version:'1'},{id:'two',name:'Second Plugin',version:'2',update:'3'}],tables:[{id:'wp_posts',name:'wp_posts'},{id:'wp_options',name:'wp_options'}]};
const target={prefix:'local_',url:'https://local.test',name:'Local',plugins:[{id:'one',version:'1'},{id:'two',version:'1'}],tables:[{id:'local_posts',name:'local_posts'}]};
const rows=Object.fromEntries(['plugins','tables'].map(kind=>[kind,source[kind].map((item,i)=>{const row={hidden:false};return {value:item.id,checked:i===0,row,closest:()=>row};})]));
for(const kind of ['plugins','tables']) {$(kind).querySelectorAll=()=>rows[kind];$('search-'+kind).value='';$('filter-'+kind).value='all';}
const c={versionsMatch:()=>true,transferVersionMatches:()=>true,inventoryLoading:false,inventoryError:'',presetLoading:false,document:{querySelectorAll:()=>[]},$,source:()=>source,destination:()=>target,sites:{},session:null,busy:false,transferView:false,presetUndo:null,presetChosen:true,activePreset:'',connectionState:{selected:'prod',recents:[],connections:[{id:'prod',name:'Production'}]},direction:()=> 'pull',siteLabel:site=>site.name || site.url};
vm.createContext(c);vm.runInContext(code.slice(code.indexOf('    function destinationAction('),code.indexOf('    function transferVersionMatches(')),c);
c.filterComponents('plugins');assert(rows.plugins.every(row=>!row.row.hidden));
$('search-plugins').value='SECOND';c.filterComponents('plugins');assert.equal(rows.plugins[0].row.hidden,true);assert.equal(rows.plugins[1].row.hidden,false);assert.equal(rows.plugins[0].checked,true,'Search preserves selection');
$('search-plugins').value='';$('filter-plugins').value='different';c.filterComponents('plugins');assert.equal(rows.plugins[0].row.hidden,true);assert.equal(rows.plugins[1].row.hidden,false);
$('filter-plugins').value='updates';c.filterComponents('plugins');assert.equal(rows.plugins[1].row.hidden,false);
$('filter-plugins').value='selected';c.filterComponents('plugins');assert.equal(rows.plugins[0].row.hidden,false);assert.equal(rows.plugins[1].row.hidden,true);
$('search-tables').value='missing';c.filterComponents('tables');assert.equal($('filter-empty-tables').hidden,false);assert.equal($('filter-count-tables').textContent,'0 of 2');assert.equal(rows.tables[0].checked,true);
c.syncTransferDialog=()=>{};
vm.runInContext('presetChosen=true',c);$('completion').hidden=true;$('selection-summary').textContent='2 tables';c.updateSetup();assert.equal($('preset').disabled,false);assert.equal($('transfer-shortcuts').hidden,false);
for(const blocked of [{inventoryLoading:true},{inventoryError:'Endpoint unavailable'},{sites:null},{versionsMatch:()=>false},{transferVersionMatches:()=>false}]){const previous=Object.fromEntries(Object.keys(blocked).map(key=>[key,c[key]]));Object.assign(c,blocked);c.updateSetup();assert.equal($('transfer-shortcuts').hidden,true);assert.equal($('selection-workspace').hidden,true);assert.equal($('preset').disabled,true);Object.assign(c,previous);}
c.updateSetup();assert.equal($('transfer-shortcuts').hidden,false,'Successful reload or sync restores presets');assert.equal($('selection-workspace').hidden,false);
c.session={direction:'pull',destination:'https://local.test'};c.busy=true;c.updateSetup();assert.equal($('stage').hidden,true);assert.equal($('preset').disabled,true);assert.equal($('flip-direction').disabled,true,'Direction cannot change during a retained transfer');
c.busy=false;c.updateSetup();assert.equal($('stage').hidden,false,'An interrupted transfer keeps its resume action');
assert.equal(c.destinationAction(),'Pull into Local');c.session.direction='push';$('remote-site').value='prod';assert.equal(c.destinationAction(),'Push to Local');
console.log('Selection UI checks passed: filters preserve selection, no-match state, locked selection and resume action, explicit destination.');

// Site identities stay in place while direction and source/destination roles change.
let routeDirection='pull';
const endpoints={source:{id:'source'},destination:{id:'destination'}};
const flow={children:[endpoints.source,'arrow',endpoints.destination],prepend(node){this.children=this.children.filter(x=>x!==node);this.children.unshift(node);},append(node){this.children=this.children.filter(x=>x!==node);this.children.push(node);}};
const routeControls={};for(const id of ['source','destination']){routeControls[id]={closest:()=>endpoints[id]};routeControls[id+'-location']={classList:{toggle(_,value){this.local=value;}}};}
routeControls['direction-label']={};routeControls['flip-direction']={setAttribute(name,value){this[name]=value;}};
const routeContext={session:null,direction:()=>routeDirection,$:id=>routeControls[id],document:{querySelector:()=>flow}};
vm.createContext(routeContext);vm.runInContext(code.slice(code.indexOf('    function labelRoute('),code.indexOf('    const source =')),routeContext);
for(const dir of ['pull','push','pull']){routeDirection=dir;routeContext.labelRoute();const local=dir==='pull'?'destination':'source';assert.equal(flow.children[0],endpoints[local]);assert.equal(flow.children[1],'arrow');assert.equal(routeControls[local+'-location'].textContent,'This site');assert.equal(routeControls['direction-label'].textContent,dir==='pull'?'Pull':'Push');assert.match(routeControls['flip-direction'].title,dir==='pull'?/switch to push/:/switch to pull/);assert.equal(routeControls['flip-direction']['data-direction'],dir);}
routeContext.session={direction:'push'};routeContext.labelRoute();assert.equal(flow.children[0],endpoints.source,'Retained direction takes precedence');
console.log('Route checks passed: current site first, arrow follows push/pull, retained direction preserved.');

// Matching is never inferred from a version alone.
$('filter-plugins').value='all';$('search-plugins').value='';$('show-matching-plugins').checked=false;
source.plugins[0].content_hash='one';target.plugins[0].content_hash='two';c.filterComponents('plugins');assert.equal(rows.plugins[0].row.hidden,false,'Same version but different files remains visible');
target.plugins[0].content_hash='one';c.filterComponents('plugins');assert.equal(rows.plugins[0].row.hidden,true,'Only a verified version+hash match is hidden');
$('show-matching-plugins').checked=true;c.filterComponents('plugins');assert.equal(rows.plugins[0].row.hidden,false);
delete source.plugins[0].content_hash;$('show-matching-plugins').checked=false;c.filterComponents('plugins');assert.equal(rows.plugins[0].row.hidden,false,'Unknown hash remains visible');
console.log('PASS Matching visibility requires version and nonempty hash equality.');
