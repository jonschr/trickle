/* global CSM */
(() => {
    'use strict';
    const $ = id => document.getElementById(`csm-${id}`);
    let sites = null, session = CSM.session, busy = false, summary = null;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const bytes = n => n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : n < 1073741824 ? `${(n / 1048576).toFixed(1)} MB` : `${(n / 1073741824).toFixed(1)} GB`;
    const tableSort = {key:'source_bytes', descending:true};
    async function api(op, data = {}) {
        const body = new URLSearchParams({action:'csm_admin', nonce:CSM.nonce, op, data:JSON.stringify(data)});
        const response = await fetch(CSM.ajax, {method:'POST', credentials:'same-origin', body});
        const text = await response.text();
        let result;
        try { result = JSON.parse(text); } catch (_) { throw new Error(`Unexpected response (HTTP ${response.status}). Check the PHP log and firewall. ${text.replace(/<[^>]*>/g, '').slice(0, 160)}`); }
        if (!result.ok) throw new Error(result.error || 'Request failed.');
        if (result.data?.nonce) CSM.nonce = result.data.nonce;
        return result.data;
    }
    function message(text) { $('status').textContent = text; }
    function fail(error) { $('error').textContent = error.message; $('error').hidden = false; feedback('Request failed. Review the error above and try again.'); }
    function feedback(text) { $('feedback').textContent = text; $('feedback').hidden = !text; }
    async function run(fn) {
        if (busy) return;
        busy = true; $('error').hidden = true;
        document.querySelector('.csm').setAttribute('aria-busy', 'true');
        feedback('Working… Keep this page open.');
        const controls = [...document.querySelectorAll('.csm button, .csm select, .csm input, .csm textarea')].filter(control => control.id !== 'csm-copy');
        const previous = controls.map(control => control.disabled);
        controls.forEach(control => { control.disabled = true; });
        try { await fn(); } catch (error) { fail(error); }
        finally { controls.forEach((control, i) => { control.disabled = previous[i]; }); busy = false; document.querySelector('.csm').setAttribute('aria-busy', 'false'); if ($('feedback').textContent === 'Working… Keep this page open.') feedback('Done.'); $('progress').hidden = true; lockSelection(); updateSelection(); }
    }
    function lockSelection() {
        document.querySelectorAll('#csm-direction, #csm-media-mode, #csm-since, #csm-replace, input[data-kind], .csm-select-all').forEach(control => { control.disabled = !!session || control.dataset.protected === 'true'; });
    }
    const direction = () => $('direction').value;
    const source = () => direction() === 'pull' ? sites.remote : sites.local;
    const destination = () => direction() === 'pull' ? sites.local : sites.remote;
    const badge = item => item?.update ? `<span class="csm-update">Update: ${esc(item.update)}</span>` : '';
    function icon(url, kind) {
        const shapes = {
            plugins:'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            themes:'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 9v12"/>',
            site:'<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>'
        };
        let image = '';
        try {
            const parsed = new URL(url);
            if (['https:', 'http:'].includes(parsed.protocol) && !parsed.username && !parsed.password) image = `<img class="csm-icon-image" src="${esc(parsed.href)}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">`;
        } catch (_) { /* Missing artwork uses the category icon. */ }
        return `<span class="csm-identity-icon csm-icon-${kind}" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">${shapes[kind]}</svg>${image}</span>`;
    }
    const selectAll = kind => `<label class="csm-select-heading"><input type="checkbox" class="csm-select-all" data-select-kind="${kind}" aria-label="Select all ${kind === 'tables' ? 'database tables' : kind}"><span${kind === 'tables' ? ' class="screen-reader-text"' : ''}>Select all ${kind === 'tables' ? 'database tables' : kind}</span></label>`;
    function components(kind) {
        if (kind === 'tables') { renderTables(); return; }
        const src = source(), dst = destination();
        const lookup = new Map(dst[kind].map(item => [item.id, item]));
        let html = `<div class="csm-columns">${selectAll(kind)}<span aria-hidden="true">Source</span><span aria-hidden="true">Destination</span></div>`;
        for (const item of src[kind]) {
            const target = lookup.get(item.id);
            const disabled = item.protected ? 'disabled' : '';
            const detail = item.protected ? 'Transfer plugin · stays installed on both ends' : (item.active ? 'Active' : 'Inactive');
            const different = target && item.version !== target.version;
            html += `<label class="csm-component"><span><input type="checkbox" data-kind="${kind}" data-protected="${!!item.protected}" aria-label="Transfer ${esc(item.name)}" value="${esc(item.id)}" ${disabled}>${icon(item.icon, kind)}<span><strong>${esc(item.name)}</strong><small>${esc(detail)}</small></span></span><span><span class="csm-cell-label">Source</span><b>${esc(item.version)}</b>${badge(item)}</span><span class="${different ? 'csm-different' : ''}"><span class="csm-cell-label">Destination</span>${target ? `<b>${esc(target.version)}</b>${badge(target)}` : '<small>Not installed</small>'}</span></label>`;
        }
        $(kind).innerHTML = html + (!src[kind].length ? '<p>No components found on the source site.</p>' : '');
    }
    function renderTables() {
        const selected = new Set([...document.querySelectorAll('input[data-kind="tables"]:checked')].map(input => input.value));
        const src = source(), dst = destination();
        const lookup = new Map(dst.tables.map(item => [item.id.slice(dst.prefix.length), item]));
        const rows = src.tables.map(item => ({item, target:lookup.get(item.id.slice(src.prefix.length))}));
        const value = row => tableSort.key === 'name' ? row.item.name : (tableSort.key.startsWith('source_') ? row.item : row.target)?.[tableSort.key.split('_')[1]];
        rows.sort((a, b) => {
            const left = value(a), right = value(b);
            if (left == null || right == null) return left == null ? (right == null ? a.item.name.localeCompare(b.item.name) : 1) : -1;
            const comparison = typeof left === 'string' ? left.localeCompare(right, undefined, {numeric:true}) : left - right;
            return (tableSort.descending ? -comparison : comparison) || a.item.name.localeCompare(b.item.name);
        });
        const ranges = [src, dst].map(site => {
            const sizes = site.tables.map(item => item.bytes);
            return {min:Math.min(...sizes), max:Math.max(...sizes)};
        });
        const pill = (item, range, site) => {
            if (!item) return '<span class="csm-missing">Not installed</span>';
            const ratio = range.max === range.min ? .5 : (Math.log1p(item.bytes) - Math.log1p(range.min)) / (Math.log1p(range.max) - Math.log1p(range.min));
            const tone = ratio <= 1 / 3 ? 'small' : ratio >= 2 / 3 ? 'large' : 'medium';
            const description = range.min === range.max ? 'Equal table sizes' : item.bytes === range.max ? 'Largest table' : item.bytes === range.min ? 'Smallest table' : tone === 'large' ? 'Larger table' : tone === 'small' ? 'Smaller table' : 'Medium table';
            return `<span class="csm-size csm-size-${tone}" title="${description} on the ${site} · ${item.bytes.toLocaleString()} bytes">${bytes(item.bytes)}</span>`;
        };
        const columns = [['name', 'Table'], ['source_rows', 'Source rows'], ['source_bytes', 'Source size'], ['destination_rows', 'Destination rows'], ['destination_bytes', 'Destination size']];
        const headers = columns.map(([key, label]) => {
            const active = tableSort.key === key;
            const path = active ? (tableSort.descending ? 'm6 9 6 6 6-6' : 'm6 15 6-6 6 6') : 'm8 9 4-4 4 4m-8 6 4 4 4-4';
            return `<th scope="col" aria-sort="${active ? (tableSort.descending ? 'descending' : 'ascending') : 'none'}">${key === 'name' ? '<div class="csm-table-name-heading">' + selectAll('tables') : ''}<button type="button" class="csm-sort" data-sort="${key}">${label}<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="${path}"/></svg></button>${key === 'name' ? '</div>' : ''}</th>`;
        }).join('');
        $('tables').innerHTML = `<div class="csm-table-toolbar"><span class="csm-size-legend">Relative size on each site <span class="csm-size csm-size-small">Smaller</span><span class="csm-size csm-size-large">Larger</span></span></div><p class="csm-scroll-hint">Scroll horizontally to compare both sites.</p><div class="csm-table-scroll" role="region" aria-label="Database table comparison" tabindex="0"><table class="csm-data-table"><caption class="screen-reader-text">Database tables. Activate a column heading to sort; activate it again to reverse the order. Sizes include data and indexes.</caption><thead><tr>${headers}</tr></thead><tbody>${rows.map(({item, target}) => `<tr><th scope="row"><label><input type="checkbox" data-kind="tables" value="${esc(item.id)}" aria-label="Transfer ${esc(item.name)}" ${selected.has(item.id) ? 'checked' : ''} ${session ? 'disabled' : ''}><span><strong>${esc(item.name)}</strong><small>${esc(item.engine)}</small></span></label></th><td>~${item.rows.toLocaleString()}</td><td>${pill(item, ranges[0], 'source')}</td><td>${target ? '~' + target.rows.toLocaleString() : '<span class="csm-missing">Not installed</span>'}</td><td>${pill(target, ranges[1], 'destination')}</td></tr>`).join('') || '<tr><td colspan="5">No database tables found on the source site.</td></tr>'}</tbody></table></div>`;
    }
    function updateSelection() {
        const counts = {};
        for (const kind of ['plugins', 'themes', 'tables', 'media_ids']) {
            counts[kind] = !sites && session?.selection ? (session.selection[kind] || []).length : document.querySelectorAll(`input[data-kind="${kind}"]:checked`).length;
            if (kind !== 'media_ids') {
                const total = document.querySelectorAll(`input[data-kind="${kind}"]:not([data-protected="true"])`).length;
                $(kind + '-summary').textContent = sites ? `${counts[kind]} of ${total} selected` : `${counts[kind]} selected`;
                const checkbox = document.querySelector(`.csm-select-all[data-select-kind="${kind}"]`);
                if (checkbox) {
                    checkbox.checked = total > 0 && counts[kind] === total;
                    checkbox.indeterminate = counts[kind] > 0 && counts[kind] < total;
                    checkbox.disabled = busy || !!session || total === 0;
                }
            }
        }
        document.querySelectorAll('.csm-sort').forEach(button => { button.disabled = busy || !sites; });
        const mode = !sites && session?.selection ? session.selection.media : $('media-mode').value;
        const media = mode === 'all' ? 'All media files' : mode === 'changed' ? 'Changed media files' : mode === 'selected' ? `${counts.media_ids} attachment${counts.media_ids === 1 ? '' : 's'} selected` : 'No media files';
        $('media-summary').textContent = media;
        $('selection-summary').textContent = `${counts.plugins} plugin${counts.plugins === 1 ? '' : 's'} · ${counts.themes} theme${counts.themes === 1 ? '' : 's'} · ${counts.tables} table${counts.tables === 1 ? '' : 's'} · ${media.toLowerCase()}`;
        const selected = counts.plugins + counts.themes + counts.tables > 0 || (mode !== 'none' && (mode !== 'selected' || counts.media_ids > 0));
        $('stage').disabled = busy || (!session && (!sites || !selected));
        if (!session) $('action-title').textContent = selected ? 'Ready to stage' : 'Choose what to transfer';
    }
    function renderMedia() {
        const mode = $('media-mode').value;
        $('since-wrap').hidden = mode !== 'changed'; $('media-list').hidden = mode !== 'selected';
        if (mode === 'changed' && sites) {
            const stamp = sites.history?.[`${direction()}:${sites.remote.url}`];
            $('since').value = stamp ? new Date(stamp * 1000).toISOString().slice(0,16) : '';
            $('watermark-note').textContent = stamp ? 'Uses the source server’s start time from the last applied media stream.' : 'No previous media transfer. Choose a UTC starting date or transfer all media first.';
        }
        updateSelection();
    }
    function render() {
        if (session) $('direction').value = session.direction;
        $('workspace').hidden = false;
        for (const [id, site] of [['source', source()], ['destination', destination()]]) {
            $(id).innerHTML = `${icon(site.icon, 'site')}<span class="csm-site-identity"><strong>${esc(site.url)}</strong>${site.name ? `<small>${esc(site.name)}</small>` : ''}</span>`;
        }
        $('version-note').textContent = `Source: WordPress ${source().wordpress}, PHP ${source().php}. Destination: WordPress ${destination().wordpress}, PHP ${destination().php}. ${source().wordpress !== destination().wordpress ? 'WordPress versions differ; core is not transferred. ' : ''}${direction() === 'push' && !sites.remote.incoming_writes ? 'Enable incoming writes on the remote site before pushing.' : ''}`;
        $('tables').replaceChildren();
        for (const kind of ['plugins','themes','tables']) components(kind);
        $('media-list').innerHTML = source().media.map(item => `<label class="csm-media"><input type="checkbox" data-kind="media_ids" value="${item.id}"><span>${esc(item.name)}<small>${esc(item.file)}</small></span></label>`).join('') + (!source().media.length ? '<p>No attachments found on the source site.</p>' : '') + (source().media_total > 250 ? '<p>Showing the latest 250 attachments. All/changed modes cover the entire uploads directory.</p>' : '');
        renderMedia();
        if (session?.selection) {
            const selection = session.selection;
            document.querySelectorAll('input[data-kind]').forEach(input => { input.checked = (selection[input.dataset.kind] || []).map(String).includes(input.value); });
            $('media-mode').value = selection.media; $('replace').checked = selection.replace;
            renderMedia();
        }
        lockSelection(); updateSelection();
    }
    async function load(refresh = false, inventory = null) {
        if (session) {
            summary = await api('status', {id:session.id});
            $('workspace').hidden = false; $('source').textContent = session.source; $('destination').textContent = session.destination;
            showSummary();
        }
        feedback(refresh ? 'Checking installed versions and available updates on both sites…' : 'Connecting and loading the site inventories…');
        sites = inventory || await api('manifest', {refresh}); render();
        $('connection-summary').textContent = `Connected to ${sites.remote.url}`;
        $('connect-status').textContent = `Connected to ${sites.remote.url}. Paste another code to change sites.`;
        feedback(refresh ? 'Versions refreshed on both sites.' : '');
        $('connection').open = false;
    }
    function showSummary() {
        if (!summary) return;
        const applied = summary.status === 'applied', restored = summary.status === 'restored';
        $('apply').hidden = !summary.ready || summary.status !== 'staging';
        $('rollback').hidden = !['applied','applying'].includes(summary.status);
        $('discard').hidden = false; $('stage').hidden = applied || summary.status === 'applying';
        $('stage').hidden = summary.status !== 'staging' || summary.ready;
        $('stage').textContent = summary.ready ? 'Stage transfer' : 'Retry remaining streams';
        $('action-title').textContent = applied ? 'Transfer applied' : restored ? 'Destination restored' : summary.ready ? 'Staged and verified' : 'Transfer in progress';
        message(`${summary.tables} tables · ${summary.rows.toLocaleString()} rows · ${summary.files.toLocaleString()} files · ${bytes(summary.bytes)}. ${applied ? 'Rollback backup retained on the destination.' : restored ? 'The destination backup has been restored.' : 'Destination: ' + session.destination}`);
    }
    $('copy').addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText($('pairing').value);
            feedback('Connection code copied. It includes this site’s URL and private key.');
        } catch (_) {
            $('connection').open = true;
            $('pairing').hidden = false;
            $('reveal').setAttribute('aria-expanded', 'true');
            $('reveal').textContent = 'Hide connection code';
            $('pairing').focus(); $('pairing').select();
            feedback('Your browser could not copy automatically. The connection code is selected; copy it with your keyboard.');
        }
    });
    $('reveal').addEventListener('click', () => {
        const show = $('pairing').hidden; $('pairing').hidden = !show;
        $('reveal').setAttribute('aria-expanded', String(show));
        $('reveal').textContent = show ? 'Hide connection code' : 'Show connection code';
    });
    let savedPermissions = {pull:$('allow-read').checked, push:$('allow-write').checked};
    for (const id of ['allow-read', 'allow-write']) $(id).addEventListener('change', () => run(async () => {
        $('permissions-status').textContent = 'Saving permissions…';
        const settings = {pull:$('allow-read').checked, push:$('allow-write').checked};
        try {
            const result = await api('settings', settings);
            savedPermissions = settings; $('pairing').value = result.pairing;
            $('permissions-status').textContent = 'Permissions saved automatically.';
            feedback('Permissions saved.');
        } catch (error) {
            $('allow-read').checked = savedPermissions.pull; $('allow-write').checked = savedPermissions.push;
            $('permissions-status').textContent = 'Save not confirmed. Previous values shown; reload to verify before retrying.';
            throw error;
        }
    }));
    $('rotate').addEventListener('click', () => run(async () => {
        if (!confirm('Rotate this site’s key? Existing connections to this site will need its new connection code.')) return;
        const result = await api('settings', {pull:$('allow-read').checked, push:$('allow-write').checked, rotate:true}); $('pairing').value = result.pairing; feedback('Connection key rotated. Update any sites that connect to this site.');
    }));
    let connectTimer;
    function connectFromCode() {
        const code = $('remote-code').value.trim();
        if (!code) { $('connect-status').textContent = 'Paste a complete connection code to connect automatically.'; return; }
        let complete = /^https?:\/\/\S+\s+[a-f0-9]{64}$/.test(code);
        if (!complete) {
            try {
                const peer = JSON.parse(atob(code));
                complete = typeof peer?.url === 'string' && /^https?:\/\//.test(peer.url) && typeof peer.key === 'string' && /^[a-f0-9]{64}$/.test(peer.key);
            } catch (_) { /* Incomplete input stays local until a complete code is pasted. */ }
        }
        if (!complete) { $('connect-status').textContent = 'The code is incomplete. Include both the site URL and its full private key.'; return; }
        if (busy) { connectTimer = setTimeout(connectFromCode, 200); return; }
        run(async () => {
            $('connect-status').textContent = 'Connecting and checking the remote site…';
            try {
                const inventory = await api('connect', {pairing:code});
                $('remote-code').value = '';
                await load(false, inventory);
            } catch (error) {
                $('connect-status').textContent = 'Could not connect. Check the code and the remote site’s permissions, then paste it again.';
                throw error;
            }
        });
    }
    $('remote-code').addEventListener('input', () => {
        clearTimeout(connectTimer);
        $('connect-status').textContent = $('remote-code').value.trim() ? 'Checking connection code…' : 'Paste a complete connection code to connect automatically.';
        connectTimer = setTimeout(connectFromCode, 600);
    });
    $('refresh').addEventListener('click', () => run(() => load(true)));
    $('direction').addEventListener('change', render);
    $('media-mode').addEventListener('change', renderMedia);
    $('themes').addEventListener('change', event => {
        const id = event.target.value, theme = source().themes.find(item => item.id === id);
        if (theme?.parent && event.target.checked) {
            const parent = [...document.querySelectorAll('input[data-kind="themes"]')].find(input => input.value === theme.parent);
            if (parent) parent.checked = true;
        }
    });
    $('workspace').addEventListener('change', event => {
        const checkbox = event.target;
        if (checkbox.matches('.csm-select-all')) {
            document.querySelectorAll(`input[data-kind="${checkbox.dataset.selectKind}"]:not(:disabled)`).forEach(input => { input.checked = checkbox.checked; });
        }
        updateSelection();
    });
    $('tables').addEventListener('click', event => {
        const button = event.target.closest('.csm-sort');
        if (!button || busy) return;
        tableSort.descending = tableSort.key === button.dataset.sort ? !tableSort.descending : button.dataset.sort !== 'name';
        tableSort.key = button.dataset.sort;
        renderTables(); updateSelection();
        document.querySelector(`.csm-sort[data-sort="${tableSort.key}"]`).focus({preventScroll:true});
    });
    document.querySelector('.csm').addEventListener('error', event => {
        if (event.target.matches?.('.csm-icon-image')) event.target.remove();
    }, true);
    $('stage').addEventListener('click', () => run(async () => {
        if (session) {
            summary = await api('status', {id:session.id});
            if (summary.status !== 'staging') throw new Error('Discard the previous transfer before starting another.');
            for (const kind of summary.pending) {
                message(`Retrying the ${kind} stream for ${session.destination}…`);
                summary = await api('transfer', {id:session.id, kind});
            }
            showSummary(); return;
        }
        const selection = {media:$('media-mode').value, replace:$('replace').checked, since:$('since').value ? Math.floor(new Date($('since').value + 'Z').getTime()/1000) : 0};
        for (const kind of ['plugins','themes','tables','media_ids']) selection[kind] = [...document.querySelectorAll(`input[data-kind="${kind}"]:checked`)].map(input => kind === 'media_ids' ? Number(input.value) : input.value);
        const dest = destination().url;
        summary = await api('prepare', {direction:direction(), selection});
        session = {id:summary.id, direction:direction(), source:source().url, destination:dest, selection}; showSummary();
        $('progress').hidden = false; $('progress').removeAttribute('value');
        const kinds = [];
        if (selection.tables.length) kinds.push('database');
        if (selection.plugins.length || selection.themes.length || selection.media !== 'none') kinds.push('files');
        for (const kind of kinds) {
            message(`Receiving and verifying the ${kind} stream for ${dest}. Keep this page open.`);
            summary = await api('transfer', {id:session.id, kind}); showSummary();
        }
    }));
    $('apply').addEventListener('click', () => run(async () => {
        if (!confirm(`Apply ${summary.tables} tables and ${summary.files} files to ${session.destination}? Selected tables and plugin/theme directories will replace their destination versions.`)) return;
        message(`Applying to ${session.destination}…`); summary = await api('commit', {id:session.id}); showSummary();
    }));
    $('rollback').addEventListener('click', () => run(async () => {
        if (!confirm(`Restore the pre-transfer backup on ${session.destination}? Changes made since the transfer will be overwritten.`)) return;
        summary = await api('rollback', {id:session.id}); showSummary();
    }));
    $('discard').addEventListener('click', () => run(async () => {
        if (!confirm('Delete this transfer’s staged data and rollback backup? Applied changes will remain.')) return;
        await api('discard', {id:session.id}); session = null; summary = null;
        for (const id of ['apply','rollback','discard']) $(id).hidden = true;
        $('stage').hidden = false; $('stage').textContent = 'Stage transfer'; $('action-title').textContent = 'Ready to stage'; message('Previous transfer data and backup removed.'); await load();
    }));
    updateSelection();
    if (CSM.connected) run(() => load());
})();
