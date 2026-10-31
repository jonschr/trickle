/* global CSM */
(() => {
    'use strict';
    const $ = id => document.getElementById(`csm-${id}`);
    let connectionState = CSM.state, sites = null, session = CSM.state.session, busy = false, summary = null, replacementRules = [], remoteUpdate = null;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const bytes = n => n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : n < 1073741824 ? `${(n / 1048576).toFixed(1)} MB` : `${(n / 1073741824).toFixed(1)} GB`;
    const tableSort = {key:'source_bytes', descending:true};
    const componentKinds = ['tables', 'plugins', 'themes', 'media'];
    function setPanelOpen(kind, open) {
        $(kind + '-body').hidden = !open;
        const button = $('disclose-' + kind);
        button.setAttribute('aria-expanded', String(open));
        button.setAttribute('aria-label', `${open ? 'Collapse' : 'Expand'} ${kind === 'tables' ? 'database' : kind === 'media' ? 'media library' : kind}`);
    }
    function setComponent(kind, enabled, selectItems = false) {
        const checkbox = $('enable-' + kind);
        checkbox.checked = enabled; setPanelOpen(kind, enabled);
        if (selectItems) {
            const itemKind = kind === 'media' ? 'media_ids' : kind;
            document.querySelectorAll(`input[data-kind="${itemKind}"]:not([data-protected="true"])`).forEach(input => { input.checked = kind === 'media' ? false : enabled; });
            if (kind === 'media') { $('media-mode').value = enabled ? 'all' : 'none'; renderMedia(); }
        }
    }
    function resetComponents() {
        for (const kind of componentKinds) setComponent(kind, false);
    }
    async function api(op, data = {}) {
        const body = new URLSearchParams({action:'csm_admin', nonce:CSM.nonce, op, data:JSON.stringify(data)});
        const response = await fetch(CSM.ajax, {method:'POST', credentials:'same-origin', body, ...(op === 'progress' ? {signal:AbortSignal.timeout(8000)} : {})});
        const text = await response.text();
        let result;
        try { result = JSON.parse(text); } catch (_) { throw new Error(`Unexpected response (HTTP ${response.status}). Check the PHP log and firewall. ${text.replace(/<[^>]*>/g, '').slice(0, 160)}`); }
        if (!result.ok) throw new Error(result.error || 'Request failed.');
        if (result.data?.nonce) CSM.nonce = result.data.nonce;
        if (Array.isArray(result.data?.state?.connections)) renderConnections(result.data.state);
        return result.data;
    }
    function message(text) { $('status').textContent = text; }
    function fail(error) { $('error').textContent = error.message; $('error').hidden = false; feedback('Request failed. Open Diagnostics below for the connection and transfer log.'); if ($('diagnostics').open) refreshLogs(); }
    function feedback(text) { $('feedback').textContent = text; $('feedback').hidden = !text; }
    let logEntries = [], loadingLogs = false;
    async function refreshLogs() {
        if (loadingLogs) return;
        loadingLogs = true; $('refresh-logs').disabled = true;
        $('log-status').textContent = 'Loading recent activity…';
        try {
            const result = await api('logs'); logEntries = result.entries;
            $('log-entries').innerHTML = logEntries.length ? `<table class="csm-log-table"><thead><tr><th scope="col">Time</th><th scope="col">Event</th><th scope="col">Details</th></tr></thead><tbody>${[...logEntries].reverse().map((entry, i) => `<tr><td><time datetime="${esc(entry.time)}">${esc(new Date(entry.time).toLocaleString())}</time><span class="csm-log-level ${entry.level === 'error' ? 'csm-log-error' : ''}">${esc(entry.level)}</span></td><td><strong>${esc(entry.event)}</strong>${entry.context.error ? `<small>${esc(entry.context.error)}</small>` : ''}${entry.context.site ? `<small>${esc(entry.context.site)}</small>` : ''}</td><td><button type="button" class="button-link" data-log-detail="${i}" aria-expanded="false" aria-controls="csm-log-detail-${i}">View details</button><pre id="csm-log-detail-${i}" hidden>${esc(JSON.stringify(entry.context, null, 2))}</pre></td></tr>`).join('')}</tbody></table>` : '<p>No events recorded yet. New connection and transfer attempts appear here.</p>';
            $('log-status').textContent = `${logEntries.length} recent events · newest first · times shown in your timezone`;
            $('download-logs').disabled = logEntries.length === 0;
        } catch (error) { $('log-status').textContent = `Could not load diagnostics: ${error.message}`; }
        finally { loadingLogs = false; $('refresh-logs').disabled = false; }
    }
    let progressStamp = '', progressChangedAt = Date.now();
    function renderActivity(detail) {
        if (!detail?.phase) { $('activity').hidden = true; return; }
        const failed = detail.failure;
        const component = failed?.component || (detail.state === 'running' ? detail.component : '');
        const count = detail.unit ? `${detail.count.toLocaleString()}${detail.total == null ? '' : ' of ' + detail.total.toLocaleString()} ${detail.unit} processed` : '';
        const stamp = `${detail.run}:${detail.updated}`;
        if (stamp !== progressStamp) { progressStamp = stamp; progressChangedAt = Date.now(); }
        const updated = detail.state === 'running' && Date.now() - progressChangedAt > 15000 ? 'No recent progress reported; the current operation may still be running.' : '';
        $('activity').textContent = [failed ? `Failed while ${failed.phase.toLowerCase()}` : detail.phase, component, count, detail.bytes ? `${bytes(detail.bytes)} streamed` : '', failed?.error, detail.poll_error, updated].filter(Boolean).join(' · ');
        $('activity').hidden = false;
    }
    async function transferRequest(op, data) {
        let stopped = false, timer;
        const poll = async () => {
            try {
                const detail = await api('progress', {id:data.id});
                if (!stopped) renderActivity(detail);
            } catch (_) {
                if (!stopped) { $('activity').hidden = false; $('activity').textContent = 'Live progress is unavailable. The transfer may still be running; waiting for its result.'; }
            }
            if (!stopped) timer = setTimeout(poll, 1000);
        };
        const action = transferAction();
        $('action-title').textContent = op === 'commit' ? (action === 'Push' ? 'Pushing…' : 'Pulling…') : op === 'rollback' ? 'Restoring destination…' : `Preparing ${action.toLowerCase()}…`;
        $('progress').hidden = false; $('progress').removeAttribute('value');
        $('activity').hidden = false; $('activity').textContent = 'Waiting for progress from the server…';
        timer = setTimeout(poll, 300);
        try {
            let result, retries = 0;
            do {
                try { result = await api(op, data); retries = 0; }
                catch (error) {
                    if (op !== 'transfer' || retries >= 3 || !/network|fetch|timed? ?out|connection|HTTP 50[234]|HTTP 429/i.test(error.message)) throw error;
                    message('Connection interrupted. Retrying…');
                    await new Promise(resolve => setTimeout(resolve, 1000 * ++retries));
                    result = {continue:true};
                }
                if (result.continue && result.progress) { summary = result; renderActivity(result.progress); }
            } while (op === 'transfer' && result.continue);
            return result;
        }
        catch (error) {
            stopped = true;
            try { summary = await api('status', {id:data.id}); showSummary(); renderActivity(await api('progress', {id:data.id})); }
            catch (_) { /* Preserve the original failure when the destination is unreachable. */ }
            if (summary?.status !== 'applied') $('action-title').textContent = summary?.error || summary?.progress?.failure ? (op === 'commit' ? `${action} failed` : op === 'rollback' ? 'Restore failed' : 'Preparation failed') : `Check ${action.toLowerCase()} status`;
            throw error;
        } finally { stopped = true; clearTimeout(timer); }
    }
    async function run(fn) {
        if (busy) return;
        busy = true; $('error').hidden = true;
        document.querySelector('.csm').setAttribute('aria-busy', 'true');
        feedback('Working… Keep this page open.');
        const controls = [...document.querySelectorAll('.csm button, .csm select, .csm input, .csm textarea')].filter(control => control.id !== 'csm-copy' && !control.matches('.csm-panel-disclosure') && !control.closest('#csm-diagnostics') && !control.closest('#csm-git-review'));
        const previous = controls.map(control => control.disabled);
        controls.forEach(control => { control.disabled = true; });
        try { await fn(); } catch (error) { fail(error); }
        finally { controls.forEach((control, i) => { control.disabled = previous[i]; }); busy = false; document.querySelector('.csm').setAttribute('aria-busy', 'false'); if ($('feedback').textContent === 'Working… Keep this page open.') feedback('Done.'); $('progress').hidden = true; lockSelection(); updateSelection(); }
    }
    function lockSelection() {
        document.querySelectorAll('#csm-remote-site, #csm-recent, #csm-direction, #csm-media-mode, #csm-media-verify, #csm-file-verify, #csm-since, #csm-media-after, #csm-media-before, #csm-replace, #csm-sync-active-plugins, input[data-kind], input[data-component], .csm-select-all').forEach(control => { control.disabled = !!session || control.dataset.protected === 'true' || (control.hasAttribute('data-component') && (busy || !sites)); });
        lockReplacements();
    }
    const direction = () => $('direction').value;
    const transferAction = () => (session?.direction || direction()) === 'push' ? 'Push' : 'Pull';
    function labelRoute() {
        const pull = (session?.direction || direction()) === 'pull';
        for (const [id, local] of [['source', !pull], ['destination', pull]]) {
            $(id + '-location').textContent = local ? 'This site' : 'Remote site';
            $(id + '-location').classList.toggle('csm-this-site', local);
        }
    }
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
            html += `<label class="csm-component"><span class="${item.active ? '' : 'csm-inactive'}"><input type="checkbox" data-kind="${kind}" data-protected="${!!item.protected}" aria-label="Transfer ${esc(item.name)}" value="${esc(item.id)}" ${disabled}>${icon(item.icon, kind)}<span><strong>${esc(item.name)}</strong><small>${esc(detail)}</small></span></span><span class="${item.active ? '' : 'csm-inactive'}"><span class="csm-cell-label">Source</span><b>${esc(item.version)}</b>${badge(item)}</span><span class="${different ? 'csm-different' : ''} ${target && !target.active ? 'csm-inactive' : ''}"><span class="csm-cell-label">Destination</span>${target ? `<b>${esc(target.version)}</b>${badge(target)}` : '<small>Not installed</small>'}</span></label>`;
        }
        $(kind).innerHTML = html + (!src[kind].length ? '<p>No components found on the source site.</p>' : '');
    }
    function renderTables() {
        const selected = new Set([...document.querySelectorAll('input[data-kind="tables"]:checked')].map(input => input.value));
        const src = source(), dst = destination();
        const lookup = new Map(dst.tables.map(item => [item.id.slice(dst.prefix.length), item]));
        const rows = src.tables.map(item => ({item, target:lookup.get(item.id.slice(src.prefix.length))}));
        const value = row => ['name', 'engine'].includes(tableSort.key) ? row.item[tableSort.key] : (tableSort.key.startsWith('source_') ? row.item : row.target)?.[tableSort.key.split('_')[1]];
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
        const columns = [['name', 'Table'], ['engine', 'Engine'], ['source_rows', 'Source rows'], ['source_bytes', 'Source size'], ['destination_rows', 'Destination rows'], ['destination_bytes', 'Destination size']];
        const headers = columns.map(([key, label]) => {
            const active = tableSort.key === key;
            const path = active ? (tableSort.descending ? 'm6 9 6 6 6-6' : 'm6 15 6-6 6 6') : 'm8 9 4-4 4 4m-8 6 4 4 4-4';
            return `<th scope="col" aria-sort="${active ? (tableSort.descending ? 'descending' : 'ascending') : 'none'}">${key === 'name' ? '<div class="csm-table-name-heading">' + selectAll('tables') : ''}<button type="button" class="csm-sort" data-sort="${key}">${label}<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="${path}"/></svg></button>${key === 'name' ? '</div>' : ''}</th>`;
        }).join('');
        $('tables').innerHTML = `<div class="csm-table-toolbar"><span class="csm-size-legend">Relative size on each site <span class="csm-size csm-size-small">Smaller</span><span class="csm-size csm-size-large">Larger</span></span></div><p class="csm-scroll-hint">Scroll horizontally to compare both sites.</p><div class="csm-table-scroll" role="region" aria-label="Database table comparison" tabindex="0"><table class="csm-data-table"><caption class="screen-reader-text">Database tables. Activate a column heading to sort; activate it again to reverse the order. Sizes include data and indexes.</caption><thead><tr>${headers}</tr></thead><tbody>${rows.map(({item, target}) => `<tr><th scope="row"><label><input type="checkbox" data-kind="tables" value="${esc(item.id)}" aria-label="Transfer ${esc(item.name)}" ${selected.has(item.id) ? 'checked' : ''} ${session ? 'disabled' : ''}><span><strong>${esc(item.name)}</strong></span></label></th><td class="csm-table-engine">${esc(item.engine)}</td><td>~${item.rows.toLocaleString()}</td><td>${pill(item, ranges[0], 'source')}</td><td>${target ? '~' + target.rows.toLocaleString() : '<span class="csm-missing">Not installed</span>'}</td><td>${pill(target, ranges[1], 'destination')}</td></tr>`).join('') || '<tr><td colspan="6">No database tables found on the source site.</td></tr>'}</tbody></table></div>`;
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
        document.querySelectorAll('input[data-component]').forEach(input => { input.disabled = busy || !!session || !sites; });
        for (const kind of componentKinds) $('disclose-' + kind).disabled = !sites || !$('enable-' + kind).checked;
        $('remote-site').disabled = busy || !!session || !connectionState.connections.length;
        $('recent').disabled = busy || !!session || !connectionState.recents.length;
        const mode = !sites && session?.selection ? session.selection.media : $('media-mode').value;
        const media = mode === 'compare' ? 'Changed or missing media files' : mode === 'all' ? 'All media files' : mode === 'changed' ? 'Changed media files' : mode === 'uploaded' ? 'Attachments by upload date' : mode === 'selected' ? `${counts.media_ids} attachment${counts.media_ids === 1 ? '' : 's'} selected` : 'No media files';
        $('media-summary').textContent = media;
        $('selection-summary').textContent = `${counts.tables} table${counts.tables === 1 ? '' : 's'} · ${counts.plugins} plugin${counts.plugins === 1 ? '' : 's'} · ${counts.themes} theme${counts.themes === 1 ? '' : 's'} · ${media.toLowerCase()}`;
        const selected = counts.plugins + counts.themes + counts.tables > 0 || (mode !== 'none' && (mode !== 'selected' || counts.media_ids > 0));
        const validMediaDates = mode !== 'uploaded' || (!!($('media-after').value || $('media-before').value) && (!$('media-after').value || !$('media-before').value || $('media-after').value <= $('media-before').value));
        $('stage').disabled = busy || !versionsMatch() || !transferVersionMatches() || (!session && (!sites || !selected || !validMediaDates || (counts.tables > 0 && $('replace').checked && !sites.replacements)));
        $('apply').disabled = busy || !versionsMatch() || !transferVersionMatches();
        renderVersionStatus();
        syncMediaSelection();
        for (const id of ['media-select-visible', 'media-clear', 'media-more']) $(id).disabled = busy || !!session;
        lockReplacements();
        $('apply').textContent = transferAction();
        if (!session) {
            $('stage').textContent = transferAction();
            $('action-title').textContent = selected ? `Ready to ${transferAction().toLowerCase()}` : 'Choose what to transfer';
        }
    }
    function transferVersionMatches() { return !session || (!!sites && session.plugin_version === sites.local.plugin_version); }
    function versionsMatch() { return !!sites && sites.local.plugin_version === sites.remote.plugin_version; }
    function renderVersionStatus() {
        $('trickle-versions').hidden = !sites;
        if (!sites) return;
        const same = versionsMatch(), local = sites.local.plugin_version, remote = sites.remote.plugin_version || 'unknown';
        $('trickle-versions').classList.toggle('csm-version-mismatch', !same || !transferVersionMatches());
        $('trickle-version-status').textContent = `Trickle — this site: ${local} · remote site: ${remote}${same ? ' · Versions match' : ' · Update required before transferring'}`;
        const supported = sites.remote.remote_update === 1;
        const mayUpdate = !!sites.remote.can_update_trickle;
        $('check-remote-update').hidden = same || !supported;
        $('check-remote-update').disabled = busy || !!session;
        $('update-remote').hidden = same || !remoteUpdate?.can_update_to_target;
        $('update-remote').disabled = busy || !!session;
        $('update-remote').textContent = `Update remote Trickle to ${local}`;
        $('trickle-update-status').textContent = !transferVersionMatches() ? 'This retained transfer was prepared with an older Trickle version. Discard and prepare again; rollback and recovery remain available.' : same ? 'Push and pull use the same Trickle version on both sites.' : session ? 'Versions changed during a retained transfer. Recovery remains available; discard and prepare again after aligning versions.' : !supported ? 'Install this Trickle build on the remote site once to enable remote updates.' : !mayUpdate ? 'Enable “Allow authenticated Trickle updates” on the remote site and use an administrator or read/write key.' : remoteUpdate ? remoteUpdate.reason : 'Check whether the remote JSON feed offers the version installed on this site. Newer remote versions require updating this site first.';
    }
    const transferDate = stamp => new Date(stamp * 1000).toLocaleString(undefined, {dateStyle:'medium', timeStyle:'short'});
    function renderConnections(state) {
        connectionState = state; session = state.session;
        $('remote-site').innerHTML = (state.selected ? '' : '<option value="" disabled>Choose a remote site…</option>') + state.connections.map(site => `<option value="${esc(site.id)}">${esc(site.name === site.url ? site.url : site.name + ' · ' + site.url)}</option>`).join('');
        $('remote-site').value = state.selected;
        const recent = $('recent').value;
        $('recent').innerHTML = '<option value="">Reuse settings from a recent transfer…</option>' + state.recents.map(item => `<option value="${esc(item.id)}">${esc(transferDate(item.started_at))} · ${item.direction === 'push' ? 'Push to' : 'Pull from'} ${esc(item.remote_name)} · ${esc(item.status)}</option>`).join('');
        $('recent').value = recent;
        $('recent-wrap').hidden = !state.recents.length;
        $('retained-note').hidden = !session;
        $('connection-summary').textContent = `${state.connections.length} saved site${state.connections.length === 1 ? '' : 's'}`;
        const selected = state.connections.find(site => site.id === state.selected);
        $('saved-connection').textContent = selected ? `Saved connection: ${selected.name}. Use it for both push and pull.` : 'Save a connection once to use it for both push and pull.';
        if (state.connections.length || session) $('workspace').hidden = false;
    }
    function clearInventory() {
        sites = null; remoteUpdate = null; resetComponents(); $('sync-active-plugins').checked = true;
        $('media-mode').value = 'none'; $('media-verify').checked = false; $('file-verify').checked = false; $('since').value = ''; $('media-after').value = ''; $('media-before').value = ''; $('replace').checked = true; $('recent').value = '';
        renderReplacements([]); renderMedia();
        for (const kind of ['plugins', 'themes', 'tables', 'media-list']) $(kind).replaceChildren();
        $('source').textContent = 'Loading source…'; $('destination').textContent = 'Loading destination…';
        $('version-note').textContent = 'Loading site inventories…';
        updateSelection();
    }
    function renderReplacements(rules) {
        replacementRules = rules.map(rule => ({...rule}));
        $('replacement-rules').innerHTML = (rules.length ? '<div class="csm-rule-columns" aria-hidden="true"><span>Use rule</span><span>Find</span><span>Replace with</span><span></span></div>' : '') + rules.map((rule, index) => {
            const custom = rule.kind === 'custom';
            const number = rules.slice(0, index + 1).filter(item => item.kind === 'custom').length;
            const label = custom ? `Custom replacement ${number}` : rule.label;
            const field = key => custom ? `<textarea rows="2" class="csm-replacement-control" data-rule-field="${key}" aria-label="${key === 'find' ? 'Find text' : 'Replacement text'} for ${esc(label.toLowerCase())}" spellcheck="false" autocomplete="off" placeholder="${key === 'find' ? 'Text to find' : 'Replacement (may be empty)'}">${esc(rule[key])}</textarea>` : `<code>${esc(rule[key])}</code>`;
            return `<div class="csm-rule-row" data-rule-index="${index}"><label class="csm-rule-check"><input type="checkbox" class="csm-replacement-control" data-rule-field="enabled" aria-label="Enable ${esc(label.toLowerCase())}" ${rule.enabled ? 'checked' : ''}><span>${esc(label)}</span></label><div><span class="csm-rule-cell-label">Find</span>${field('find')}</div><div><span class="csm-rule-cell-label">Replace with</span>${field('replace')}</div>${custom ? `<button type="button" class="button-link button-link-delete csm-replacement-control" data-remove-rule="${index}" aria-label="Remove ${esc(label.toLowerCase())}">Remove</button>` : '<span></span>'}</div>`;
        }).join('');
        lockReplacements();
    }
    function lockReplacements() {
        const available = !!sites?.replacements;
        document.querySelectorAll('.csm-replacement-control').forEach(control => { control.disabled = busy || !!session || !$('replace').checked || !available; });
        $('replacement-status').textContent = !available ? 'Load both sites with the current Trickle version to preview and configure replacements.' : !$('replace').checked ? 'Find & replace is off. No replacement rules will run.' : `${replacementRules.filter(rule => rule.enabled).length} enabled rules. Replacement settings are locked once a transfer is staged.`;
    }
    function currentSelection() {
        const selection = {enabled_components:componentKinds.filter(kind => $('enable-' + kind).checked), expanded_components:componentKinds.filter(kind => !$(kind + '-body').hidden), media:$('enable-media').checked ? $('media-mode').value : 'none', media_after:$('media-after').value, media_before:$('media-before').value, replace:$('replace').checked, sync_active_plugins:$('sync-active-plugins').checked, since:$('since').value ? Math.floor(new Date($('since').value + 'Z').getTime()/1000) : 0};
        selection.file_verify = ($('enable-plugins').checked || $('enable-themes').checked) && !!$('file-verify')?.checked;
        selection.media_verify = selection.media === 'compare' && !!$('media-verify')?.checked;
        for (const kind of ['plugins','themes','tables','media_ids']) selection[kind] = $('enable-' + (kind === 'media_ids' ? 'media' : kind)).checked ? [...document.querySelectorAll(`input[data-kind="${kind}"]:checked`)].map(input => kind === 'media_ids' ? Number(input.value) : input.value) : [];
        if (sites?.replacements) selection.replacements = replacementRules.map(rule => ({...rule}));
        return selection;
    }
    function restoreSelection(selection, strict = true) {
        if (strict) {
            const missing = [];
            for (const kind of ['plugins', 'themes', 'tables', 'media_ids']) {
                const available = new Set([...document.querySelectorAll(`input[data-kind="${kind}"]:not([data-protected="true"])`)].map(input => input.value));
                for (const id of selection[kind] || []) if (!available.has(String(id))) missing.push(String(id));
            }
            if (missing.length) throw new Error(`Cannot reuse these settings: ${missing.slice(0, 5).join(', ')} are absent from the current source inventory. Make a new selection instead.`);
        }
        document.querySelectorAll('input[data-kind]').forEach(input => { input.checked = (selection[input.dataset.kind] || []).map(String).includes(input.value); });
        for (const kind of componentKinds) {
            const hasItems = kind === 'media' ? selection.media !== 'none' : (selection[kind] || []).length > 0;
            setComponent(kind, hasItems || (selection.enabled_components || []).includes(kind));
            if (Array.isArray(selection.expanded_components)) setPanelOpen(kind, $('enable-' + kind).checked && selection.expanded_components.includes(kind));
        }
        $('file-verify').checked = !!selection.file_verify; $('sync-active-plugins').checked = selection.sync_active_plugins !== false;
        $('media-mode').value = selection.media; $('media-verify').checked = !!selection.media_verify; $('replace').checked = selection.replace;
        $('media-after').value = selection.media_after || ''; $('media-before').value = selection.media_before || '';
        renderReplacements(selection.replacements ?? sites.replacements?.[direction()] ?? []);
        renderMedia();
        $('since').value = selection.since ? new Date(selection.since * 1000).toISOString().slice(0,19) : '';
        if (selection.media === 'changed') $('watermark-note').textContent = 'Using the exact UTC starting date saved with this transfer.';
        updateSelection();
    }
    let mediaAnchor = null;
    function syncMediaSelection() {
        let count = 0;
        document.querySelectorAll('button[data-media-id]').forEach(button => {
            const checked = document.getElementById(`csm-media-select-${button.dataset.mediaId}`).checked;
            button.setAttribute('aria-pressed', String(checked)); button.disabled = busy || !!session;
            if (checked) count++;
        });
        $('media-selected-count').textContent = `${count} attachment${count === 1 ? '' : 's'} selected`;
    }
    function renderMediaGrid() {
        const selected = new Set([...document.querySelectorAll('input[data-kind="media_ids"]:checked')].map(input => input.value));
        const media = source().media;
        $('media-list').innerHTML = media.map(item => {
            item = {...item, id:Number(item.id)};
            if (!Number.isSafeInteger(item.id) || item.id < 1) return '';
            let preview = '';
            try { const url = new URL(item.preview); if (['http:', 'https:'].includes(url.protocol) && !url.username && !url.password) preview = `<img class="csm-media-preview" src="${esc(url.href)}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">`; } catch (_) { /* Missing artwork uses the file placeholder. */ }
            const date = item.uploaded ? new Date(item.uploaded) : null;
            const uploaded = date && !Number.isNaN(date.getTime()) ? date.toLocaleDateString(undefined, {year:'numeric',month:'short',day:'numeric',timeZone:'UTC'}) : 'Upload date unavailable';
            const name = item.name || item.file || `Attachment ${item.id}`;
            return `<div class="csm-media-tile"><button type="button" class="csm-media-choice" data-media-id="${item.id}" aria-label="${esc(name)}, uploaded ${esc(uploaded)}" aria-pressed="${selected.has(String(item.id))}" title="${esc(item.file || name)}"><span class="csm-media-image"><svg class="csm-media-placeholder" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 3h9l5 5v13H5zM14 3v6h5M8 14h8M8 17h6"/></svg>${preview}<span class="csm-media-selected-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6"/></svg></span></span><span class="csm-media-caption"><strong>${esc(name)}</strong><time${item.uploaded ? ` datetime="${esc(item.uploaded)}"` : ''}>${esc(uploaded)}</time></span></button><input type="checkbox" hidden data-kind="media_ids" id="csm-media-select-${item.id}" value="${item.id}" ${selected.has(String(item.id)) ? 'checked' : ''}></div>`;
        }).join('') || '<p>No attachments found on the source site.</p>';
        $('media-preview-note').textContent = source().media_browsing ? 'Newest uploads first · upload dates shown in UTC · previews load directly from the source' : 'Update Trickle on the source site to show thumbnails and upload dates and browse the full library.';
        $('media-page-status').textContent = `Showing ${media.length} of ${source().media_total} attachments`;
        $('media-more').hidden = !source().media_more || !source().media_browsing;
        $('media-mode').querySelector('option[value="uploaded"]').disabled = !source().media_dates;
        $('media-mode').querySelector('option[value="compare"]').disabled = !sites.remote.media_compare;
        syncMediaSelection();
    }
    function mergeMedia(items) {
        const media = new Map(source().media.map(item => [item.id, item]));
        for (const item of items) media.set(item.id, item);
        source().media = [...media.values()].sort((a,b) => (Date.parse(b.uploaded) || 0) - (Date.parse(a.uploaded) || 0) || b.id - a.id);
    }
    async function loadSelectedMedia(selection) {
        if (selection?.media === 'compare' && !sites.remote.media_compare) throw new Error('Update Trickle on both sites to compare media checksums.');
        if (selection?.media === 'uploaded' && !source().media_dates) throw new Error('Update Trickle on the source site to reuse upload-date transfers.');
        const missing = (selection?.media_ids || []).filter(id => !source().media.some(item => item.id === id));
        if (missing.length && source().media_browsing) {
            const result = await api('media', {direction:direction(), site:$('remote-site').value, ids:missing}); mergeMedia(result.media);
        }
    }
    function renderMedia() {
        const mode = $('media-mode').value;
        $('compare-wrap').hidden = mode !== 'compare';
        $('since-wrap').hidden = mode !== 'changed'; $('media-browser').hidden = mode !== 'selected'; $('uploaded-wrap').hidden = mode !== 'uploaded';
        if (mode === 'uploaded') $('uploaded-note').textContent = !$('media-after').value && !$('media-before').value ? 'Choose at least one upload date. Dates are UTC and inclusive; the range covers the entire source library, including original files and image sizes.' : $('media-after').value && $('media-before').value && $('media-after').value > $('media-before').value ? 'The end date must be on or after the start date.' : 'Includes originals and generated image sizes for all attachments uploaded in this date range. Dates are UTC and inclusive.';
        if (mode === 'changed' && sites) {
            const stamp = sites.history?.[`${direction()}:${sites.remote.url}`];
            $('since').value = stamp ? new Date(stamp * 1000).toISOString().slice(0,19) : '';
            $('watermark-note').textContent = stamp ? 'Uses the source server’s start time from the last applied media stream.' : 'No previous media transfer. Choose a UTC starting date or transfer all media first.';
        }
        updateSelection();
    }
    function render() {
        resetComponents(); $('media-mode').value = 'none';
        if (session) $('direction').value = session.direction;
        labelRoute();
        $('workspace').hidden = false;
        for (const [id, site] of [['source', source()], ['destination', destination()]]) {
            $(id).innerHTML = `${icon(site.icon, 'site')}<span class="csm-site-identity"><strong>${esc(site.url)}</strong>${site.name ? `<small>${esc(site.name)}</small>` : ''}</span>`;
        }
        $('version-note').textContent = `Source: WordPress ${source().wordpress}, PHP ${source().php}. Destination: WordPress ${destination().wordpress}, PHP ${destination().php}. ${source().wordpress !== destination().wordpress ? 'WordPress versions differ; core is not transferred. ' : ''}${direction() === 'push' && !sites.remote.incoming_writes ? 'Enable incoming writes on the remote site before pushing.' : ''}`;
        renderReplacements(sites.replacements?.[direction()] ?? []);
        $('tables').replaceChildren();
        for (const kind of ['plugins','themes','tables']) components(kind);
        mediaAnchor = null; renderMediaGrid();
        renderMedia();
        if (session?.selection) restoreSelection(session.selection, false);
        lockSelection(); updateSelection();
    }
    async function load(refresh = false, inventory = null, selection = null) {
        const preserve = selection || (refresh && sites && !session ? currentSelection() : null);
        if (session) {
            $('direction').value = session.direction;
            summary = await api('status', {id:session.id});
            labelRoute();
            $('workspace').hidden = false; $('source').textContent = session.source; $('destination').textContent = session.destination;
            if (['applied','restored'].includes(summary.status)) { const route = {...session}; await finishTransfer(summary, route); return; }
            if (summary.status === 'applying') { summary = await api('recover', {id:session.id}); const route = {...session}; await finishTransfer(summary, route); return; }
            showSummary();
        }
        feedback(refresh ? 'Checking installed versions and available updates on both sites…' : 'Connecting and loading the site inventories…');
        sites = inventory || await api('manifest', {refresh}); remoteUpdate = null;
        await loadSelectedMedia(session?.selection || preserve); render();
        if (preserve) restoreSelection(preserve);
        if (session && summary?.id !== session.id) { summary = await api('status', {id:session.id}); showSummary(); }
        $('connect-status').textContent = 'Paste a complete code to save or update the connection automatically.';
        feedback(refresh ? 'Versions refreshed on both sites.' : '');
        $('connection').open = false;
        if (session?.automatic) await migrate();
    }
    function showSummary() {
        if (!summary || !session) return;
        const action = transferAction();
        $('apply').hidden = true;
        $('rollback').hidden = summary.status !== 'applying';
        $('discard').hidden = summary.status !== 'staging';
        $('stage').hidden = summary.status !== 'staging';
        $('stage').textContent = `Continue ${action.toLowerCase()}`;
        $('action-title').textContent = summary.status === 'applied' ? `${action} complete` : summary.status === 'restored' ? 'Destination restored' : summary.status === 'applying' ? 'Recovering…' : `${action === 'Push' ? 'Pushing' : 'Pulling'}…`;
        message(`${summary.tables} tables · ${summary.files.toLocaleString()} files · ${bytes(summary.bytes)}`);
        $('media-comparison').hidden = true;
        $('skipped-folders').hidden = !summary.skipped_folders?.length;
        $('skipped-folders').textContent = summary.skipped_folders?.length ? 'Skipped: ' + summary.skipped_folders.join(', ') : '';
        renderActivity(summary.progress);
        renderPreview();
    }
    async function finishTransfer(result, route) {
        const finished = await api('finish', {id:route.id});
        summary = null; session = null; renderPreview();
        for (const id of ['apply','rollback','discard','retained-note','activity']) $(id).hidden = true;
        $('stage').hidden = false;
        $('completion').hidden = false;
        $('completion-title').textContent = result.status === 'applied' ? `${route.direction === 'push' ? 'Push' : 'Pull'} complete` : 'Destination restored';
        $('completion-detail').textContent = result.status === 'applied' ? (result.tables || result.files ? `${result.tables} tables · ${result.files.toLocaleString()} files · Backup saved` : 'Up to date · Backup saved') : 'Previous changes recovered.';
        $('view-site').href = route.view_url || route.destination;
        const backupUrl = new URL($('backup-link').href);
        if (route.direction === 'push') backupUrl.searchParams.set('site', route.remote_id);
        else backupUrl.searchParams.delete('site');
        $('backup-link').href = backupUrl.href;
        try { await load(true); }
        catch (_) { message(''); feedback('Complete. Reload to refresh the inventory.'); return; }
        message('');
        const warnings = [finished.cleanup_warning];
        if (result.health && !result.health.verified) warnings.push('Check the site; its health check was inconclusive.');
        if (result.activation_missing?.length) warnings.push(`Could not activate ${result.activation_missing.length} missing plugin${result.activation_missing.length === 1 ? '' : 's'}. See Diagnostics.`);
        feedback(warnings.filter(Boolean).join(' '));
    }
    let previewItems = [], previewId = '';
    function renderPreview() {
        const preview = summary?.preview;
        $('preview').hidden = !preview;
        if (!preview) { previewId = ''; previewItems = []; return; }
        if (previewId !== summary.id) { previewId = summary.id; previewItems = preview.items; }
        const c = preview.counts;
        $('preview-summary').textContent = `${c.add} added · ${c.replace} replaced · ${c.delete} deleted · ${c.unchanged} unchanged files`;
        $('preview-warnings').replaceChildren(...preview.warnings.map(text => { const p = document.createElement('p'); p.textContent = text; return p; }));
        $('preview-files').innerHTML = previewItems.map(item => `<tr><td>${esc({add:'Add', replace:'Replace', delete:'Delete'}[item.action])}</td><th scope="row"><code>${esc(item.path)}</code></th><td>${bytes(item.before)}</td><td>${bytes(item.after)}</td></tr>`).join('') || '<tr><td colspan="4">No file changes.</td></tr>';
        $('preview-count').textContent = `Showing ${previewItems.length} of ${preview.total} changes · deletions first · rollback files: ${bytes(preview.backup_bytes)}`;
        $('preview-more').hidden = previewItems.length >= preview.total;
    }
    $('preview-more').addEventListener('click', () => run(async () => {
        const page = await api('preview', {id:session.id, offset:previewItems.length});
        previewItems = [...previewItems, ...page.items]; renderPreview();
    }));
    function renderKeys(keys) {
        $('keys').innerHTML = keys.length ? '<ul class="csm-key-list">' + keys.map(key => `<li><span><strong>${esc(key.name)}</strong><small>${key.write ? 'Read and write' : 'Read only'} · expires ${esc(transferDate(key.expires))}</small></span><button type="button" class="button-link button-link-delete" data-revoke-key="${esc(key.id)}">Revoke</button></li>`).join('') + '</ul>' : '<p>No named connection keys.</p>';
    }
    $('issue-key').addEventListener('click', () => run(async () => {
        const key = await api('issue_key', {name:$('key-name').value, read:true, write:$('key-write').checked, days:Number($('key-days').value)});
        renderKeys(key.keys); $('new-code').value = key.pairing; $('new-key').hidden = false;
        $('new-code').focus(); $('new-code').select();
    }));
    $('copy-key').addEventListener('click', async () => {
        try { await navigator.clipboard.writeText($('new-code').value); $('copy-key').textContent = 'Code copied'; setTimeout(() => { $('copy-key').textContent = 'Copy new code'; }, 2000); }
        catch (_) { $('new-code').focus(); $('new-code').select(); }
    });
    $('keys').addEventListener('click', event => {
        const id = event.target.dataset.revokeKey; if (!id) return;
        run(async () => { if (!confirm('Revoke this connection key immediately? Transfers using it will need an administrator connection to recover.')) return; renderKeys((await api('revoke_key', {id})).keys); });
    });
    renderKeys(CSM.keys || []);
    let copyResetTimer;
    $('copy').addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText($('pairing').value);
            const button = $('copy'), label = button.querySelector('span');
            button.style.minWidth = `${button.offsetWidth}px`;
            label.textContent = 'Code copied';
            clearTimeout(copyResetTimer);
            copyResetTimer = setTimeout(() => { label.textContent = 'Copy connection code'; }, 2000);
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
    let savedPermissions = {pull:$('allow-read').checked, push:$('allow-write').checked, updates:$('allow-updates').checked};
    for (const id of ['allow-read', 'allow-write', 'allow-updates']) $(id).addEventListener('change', () => run(async () => {
        $('permissions-status').textContent = 'Saving permissions…';
        const settings = {pull:$('allow-read').checked, push:$('allow-write').checked, updates:$('allow-updates').checked};
        try {
            const result = await api('settings', settings);
            savedPermissions = settings; $('pairing').value = result.pairing;
            $('permissions-status').textContent = 'Permissions saved automatically.';
            feedback('Permissions saved.');
        } catch (error) {
            $('allow-read').checked = savedPermissions.pull; $('allow-write').checked = savedPermissions.push; $('allow-updates').checked = savedPermissions.updates;
            $('permissions-status').textContent = 'Save not confirmed. Previous values shown; reload to verify before retrying.';
            throw error;
        }
    }));
    $('rotate').addEventListener('click', () => run(async () => {
        if (!confirm('Rotate this site’s key? Existing connections to this site will need its new connection code.')) return;
        const result = await api('settings', {pull:$('allow-read').checked, push:$('allow-write').checked, updates:$('allow-updates').checked, rotate:true}); $('pairing').value = result.pairing; renderKeys([]); $('new-key').hidden = true; feedback('Connection key rotated. Update any sites that connect to this site.');
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
                const result = await api('connect', {pairing:code, name:$('remote-name').value});
                $('remote-code').value = ''; $('remote-name').value = '';
                $('connect-status').textContent = 'Connection saved. Its code has been cleared; choose the site in the transfer settings.';
                if (result.state.selected === result.saved) { if (!session) clearInventory(); await load(); }
                else feedback('Remote site saved. The retained transfer stays attached to its original site; select the new site after discarding that transfer.');
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
    $('check-remote-update').addEventListener('click', () => run(async () => {
        remoteUpdate = await api('remote_update_info', {site:$('remote-site').value}); renderVersionStatus();
    }));
    $('update-remote').addEventListener('click', () => run(async () => {
        if (!remoteUpdate?.can_update_to_target) return;
        if (!confirm(`Update Trickle on ${sites.remote.url} from ${sites.remote.plugin_version} to ${sites.local.plugin_version}? This updates the migration plugin before any transfer begins.`)) return;
        feedback('Updating remote Trickle through its configured JSON feed…');
        await api('update_remote', {site:$('remote-site').value});
        await load(true); feedback('Remote Trickle updated. Versions match; choose what to transfer.');
    }));
    $('refresh').addEventListener('click', () => run(() => load(true)));
    $('remote-site').addEventListener('change', () => run(async () => {
        const chosen = $('remote-site').value;
        try { renderConnections(await api('select', {site:chosen})); }
        catch (error) { renderConnections(connectionState); throw error; }
        clearInventory(); await load();
    }));
    $('recent').addEventListener('change', () => {
        const id = $('recent').value;
        if (!id) return;
        run(async () => {
            const profile = await api('recent', {id});
            renderConnections(await api('select', {site:profile.remote_id}));
            $('direction').value = profile.direction;
            clearInventory(); await load(false, null, profile.selection);
            $('recent').value = id;
            feedback(`Loaded the ${profile.direction} settings from ${transferDate(profile.started_at)}. Nothing has been staged or applied.`);
        });
    });
    $('direction').addEventListener('change', () => { if (sites) { $('media-mode').value = 'none'; $('replace').checked = true; $('recent').value = ''; render(); } });
    for (const id of ['file-verify', 'media-verify', 'sync-active-plugins']) $(id).addEventListener('change', () => { $('recent').value = ''; });
    $('media-mode').addEventListener('change', () => { mediaAnchor = null; renderMedia(); });
    for (const id of ['media-after', 'media-before']) $(id).addEventListener('change', () => { $('recent').value = ''; renderMedia(); });
    $('media-list').addEventListener('click', event => {
        const button = event.target.closest('button[data-media-id]');
        if (!button || busy || session) return;
        const buttons = [...document.querySelectorAll('button[data-media-id]')], index = buttons.indexOf(button);
        const anchor = buttons.findIndex(item => item.dataset.mediaId === mediaAnchor);
        const checked = !document.getElementById(`csm-media-select-${button.dataset.mediaId}`).checked;
        const first = event.shiftKey && anchor >= 0 ? Math.min(anchor, index) : index;
        const last = event.shiftKey && anchor >= 0 ? Math.max(anchor, index) : index;
        for (let i = first; i <= last; i++) document.getElementById(`csm-media-select-${buttons[i].dataset.mediaId}`).checked = checked;
        mediaAnchor = button.dataset.mediaId; $('recent').value = ''; updateSelection();
    });
    for (const [id, checked] of [['media-select-visible', true], ['media-clear', false]]) $(id).addEventListener('click', () => {
        if (busy || session) return;
        document.querySelectorAll('input[data-kind="media_ids"]').forEach(input => { input.checked = checked; });
        mediaAnchor = null; $('recent').value = ''; updateSelection();
    });
    $('media-more').addEventListener('click', () => run(async () => {
        const result = await api('media', {direction:direction(), site:$('remote-site').value, page:(source().media_page || 1) + 1});
        mergeMedia(result.media); source().media_page = result.media_page; source().media_more = result.media_more;
        renderMediaGrid(); updateSelection();
    }));
    $('themes').addEventListener('change', event => {
        const id = event.target.value, theme = source().themes.find(item => item.id === id);
        if (theme?.parent && event.target.checked) {
            const parent = [...document.querySelectorAll('input[data-kind="themes"]')].find(input => input.value === theme.parent);
            if (parent) parent.checked = true;
        }
    });
    $('workspace').addEventListener('change', event => {
        const checkbox = event.target;
        if (checkbox.matches('input[data-component]')) {
            if (busy || session) return;
            setComponent(checkbox.dataset.component, checkbox.checked, true);
            $('recent').value = '';
        }
        if (checkbox.matches('.csm-select-all')) {
            document.querySelectorAll(`input[data-kind="${checkbox.dataset.selectKind}"]:not(:disabled)`).forEach(input => { input.checked = checkbox.checked; });
        }
        updateSelection();
    });
    document.querySelectorAll('.csm-panel-disclosure').forEach(button => button.addEventListener('click', () => {
        const kind = button.dataset.disclose;
        setPanelOpen(kind, $(kind + '-body').hidden);
    }));
    $('tables').addEventListener('click', event => {
        const button = event.target.closest('.csm-sort');
        if (!button || busy) return;
        tableSort.descending = tableSort.key === button.dataset.sort ? !tableSort.descending : !['name', 'engine'].includes(button.dataset.sort);
        tableSort.key = button.dataset.sort;
        renderTables(); updateSelection();
        document.querySelector(`.csm-sort[data-sort="${tableSort.key}"]`).focus({preventScroll:true});
    });
    document.querySelector('.csm').addEventListener('error', event => {
        if (event.target.matches?.('.csm-icon-image, .csm-media-preview')) event.target.remove();
    }, true);
    $('replace').addEventListener('change', () => { $('recent').value = ''; lockReplacements(); updateSelection(); });
    $('add-replacement').addEventListener('click', () => {
        const rules = [...replacementRules, {kind:'custom', id:'', label:'', find:'', replace:'', enabled:true}];
        renderReplacements(rules); $('recent').value = '';
        document.querySelector(`[data-rule-index="${rules.length - 1}"] textarea[data-rule-field="find"]`).focus();
    });
    $('replacement-rules').addEventListener('input', event => {
        const field = event.target.dataset.ruleField;
        if (!field) return;
        const index = Number(event.target.closest('[data-rule-index]').dataset.ruleIndex);
        replacementRules[index][field] = field === 'enabled' ? event.target.checked : event.target.value;
        $('recent').value = ''; lockReplacements();
    });
    $('replacement-rules').addEventListener('click', event => {
        const remove = event.target.closest('[data-remove-rule]');
        if (!remove) return;
        const rules = [...replacementRules]; rules.splice(Number(remove.dataset.removeRule), 1);
        renderReplacements(rules); $('recent').value = ''; $('add-replacement').focus();
    });
    async function reviewGitFolders(folders) {
        const dialog = $('git-review');
        $('git-folders').innerHTML = folders.map(item => `<label class="csm-git-folder"><input type="checkbox" value="${esc(item.path)}"><span><code>${esc(item.path)}</code><small>${item.source && item.destination ? 'Git repository on both sites' : item.destination ? 'Git repository on the destination' : 'Git repository on the source'} · skipped unless explicitly included</small></span></label>`).join('');
        dialog.returnValue = 'cancel'; dialog.showModal();
        return new Promise(resolve => dialog.addEventListener('close', () => resolve(dialog.returnValue === 'prepare' ? [...$('git-folders').querySelectorAll('input:checked')].map(input => input.value) : null), {once:true}));
    }
    $('git-folders').addEventListener('change', event => {
        if (event.target.checked && !confirm(`Include ${event.target.value}? This can replace or delete actively developed destination code. Repository metadata, node_modules, .gitignore and environment files remain excluded.`)) event.target.checked = false;
    });
    async function migrate() {
        $('completion').hidden = true;
        try {
            if (!session) {
                previewId = ''; previewItems = [];
                const selection = currentSelection();
                const dest = destination().url;
                let git_confirmed = [];
                if (selection.plugins.length || selection.themes.length || selection.media !== 'none') {
                    const review = await api('review', {site:$('remote-site').value, direction:direction(), selection});
                    if (review.git_folders.length) {
                        git_confirmed = await reviewGitFolders(review.git_folders);
                        if (git_confirmed === null) return;
                    }
                }
                summary = await api('prepare', {site:$('remote-site').value, direction:direction(), selection, git_confirmed, automatic:true});
                session = {view_url:destination().home || dest, plugin_version:sites.local.plugin_version, id:summary.id, remote_id:$('remote-site').value, direction:direction(), source:source().url, destination:dest, selection, automatic:true};
            } else summary = await api('status', {id:session.id});
            const route = {...session};
            if (['applied','restored'].includes(summary.status)) { await finishTransfer(summary, route); return; }
            if (summary.status === 'applying') {
                summary = await api('recover', {id:session.id});
                await finishTransfer(summary, route); return;
            }
            showSummary();
            for (const kind of summary.pending) {
                message(`Verifying ${kind}…`);
                summary = await transferRequest('transfer', {id:session.id, kind}); showSummary();
            }
            message(`${transferAction() === 'Push' ? 'Pushing' : 'Pulling'}…`);
            summary = await transferRequest('commit', {id:session.id});
            await finishTransfer(summary, route);
        } catch (error) {
            if (session) {
                const route = {...session};
                try {
                    const recovered = await api('recover', {id:session.id, cancel:true});
                    if (['applied','restored'].includes(recovered.status)) await finishTransfer(recovered, route);
                    else { session = null; summary = null; await load(); message('Destination unchanged.'); }
                } catch (_) { message('Recovery will retry when this page reconnects.'); }
            }
            throw error;
        }
    }
    $('stage').addEventListener('click', () => run(migrate));
    $('apply').addEventListener('click', () => run(migrate));
    $('rollback').addEventListener('click', () => run(migrate));
    $('discard').addEventListener('click', () => run(async () => {
        await api('recover', {id:session.id, cancel:true});
        session = null; summary = null; renderPreview();
        for (const id of ['apply','rollback','discard','activity']) $(id).hidden = true;
        $('stage').hidden = false; await load(); message('Transfer cancelled. Destination unchanged.');
    }));
    $('diagnostics').addEventListener('toggle', () => { if ($('diagnostics').open) refreshLogs(); });
    $('refresh-logs').addEventListener('click', refreshLogs);
    $('log-entries').addEventListener('click', event => {
        const button = event.target.closest('[data-log-detail]');
        if (!button) return;
        const detail = document.getElementById(button.getAttribute('aria-controls'));
        detail.hidden = !detail.hidden;
        button.setAttribute('aria-expanded', String(!detail.hidden));
        button.textContent = detail.hidden ? 'View details' : 'Hide details';
    });
    $('download-logs').addEventListener('click', () => {
        const file = new Blob([JSON.stringify({site:location.origin, downloaded_at:new Date().toISOString(), entries:logEntries}, null, 2)], {type:'application/json'});
        const url = URL.createObjectURL(file), link = document.createElement('a');
        link.href = url; link.download = `trickle-diagnostics-${new Date().toISOString().slice(0,10)}.json`;
        document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    renderConnections(connectionState); updateSelection();
    if (CSM.state.destination_recovery) run(async () => {
        feedback('Recovering…'); await api('recover_destination');
        if (connectionState.selected || session) await load();
        else feedback('Destination restored.');
    });
    else if (connectionState.selected || session) run(() => load());
    setInterval(() => { if (!busy && session?.automatic && versionsMatch() && transferVersionMatches()) run(migrate); }, 15000);
    let checkingProgress = false;
    setInterval(async () => {
        if (busy || checkingProgress || !session || summary?.progress?.state !== 'running') return;
        checkingProgress = true;
        const id = session.id;
        try {
            const detail = await api('progress', {id});
            if (!busy && session?.id === id) {
                renderActivity(detail);
                if (detail.state !== 'running') {
                    const next = await api('status', {id});
                    if (!busy && session?.id === id) { summary = next; showSummary(); }
                }
            }
        } catch (_) { /* Keep the last known activity until the next read succeeds. */ }
        finally { checkingProgress = false; }
    }, 1500);
})();
