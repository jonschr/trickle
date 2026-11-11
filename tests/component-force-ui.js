const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const code=fs.readFileSync(__dirname+'/../assets/admin.js','utf8');
const controls={};const $=id=>controls[id]||(controls[id]={value:'',checked:false,hidden:false});
for(const kind of ['tables','plugins','themes','media'])$('enable-'+kind).checked=true;
$('media-mode').value='all';$('transport').value='stream';$('replace').checked=true;
const ctx={$,sites:null,componentKinds:['tables','plugins','themes','media'],document:{querySelectorAll:()=>[]}};
vm.createContext(ctx);vm.runInContext(code.slice(code.indexOf('    function currentSelection()'),code.indexOf('    function restoreSelection(')),ctx);
for(const kind of ['plugins','themes','media']){
 for(const other of ['plugins','themes','media'])$(other+'-force').checked=other===kind;
 const selection=ctx.currentSelection();
 for(const other of ['plugins','themes','media'])assert.equal(selection[other+'_force'],other===kind,kind+' force is independent of '+other);
 assert.equal('file_force' in selection,false,'No new global force flag');
}
$('plugins-force').checked=true;$('enable-plugins').checked=false;assert.equal(ctx.currentSelection().plugins_force,false,'Disabled components do not force files');
const start=code.indexOf("        for (const kind of ['plugins','themes']) if ($(kind + '-force')) $(kind + '-force').checked =");
assert(start>=0);const restore=code.slice(start,code.indexOf('\n',start));
ctx.selection={file_force:true,plugins_force:false};vm.runInContext(restore,ctx);assert.equal($('plugins-force').checked,false);assert.equal($('themes-force').checked,true);
const markup=fs.readFileSync(__dirname+'/../includes/admin.php','utf8');assert(!markup.includes('id="csm-file-force"'));
for(const kind of ['plugins','themes','media'])assert(markup.includes('id="csm-'+kind+'-force"'));
console.log('PASS Independent plugin/theme/media force controls, disabled components, legacy recent settings, and removal of global UI.');

ctx.sites={};ctx.source=()=>({plugins:[{id:'custom',follow_symlinks:true},{id:'unselected',follow_symlinks:true}],themes:[]});ctx.document.querySelectorAll=selector=>selector.includes('data-kind="plugins"')?[{value:'custom'}]:[];$('enable-plugins').checked=true;assert.equal(ctx.currentSelection().follow_symlinks.join(','),'plugins/custom');$('enable-plugins').checked=false;assert.equal(ctx.currentSelection().follow_symlinks.length,0);
console.log('PASS Source approvals are included only for selected, enabled components.');
