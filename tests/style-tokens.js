/* Catch broken CSS token references, including component-local aliases. No browser or network. */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const sheets = ['admin.css', 'workspace.css', 'clarity.css'].map(name => fs.readFileSync(path.join(__dirname, '../assets', name), 'utf8'));
const definitions = new Set(sheets.flatMap(css => [...css.matchAll(/(--[\w-]+)\s*:/g)].map(match => match[1])));
for (const css of sheets) {
    assert.doesNotMatch(css, /var\(\s*undefined\b/, 'Unmapped values must never become CSS variables');
    assert.doesNotMatch(css, /(?:\b(?:color|background-color|scrollbar-color)\s*:[^;{}]*|\bsolid\s+)var\(--csm-text-/, 'Font-size tokens cannot be used as colors');
    for (const [, name, fallback] of css.matchAll(/var\(\s*(--[\w-]+)\s*(,)?/g)) {
        assert(definitions.has(name) || name.startsWith('--wp-') || (fallback && !name.startsWith('--csm-')), `Undefined design token: ${name}`);
    }
}
console.log('PASS Shared and component-local CSS token references resolve.');
