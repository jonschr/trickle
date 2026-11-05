/* global CSM, CSMTransferProgress, CSMRequest */
(() => {
    'use strict';
    const $ = id => document.getElementById(`csm-${id}`);
    let connectionState = CSM.state, sites = null, session = CSM.state.session, busy = false, summary = null, replacementRules = [], remoteUpdate = null;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const bytes = n => n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KiB` : n < 1073741824 ? `${(n / 1048576).toFixed(1)} MiB` : `${(n / 1073741824).toFixed(1)} GiB`;
    const transferMeter = new CSMTransferProgress($('transfer-meter'));
    let transferView = false, diagnosticRoute = null;
    const actionHome = $('stage').parentElement;
    const tableSort = {key:'source_bytes', descending:true};
    const componentKinds = ['tables', 'plugins', 'themes', 'media'];
    function setPanelOpen(kind, open) {
        $(kind + '-body').hidden = !open;
        const button = $('disclose-' + kind);
        button.setAttribute('aria-expanded', String(open));
        if (open) showComponent(kind); else syncComponentView();
    }
    function showComponent(kind) {
        const workbench = $('component-workbench');
        if (!workbench) return;
        workbench.dataset.current = kind; syncComponentView();
    }
    function syncComponentView() {
        const workbench = $('component-workbench');
        if (!workbench) return;
        const kind = workbench.dataset.current || 'tables';
        const labels = {tables:'database', plugins:'plugins', themes:'themes', media:'media library'};
        const open = !$(kind + '-body').hidden;
        workbench.querySelectorAll('[data-component-panel]').forEach(panel => {
            const key = panel.dataset.componentPanel, current = key === kind;
            panel.toggleAttribute('data-current', current);
            const button = $('disclose-' + key);
            button.setAttribute('aria-expanded', String(current && !$(key + '-body').hidden));
            button.setAttribute('aria-label', `View ${labels[key]} selection`);
            if (current) button.setAttribute('aria-current', 'true'); else button.removeAttribute('aria-current');
        });
        $('selection-placeholder').hidden = open;
        $('placeholder-title').textContent = `${$('enable-' + kind).checked ? 'Selection hidden' : 'Include ' + labels[kind]}`;
        $('include-current').textContent = $('enable-' + kind).checked ? 'Show selection' : 'Include ' + labels[kind];
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
    let cancellation = 0, cancelling = false;
    const activeRequests=new Set();
    function cancelled() { return Object.assign(new Error("Transfer cancelled."), {cancelled:true}); }
    async function api(op, data = {}, generation = null) {
        if (generation===null && data.id && !['cancel','recover','rollback'].includes(op)) generation=cancellation;
        const controller = new AbortController();
        if (generation !== null) activeRequests.add(controller);
        let result;
        try { result = await CSMRequest(op, data, controller); }
        catch (error) { if (generation !== null && generation !== cancellation) throw cancelled(); throw error; }
        finally { activeRequests.delete(controller); }
        if (generation !== null && generation !== cancellation) throw cancelled();
        if (result?.nonce) CSM.nonce = result.nonce;
        if (Array.isArray(result?.state?.connections)) renderConnections(result.state);
        return result;
    }
    function message(text) { $('status').textContent = text; }
    function fail(error) {
        transferMeter.fail?.(error);
        $('error').innerHTML = `<p>${esc(error.message)}</p>`;
        const copy = document.createElement('button'); copy.type = 'button'; copy.className = 'button-link'; copy.textContent = 'Copy error details';
        copy.addEventListener('click', async () => { try { await navigator.clipboard.writeText(error.message); copy.textContent = 'Copied'; } catch (_) { copy.textContent = 'Select the error text to copy'; } });
        $('error').append(copy);
        $('error').hidden = false;
        $('error').scrollIntoView({block:'nearest'});
        feedback('');
        if ($('diagnostics').open) refreshLogs();
    }
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
    function diagnosticDetails() {
        const route = session || diagnosticRoute || transferMeter.timeline || {};
        const remote = sites?.remote || {};
        return {transfer_id:route.id ?? transferMeter.timeline?.id ?? '', direction:route.direction || direction(),
            status:summary?.status || route.status || transferMeter.phase, source:route.source || (sites ? source()?.url : '') || '',
            destination:route.destination || (sites ? destination()?.url : '') || '', transport:session?.selection?.transport || $('transport').value,
            phase:transferMeter.detail?.phase || transferMeter.phase,
            elapsed_seconds:transferMeter.started ? Math.max(0, Math.round(((transferMeter.frozen || Date.now())-transferMeter.started)/1000)) : 0,
            error:$('error').hidden ? '' : ($('error').querySelector('p')?.textContent || ''),
            notice:$('feedback').hidden ? '' : $('feedback').textContent,
            remote_version:remote.plugin_version || '', remote_wordpress:remote.wordpress || '', remote_php:remote.php || ''};
    }
    async function copyDiagnostics() {
        const button = $('copy-diagnostics'), output = $('diagnostic-output');
        button.disabled = true; button.textContent = 'Copying…'; output.hidden = true;
        const details = diagnosticDetails();
        const report = api('diagnostics', {details}).catch(() => ({schema_version:1, transfer_id:details.transfer_id,
            direction:details.direction, status:details.status, diagnostics_unavailable:true}));
        const text = report.then(data => JSON.stringify(data, null, 2));
        try {
            if (navigator.clipboard?.write && typeof ClipboardItem !== 'undefined') {
                await navigator.clipboard.write([new ClipboardItem({'text/plain':text.then(value => new Blob([value], {type:'text/plain'}))})]);
            } else { await navigator.clipboard.writeText(await text); }
            button.textContent = (await report).diagnostics_unavailable ? 'Partial diagnostics copied' : 'Diagnostics copied';
        } catch (_) {
            output.value = await text; output.hidden = false; output.focus(); output.select();
            button.textContent = 'Select diagnostics';
        } finally { button.disabled = false; }
    }
    let progressStamp = '', progressChangedAt = Date.now();
    function renderActivity(detail) {
        transferMeter.update(detail);
        if (transferMeter.timeline || !detail?.phase) { $('activity').hidden = true; return; }
        const failed = detail.failure;
        const component = failed?.component || (detail.state === 'running' ? detail.component : '');
        const count = detail.unit && detail.unit !== 'bytes' ? `${detail.count.toLocaleString()}${detail.total == null ? '' : ' of ' + detail.total.toLocaleString()} ${detail.unit} processed` : '';
        const stamp = `${detail.run}:${detail.updated}`;
        if (stamp !== progressStamp) { progressStamp = stamp; progressChangedAt = Date.now(); }
        const updated = detail.state === 'running' && Date.now() - progressChangedAt > 15000 ? 'No recent progress reported; the current operation may still be running.' : '';
        $('activity').textContent = [failed ? `Failed while ${failed.phase.toLowerCase()}` : detail.phase, component, count, detail.bytes && detail.unit !== 'bytes' ? `${bytes(detail.bytes)} streamed` : '', failed?.error, detail.poll_error, updated].filter(Boolean).join(' · ');
        $('activity').hidden = false;
    }
    async function transferRequest(op, data) {
        let stopped = false, timer, lastLogRead = 0;
        const generation = cancellation;
        const poll = async () => {
            try {
                const detail = await api('progress', {id:data.id});
                if (!stopped) renderActivity(detail);
                if (!stopped && Date.now() - lastLogRead > 5000) {
                    lastLogRead = Date.now();
                    api('logs').then(logs => { if (!stopped) transferMeter.ingestLogs?.(logs.entries); }).catch(() => {});
                }
            } catch (_) {
                if (!stopped) { $('activity').hidden = false; $('activity').textContent = 'Live progress is unavailable. The transfer may still be running; waiting for its result.'; }
            }
            if (!stopped) timer = setTimeout(poll, 1000);
        };
        const action = transferAction();
        transferMeter.operation?.(op, data.kind);
        $('action-title').textContent = ['commit','transfer'].includes(op) ? (action === 'Push' ? 'Pushing…' : 'Pulling…') : op === 'rollback' ? 'Restoring destination…' : `Preparing ${action.toLowerCase()}…`;
        $('activity').hidden = false; $('activity').textContent = 'Waiting for progress from the server…';
        timer = setTimeout(poll, 300);
        try {
            let result, retries = 0;
            do {
                try { if (generation !== cancellation) throw cancelled(); result = await api(op, data, generation); if (generation !== cancellation) throw cancelled(); retries = 0; transferMeter.resolved?.(); }
                catch (error) {
                    if (error.cancelled || generation !== cancellation) throw cancelled();
                    if (error.retryExhausted || !['preflight','transfer'].includes(op) || retries >= 3 || !error.retryable) throw error;
                    const retry=await api('retry_state',{id:data.id,failed:true});
                    if (retry.stopped) { session.automatic=false; throw new Error('No progress after repeated retries. Transfer stopped; cancelling.'); }
                    message('Connection interrupted. Retrying…');
                    const wait = Math.max(error.retryAfter || 0, 2 ** retries++);
                    transferMeter.retry?.(error.message, wait);
                    await new Promise(resolve => setTimeout(resolve, 1000 * wait));
                    result = {continue:true};
                }
                if (result.progress) renderActivity({...result.progress, ...(Array.isArray(result.pending) ? {pending:result.pending} : {}), ...(result.timeline ? {timeline:result.timeline} : {})});
                else transferMeter.update(result);
                if (result.continue) summary = result;
            } while (result.continue);
            if (generation !== cancellation) throw cancelled();
            api('logs').then(logs => transferMeter.ingestLogs?.(logs.entries)).catch(() => {});
            return result;
        }
        catch (error) {
            stopped = true;
            if (error.cancelled || generation !== cancellation) throw cancelled();
            if (error.busy) { error.pending = true; throw error; }
            try { summary = await api('status', {id:data.id},generation); if (generation!==cancellation) throw cancelled(); showSummary(); renderActivity(await api('progress', {id:data.id},generation)); }
            catch (_) { /* Preserve the original failure when the destination is unreachable. */ }
            if (summary?.status !== 'applied') $('action-title').textContent = summary?.error || summary?.progress?.failure ? (op === 'commit' ? `${action} failed` : op === 'rollback' ? 'Restore failed' : 'Preparation failed') : `Check ${action.toLowerCase()} status`;
            throw error;
        } finally { stopped = true; clearTimeout(timer); }
    }
    async function run(fn) {
        if (busy || cancelling) return;
        busy = true; $('error').hidden = true;
        $('transfer-setup').setAttribute('aria-busy', 'true');
        feedback('');
        const controls = [...document.querySelectorAll('.csm button, .csm select, .csm input, .csm textarea')].filter(control => !['csm-copy','csm-discard','csm-close-transfer','csm-close-connection','csm-copy-diagnostics','csm-diagnostic-output'].includes(control.id) && !control.matches('.csm-panel-disclosure') && !control.closest('#csm-transfer-meter') && !control.closest('#csm-diagnostics') && !control.closest('#csm-git-review'));
        const previous = controls.map(control => control.disabled);
        controls.forEach(control => { control.disabled = true; });
        try { await fn(); } catch (error) { fail(error); }
        finally { controls.forEach((control, i) => { control.disabled = previous[i]; }); busy = false; $('transfer-setup').setAttribute('aria-busy', 'false'); lockSelection(); updateSelection(); if (transferMeter.phase === 'complete' && !$('completion').hidden) $('action-title').textContent = $('completion-title').textContent; }
    }
    function lockSelection() {
        document.querySelectorAll('#csm-transport, #csm-remote-site, #csm-recent, #csm-direction, #csm-media-mode, #csm-media-verify, #csm-media-force, #csm-file-verify, #csm-plugins-force, #csm-themes-force, #csm-since, #csm-media-after, #csm-media-before, #csm-replace, #csm-ignore-active-plugins, input[data-kind], input[data-component], .csm-select-all').forEach(control => { control.disabled = !!session || control.dataset.protected === 'true' || (control.hasAttribute('data-component') && (busy || !sites)); });
        lockReplacements();
    }
    const direction = () => $('direction').value;
    const transferAction = () => (session?.direction || direction()) === 'push' ? 'Push' : 'Pull';
    function labelRoute() {
        const pull = (session?.direction || direction()) === 'pull';
        const flow = document.querySelector('.csm-route-flow');
        flow.prepend($(pull ? 'destination' : 'source').closest('.csm-endpoint'));
        flow.append($(pull ? 'source' : 'destination').closest('.csm-endpoint'));
        flow.querySelector('.csm-route-arrow path').setAttribute('d', pull ? 'M19 12H5m6-6-6 6 6 6' : 'M5 12h14m-6-6 6 6-6 6');
        $('direction-label').textContent=pull?'Pull':'Push';
        $('flip-direction').title=pull?'Pull from remote. Click to switch to push.':'Push to remote. Click to switch to pull.';
        $('flip-direction').setAttribute('aria-label',$('flip-direction').title);
        for (const [id, local] of [['source', !pull], ['destination', pull]]) {
            $(id + '-location').textContent = local ? 'This site' : 'Remote site';
            $(id + '-location').classList.toggle('csm-this-site', local);
        }
    }
    const source = () => direction() === 'pull' ? sites.remote : sites.local;
    const destination = () => direction() === 'pull' ? sites.local : sites.remote;
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
    function componentState(kind, item, inventory) {
        if(!item)return '';
        const parent=kind==='themes' && inventory.some(theme=>theme.active && theme.parent===item.id);
        return parent?'Active parent':item.active?'Active':'Inactive';
    }
    function versionChange(item, target) {
        if(item.protected)return {kind:'protected',label:'Protected'};
        if(!target)return {kind:'install',label:'New install'};
        if(item.version && (item.version===target.version || item.version_order===0))return {kind:'same',label:'Same version'};
        if(item.version_order>0)return {kind:'upgrade',label:'Upgrade'};
        if(item.version_order<0)return {kind:'downgrade',label:'Downgrade'};
        return {kind:'different',label:'Different'};
    }
    function components(kind) {
        if (kind === 'tables') { renderTables(); return; }
        const src=source(), dst=destination(), lookup=new Map(dst[kind].map(item=>[item.id,item]));
        const signs={upgrade:'M12 19V5m-5 5 5-5 5 5',downgrade:'M12 5v14m-5-5 5 5 5-5',same:'M5 12h14',install:'M12 5v14m-7-7h14',different:'M9 9a3 3 0 0 1 6 0c0 2-3 2-3 4m0 4h.01',protected:'M7 10V7a5 5 0 0 1 10 0v3M5 10h14v11H5z'};
        const glyph=(path,cls='')=>`<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${path}"/></svg>`;
        let html=`<div class="csm-code-scroll" tabindex="0" role="region" aria-label="${kind === 'plugins' ? 'Plugin' : 'Theme'} comparison"><div class="csm-code-table"><div class="csm-columns csm-code-columns">${selectAll(kind)}<span class="csm-version-headings"><span>Installed</span><span class="screen-reader-text">Version change</span><span>Incoming</span></span></div>`;
        for(const item of src[kind]){
            const target=lookup.get(item.id), change=versionChange(item,target), state=componentState(kind,item,src[kind]);
            const sizeCell=side=>item.protected?'':`<span class="csm-component-size" data-component-size="${kind}" data-size-id="${esc(item.id)}" data-size-site="${side}"></span>`;
            const cell=(plugin,inventory,side)=>{
                const activity=componentState(kind,plugin,inventory), label=side==='source'?'Incoming from source':'Installed on destination';
                const update=plugin?.update, both=update && item.update===target?.update;
                const updateBadge=update?`<span class="csm-update" title="${esc('Update available: '+update+(both?' on both sites':' · '+label))}">${glyph('M12 17V7m-4 4 4-4 4 4','csm-update-arrow')}<span class="screen-reader-text">Update available: </span>${esc(update)}</span>`:'';
                const mark=activity==='Active parent'?'<circle cx="6" cy="6" r="4.5"/><circle cx="6" cy="6" r="1.5" fill="currentColor"/>':`<circle cx="6" cy="6" r="4"${activity==='Inactive'?'':' fill="currentColor"'}/>`;
                return `<span class="csm-version-endpoint"><span class="screen-reader-text">${label}: </span>${plugin?`<span class="csm-activation ${activity==='Inactive'?'is-inactive':'is-active'}" role="img" aria-label="${esc(activity)}" title="${esc(activity+' · '+label)}"><svg viewBox="0 0 12 12" fill="none" stroke="currentColor" aria-hidden="true">${mark}</svg></span>`:''}<b title="${esc(plugin?.version || (plugin?'Unknown version':'Not installed'))}">${esc(plugin?.version || (plugin?'?':'—'))}</b>${plugin?sizeCell(side):''}${updateBadge}</span>`;
            };
            const outcome=`<span class="csm-version-indicator" role="img" aria-label="${esc(change.label)}" title="${esc(change.label)}">${glyph(signs[change.kind])}</span>`;
            html+=`<label class="csm-component csm-code-component" data-change="${change.kind}" data-source-active="${state!=='Inactive'}"><span class="csm-component-identity ${state==='Inactive'?'csm-inactive':''}"><input type="checkbox" data-kind="${kind}" data-protected="${!!item.protected}" aria-label="Transfer ${esc(item.name)}" value="${esc(item.id)}" ${item.protected?'disabled':''}>${icon(item.icon,kind)}<span><strong title="${esc(item.name+(item.protected?' · Managed separately':''))}">${esc(item.name)}</strong></span></span><span class="csm-version-comparison"><span class="csm-version-change">${cell(target,dst[kind],'destination')}${outcome}${cell(item,src[kind],'source')}</span></span></label>`;
        }
        $(kind).innerHTML=html+(!src[kind].length?'<p>No components found on the source site.</p>':'')+'</div></div>';
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
        $('tables').innerHTML = `<p class="csm-scroll-hint">Scroll horizontally to compare both sites.</p><div class="csm-table-scroll" role="region" aria-label="Database table comparison" tabindex="0"><table class="csm-data-table"><caption class="screen-reader-text">Database tables. Activate a column heading to sort; activate it again to reverse the order. Sizes include data and indexes.</caption><thead><tr>${headers}</tr></thead><tbody>${rows.map(({item, target}) => `<tr><th scope="row"><label><input type="checkbox" data-kind="tables" value="${esc(item.id)}" aria-label="Transfer ${esc(item.name)}" ${selected.has(item.id) ? 'checked' : ''} ${session ? 'disabled' : ''}><span><strong>${esc(item.name)}</strong></span></label></th><td class="csm-table-engine">${esc(item.engine)}</td><td>~${item.rows.toLocaleString()}</td><td>${pill(item, ranges[0], 'source')}</td><td>${target ? '~' + target.rows.toLocaleString() : '<span class="csm-missing">Not installed</span>'}</td><td>${pill(target, ranges[1], 'destination')}</td></tr>`).join('') || '<tr><td colspan="6">No database tables found on the source site.</td></tr>'}</tbody></table></div>`;
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
        for (const kind of componentKinds) $('disclose-' + kind).disabled = !sites;
        $('remote-site').disabled = busy || !!session || !connectionState.connections.length;
        $('recent').disabled = busy || !!session || !connectionState.recents.length;
        const mode = !sites && session?.selection ? session.selection.media : $('media-mode').value;
        const media = ['all','compare'].includes(mode) ? ((sites ? $('media-force').checked : session?.selection?.media_force) ? 'All media files · forced' : 'All media files') : mode === 'changed' ? 'Changed media files' : mode === 'uploaded' ? 'Attachments by upload date' : mode === 'selected' ? `${counts.media_ids} attachment${counts.media_ids === 1 ? '' : 's'} selected` : 'No media files';
        $('media-summary').textContent = media;
        $('selection-summary').textContent = `${counts.tables} table${counts.tables === 1 ? '' : 's'} · ${counts.plugins} plugin${counts.plugins === 1 ? '' : 's'} · ${counts.themes} theme${counts.themes === 1 ? '' : 's'} · ${media.toLowerCase()}`;
        const selected = counts.plugins + counts.themes + counts.tables > 0 || (mode !== 'none' && (mode !== 'selected' || counts.media_ids > 0));
        const validMediaDates = mode !== 'uploaded' || (!!($('media-after').value || $('media-before').value) && (!$('media-after').value || !$('media-before').value || $('media-after').value <= $('media-before').value));
        $('stage').disabled = busy || cancelling || (session?.retryAt > Date.now()) || !versionsMatch() || !transferVersionMatches() || (!session && (!sites || !selected || !validMediaDates || (counts.tables > 0 && $('replace').checked && !sites.replacements)));
        $('apply').disabled = busy || cancelling || !versionsMatch() || !transferVersionMatches();
        renderVersionStatus();
        syncMediaSelection();
        updateSetup();
        renderComponentSizes();
        $('include-current').disabled = !sites || busy || !!session;
        for (const kind of ['tables','plugins','themes']) filterComponents(kind);
        for (const id of ['media-select-visible', 'media-clear', 'media-more']) $(id).disabled = busy || !!session;
        lockReplacements();
        $('apply').textContent = transferAction();
        if (!session) {
            $('stage').textContent = destinationAction();
            if ($('completion').hidden && !transferView) $('action-title').textContent = destinationAction();
        }
    }
    function destinationAction() {
        if ((session?.direction || direction()) === 'pull') return 'Pull into this site';
        const peer = connectionState.connections.find(item => item.id === $('remote-site').value);
        return `Push to ${peer?.name || 'remote site'}`;
    }
    function updateSetup() {
        $('action-destination').textContent = session?.destination || (sites ? destination().url : '');
        $('preset').disabled = busy || !!session || !sites;
        $('selection-workspace').hidden=!sites && !session;
        $('transfer-shortcuts').hidden=!sites && !connectionState.recents?.length;
        $('flip-direction').disabled=busy || !!session || !sites;
        const menu=$('site-menu');if(busy || session)menu.open=false;
        $('site-menu-toggle').setAttribute('aria-disabled',String(busy || !!session));
        for(const button of document.querySelectorAll('[data-select-site], [data-add-site], #csm-update-connection'))button.disabled=busy || !!session || button.hasAttribute('data-select-site') && button.dataset.selectSite===connectionState.selected;
        $('refresh').disabled=busy || !!session || !connectionState.selected;
        $('stage').hidden = busy && (!!session || transferView) || !session && !$('completion').hidden;
        syncTransferDialog();
    }
    function syncTransferDialog() {
        const dialog = $('transfer-dialog');
        const active = transferView || !!session || !$('completion').hidden;
        $('close-transfer').hidden = busy || !!session;
        $('completion-elapsed').hidden = $('transfer-meter').hidden || !$('completion-elapsed').textContent;
        if (active && !dialog.open) {
            $('transfer-notices').append($('error'), $('feedback'));
            $('transfer-controls').prepend($('stage'));
            dialog.showModal();
            document.body.classList.add('csm-transfer-open');
            $('action-title').focus({preventScroll:true});
        } else if (!active && dialog.open) {
            $('app-notices').append($('error'), $('feedback'));
            actionHome.prepend($('stage'));
            dialog.close();
            document.body.classList.remove('csm-transfer-open');
            ($('stage').disabled ? $('enable-tables') : $('stage')).focus({preventScroll:true});
        }
    }
    function closeTransferView() {
        if (busy || session) return;
        transferView = false;
        $('completion').hidden = true; $('transfer-meter').hidden = true;
        transferMeter.clear?.(); updateSelection();
    }
    function containTransferFocus(event) {
        if (event.key !== 'Tab') return;
        const dialog = $('transfer-dialog');
        const controls = [...dialog.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])')].filter(node => node.getClientRects().length && !node.closest('[hidden]'));
        const target = event.shiftKey ? controls.at(-1) : controls[0];
        if (!controls.length) { event.preventDefault(); $('action-title').focus(); }
        else if (event.shiftKey && (document.activeElement === controls[0] || !controls.includes(document.activeElement)) || !event.shiftKey && document.activeElement === controls.at(-1)) { event.preventDefault(); target.focus(); }
    }
    function filterComponents(kind) {
        const search = $('search-' + kind), filter = $('filter-' + kind);
        if (!search || !filter || !sites) return;
        const query = search.value.trim().toLocaleLowerCase(), mode = filter.value;
        const lookup = new Map(destination()[kind].map(item => [kind === 'tables' ? item.id.slice(destination().prefix.length) : item.id, item]));
        const inventory = new Map(source()[kind].map(item => [item.id, item]));
        let total = 0, shown = 0;
        $(kind).querySelectorAll(`input[data-kind="${kind}"]`).forEach(input => {
            const item = inventory.get(input.value), target = lookup.get(kind === 'tables' ? input.value.slice(source().prefix.length) : input.value);
            const row = input.closest(kind === 'tables' ? 'tr' : '.csm-component');
            if (!item || !row) return;
            const matches = `${item.name} ${item.id}`.toLocaleLowerCase().includes(query) && (mode === 'all' || mode === 'selected' && input.checked || mode === 'updates' && !!(item.update || target?.update) || mode === 'different' && (!target || item.version !== target.version && item.version_order !== 0));
            row.hidden = !matches; total++; if (matches) shown++;
        });
        $('filter-count-' + kind).textContent = `${shown} of ${total}`;
        $('filter-empty-' + kind).hidden = shown > 0 || total === 0;
    }
    async function applyPreset(name) {
        if (!name || session) return;
        const preset = await api('preset', {preset:name, site:$('remote-site').value});
        $('direction').value = preset.direction; render();
        const enabled = ['tables','plugins','themes'].filter(kind => preset.selection[kind]?.length);
        restoreSelection({...currentSelection(), ...preset.selection, file_verify:$('file-verify').checked, enabled_components:enabled, expanded_components:enabled});
        $('preset').value = name;
        $('preset-status').textContent = 'Preset loaded. Selections remain editable.'; feedback('');
    }
    function renderComponentSizes() {
        const estimate=sites?source().estimates:null;
        for (const kind of componentKinds) {
            const size = kind === 'tables' ? (sites ? source().tables.reduce((sum,table)=>sum+table.bytes,0) : null) : estimate?.bytes[kind];
            const complete = kind === 'tables' ? !!sites : estimate?.complete;
            const cell = $(kind + '-source-size');
            cell.textContent = size != null && (complete || size > 0) ? `${complete ? '~' : '≥ '}${(size / 1048576).toLocaleString(undefined,{minimumFractionDigits:1,maximumFractionDigits:1})} MiB` : 'Estimating…';
            cell.title = size != null ? `${kind === 'tables' ? 'Database' : kind} on the source · ${bytes(size)} before compression and matching-file skips` : 'Source size not yet available';
        }
        renderSizeDetails();
        $('summary-size-note').textContent=estimate?.refreshing?'Refreshing source sizes…':estimate?.complete?'Source sizes before compression. Matching files are skipped unless forced.':'Estimating source sizes…';
    }
    function estimateSize(estimate, size) {
        return estimate && (estimate.complete || size>0) ? `${estimate.complete?'~':'≥ '}${bytes(size || 0)}` : 'Estimating…';
    }
    function renderSizeDetails() {
        if (!sites) return;
        document.querySelectorAll('[data-component-size]').forEach(cell=>{
            const site=cell.dataset.sizeSite==='source'?source():destination(), kind=cell.dataset.componentSize;
            cell.textContent=estimateSize(site.estimates,site.estimates?.items?.[kind]?.[cell.dataset.sizeId]);
        });
        const panel=$('media-sizes'), list=$('media-year-sizes');
        if (!panel || !list) return;
        const estimate=source().estimates, years=Object.entries(estimate?.items?.media || {}).sort(([a],[b])=>a==='_other'?1:b==='_other'?-1:b.localeCompare(a));
        panel.hidden=!estimate;
        list.innerHTML=years.map(([year,size])=>`<div><dt>${year==='_other'?'Other files':esc(year)}</dt><dd>${esc(estimateSize(estimate,size))}</dd></div>`).join('') || `<div><dt>${estimate?.complete?'No media files':'Estimating…'}</dt><dd></dd></div>`;
    }
    let estimateTimer=null, estimateGeneration=0;
    async function loadEstimates(generation=++estimateGeneration) {
        if (!sites || session || generation!==estimateGeneration) return;
        const site=$('remote-site').value;
        try {
            const result=await api('estimates',{site});
            if (session || generation!==estimateGeneration || site!==$('remote-site').value) return;
            for (const key of ['local','remote']) { sites[key].estimates=result[key]; }
            renderComponentSizes();
            if (!result.local.complete || result.local.refreshing || !result.remote.complete || result.remote.refreshing) estimateTimer=setTimeout(()=>loadEstimates(generation),750);
        } catch (_) { if (!session && generation===estimateGeneration) $('summary-size-note').textContent='Size estimate unavailable. Try Refresh versions.'; }
    }
    function transferVersionMatches() { return !session || (!!sites && session.plugin_version === sites.local.plugin_version); }
    function versionsMatch() { return !!sites && sites.local.plugin_version === sites.remote.plugin_version && (!sites.remote.remote_build_update || sites.local.trickle_build === sites.remote.trickle_build); }
    function renderEndpoints() {
        const peer=connectionState.connections.find(site=>site.id===connectionState.selected), pull=(session?.direction || direction())==='pull';
        const local=sites?.local || CSM.local, remote=sites?.remote || peer;
        labelRoute();
        for(const [id,site] of [['source',pull?remote:local],['destination',pull?local:remote]]){
            const card=$(id).closest('.csm-endpoint'), isRemote=id===(pull?'source':'destination');
            card.classList.toggle('csm-empty-endpoint',!site);card.classList.toggle('csm-remote-endpoint',isRemote);
            if(!site){$(id).innerHTML=`<button type="button" class="button" data-add-site>Add ${pull?'source':'destination'}</button>`;continue;}
            const versions=[['Trickle',site.plugin_version],['WordPress',site.wordpress],['PHP',site.php]].filter(([,value])=>value);
            $(id).innerHTML=`${icon(site.icon,'site')}<span class="csm-site-identity"><strong>${esc(site.url)}</strong>${site.name?`<small>${esc(site.name)}</small>`:''}${versions.length?`<span class="csm-site-versions">${versions.map(([label,version])=>`<span>${label} <b>${esc(version)}</b></span>`).join('')}</span>`:''}</span>`;
        }
        $(pull?'source':'destination').closest('.csm-endpoint').append($('site-menu'));
        $('site-menu').hidden=!connectionState.connections.length;
        $('update-connection').hidden=!peer;
    }
    function renderVersionStatus() {
        $('trickle-versions').hidden = !sites;
        $('install-local-build').hidden = !sites;
        $('install-local-build').textContent = sites?.remote.remote_build_update ? 'Install local Trickle build' : 'Install the new ZIP once to enable build sync';
        $('install-local-build').disabled = busy || !!session || !sites?.remote.remote_build_update || !sites?.remote.can_update_trickle || sites?.remote.file_modifications===false;
        if (!sites) return;
        renderEndpoints();
        const same = versionsMatch(), local = sites.local.plugin_version;
        $('trickle-versions').hidden = same && transferVersionMatches();
        $('trickle-versions').classList.toggle('csm-version-mismatch', !same || !transferVersionMatches());
        $('trickle-version-status').textContent = same ? '' : 'Trickle update required before transferring';
        if (sites.remote.remote_build_update) {
            const mayUpdate=!!sites.remote.can_update_trickle;
            $('check-remote-update').hidden=true;
            $('update-remote').hidden=same||!mayUpdate;
            $('update-remote').disabled=busy||!!session||sites.remote.file_modifications===false;
            $('update-remote').textContent='Install local Trickle build';
            $('trickle-version-status').textContent=same?'':'Trickle builds differ';
            $('trickle-update-status').textContent=session?'Finish the transfer before changing Trickle.':sites.remote.file_modifications===false?'WordPress disallows file modifications on the remote site.':!mayUpdate?'Use an administrator or read/write key to update Trickle.':'Copy this site’s installed Trickle files to the remote site.';
            return;
        }
        const supported = sites.remote.remote_update === 1;
        const mayUpdate = !!sites.remote.can_update_trickle;
        $('check-remote-update').hidden = same || !supported;
        $('check-remote-update').disabled = busy || !!session;
        $('update-remote').hidden = same || !remoteUpdate?.can_update_to_target;
        $('update-remote').disabled = busy || !!session;
        $('update-remote').textContent = `Update remote Trickle to ${local}`;
        $('trickle-update-status').textContent = !transferVersionMatches() ? 'This retained transfer was prepared with an older Trickle version. Discard and prepare again; rollback and recovery remain available.' : same ? 'Push and pull use the same Trickle version on both sites.' : session ? 'Versions changed during a retained transfer. Recovery remains available; discard and prepare again after aligning versions.' : !supported ? 'Install this Trickle build on the remote site once to enable remote updates.' : !mayUpdate ? 'Use an administrator or read/write key to update Trickle.' : remoteUpdate ? remoteUpdate.reason : 'Check whether the remote JSON feed offers the version installed on this site. Newer remote versions require updating this site first.';
    }
    const transferDate = stamp => new Date(stamp * 1000).toLocaleString(undefined, {dateStyle:'medium', timeStyle:'short'});
    function renderConnections(state) {
        connectionState = state; session = state.session;
        if(state.selected!==$('remote-site').value && !session)sites=null;
        $('remote-site').value = state.selected;
        const recent = $('recent').value;
        $('recent').innerHTML = '<option value="">Reuse a recent transfer…</option>' + state.recents.map(item => `<option value="${esc(item.id)}">${esc(transferDate(item.started_at))} · ${item.direction === 'push' ? 'Push to' : 'Pull from'} ${esc(item.remote_name)} · ${esc(item.status)}</option>`).join('');
        $('recent').value = recent;
        $('recent-wrap').hidden = !state.recents.length;
        $('retained-note').hidden = !session;
        $('saved-sites').innerHTML=state.connections.map(site=>`<button type="button" data-select-site="${esc(site.id)}" aria-pressed="${site.id===state.selected}"><strong>${esc(site.name)}</strong><small>${esc(site.url)}</small></button>`).join('');
        $('workspace').hidden=false;renderEndpoints();
    }
    function clearInventory() {
        sites = null; remoteUpdate = null; resetComponents(); $('ignore-active-plugins').checked = false;
        $('media-mode').value = 'none'; $('media-verify').checked = false; $('media-force').checked = false; $('file-verify').checked = false; for (const kind of ['plugins','themes']) if ($(kind + '-force')) $(kind + '-force').checked = false; $('since').value = ''; $('media-after').value = ''; $('media-before').value = ''; $('replace').checked = true; $('recent').value = '';
        renderReplacements([]); renderMedia();
        for (const kind of ['plugins', 'themes', 'tables', 'media-list']) $(kind).replaceChildren();
        renderEndpoints();
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
        $('replacement-status').textContent = !available ? 'Load matching Trickle builds to edit replacements.' : !$('replace').checked ? 'Find & replace is off.' : `${replacementRules.filter(rule => rule.enabled).length} rules enabled.`;
    }
    function currentSelection() {
        const selection = {transport:$('transport').value, enabled_components:componentKinds.filter(kind => $('enable-' + kind).checked), expanded_components:componentKinds.filter(kind => !$(kind + '-body').hidden), media:$('enable-media').checked ? $('media-mode').value : 'none', media_after:$('media-after').value, media_before:$('media-before').value, replace:$('replace').checked, sync_active_plugins:!$('ignore-active-plugins').checked, since:$('since').value ? Math.floor(new Date($('since').value + 'Z').getTime()/1000) : 0};
        for (const kind of ['plugins','themes']) selection[kind + '_force'] = $('enable-' + kind).checked && !!$(kind + '-force')?.checked;
        selection.file_verify = ($('enable-plugins').checked || $('enable-themes').checked) && !!$('file-verify')?.checked;
        selection.media_force = selection.media === 'all' && !!$('media-force')?.checked;
        selection.media_verify = selection.media === 'all' && !selection.media_force && !!$('media-verify')?.checked;
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
        showComponent(componentKinds.find(kind => !$(kind + '-body').hidden) || 'tables');
        for (const kind of ['plugins','themes']) if ($(kind + '-force')) $(kind + '-force').checked = !!(selection[kind + '_force'] ?? selection.file_force);
        $('transport').value = selection.transport || 'stream'; $('file-verify').checked = !!selection.file_verify; $('ignore-active-plugins').checked = selection.sync_active_plugins === false;
        $('media-mode').value = selection.media === 'compare' ? 'all' : selection.media; $('media-force').checked = !!selection.media_force; $('media-verify').checked = !!selection.media_verify; $('replace').checked = selection.replace;
        $('media-after').value = selection.media_after || ''; $('media-before').value = selection.media_before || '';
        renderReplacements(selection.replacements ?? sites.replacements?.[direction()] ?? []);
        renderMedia();
        $('since').value = selection.since ? new Date(selection.since * 1000).toISOString().slice(0,19) : '';
        if (selection.media === 'changed') $('watermark-note').textContent = 'Saved start date (UTC).';
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
        $('media-preview-note').textContent = source().media_browsing ? 'Newest first · dates in UTC' : 'Update source Trickle for thumbnails, upload dates and full-library browsing.';
        $('media-page-status').textContent = `Showing ${media.length} of ${source().media_total} attachments`;
        $('media-more').hidden = !source().media_more || !source().media_browsing;
        $('media-mode').querySelector('option[value="uploaded"]').disabled = !source().media_dates;

        syncMediaSelection();
    }
    function mergeMedia(items) {
        const media = new Map(source().media.map(item => [item.id, item]));
        for (const item of items) media.set(item.id, item);
        source().media = [...media.values()].sort((a,b) => (Date.parse(b.uploaded) || 0) - (Date.parse(a.uploaded) || 0) || b.id - a.id);
    }
    async function loadSelectedMedia(selection) {
        if (['all','compare'].includes(selection?.media) && !selection.media_force && !sites.remote.media_compare) throw new Error('Update Trickle on both sites to compare media checksums.');
        if (selection?.media === 'uploaded' && !source().media_dates) throw new Error('Update Trickle on the source site to reuse upload-date transfers.');
        const missing = (selection?.media_ids || []).filter(id => !source().media.some(item => item.id === id));
        if (missing.length && source().media_browsing) {
            const result = await api('media', {direction:direction(), site:$('remote-site').value, ids:missing}); mergeMedia(result.media);
        }
    }
    function renderMedia() {
        const mode = $('media-mode').value;
        $('media-force-wrap').hidden = mode !== 'all';
        $('compare-wrap').hidden = mode !== 'all' || $('media-force').checked;
        $('since-wrap').hidden = mode !== 'changed'; $('media-browser').hidden = mode !== 'selected'; $('uploaded-wrap').hidden = mode !== 'uploaded';
        if (mode === 'uploaded') $('uploaded-note').textContent = !$('media-after').value && !$('media-before').value ? 'Choose at least one date (UTC, inclusive). Includes originals and image sizes.' : $('media-after').value && $('media-before').value && $('media-after').value > $('media-before').value ? 'The end date must be on or after the start date.' : 'Dates are inclusive (UTC). Includes originals and image sizes.';
        if (mode === 'changed' && sites) {
            const stamp = sites.history?.[`${direction()}:${sites.remote.url}`];
            $('since').value = stamp ? new Date(stamp * 1000).toISOString().slice(0,19) : '';
            $('watermark-note').textContent = stamp ? 'Source start time of the last completed media transfer.' : 'Choose a UTC starting date.';
        }
        updateSelection();
    }
    function render() {
        $('preset').value = ''; $('preset-status').textContent = 'Selections stay editable.';
        resetComponents(); $('media-mode').value = 'none'; $('media-force').checked = false;
        if (session) $('direction').value = session.direction;
        labelRoute();
        $('workspace').hidden = false;
        renderEndpoints();
        $('version-note').textContent = [source().wordpress !== destination().wordpress ? 'WordPress versions differ; core is not transferred.' : '', direction() === 'push' && !sites.remote.incoming_writes ? 'Enable incoming writes on the remote site before pushing.' : ''].filter(Boolean).join(' ');
        $('version-note').hidden = !$('version-note').textContent;
        renderReplacements(sites.replacements?.[direction()] ?? []);
        $('tables').replaceChildren();
        for (const kind of ['plugins','themes','tables']) components(kind);
        mediaAnchor = null; renderMediaGrid();
        renderMedia();
        if (session?.selection) restoreSelection(session.selection, false);
        lockSelection(); updateSelection();
        clearTimeout(estimateTimer); if (!session && sites.local.bounded_work===2 && sites.remote.bounded_work===2 && (!sites.local.estimates?.complete || sites.local.estimates?.refreshing || !sites.remote.estimates?.complete || sites.remote.estimates?.refreshing)) loadEstimates();
    }
    async function load(refresh = false, inventory = null, selection = null) {
        const preserve = selection || (refresh && sites && !session ? currentSelection() : null);
        if (session?.cancel_requested) { await cancelTransfer(); return; }
        if (session) {
            $('direction').value = session.direction;
            summary = await api('status', {id:session.id});
            labelRoute();
            $('workspace').hidden = false; $('source').textContent = session.source; $('destination').textContent = session.destination;
            if (['applied','restored'].includes(summary.status)) { const route = {...session}; await finishTransfer(summary, route); return; }
            if (['applying','restoring'].includes(summary.status)) { summary = await transferRequest('recover', {id:session.id}); const route = {...session}; await finishTransfer(summary, route); return; }
            showSummary();
        }
        feedback(refresh ? 'Checking installed versions and available updates on both sites…' : 'Connecting and estimating site sizes…');
        sites = inventory || await api('manifest', {refresh}); remoteUpdate = null;
        await loadSelectedMedia(session?.selection || preserve); render();
        if (preserve) restoreSelection(preserve);
        if (session && summary?.id !== session.id) { summary = await api('status', {id:session.id}); showSummary(); }
        $('connect-status').textContent = 'Paste a code to connect.';
        feedback(refresh ? 'Versions refreshed on both sites.' : '');

        if (session?.automatic) await migrate();
    }
    function showSummary() {
        if (!summary || !session) return;
        const action = transferAction();
        $('apply').hidden = true;
        $('rollback').hidden = summary.status !== 'applying';
        $('discard').hidden = !['staging','applying','restoring','backup_copy'].includes(summary.status);
        $('stage').hidden = summary.status !== 'staging';
        $('stage').textContent = `Continue ${destinationAction().toLowerCase()}`;
        $('action-title').textContent = summary.status === 'applied' ? `${action} complete` : summary.status === 'restored' ? 'Destination restored' : summary.status === 'applying' ? 'Recovering…' : `${action === 'Push' ? 'Pushing' : 'Pulling'}…`;
        message(`${summary.tables} tables · ${summary.files.toLocaleString()} files · ${bytes(summary.bytes)}`);
        $('media-comparison').hidden = true;
        $('skipped-folders').hidden = !summary.skipped_folders?.length;
        $('skipped-folders').textContent = summary.skipped_folders?.length ? 'Skipped: ' + summary.skipped_folders.join(', ') : '';
        renderActivity(summary.progress);
        renderPreview(); updateSetup();
    }
    async function finishTransfer(result, route) {
        diagnosticRoute = {...route, status:result.status};
        $('error').hidden = true;
        const finished = await api('finish', {id:route.id});
        transferMeter.finish(result.status, result);
        summary = null; session = null; renderPreview();
        for (const id of ['apply','rollback','discard','retained-note','activity']) $(id).hidden = true;
        $('stage').hidden = false;
        $('completion').hidden = false;
        $('completion-title').textContent = result.status === 'applied' ? `${route.direction === 'push' ? 'Push' : 'Pull'} complete` : 'Destination restored';
        $('view-site').href = route.view_url || route.destination;
        const backupUrl = new URL($('backup-link').href);
        if (route.direction === 'push') backupUrl.searchParams.set('site', route.remote_id);
        else backupUrl.searchParams.delete('site');
        if (result.status === 'applied') backupUrl.searchParams.set('backup', route.id);
        else backupUrl.searchParams.delete('backup');
        $('backup-link').textContent = result.status === 'applied' ? 'View backup' : 'Backups';
        $('backup-link').href = backupUrl.href;
        try { await load(true); }
        catch (_) { message(''); feedback('Complete. Reload to refresh the inventory.'); return; }
        message('');
        $('action-title').textContent = $('completion-title').textContent;
        const warnings = [finished.cleanup_warning];
        if (result.activation_missing?.length) warnings.push(`Not activated — missing plugin files: ${result.activation_missing.join(', ')}.`);
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
            document.querySelector('[data-csm-tab="settings"]').click();
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
            $('permissions-status').textContent = 'Saved.';
            feedback('');
        } catch (error) {
            $('allow-read').checked = savedPermissions.pull; $('allow-write').checked = savedPermissions.push;
            $('permissions-status').textContent = 'Save not confirmed. Previous values shown; reload to verify before retrying.';
            throw error;
        }
    }));
    $('rotate').addEventListener('click', () => run(async () => {
        if (!confirm('Regenerate this site’s connection key? Existing connections will stop working until you give them the new code.')) return;
        const result = await api('settings', {pull:$('allow-read').checked, push:$('allow-write').checked, rotate:true}); $('pairing').value = result.pairing; feedback('Connection key regenerated. Copy the new code to reconnect your other sites.');
    }));
    let connectTimer, connectionTrigger;
    function openConnection(update=false) {
        if(busy || session)return;
        connectionTrigger=document.activeElement;$('site-menu').open=false;
        const peer=update?connectionState.connections.find(site=>site.id===connectionState.selected):null;
        $('add-site-title').textContent=update?'Update connection':'Add a site';
        $('remote-name').value=peer?.name || '';$('remote-code').value='';
        $('connect-status').textContent='Paste a code to connect.';$('connect-status').dataset.tone='';
        $('connection-dialog').showModal();$('remote-code').focus();
    }
    async function selectSite(id) {
        if(busy || session)return;
        $('site-menu').open=false;
        await run(async()=>{
            try{renderConnections(await api('select',{site:id}));}
            catch(error){renderConnections(connectionState);throw error;}
            clearInventory();await load();$('site-menu-toggle').focus();
        });
    }
    $('site-menu-toggle').addEventListener('click',event=>{if(busy || session)event.preventDefault();});
    document.querySelector('.csm-route').addEventListener('click',event=>{
        if(event.target.closest('[data-add-site]'))openConnection();
        const site=event.target.closest('[data-select-site]');if(site)selectSite(site.dataset.selectSite);
    });
    document.addEventListener('click',event=>{if(!$('site-menu').contains(event.target))$('site-menu').open=false;});
    $('site-menu').addEventListener('keydown',event=>{if(event.key==='Escape'){$('site-menu').open=false;$('site-menu-toggle').focus();event.preventDefault();}});
    $('update-connection').addEventListener('click',()=>openConnection(true));
    $('close-connection').addEventListener('click',()=>$('connection-dialog').close());
    $('connection-dialog').addEventListener('cancel',()=>{clearTimeout(connectTimer);$('remote-code').value='';});
    $('connection-dialog').addEventListener('close',()=>{
        clearTimeout(connectTimer);$('remote-code').value='';
        if(connectionTrigger?.isConnected && connectionTrigger.getClientRects().length)connectionTrigger.focus();else if(connectionState.selected)$('site-menu-toggle').focus();
    });
    function connectFromCode() {
        if(!$('connection-dialog').open || session)return;
        const code=$('remote-code').value.trim();
        if(!code){$('connect-status').textContent='Paste a code to connect.';return;}
        let complete=/^https?:\/\/\S+\s+[a-f0-9]{64}$/.test(code);
        if(!complete){
            try{const peer=JSON.parse(atob(code));complete=typeof peer?.url==='string'&&/^https?:\/\//.test(peer.url)&&typeof peer.key==='string'&&/^[a-f0-9]{64}$/.test(peer.key);}
            catch(_){/* Incomplete input stays local. */}
        }
        if(!complete){$('connect-status').textContent='Include the site URL and its full private key.';return;}
        if(busy){connectTimer=setTimeout(connectFromCode,200);return;}
        run(async()=>{
            $('connect-status').textContent='Connecting…';
            try{
                const result=await api('connect',{pairing:code,name:$('remote-name').value});
                $('remote-code').value='';$('remote-name').value='';
                renderConnections(result.state);clearInventory();await load();
                $('connection-dialog').close();feedback('');
            }catch(error){
                if(!$('connection-dialog').open)throw error;
                $('connect-status').textContent=error.message;$('connect-status').dataset.tone='error';
            }
        });
    }
    $('remote-code').addEventListener('input',()=>{
        clearTimeout(connectTimer);$('connect-status').dataset.tone='';
        $('connect-status').textContent=$('remote-code').value.trim()?'Checking code…':'Paste a code to connect.';
        connectTimer=setTimeout(connectFromCode,600);
    });
    $('check-remote-update').addEventListener('click', () => run(async () => {
        remoteUpdate = await api('remote_update_info', {site:$('remote-site').value}); renderVersionStatus();
    }));
    async function installLocalBuild() {
        if (!sites?.remote.remote_build_update || !sites.remote.can_update_trickle || session) return;
        $('site-menu').open=false;feedback('Installing local Trickle build…');
        await api('update_remote',{site:$('remote-site').value});
        await load();feedback('Remote Trickle matches this site.');
    }
    $('install-local-build').addEventListener('click',()=>run(installLocalBuild));
    $('update-remote').addEventListener('click', () => run(async () => {
        if (sites?.remote.remote_build_update) { await installLocalBuild(); return; }
        if (!remoteUpdate?.can_update_to_target) return;
        if (!confirm(`Update Trickle on ${sites.remote.url} from ${sites.remote.plugin_version} to ${sites.local.plugin_version}? This updates the migration plugin before any transfer begins.`)) return;
        feedback('Updating remote Trickle through its configured JSON feed…');
        await api('update_remote', {site:$('remote-site').value});
        await load(true); feedback('Remote Trickle updated. Versions match; choose what to transfer.');
    }));
    $('preset').addEventListener('change', () => run(() => applyPreset($('preset').value)));
    document.querySelectorAll('[data-search-kind]').forEach(input => input.addEventListener('input', () => filterComponents(input.dataset.searchKind)));
    document.querySelectorAll('[data-filter-kind]').forEach(input => input.addEventListener('change', () => filterComponents(input.dataset.filterKind)));
    $('refresh').addEventListener('click', () => { $('site-menu').open=false;run(() => load(true)); });
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
    $('flip-direction').addEventListener('click',()=>{
        if(busy || session || !sites)return;
        $('direction').value=direction()==='pull'?'push':'pull';$('direction').dispatchEvent(new Event('change'));
    });
    $('direction').addEventListener('change', () => { if (sites) { $('media-mode').value = 'none'; $('replace').checked = true; $('recent').value = ''; render(); } });
    let savedTransferSettings = {transport:$('transport').value, file_verify:$('file-verify').checked};
    for (const id of ['transport', 'file-verify']) $(id).addEventListener('change', () => run(async () => {
        $('transfer-settings-status').textContent = 'Saving…';
        try {
            savedTransferSettings = await api('transfer_settings', {transport:$('transport').value, file_verify:$('file-verify').checked});
            $('transfer-settings-status').textContent = 'Saved.';
        } catch (error) {
            $('transport').value = savedTransferSettings.transport; $('file-verify').checked = savedTransferSettings.file_verify;
            $('transfer-settings-status').textContent = 'Save failed. Previous preferences restored.';
            throw error;
        }
    }));
    for (const id of ['transport', 'file-verify', 'media-verify', 'ignore-active-plugins']) $(id).addEventListener('change', () => { $('recent').value = ''; });
    $('media-mode').addEventListener('change', () => { mediaAnchor = null; renderMedia(); });
    for (const kind of ['plugins','themes']) $(kind + '-force')?.addEventListener('change', () => { $('recent').value = ''; updateSelection(); });
    $('media-force').addEventListener('change', () => { $('recent').value = ''; renderMedia(); });
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
        if (!session && !$('completion').hidden) { $('completion').hidden = true; $('transfer-meter').hidden = true; }
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
        setPanelOpen(button.dataset.disclose, true);
    }));
    $('include-current').addEventListener('click', () => {
        if (busy || session || !sites) return;
        const kind = $('component-workbench').dataset.current;
        if ($('enable-' + kind).checked) setPanelOpen(kind, true); else setComponent(kind, true, true);
        $('recent').value = ''; updateSelection();
    });
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
        if(!session)transferMeter.clear?.();
        $('completion').hidden = true;
        if (!session) {
            diagnosticRoute = {id:'', direction:direction(), source:source()?.url || '', destination:destination()?.url || '', status:'preparing'};
            $('copy-diagnostics').textContent = 'Copy diagnostics'; $('diagnostic-output').hidden = true;
        }
        transferView = true; $('action-title').textContent = destinationAction();
        message('Connecting…'); updateSetup();
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
                        if (git_confirmed === null) { transferView = false; updateSetup(); return; }
                    }
                }
                summary = await api('prepare', {site:$('remote-site').value, direction:direction(), selection, git_confirmed, automatic:true});
                session = {view_url:destination().home || dest, plugin_version:sites.local.plugin_version, id:summary.id, remote_id:$('remote-site').value, direction:direction(), source:source().url, destination:dest, selection, automatic:true};
            } else summary = await api('status', {id:session.id});
            const route = {...session};
            transferMeter.begin(summary.timeline || {id:session.id,elapsed:0,components:{},streams:{},selected:[],ready:false});
            transferMeter.update(summary);
            if (['applied','restored'].includes(summary.status)) { await finishTransfer(summary, route); return; }
            if (['applying','restoring'].includes(summary.status)) {
                transferMeter.setPhase('recover');
                summary = await transferRequest('recover', {id:session.id});
                await finishTransfer(summary, route); return;
            }
            showSummary();
            const pending = [...summary.pending];
            for (const kind of pending) {
                message('');
                summary = await transferRequest('preflight', {id:session.id,kind});
            }
            for (const kind of pending) {
                message('');
                summary = await transferRequest('transfer', {id:session.id, kind}); showSummary();
            }
            message('');
            summary = await transferRequest('commit', {id:session.id});
            await finishTransfer(summary, route);
        } catch (error) {
            if (error.cancelled) return;
            if (error.retryExhausted && session) { const retry=await api('retry_state',{id:session.id,failed:true}); if (retry.stopped) { await cancelTransfer(); throw new Error('Transfer stopped after repeated retries without progress.'); } const wait = Math.max(15, error.retryAfter || 0); session.retryAt = Date.now() + 1000 * wait; transferMeter.retry?.(error.message, wait); message('Connection interrupted. Transfer saved; retrying after a pause…'); return; }
            if (error.pending || error.busy) { transferMeter.retry?.(error.message); message('Transfer is still running. Reconnecting…'); return; }
            if (session) {
                const route = {...session}; session.automatic=false;
                try {
                    await api('cancel',{id:route.id});
                    const recovered = await transferRequest('recover', {id:session.id, cancel:true});
                    if (['applied','restored'].includes(recovered.status)) await finishTransfer(recovered, route);
                    else { transferMeter.finish('cancelled'); session = null; summary = null; await load(); message('Destination unchanged.'); }
                } catch (_) { message('Recovery will retry when this page reconnects.'); }
            }
            throw error;
        }
    }
    $('stage').addEventListener('click', () => run(migrate));
    $('apply').addEventListener('click', () => run(migrate));
    $('rollback').addEventListener('click', () => run(migrate));
    async function cancelTransfer() {
        if (!session || cancelling) return;
        cancelling = true; cancellation++;
        for (const request of activeRequests) request.abort();
        const route = {...session}; session.automatic = false;
        $('discard').disabled = true; message('Cancelling…');
        try {
            await api('cancel',{id:route.id});
            let result;
            const deadline=Date.now()+120000;
            for (let attempt = 0; attempt < 180 && Date.now()<deadline; attempt++) {
                try { result = await api('recover', {id:route.id, cancel:true}); if (!result.continue) break; result=null; }
                catch (error) {
                    const wait = error.busy ? 1000 : error.retryable && error.retryAfter > 0 ? error.retryAfter * 1000 : 0;
                    if (!wait || Date.now() + wait >= deadline) throw error;
                    message(error.busy ? 'Cancelling… Waiting for the current request.' : `Cancelling… Waiting ${Math.ceil(wait / 1000)}s for the server.`);
                    await new Promise(resolve => setTimeout(resolve, wait));
                }
            }
            if (!result) throw new Error('Cancellation is waiting for the server. Automatic retries are paused; try Cancel again.');
            if (['applied','restored'].includes(result.status)) { await finishTransfer(result, route); return; }
            if (result.status !== 'cancelled') throw new Error('Cancellation not confirmed. Check transfer status before continuing.');
            transferMeter.finish('cancelled');
            session = null; summary = null; transferView = false; renderPreview();
            for (const id of ['apply','rollback','discard','activity']) $(id).hidden = true;
            $('stage').hidden = false; await load(); message('Transfer cancelled. Destination unchanged.');
        } catch (error) { fail(error); }
        finally { cancelling = false; $('discard').disabled = false; updateSelection(); }
    }
    $('discard').addEventListener('click', cancelTransfer);
    $('close-transfer').addEventListener('click', closeTransferView);
    $('transfer-dialog').addEventListener('cancel', event => { event.preventDefault(); closeTransferView(); });
    $('transfer-dialog').addEventListener('keydown', containTransferFocus);
    window.addEventListener('beforeunload', event => {
        if (session || transferView && busy) { event.preventDefault(); event.returnValue = ''; }
    });
    $('copy-diagnostics').addEventListener('click', copyDiagnostics);
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
    $('direction').value = session?.direction || connectionState.recents.find(item => ['push','pull'].includes(item.direction))?.direction || 'pull';
    renderConnections(connectionState); updateSelection();
    if (CSM.state.recovery_error) { fail(new Error(`Recovery needs attention: ${CSM.state.recovery_error}. The site remains protected. Check Diagnostics, then retry recovery.`)); const retry=document.createElement('button'); retry.type='button'; retry.className='button'; retry.textContent='Retry recovery'; retry.addEventListener('click',()=>run(async()=>{ let result; do { result=await api('recover_destination'); } while (result.continue); location.reload(); })); $('error').append(retry); }
    else if (CSM.state.destination_recovery) run(async () => {
        feedback('Recovering…'); let result; do { result=await api('recover_destination'); } while (result.continue);
        if (connectionState.selected || session) await load();
        else feedback('Destination restored.');
    });
    else if (connectionState.selected || session) run(() => load());
    setInterval(() => transferMeter.render(), 1000);
    setInterval(() => { if (!busy && session?.automatic && Date.now() >= (session.retryAt || 0) && versionsMatch() && transferVersionMatches()) run(migrate); }, 15000);
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
