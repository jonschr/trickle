/* The real recent migration renderer must distinguish selected scope from recorded results. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/../assets/admin.js','utf8'),controls={};
const c={$:id=>controls[id]??={},busy:false,session:null,activePreset:'',presetLoading:false,transferDate:stamp=>'date-'+stamp,connectionState:{selected:'one',connections:[{id:'one',name:'Harrison'}],recents:[
 {id:'first',remote_id:'one',remote_name:'Harrison <script>',direction:'pull',started_at:20,completed_at:30,status:'applied',selection:{tables:['wp_posts'],plugins:['one'],themes:[],media:'all'},scope:{plugins:[{id:'one',name:'Example <img>',version:'1.2'}]},result:{tables:1,files:12,unchanged_files:25}},
 {id:'incomplete',remote_id:'one',remote_name:'Harrison',direction:'push',started_at:40,status:'staged',selection:{plugins:['code'],themes:['child'],tables:[],media:'none'},result:{tables:0,files:7}},
 {id:'other',remote_id:'two',started_at:50,selection:{},status:'applied'}]}};
vm.createContext(c);vm.runInContext(code.slice(code.indexOf('    const esc ='),code.indexOf('    const bytes ='))+code.slice(code.indexOf('    function recentScope('),code.indexOf('    async function reuseRecent(')),c);
c.renderRecentMigrations();const html=controls['recent-list'].innerHTML;
assert(html.indexOf('Staged · not applied')<html.indexOf('Completed'));assert.match(html,/1 table/);assert.match(html,/1 plugin/);assert.match(html,/All media · unchanged files skipped/);assert.match(html,/12 files copied.*25 unchanged files skipped/);assert.doesNotMatch(html,/7 files copied/);assert.match(html,/Example &lt;img&gt; 1.2/);assert(!html.includes('<script>'));assert(!html.includes('data-reuse-recent="other"'));assert.match(html,/Use these settings/);assert.doesNotMatch(html,/<select/);
assert.equal(controls['recent-wrap'].hidden,true,'History stays hidden before choosing Most recent');
c.activePreset='recent';c.renderRecentMigrations();assert.equal(controls['recent-wrap'].hidden,false);
c.presetLoading=true;c.renderRecentMigrations();assert.equal(controls['recent-wrap'].hidden,true,'History hides while another preset is loading');c.presetLoading=false;
c.activePreset='database';c.renderRecentMigrations();assert.equal(controls['recent-wrap'].hidden,true,'Other presets hide history');
c.activePreset='recent';c.session={id:'retained'};c.renderRecentMigrations();assert.equal(controls['recent-wrap'].hidden,true,'Active transfers hide history');c.session=null;
c.connectionState.selected='none';c.renderRecentMigrations();assert.equal(controls['recent-wrap'].hidden,true);
console.log('PASS History records show route, date, selected scope, item names and actual results; incomplete transfers are explicit and HTML is escaped.');
