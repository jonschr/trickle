/* Run: node tests/components.js. Exercises the actual UI selection helpers without a WordPress site. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/admin.js'), 'utf8');
const kinds = ['tables', 'plugins', 'themes', 'media'];
const controls = {};
for (const kind of kinds) {
    controls['enable-' + kind] = {checked:false, setAttribute(name, value) { this[name] = value; }};
    controls[kind + '-body'] = {hidden:true};
    controls['disclose-' + kind] = {setAttribute(name, value) { this[name] = value; }};
}
Object.assign(controls, {'media-mode':{value:'none'}, 'media-verify':{checked:false}, 'media-after':{value:''}, 'media-before':{value:''}, replace:{checked:true}, since:{value:''}});
const items = [
    {kind:'plugins', value:'one', checked:false},
    {kind:'plugins', value:'two', checked:false},
    {kind:'plugins', value:'trickle', protected:true, checked:false},
    {kind:'tables', value:'wp_posts', checked:false},
    {kind:'themes', value:'parent', checked:false},
    {kind:'media_ids', value:'42', checked:false},
];
let mediaRenders = 0;
const context = {
    $: id => controls[id], componentKinds:kinds, sites:null,
    renderMedia: () => { mediaRenders++; },
    document:{querySelectorAll(selector) {
        const kind = selector.match(/data-kind="([^"]+)"/)[1];
        return items.filter(item => item.kind === kind && (!selector.includes('data-protected') || !item.protected) && (!selector.includes(':checked') || item.checked));
    }},
};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('    function setPanelOpen('), source.indexOf('    function resetComponents(')) + source.slice(source.indexOf('    function currentSelection('), source.indexOf('    function restoreSelection(')), context);
context.setComponent('plugins', true, true);
assert.equal(items.filter(item => item.kind === 'plugins' && item.checked).length, 2);
assert.equal(items.find(item => item.protected).checked, false);
assert.equal(controls['plugins-body'].hidden, false);
assert.equal(controls['disclose-plugins']['aria-expanded'], 'true');
context.setPanelOpen('plugins', false);
assert.equal(controls['enable-plugins'].checked, true);
assert.equal(items.filter(item => item.kind === 'plugins' && item.checked).length, 2);
assert.equal(controls['plugins-body'].hidden, true);
assert.equal(context.currentSelection().enabled_components.join(','), 'plugins');
assert.equal(context.currentSelection().expanded_components.length, 0);
context.setPanelOpen('plugins', true);
assert.equal(controls['enable-plugins'].checked, true);
assert.equal(context.currentSelection().expanded_components.join(','), 'plugins');
for (const item of items) item.checked = false;
context.setComponent('plugins', true);
assert.equal(controls['enable-plugins'].checked, true);
assert.equal(controls['plugins-body'].hidden, false);
assert.equal(context.currentSelection().plugins.length, 0);
assert.equal(context.currentSelection().enabled_components.join(','), 'plugins');
context.setComponent('plugins', false, true);
assert.equal(controls['plugins-body'].hidden, true);
items[0].checked = true;
assert.equal(context.currentSelection().plugins.length, 0, 'Disabled components cannot leak stale item selections into a transfer');
context.setComponent('media', true, true);
assert.equal(controls['media-mode'].value, 'all');
assert.equal(controls['media-body'].hidden, false);
assert.equal(context.currentSelection().media, 'all');
context.setComponent('media', false, true);
assert.equal(context.currentSelection().media, 'none');
assert.equal(context.currentSelection().media_ids.length, 0);
assert.equal(controls['media-body'].hidden, true);
assert.equal(mediaRenders, 2);
context.setComponent('media', true, true);
controls['media-mode'].value = 'compare'; controls['media-verify'].checked = true;
assert.equal(context.currentSelection().media_verify, true);
context.setComponent('media', false, true);
assert.equal(context.currentSelection().media_verify, false, 'Disabled media cannot leak a stale full-verification option');
console.log('Component selection checks passed: defaults, independent collapse, protected plugin, enabled-empty state, disabled selection gating, and media toggles.');
