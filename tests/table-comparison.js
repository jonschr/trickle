/* Source-size cell fills and byte sorting stay independent of destination sizes. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/../assets/admin.js','utf8'),controls={tables:{},'table-order':{}};
const src={name:'Source',prefix:'wp_',tables:[{id:'wp_a_small',name:'wp_a_small',bytes:100,rows:900000,engine:'InnoDB'},{id:'wp_z_large',name:'wp_z_large',bytes:300*1048576,rows:500000,engine:'InnoDB'}]};
const dst={name:'Destination',prefix:'other_',tables:[{id:'other_z_large',bytes:150*1048576,rows:250000}]};
const context={$:id=>controls[id],direction:()=> 'push',source:()=>src,destination:()=>dst,siteLabel:s=>s.name,session:null,tableSort:{key:'source_bytes',descending:true},selectAll:()=>'',document:{querySelectorAll:()=>[{value:'wp_a_small'}]}};
vm.createContext(context);vm.runInContext(code.slice(code.indexOf('    const esc ='),code.indexOf('    const transferMeter'))+code.slice(code.indexOf('    function renderTables('),code.indexOf('    function updateSelection(')),context);
context.renderTables();let html=controls.tables.innerHTML;
const initialHeaders=html.match(/<thead>(.*?)<\/thead>/)[1];
assert.doesNotMatch(initialHeaders,/<strong>/,'Site names live outside the single-line column headers');
assert.match(html,/<p class="csm-table-context"><span><strong>Source:<\/strong> Source<\/span><span><strong>Destination:<\/strong> Destination/);
assert.match(initialHeaders,/viewBox="0 0 32 32" width="20" height="20"/,'Sort controls use full-size Carbon arrows');
assert.match(initialHeaders,/data-sort="source_bytes" aria-label="Sort by source size ascending"/,'Active sort describes its reverse action');
assert.match(initialHeaders,/aria-sort="descending"/);

assert(html.indexOf('value="wp_z_large"')<html.indexOf('value="wp_a_small"'));
assert.match(html,/class="csm-table-source" data-size-band="large" style="--csm-size-mix:100%"/);assert.doesNotMatch(html,/csm-size-scale/);assert.match(html,/250,000 rows/);assert.match(html,/Not installed/);
assert.match(html,/value="wp_a_small"[^>]*checked/);assert.doesNotMatch(html,/value="wp_z_large"[^>]*checked/);
context.tableSort={key:'name',descending:false};context.renderTables();html=controls.tables.innerHTML;
assert(html.indexOf('value="wp_a_small"')<html.indexOf('value="wp_z_large"'));assert.match(html,/value="wp_a_small"[^>]*checked/);assert.match(html,/class="csm-table-source" data-size-band="large" style="--csm-size-mix:100%"/,'Excluded tables retain their size color');
assert.equal(controls['table-order'].value,'name');
console.log('PASS Largest-first sorting, source-size colors, prefix matching, missing targets and selection preservation.');

assert.match(code,/const tableSort = \{key:'source_bytes', descending:true\}/,'Initial sort is descending byte size');
assert.match(html,/<th scope="col">Type<\/th>/);
assert.match(html,/<td class="csm-table-engine">InnoDB<\/td>/);
assert.doesNotMatch(html,/<small>InnoDB/);
assert.match(html,/data-size-band="small" style="--csm-size-mix:0%"/,'The smallest table uses the green end of the scale');
assert.match(html,/<b>[^<]+<\/b><small class="csm-table-row-count">/,'Size and rows are grouped on the same metrics line');
context.tableSort={key:'source_bytes',descending:false};context.renderTables();html=controls.tables.innerHTML;
assert(html.indexOf('value="wp_a_small"')<html.indexOf('value="wp_z_large"'),'Ascending size uses bytes even when the smallest table has more rows');
context.tableSort={key:'destination_bytes',descending:true};context.renderTables();html=controls.tables.innerHTML;
assert(html.indexOf('value="wp_z_large"')<html.indexOf('value="wp_a_small"'),'Destination size sorts present tables before missing targets');
const sourceColors=markup=>markup.match(/class="csm-table-source"[^>]+/g);
context.tableSort={key:'source_bytes',descending:true};context.renderTables();
const before=sourceColors(controls.tables.innerHTML);
dst.tables[0].bytes=900*1048576;context.renderTables();
assert.deepEqual(sourceColors(controls.tables.innerHTML),before,'Larger destination sizes cannot change source colors');
assert.doesNotMatch(controls.tables.innerHTML,/<td class="csm-table-destination"[^>]*(?:style|data-size-band)/,'Destination cells have no size colors');
context.source=()=>dst;context.destination=()=>src;context.renderTables();html=controls.tables.innerHTML;
assert.match(html,/data-size-band="small" style="--csm-size-mix:0%"/,'A single-table source uses the green endpoint regardless of destination');
src.tables=[];dst.tables=[];context.renderTables();html=controls.tables.innerHTML;
assert.match(html,/colspan="4">No database tables/);
src.tables=[{id:'wp_empty',name:'wp_empty',bytes:0,rows:0,engine:'InnoDB'}];context.source=()=>src;context.destination=()=>dst;context.renderTables();html=controls.tables.innerHTML;
assert.match(html,/data-size-band="small" style="--csm-size-mix:0%"/);assert.doesNotMatch(html,/NaN|Infinity/,'Empty tables have a finite size scale');
console.log('PASS Engine column, source-only size colors, byte sorting, missing/empty tables and same-line metrics.');
src.tables[0].bytes=100;context.renderTables();assert.doesNotMatch(controls.tables.innerHTML,/NaN|Infinity/,'Equal-size tables have a finite scale');

src.tables=[100,125,150,175,200].map((size,index)=>({id:'wp_'+index,name:'wp_'+index,bytes:size,rows:0,engine:'InnoDB'}));
context.tableSort={key:'name',descending:false};context.renderTables();
assert.deepEqual(sourceColors(controls.tables.innerHTML).map(cell=>cell.match(/data-size-band="(\w+)" style="--csm-size-mix:([\d.]+)%"/).slice(1)),[['small','0'],['small','50'],['small','100'],['large','50'],['large','100']],'Actual byte spacing interpolates green through yellow to red');

let onScroll;
vm.runInNewContext(code.slice(code.indexOf("    document.addEventListener('scroll',"),code.indexOf("    $('workspace').addEventListener('change',")),{document:{addEventListener(type,listener,options){assert.equal(type,'scroll');assert(options.capture && options.passive);onScroll=listener;}}});
let faded;
const scroller={scrollTop:12,matches:selector=>selector.includes('.csm-table-scroll'),classList:{toggle(name,enabled){assert.equal(name,'csm-scrolled');faded=enabled;}}};
onScroll({target:scroller});assert.equal(faded,true,'Scrolling adds the header fade');
scroller.scrollTop=0;onScroll({target:scroller});assert.equal(faded,false,'Returning to the top removes the fade');
onScroll({target:{}});
console.log('PASS Header fade follows vertical scroll and ignores unrelated targets.');

for(const mode of ['push','pull']){
 context.direction=()=>mode;context.renderTables();const html=controls.tables.innerHTML;
 const headers=html.match(/<thead>(.*?)<\/thead>/)[1];
 assert((headers.indexOf('data-sort="destination_bytes"')<headers.indexOf('data-sort="source_bytes"'))===(mode==='pull'));
 const row=html.match(/<tbody><tr>(.*?)<\/tr>/)[1];
 assert((row.indexOf('class="csm-table-destination"')<row.indexOf('class="csm-table-source"'))===(mode==='pull'));
 const contextLine=html.match(/<p class="csm-table-context">(.*?)<\/p>/)[1];
 assert((contextLine.indexOf('Destination:')<contextLine.indexOf('Source:'))===(mode==='pull'));
}
console.log('PASS Table headings, values and site labels follow card order in both directions.');
