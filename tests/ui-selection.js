/* Execute real UI filters/setup helpers with fixture controls. */
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const code=fs.readFileSync(require('node:path').join(__dirname,'../assets/admin.js'),'utf8');
const controls={};const $=id=>controls[id]??=( {value:'',hidden:false,textContent:'',dataset:{},setAttribute(){}} );
const source={prefix:'wp_',plugins:[{id:'one',name:'First Plugin',version:'1'},{id:'two',name:'Second Plugin',version:'2',update:'3'}],tables:[{id:'wp_posts',name:'wp_posts'},{id:'wp_options',name:'wp_options'}]};
const target={prefix:'local_',url:'https://local.test',plugins:[{id:'one',version:'1'},{id:'two',version:'1'}],tables:[{id:'local_posts',name:'local_posts'}]};
const rows=Object.fromEntries(['plugins','tables'].map(kind=>[kind,source[kind].map((item,i)=>{const row={hidden:false};return {value:item.id,checked:i===0,row,closest:()=>row};})]));
for(const kind of ['plugins','tables']) {$(kind).querySelectorAll=()=>rows[kind];$('search-'+kind).value='';$('filter-'+kind).value='all';}
const c={document:{querySelectorAll:()=>[]},$,source:()=>source,destination:()=>target,sites:{},session:null,busy:false,transferView:false,connectionState:{connections:[{id:'prod',name:'Production'}]},direction:()=> 'pull'};
vm.createContext(c);vm.runInContext(code.slice(code.indexOf('    function destinationAction('),code.indexOf('    function transferVersionMatches(')),c);
c.filterComponents('plugins');assert(rows.plugins.every(row=>!row.row.hidden));
$('search-plugins').value='SECOND';c.filterComponents('plugins');assert.equal(rows.plugins[0].row.hidden,true);assert.equal(rows.plugins[1].row.hidden,false);assert.equal(rows.plugins[0].checked,true,'Search preserves selection');
$('search-plugins').value='';$('filter-plugins').value='different';c.filterComponents('plugins');assert.equal(rows.plugins[0].row.hidden,true);assert.equal(rows.plugins[1].row.hidden,false);
$('filter-plugins').value='updates';c.filterComponents('plugins');assert.equal(rows.plugins[1].row.hidden,false);
$('filter-plugins').value='selected';c.filterComponents('plugins');assert.equal(rows.plugins[0].row.hidden,false);assert.equal(rows.plugins[1].row.hidden,true);
$('search-tables').value='missing';c.filterComponents('tables');assert.equal($('filter-empty-tables').hidden,false);assert.equal($('filter-count-tables').textContent,'0 of 2');assert.equal(rows.tables[0].checked,true);
c.syncTransferDialog=()=>{};
$('completion').hidden=true;$('selection-summary').textContent='2 tables';c.updateSetup();assert.equal($('preset').disabled,false);
c.session={direction:'pull',destination:'https://local.test'};c.busy=true;c.updateSetup();assert.equal($('stage').hidden,true);assert.equal($('preset').disabled,true);assert.equal($('flip-direction').disabled,true,'Direction cannot change during a retained transfer');
c.busy=false;c.updateSetup();assert.equal($('stage').hidden,false,'An interrupted transfer keeps its resume action');
assert.equal(c.destinationAction(),'Pull into this site');c.session.direction='push';$('remote-site').value='prod';assert.equal(c.destinationAction(),'Push to Production');
console.log('Selection UI checks passed: filters preserve selection, no-match state, locked selection and resume action, explicit destination.');

// Site identities stay in place while direction and source/destination roles change.
let routeDirection='pull', arrow;
const endpoints={source:{id:'source'},destination:{id:'destination'}};
const flow={children:[endpoints.source,'arrow',endpoints.destination],prepend(node){this.children=this.children.filter(x=>x!==node);this.children.unshift(node);},append(node){this.children=this.children.filter(x=>x!==node);this.children.push(node);},querySelector(){return {setAttribute(_,value){arrow=value;}};}};
const routeControls={};for(const id of ['source','destination']){routeControls[id]={closest:()=>endpoints[id]};routeControls[id+'-location']={classList:{toggle(_,value){this.local=value;}}};}
routeControls['direction-label']={};routeControls['flip-direction']={setAttribute(){}};
const routeContext={session:null,direction:()=>routeDirection,$:id=>routeControls[id],document:{querySelector:()=>flow}};
vm.createContext(routeContext);vm.runInContext(code.slice(code.indexOf('    function labelRoute('),code.indexOf('    const source =')),routeContext);
for(const dir of ['pull','push','pull']){routeDirection=dir;routeContext.labelRoute();const local=dir==='pull'?'destination':'source';assert.equal(flow.children[0],endpoints[local]);assert.equal(flow.children[1],'arrow');assert.equal(routeControls[local+'-location'].textContent,'This site');assert.equal(routeControls['direction-label'].textContent,dir==='pull'?'Pull':'Push');assert.match(routeControls['flip-direction'].title,dir==='pull'?/switch to push/:/switch to pull/);assert.equal(arrow,dir==='pull'?'M19 12H5m6-6-6 6 6 6':'M5 12h14m-6-6 6 6-6 6');}
routeContext.session={direction:'push'};routeContext.labelRoute();assert.equal(flow.children[0],endpoints.source,'Retained direction takes precedence');
console.log('Route checks passed: current site first, arrow follows push/pull, retained direction preserved.');
