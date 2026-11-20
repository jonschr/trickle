// Exercise actual inline-editor handlers with isolated DOM controls.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/admin.js'),'utf8');
const listeners={},calls=[],focus=[],feedback=[];
const name={value:'Draft',focus(){focus.push('input');},select(){focus.push('select');}};
const rename={dataset:{renameSite:'a'},hidden:false,focus(){focus.push('rename');}};
const form={dataset:{connectionEditor:'a'},elements:{name},hidden:true,closest(){return row;}};
const row={querySelector(selector){return selector==='form'?form:rename;}};rename.closest=()=>row;
const controls={
    'saved-sites':{addEventListener(type,fn){listeners[type]=fn;},querySelectorAll(){return [form];},querySelector(){return rename;}},
    'site-menu':{hidden:false},'site-menu-toggle':{focus(){focus.push('menu');}}
};
let pending;
const context={busy:false,session:null,connectionState:{selected:'a',connections:[{id:'a',name:'Harrison'},{id:'b',name:'Other'}]},
    $:id=>controls[id],confirm:()=>true,feedback:text=>feedback.push(text),
    run:fn=>{pending=fn();},api:async(op,data)=>{calls.push([op,data]);if(op==='remove_connection')context.connectionState.selected='b';},
    clearInventory(){calls.push(['clear']);},startInventoryLoad(){calls.push(['load']);},document:{querySelector(){return {focus(){focus.push('add');}};}}
};
vm.createContext(context);vm.runInContext(source.slice(source.indexOf('    function closeConnectionEditor('),source.indexOf("    $('site-menu-toggle').addEventListener('click'")),context);
const target=selectors=>({closest:selector=>selectors[selector]||null});
const renameEvent={target:target({'[data-rename-site]':rename})};
(async()=>{
    listeners.click(renameEvent);assert.equal(form.hidden,false);assert.equal(rename.hidden,true);assert.equal(name.value,'Harrison');assert.deepEqual(focus.slice(-2),['input','select']);
    let prevented=false,stopped=false;
    listeners.keydown({key:'Escape',target:target({'[data-connection-editor]':form}),preventDefault(){prevented=true;},stopPropagation(){stopped=true;}});
    assert(prevented&&stopped);assert.equal(form.hidden,true);assert.equal(rename.hidden,false);
    listeners.click(renameEvent);name.value='  Renamed  ';
    listeners.submit({target:target({'[data-connection-editor]':form}),preventDefault(){}});await pending;
    assert.equal(calls[0][0],'rename_connection');assert.equal(calls[0][1].site,'a');assert.equal(calls[0][1].name,'Renamed');assert.deepEqual(focus.slice(-1),['rename']);
    const before=calls.length;name.value='  ';
    listeners.submit({target:target({'[data-connection-editor]':form}),preventDefault(){}});assert.equal(calls.length,before);
    const remove={dataset:{removeSite:'a'}};const removeEvent={target:target({'[data-remove-site]':remove})};
    context.confirm=()=>false;listeners.click(removeEvent);assert.equal(calls.length,before);
    context.confirm=()=>true;listeners.click(removeEvent);await pending;
    assert.equal(calls[before][0],'remove_connection');assert.equal(calls[before][1].site,'a');assert.deepEqual(calls.slice(-2),[['clear'],['load']]);
    context.session={id:'retained'};const count=calls.length;listeners.click(removeEvent);assert.equal(calls.length,count);
    console.log('PASS Inline rename, focus, Escape, trimmed save, blank validation, removal confirmation, selected-site reset and retained-transfer guard.');
})().catch(error=>{console.error(error);process.exitCode=1;});
