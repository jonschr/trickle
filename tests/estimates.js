const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const code=fs.readFileSync(__dirname+'/../assets/admin.js','utf8');
const cells=[{dataset:{componentSize:'plugins',sizeId:'example',sizeSite:'source'}},{dataset:{componentSize:'themes',sizeId:'theme',sizeSite:'destination'}}];
const controls={'media-sizes':{},'media-year-sizes':{}};
const src={estimates:{complete:false,items:{plugins:{example:1024},media:{2024:300,2026:400,_other:50}}}};
const dst={estimates:{complete:true,items:{themes:{theme:2048}}}};
const context={sites:{},source:()=>src,destination:()=>dst,$:id=>controls[id],document:{querySelectorAll:()=>cells}};
vm.createContext(context);
vm.runInContext(code.slice(code.indexOf('    const esc ='),code.indexOf('    const transferMeter'))+code.slice(code.indexOf('    function estimateSize('),code.indexOf('    let estimateTimer')),context);
context.renderSizeDetails();
assert.equal(cells[0].textContent,'≥ 1.0 KiB');assert.equal(cells[1].textContent,'~2.0 KiB');
let html=controls['media-year-sizes'].innerHTML;
assert.ok(html.indexOf('2026')<html.indexOf('2024') && html.indexOf('2024')<html.indexOf('Other files'));
assert.equal(controls['media-sizes'].hidden,false);
src.estimates.complete=true;context.renderSizeDetails();assert.equal(cells[0].textContent,'~1.0 KiB');
src.estimates.items.media={};context.renderSizeDetails();assert.match(controls['media-year-sizes'].innerHTML,/No media files/);
src.estimates=null;context.renderSizeDetails();assert.equal(controls['media-sizes'].hidden,true);assert.equal(cells[0].textContent,'Estimating…');
src.estimates={complete:false,items:{media:{'<img>':42}}};context.renderSizeDetails();assert.doesNotMatch(controls['media-year-sizes'].innerHTML,/<img>/);
console.log('Estimate UI checks passed: source/destination sizes, partial/empty states, year ordering, and escaped labels.');

// Selected sizes update all four component headers and the combined footer.
for(const kind of ['tables','plugins','themes','media']){
 controls[kind+'-source-size']={};controls['enable-'+kind]={checked:true};
}
controls['summary-size-note']={};controls['database-summary']={};controls['media-mode']={value:'all'};
context.componentKinds=['tables','plugins','themes','media'];
const selected={tables:['one','two'],plugins:['example'],themes:[],media_ids:[]};
context.document.querySelectorAll=selector=>{
 if(selector==='[data-component-size]')return cells;
 const kind=selector.match(/data-kind="([^"]+)"/)?.[1];return (selected[kind]||[]).map(value=>({value}));
};
src.tables=[{id:'one',bytes:1048576},{id:'two',bytes:1048576}];
src.estimates={complete:true,bytes:{plugins:30*1048576,themes:0,media:5242880},items:{plugins:{example:3145728,unselected:27*1048576},themes:{},media:{}}};
vm.runInContext(code.slice(code.indexOf('    function renderComponentSizes('),code.indexOf('    function estimateSize(')),context);
context.renderComponentSizes();
assert.equal(controls['tables-source-size'].textContent,'~2.0 MiB');
assert.equal(controls['plugins-source-size'].textContent,'~3.0 MiB','Unselected plugins do not inflate the selected source size');
assert.equal(controls['themes-source-size'].textContent,'~0 B');
assert.equal(controls['media-source-size'].textContent,'~5.0 MiB');
assert.equal(controls['database-summary'].textContent,'~10.0 MiB of source data selected');
selected.tables.pop();context.renderComponentSizes();assert.equal(controls['database-summary'].textContent,'~9.0 MiB of source data selected');
selected.tables.push('two');controls['enable-plugins'].checked=false;context.renderComponentSizes();
assert(controls['plugins-source-size'].hidden);assert.equal(controls['database-summary'].textContent,'~7.0 MiB of source data selected');
controls['enable-plugins'].checked=true;
src.estimates.complete=false;context.renderComponentSizes();
assert.equal(controls['plugins-source-size'].textContent,'≥ 3.0 MiB');assert.match(controls['database-summary'].textContent,/10.0 MiB known.*estimating/);
src.estimates=null;context.renderComponentSizes();assert.equal(controls['media-source-size'].textContent,'Estimating…');assert.equal(controls['tables-source-size'].textContent,'~2.0 MiB');
src.estimates={complete:true,bytes:{media:5242880},items:{plugins:{example:3145728},media:{}}};
controls['media-mode'].value='uploaded';context.renderComponentSizes();
assert.equal(controls['media-source-size'].textContent,'Size pending');assert.equal(controls['database-summary'].textContent,'5.0 MiB known · media size pending');
controls['media-mode'].value='selected';context.renderComponentSizes();assert.equal(controls['media-source-size'].textContent,'~0 B','An empty media selection is zero');
selected.media_ids.push('42');context.renderComponentSizes();assert.equal(controls['media-source-size'].textContent,'Size pending','Filtered media must not show the whole library size');
for(const kind of context.componentKinds)controls['enable-'+kind].checked=false;
context.renderComponentSizes();assert.equal(controls['database-summary'].textContent,'No components included');
assert(context.componentKinds.every(kind=>controls[kind+'-source-size'].hidden));
console.log('PASS Selected component sizes, combined total, exclusions, partial caches, empty states and filtered-media pending sizes.');
