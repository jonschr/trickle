/* Exercise actual dialog lifecycle and focus helpers; no transfer or network. */
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(__dirname+'/../assets/admin.js','utf8'),nodes={},classes=new Set();
const document={activeElement:null,body:{classList:{add:n=>classes.add(n),remove:n=>classes.delete(n)}}};
function node(id){return nodes[id]??=( {id,hidden:true,children:[],focus(){document.activeElement=this;},append(...items){for(const item of items)item.parentElement=this;this.children.push(...items);},prepend(item){item.parentElement=this;this.children.unshift(item);},insertBefore(item){item.parentElement=this;},getClientRects(){return this.hidden?[]:[{}];},closest(){return this.hidden?{}:null;}} );}
const dialog=node('transfer-dialog');dialog.open=false;dialog.showModal=function(){this.open=true;};dialog.close=function(){this.open=false;};
const actionHome=node('action-home');
const c={transferMeter:{clear(){}},$:node,transferView:false,session:null,busy:false,actionHome,document,updateSelection(){c.syncTransferDialog();}};
vm.createContext(c);vm.runInContext(source.slice(source.indexOf('    function syncTransferDialog('),source.indexOf('    function filterComponents(')),c);
c.syncTransferDialog();assert(!dialog.open);
c.session={id:'retained'};c.syncTransferDialog();assert(dialog.open);assert(classes.has('csm-transfer-open'));assert.equal(node('error').parentElement,node('transfer-notices'));assert.equal(node('stage').parentElement,node('transfer-controls'));assert.equal(document.activeElement,node('action-title'));assert(node('close-transfer').hidden);
c.closeTransferView();assert(dialog.open,'Active transfer cannot be dismissed');
c.session=null;c.busy=true;c.transferView=true;c.closeTransferView();assert(dialog.open,'Initial preparation also cannot be dismissed');
c.busy=false;node('completion').hidden=false;c.syncTransferDialog();assert(!node('close-transfer').hidden);
c.closeTransferView();assert(!dialog.open);assert(!classes.has('csm-transfer-open'));assert.equal(node('stage').parentElement,actionHome);assert.equal(node('error').parentElement,node('app-notices'));assert(node('completion').hidden&&node('transfer-meter').hidden);
assert.equal(document.activeElement,node('stage'),'Dismissal restores focus to the setup action');
const first=node('first'),last=node('last');first.hidden=last.hidden=false;dialog.querySelectorAll=()=>[first,last];
let prevented=false;const key=shift=>({key:'Tab',shiftKey:shift,preventDefault(){prevented=true;}});
document.activeElement=last;c.containTransferFocus(key(false));assert(prevented&&document.activeElement===first);
prevented=false;document.activeElement=first;c.containTransferFocus(key(true));assert(prevented&&document.activeElement===last);
prevented=false;document.activeElement=node('action-title');c.containTransferFocus(key(true));assert(prevented&&document.activeElement===last);
c.transferAction=()=> 'Pull';c.destinationAction=()=> 'Pull into this site';c.bytes=String;c.message=c.renderActivity=c.renderPreview=c.updateSetup=()=>{};
vm.runInContext(source.slice(source.indexOf('    function showSummary('),source.indexOf('    async function finishTransfer(')),c);
for(const status of ['staging','applying','restoring','backup_copy']){c.session={};c.summary={status,tables:0,files:0,bytes:0};c.showSummary();assert(!node('discard').hidden,'Cancel remains available during '+status);}
console.log('Transfer dialog checks passed: retained sessions, busy dismissal guard, notices, action restoration, completion and keyboard focus.');
