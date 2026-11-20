/* Execute the real compatibility UI helpers without a WordPress site. */
const assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm'), path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/admin.js'), 'utf8');
const controls = {};
for (const id of ['trickle-versions','trickle-version-status','trickle-update-status','check-remote-update','update-remote','source','destination','site-menu-toggle','site-menu','update-connection','install-local-build','clear-update-comparisons','open-remote-trickle']) controls[id] = {classList:{toggle(name,on){this[name]=on;}}};
let menuParent;const siteMenu=controls['site-menu'];
for(const id of ['source','destination']){
 let html='';Object.defineProperty(controls[id],'innerHTML',{get:()=>html,set(value){html=value;if(menuParent===id)delete controls['site-menu'];}});
 controls[id].closest=()=>({classList:{toggle(){}}});controls[id].append=menu=>{assert.equal(menu,siteMenu,'Rerender retains the menu and its handlers');controls['site-menu']=menu;menuParent=id;};
}
const c = {labelRoute(){},connectionState:{connections:[]},direction:()=>c.pull?'pull':'push',$:id=>controls[id],sites:null,session:null,busy:false,remoteUpdate:null,esc:value=>String(value??'').replace(/</g,'&lt;'),icon:()=>'',pull:true,source:()=>c.pull?c.sites.remote:c.sites.local,destination:()=>c.pull?c.sites.local:c.sites.remote};vm.createContext(c);
vm.runInContext(source.slice(source.indexOf('    function transferVersionMatches('),source.indexOf('    const transferDate')),c);
c.renderVersionStatus();assert.equal(controls['trickle-versions'].hidden,true);
c.sites={local:{plugin_version:'0.1.9',wordpress:'6.8',php:'8.4'},remote:{plugin_version:'0.1.8',wordpress:'6.7',php:'8.1',remote_update:1,can_update_trickle:false}};
c.renderVersionStatus();assert.equal(c.versionsMatch(),false);assert.match(controls['trickle-update-status'].textContent,/administrator or read\/write key/);assert.equal(controls['update-remote'].hidden,true);
c.sites.remote.can_update_trickle=true;c.remoteUpdate={can_update_to_target:true,reason:'Matching release available'};c.renderVersionStatus();
assert.equal(controls['update-remote'].hidden,false);assert.equal(controls['update-remote'].textContent,'Update remote Trickle to 0.1.9');
c.busy=true;c.renderVersionStatus();assert.equal(controls['update-remote'].disabled,true);assert.equal(controls['check-remote-update'].disabled,true);
c.busy=false;c.sites.remote.plugin_version='0.1.9';c.renderVersionStatus();assert.equal(c.versionsMatch(),true);assert.equal(controls['update-remote'].hidden,true);assert.equal(controls['trickle-versions'].hidden,true);
assert.equal(menuParent,'source','Pull selector lives below the remote source labels');
assert.match(controls.source.innerHTML,/Trickle <b>0.1.9.*WordPress <b>6.7.*PHP <b>8.1/);
assert.match(controls.destination.innerHTML,/WordPress <b>6.8.*PHP <b>8.4/);
c.pull=false;c.renderVersionStatus();assert.equal(menuParent,'destination','Push selector lives below the remote destination labels');assert.match(controls.source.innerHTML,/WordPress <b>6.8/);assert.match(controls.destination.innerHTML,/WordPress <b>6.7/);
c.sites.local.php='<img>';c.renderVersionStatus();assert.match(controls.source.innerHTML,/&lt;img>/);c.pull=true;
c.session={plugin_version:'0.1.8'};c.renderVersionStatus();assert.equal(c.transferVersionMatches(),false);assert.match(controls['trickle-update-status'].textContent,/older Trickle version/);
c.session=null;c.sites.remote={plugin_version:'0.1.7'};c.remoteUpdate=null;c.renderVersionStatus();assert.equal(controls['check-remote-update'].hidden,true);assert.match(controls['trickle-update-status'].textContent,/Install this Trickle version/);
c.sites.remote={plugin_version:'0.1.8',remote_update:1,can_update_trickle:true};c.remoteUpdate={can_update_to_target:false,reason:'Release unavailable'};c.renderVersionStatus();assert.equal(controls['update-remote'].hidden,true);assert.equal(controls['trickle-update-status'].textContent,'Release unavailable');
console.log('Version UI checks passed: mismatches, permissions, eligible update, busy state, matched versions, stale transfer, legacy remote, and unavailable release.');

// Same version numbers can still refer to different development builds.
c.session=null;c.remoteUpdate=null;c.sites.local.trickle_build='local-build';c.sites.remote={plugin_version:c.sites.local.plugin_version,remote_build_update:1,trickle_build:'old-build',can_update_trickle:true};
c.renderVersionStatus();assert.equal(c.versionsMatch(),false);assert.equal(controls['trickle-version-status'].textContent,'Trickle version conflict');assert.equal(controls['update-remote'].hidden,false);assert.equal(controls['update-remote'].textContent,'Sync remote Trickle');assert.equal(controls['install-local-build'].hidden,false);
c.sites.remote.trickle_build='local-build';c.renderVersionStatus();assert(c.versionsMatch());assert.equal(controls['trickle-versions'].hidden,true);assert.equal(controls['install-local-build'].hidden,false,'Matching sites still offer installation from the remote menu');
c.busy=true;c.renderVersionStatus();assert.equal(controls['install-local-build'].disabled,true);
console.log('Local-build UI checks passed: same-version mismatch, one-click action, matching builds and busy guard.');

c.busy=false;c.remoteUpdate={retained:true};c.connectionState={selected:'remote',connections:[{id:'remote',url:'https://remote.test/subdir'}]};c.sites.remote.trickle_build='old';c.renderVersionStatus();
assert.equal(controls['clear-update-comparisons'].hidden,false);assert.equal(controls['open-remote-trickle'].href,'https://remote.test/subdir/wp-admin/tools.php?page=trickle&view=settings#csm-source-preparations');assert.match(controls['trickle-update-status'].textContent,/Clear completed comparisons/);
c.session={plugin_version:c.sites.local.plugin_version};c.renderVersionStatus();assert(controls['clear-update-comparisons'].disabled,'A retained transfer cannot use comparison cleanup');
console.log('PASS Retained-state notices expose cleanup and the correct remote dashboard, with active-transfer locking.');

c.session=null;c.remoteUpdate={syncError:true,retained:false};c.renderVersionStatus();assert(controls['clear-update-comparisons'].hidden,'Endpoint denial does not suggest comparison cleanup');assert.equal(controls['open-remote-trickle'].hidden,false,'Sync errors retain a path to the remote dashboard');
