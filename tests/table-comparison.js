/* Table comparison keeps both sites on one size scale and preserves selection while sorting. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/../assets/admin.js','utf8'),controls={tables:{},'table-order':{}};
const src={name:'Source',prefix:'wp_',tables:[{id:'wp_a_small',name:'wp_a_small',bytes:100,rows:1,engine:'InnoDB'},{id:'wp_z_large',name:'wp_z_large',bytes:300*1048576,rows:500000,engine:'InnoDB'}]};
const dst={name:'Destination',prefix:'other_',tables:[{id:'other_z_large',bytes:150*1048576,rows:250000}]};
const context={$:id=>controls[id],source:()=>src,destination:()=>dst,siteLabel:s=>s.name,session:null,tableSort:{key:'source_bytes',descending:true},selectAll:()=>'',document:{querySelectorAll:()=>[{value:'wp_a_small'}]}};
vm.createContext(context);vm.runInContext(code.slice(code.indexOf('    const esc ='),code.indexOf('    const transferMeter'))+code.slice(code.indexOf('    function renderTables('),code.indexOf('    function updateSelection(')),context);
context.renderTables();let html=controls.tables.innerHTML;
assert(html.indexOf('value="wp_z_large"')<html.indexOf('value="wp_a_small"'));
assert.match(html,/csm-table-size is-large/);assert.match(html,/width:100%/);assert.match(html,/width:50%/);assert.match(html,/250,000 rows/);assert.match(html,/Not installed/);
assert.match(html,/value="wp_a_small"[^>]*checked/);assert.doesNotMatch(html,/value="wp_z_large"[^>]*checked/);
context.tableSort={key:'name',descending:false};context.renderTables();html=controls.tables.innerHTML;
assert(html.indexOf('value="wp_a_small"')<html.indexOf('value="wp_z_large"'));assert.match(html,/value="wp_a_small"[^>]*checked/);assert.match(html,/csm-table-size is-large/,'Excluded large tables retain their size highlight');
assert.equal(controls['table-order'].value,'name');
console.log('PASS Largest-first sorting, shared size scale, prefix matching, missing targets and selection preservation.');
