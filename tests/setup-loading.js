/* Real loading/preset guards: endpoint validation precedes presets; stale replies cannot change sites. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/../assets/admin.js','utf8'),controls={};
const $=id=>controls[id]??=({value:'',checked:false,hidden:true,dataset:{},setAttribute(k,v){this[k]=v;}});
$('remote-site').value='a';$('direction').value='pull';
const requests=[],mediaLoads=[];
const c={$,sites:null,session:null,busy:false,presetChosen:false,presetBaseline:null,activePreset:'',presetUndo:null,presetRequest:0,presetLoading:false,inventoryGeneration:0,inventoryLoading:true,inventoryError:'',comparisonGeneration:0,invalidateComparisons(){this.comparisonGeneration++;},remoteUpdate:null,componentKinds:['tables','plugins','themes','media'],connectionState:{selected:'a',connections:[],recents:[]},direction:()=> $('direction').value,document:{querySelectorAll:()=>[]},renderSectionLoading(){},updateSetup(){},updateSelection(){},render(){c.presetChosen=false;},renderConnections(){},loadSelectedMedia:async selection=>mediaLoads.push(selection),startComparisons(){},source(){throw Error('Preset touched an unvalidated inventory');},api(op,data){assert.equal(op,'manifest');return new Promise((resolve,reject)=>requests.push({data,resolve,reject}));}};
const inventory=(tag,version='1',build='same')=>({tag,local:{plugin_version:'1',trickle_build:'same'},remote:{plugin_version:version,trickle_build:build,remote_build_update:true}});
vm.createContext(c);vm.runInContext(code.slice(code.indexOf('    function presetsAvailable('),code.indexOf('    function updateSetup('))+code.slice(code.indexOf('    function transferVersionMatches('),code.indexOf('    function renderEndpoints('))+code.slice(code.indexOf('    async function applyPreset('),code.indexOf('    function renderComponentSizes('))+code.slice(code.indexOf('    async function load('),code.indexOf('    function renderSkippedFolders(')),c);
async function assertBlocked(){
 assert.equal(c.presetsAvailable(),false);
 const request=c.presetRequest;
 for(const preset of ['everything','database','database-media','recent','custom'])await c.applyPreset(preset);
 assert.equal(c.presetRequest,request,'Blocked choices cannot start preset work');assert.equal(c.presetChosen,false);assert.equal(requests.length,0,'Blocked choices cannot send requests');
}
(async()=>{
 await assertBlocked();
 c.sites=inventory('loading');await assertBlocked();
 c.inventoryLoading=false;c.inventoryError='Offline';await assertBlocked();
 c.inventoryError='';c.sites=inventory('version-conflict','2');await assertBlocked();
 c.sites=inventory('build-conflict','1','different');await assertBlocked();
 c.sites=inventory('ready');assert.equal(c.presetsAvailable(),true);
 c.sites=null;c.presetLoading=true;const a=c.load();assert.equal(c.presetLoading,false,'Reload invalidates a pending preset request');$('remote-site').value='b';c.connectionState.selected='b';const b=c.load();
 requests[1].resolve(inventory('site-b'));await b;assert.equal(c.sites.tag,'site-b');assert.equal(c.presetsAvailable(),true);
 requests[0].resolve(inventory('site-a'));await a;assert.equal(c.sites.tag,'site-b','Late reply from former site is ignored');assert.equal(mediaLoads.length,1);
 const failed=c.load();assert.equal(c.presetsAvailable(),false,'Refreshing gates existing inventories too');requests[2].reject(Error('Offline'));await failed;assert.equal(c.inventoryError,'Offline');assert.equal($('inventory-retry').hidden,false);assert.equal(c.presetsAvailable(),false,'A previous valid inventory cannot bypass endpoint failure');
 const retry=c.load();requests[3].resolve(inventory('conflict','2'));await retry;assert.equal(c.inventoryError,'');assert.equal(c.presetsAvailable(),false);assert.equal(mediaLoads.length,1,'Conflicting versions do not trigger further detail requests');
 const synced=c.load();requests[4].resolve(inventory('synced'));await synced;assert.equal(c.presetsAvailable(),true,'Successful sync/reload restores preset access');assert.equal($('inventory-retry').hidden,true);
 c.session={plugin_version:'old'};assert.equal(c.presetsAvailable(),false,'Retained transfer version conflicts remain gated');
 console.log('PASS Preset validation gate, version/build conflicts, stale-site replies, endpoint retry and successful sync recovery.');
})().catch(e=>{console.error(e);process.exitCode=1;});
