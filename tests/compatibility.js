/* Execute the real error-notice renderer, including untrusted component/parser text. */
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/admin.js'), 'utf8');
let scrolled = false;
const controls = {error:{append(node){this.copy=node;},hidden:true,scrollIntoView(){scrolled=true;}},diagnostics:{open:false}};
let meterError, copied, focusRestored=false;
const previousFocus={isConnected:true,disabled:false,closest(){return null;},focus(){focusRestored=true;}};
const context = {document:{activeElement:previousFocus,createElement(){return {children:[],append(...nodes){this.children.push(...nodes);},addEventListener(name,handler){this[name]=handler;}};}},navigator:{clipboard:{async writeText(value){copied=value;}}},transferMeter:{fail(error){meterError=error.message;}},$:id=>controls[id],feedback(){},refreshLogs(){throw Error('Diagnostics should stay closed');}};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('    const esc ='),source.indexOf('    const bytes =')) + source.slice(source.indexOf('    function fail('),source.indexOf('    function feedback(')),context);
context.fail(Error('Theme "<img src=x onerror=alert(1)>": PHP parse error on destination PHP 8.4. File: themes/example/broken.php.'));
assert.equal(controls.error.hidden,false);
assert.equal(scrolled,true);
assert.match(meterError,/PHP parse error on destination PHP 8.4/);
assert.match(controls.error.innerHTML,/^<p class="csm-error-message">Theme &quot;&lt;img/);
assert.match(controls.error.innerHTML,/PHP parse error on destination PHP 8.4/);
assert.equal(controls.error.innerHTML.includes('<img'),false);
const actions=controls.error.copy;
assert.equal(actions.className,'csm-error-actions');assert.equal(actions.children.length,2);
assert.equal(actions.children[0].textContent,'Copy error details');assert.equal(actions.children[1].textContent,'Dismiss');
(async()=>{
 await actions.children[0].click();assert.match(copied,/Theme "<img/);assert.equal(actions.children[0].textContent,'Copied');
 actions.children[1].click();assert.equal(controls.error.hidden,true);assert(focusRestored);
 console.log('PASS Error details are escaped, copy uses the original text, actions are grouped, and dismiss restores focus.');
})().catch(error=>{console.error(error);process.exitCode=1;});
