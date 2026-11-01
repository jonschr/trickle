/* Execute the real error-notice renderer, including untrusted component/parser text. */
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/admin.js'), 'utf8');
let scrolled = false;
const controls = {error:{append(node){this.copy=node;},hidden:true,scrollIntoView(){scrolled=true;}},diagnostics:{open:false}};
let meterError;
const context = {document:{createElement(){return {addEventListener(){}};}},transferMeter:{fail(error){meterError=error.message;}},$:id=>controls[id],feedback(){},refreshLogs(){throw Error('Diagnostics should stay closed');}};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('    const esc ='),source.indexOf('    const bytes =')) + source.slice(source.indexOf('    function fail('),source.indexOf('    function feedback(')),context);
context.fail(Error('Theme "<img src=x onerror=alert(1)>": PHP parse error on destination PHP 8.4. File: themes/example/broken.php.'));
assert.equal(controls.error.hidden,false);
assert.equal(scrolled,true);
assert.match(meterError,/PHP parse error on destination PHP 8.4/);
assert.match(controls.error.innerHTML,/^<p>Theme &quot;&lt;img/);
assert.match(controls.error.innerHTML,/PHP parse error on destination PHP 8.4/);
assert.equal(controls.error.innerHTML.includes('<img'),false);
console.log('Compatibility notice checks passed: visible, scrolled into view, PHP/file details preserved, HTML escaped.');
