/* global CSM, CSMTransferProgress, CSMRequest */
(() => {
    'use strict';
    const $ = id => document.getElementById(`csm-${id}`);
    let connectionState = CSM.state, sites = null, session = CSM.state.session, busy = false, summary = null, replacementRules = [], remoteUpdate = null;
    let connectionRevision=0;
    let inventoryGeneration=0, inventoryLoading=false, inventoryError='', presetRequest=0, presetLoading=false;
    let comparisonGeneration=0, comparisonState=null, comparisonPromise=Promise.resolve();
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
            button.setAttribute('aria-expanded', String(!$(key + '-body').hidden));
            button.setAttribute('aria-label', `View ${labels[key]} selection`);
            if (current) button.setAttribute('aria-current', 'true'); else button.removeAttribute('aria-current');
        });
        $('selection-placeholder').hidden = true;
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
    async function api(op, data = {}, generation = null, requestController = null) {
        if (generation===null && data.id && !['cancel','recover','rollback'].includes(op)) generation=cancellation;
        const controller = requestController || new AbortController();
        if (generation !== null) activeRequests.add(controller);
        let result;
        try { result = await CSMRequest(op, data, controller); }
        catch (error) { if (generation !== null && generation !== cancellation) throw cancelled(); throw error; }
        finally { activeRequests.delete(controller); }
        if (generation !== null && generation !== cancellation) throw cancelled();
        if (result?.nonce) CSM.nonce = result.nonce;
        if (['rename_connection','remove_connection'].includes(op)) connectionRevision++;
        if (!['manifest','compare_component','warm_media','estimates'].includes(op) && Array.isArray(result?.state?.connections)) renderConnections(result.state);
        return result;
    }
    function message(text) { $('status').textContent = text; }
    function fail(error) {
        transferMeter.fail?.(error);
        if (transferMeter.timeline) { $('error').hidden = true; feedback(''); return; }
        const returnFocus = document.activeElement;
        $('error').innerHTML = `<p class="csm-error-message">${esc(error.message)}</p>`;
        const actions = document.createElement('div'); actions.className = 'csm-error-actions';
        const copy = document.createElement('button'); copy.type = 'button'; copy.className = 'button-link'; copy.textContent = 'Copy error details';
        copy.addEventListener('click', async () => { try { await navigator.clipboard.writeText(error.message); copy.textContent = 'Copied'; } catch (_) { copy.textContent = 'Select the error text to copy'; } });
        const dismiss = document.createElement('button'); dismiss.type = 'button'; dismiss.className = 'button-link'; dismiss.textContent = 'Dismiss';
        dismiss.addEventListener('click', () => {
            $('error').hidden = true;
            if (returnFocus?.isConnected && !returnFocus.disabled && !returnFocus.closest('#csm-error')) returnFocus.focus({preventScroll:true});
            else document.querySelector?.('.csm-tabs a')?.focus({preventScroll:true});
        });
        actions.append(copy, dismiss); $('error').append(actions);
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
        let stopped = false, timer, lastLogRead = 0, pollNotBefore = 0;
        const generation = cancellation;
        const poll = async () => {
            if (stopped) return;
            if (Date.now() < pollNotBefore) { timer=setTimeout(poll,pollNotBefore-Date.now()); return; }
            try {
                const detail = await api('progress', {id:data.id});
                if(detail.poll_retry_after>0)pollNotBefore=Math.max(pollNotBefore,Date.now()+1000*detail.poll_retry_after);
                if (!stopped) renderActivity(detail);
                if (!stopped && Date.now() - lastLogRead > 5000) {
                    lastLogRead = Date.now();
                    api('logs', {id:data.id}).then(logs => { if (!stopped) transferMeter.ingestLogs?.(logs.entries); }).catch(() => {});
                }
            } catch (error) {
                pollNotBefore=Math.max(pollNotBefore,Date.now()+1000*(error.retryAfter || 0));
                if (!stopped) { $('activity').hidden = false; $('activity').textContent = 'Live progress is unavailable. The transfer may still be running; waiting for its result.'; }
            }
            if (!stopped) timer = setTimeout(poll, Math.max(3000,pollNotBefore-Date.now()));
        };
        const action = transferAction();
        transferMeter.operation?.(op, data.kind);
        $('action-title').textContent = ['commit','transfer'].includes(op) ? (action === 'Push' ? 'Pushing…' : 'Pulling…') : op === 'rollback' ? 'Restoring destination…' : `Preparing ${action.toLowerCase()}…`;
        $('activity').hidden = false; $('activity').textContent = 'Waiting for progress from the server…';
        timer = setTimeout(poll, 2000);
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
                    pollNotBefore=Math.max(pollNotBefore,Date.now()+1000*wait);
                    transferMeter.retry?.(error.message, wait);
                    await new Promise(resolve => setTimeout(resolve, 1000 * wait));
                    result = {continue:true};
                }
                if (result.progress) renderActivity({...result.progress, ...(Array.isArray(result.pending) ? {pending:result.pending} : {}), ...(result.timeline ? {timeline:result.timeline} : {})});
                else transferMeter.update(result);
                if (result.continue) summary = result;
            } while (result.continue);
            if (generation !== cancellation) throw cancelled();
            if (['commit','recover','rollback'].includes(op)) api('logs', {id:data.id}).then(logs => transferMeter.ingestLogs?.(logs.entries)).catch(() => {});
            return result;
        }
        catch (error) {
            stopped = true;
            if (error.cancelled || generation !== cancellation) throw cancelled();
            if (error.busy || error.retryAfter>0) { error.pending = true; throw error; }
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
        const controls = [...document.querySelectorAll('.csm button, .csm select, .csm input, .csm textarea')].filter(control => !['csm-copy','csm-copy-settings','csm-discard','csm-close-transfer','csm-close-connection','csm-copy-diagnostics','csm-diagnostic-output'].includes(control.id) && !control.matches('.csm-panel-disclosure') && !control.closest('#csm-transfer-meter') && !control.closest('#csm-diagnostics') && !control.closest('#csm-git-review'));
        const previous = controls.map(control => control.disabled);
        controls.forEach(control => { control.disabled = true; });
        try { await fn(); } catch (error) { fail(error); }
        finally { controls.forEach((control, i) => { control.disabled = previous[i]; }); busy = false; $('transfer-setup').setAttribute('aria-busy', 'false'); lockSelection(); updateSelection(); startComparisons(); if (transferMeter.phase === 'complete' && !$('completion').hidden) $('action-title').textContent = $('completion-title').textContent; }
    }
    function lockSelection() {
        document.querySelectorAll('#csm-transport, #csm-remote-site, #csm-recent, #csm-direction, #csm-media-mode, #csm-media-verify, #csm-media-force, #csm-file-verify, #csm-plugins-force, #csm-themes-force, #csm-since, #csm-media-after, #csm-media-before, #csm-replace, #csm-ignore-active-plugins, input[data-kind], input[data-component], .csm-select-all').forEach(control => { control.disabled = !!session || control.dataset.protected === 'true' || (control.hasAttribute('data-component') && (busy || !sites || inventoryLoading || !!inventoryError)); });
        lockReplacements();
    }
    const direction = () => $('direction').value;
    const transferAction = () => (session?.direction || direction()) === 'push' ? 'Push' : 'Pull';
    function labelRoute() {
        const pull = (session?.direction || direction()) === 'pull';
        const flow = document.querySelector('.csm-route-flow');
        flow.prepend($(pull ? 'destination' : 'source').closest('.csm-endpoint'));
        flow.append($(pull ? 'source' : 'destination').closest('.csm-endpoint'));
        $('flip-direction').setAttribute('data-direction',pull?'pull':'push');
        $('direction-label').textContent=pull?'Pull':'Push';
        $('flip-direction').title=pull?'Pull from remote. Click to switch to push.':'Push to remote. Click to switch to pull.';
        $('flip-direction').setAttribute('aria-label',$('flip-direction').title);
        for (const [id, local] of [['source', !pull], ['destination', pull]]) {
            $(id + '-location').textContent = local ? 'This site' : 'Saved connection';
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
    const selectAll = kind => `<label class="csm-select-heading"><input type="checkbox" class="csm-select-all" data-select-kind="${kind}" aria-label="Select all ${kind === 'tables' ? 'database tables' : kind}"><span${kind === 'tables' ? ' class="screen-reader-text"' : ''}>${kind === 'plugins' ? 'Plugin' : kind === 'themes' ? 'Theme' : 'Select all database tables'}</span></label>`;
    function siteLabel(site) {
        const peer=typeof connectionState==='undefined'?null:connectionState.connections.find(peer=>peer.url===site.url);
        return peer?.name || site.name || site.url;
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
        const pull=direction()==='pull';
        const headings=[`<span>Source<strong>${esc(siteLabel(src))}</strong></span>`,`<span>Destination<strong>${esc(siteLabel(dst))}</strong></span>`];
        if(pull)headings.reverse();
        let html=`<div class="csm-code-scroll" tabindex="0" role="region" aria-label="${kind} comparison"><div class="csm-code-table"><div class="csm-columns csm-code-columns">${selectAll(kind)}${headings.join('')}</div>`;
        for (const entry of src[kind]) {
            const item={...entry}; if(item.symlinks && item.follow_symlinks){item.protected=false;delete item.protected_reason;}
            const target=lookup.get(item.id), change=versionChange(item,target);
            const matching=!!target && item.version===target.version && !!item.content_hash && item.content_hash===target.content_hash;
            const label=matching?'Matching skipped':change.kind==='same'?(item.content_hash && target?.content_hash?'Files differ':'Hash not verified'):change.label;
            const tone=matching?'same':change.kind==='same'?'different':change.kind;
            const sourceCell=`<span class="csm-source-version"><span>${esc(item.version || '?')}</span><span class="csm-component-hash"><small>Source hash</small><code title="${esc(item.content_hash || '')}">${esc(item.content_hash?.slice(0,12) || 'Not verified')}</code></span></span>`;
            const destinationCell=`<span class="csm-destination-version"><span>${target && target.version!==item.version?`<del>${esc(target.version || '?')}</del> `:!target?'<del>Not installed</del> ':''}<b data-incoming="${esc(item.version || '?')}" data-installed="${esc(target?.version || 'Not installed')}">${esc(item.version || '?')}</b></span><small data-change-label="${esc(label)}">${esc(label)}</small><span class="csm-component-hash"><small>Current hash</small><code title="${esc(target?.content_hash || '')}">${esc(target?.content_hash?.slice(0,12) || (target?'Not verified':'No files'))}</code></span>${item.protected_reason?`<span class="csm-blocked-reason">${esc(item.protected_reason)}${item.symlinks?`<button type="button" class="button-link csm-follow-links" data-follow-links="${esc(item.id)}" data-follow-kind="${kind}">Include linked files</button>`:''}</span>`:''}</span>`;
            html+=`<div class="csm-component csm-code-component" data-change="${tone}"><label class="csm-component-identity"><input type="checkbox" data-kind="${kind}" data-protected="${!!item.protected}" aria-label="Transfer ${esc(item.name)}" value="${esc(item.id)}" ${item.protected?'disabled':''}><strong>${esc(item.name)}</strong></label>${pull?destinationCell+sourceCell:sourceCell+destinationCell}</div>`;
        }
        $(kind).innerHTML=html+'</div></div>';
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
        const sizes=src.tables.map(t=>t.bytes);
        const max=Math.max(1,...sizes), min=Math.min(max,...sizes);
        const pill = item => {
            if (!item) return '<span class="csm-missing">Not installed</span>';
            return `<span class="csm-table-metrics"><b>${bytes(item.bytes)}</b><small class="csm-table-row-count">~${Number(item.rows || 0).toLocaleString()} rows</small></span>`;
        };
        const pull=direction()==='pull';
        const siteColumns=[['source_bytes','Source',src],['destination_bytes','Destination',dst]];
        if(pull)siteColumns.reverse();
        const columns = [['name', 'Table'], ['engine', 'Type'], ...siteColumns];
        if($('table-order'))$('table-order').value=tableSort.key;
        const headers = columns.map(([key, label]) => {
            if(key==='engine')return '<th scope="col">Type</th>';
            const active = tableSort.key === key;
            // Carbon @carbon/icons 11.92.0; Apache-2.0 notices are in vendor/carbon.
            const path = active ? (tableSort.descending ? 'M24.59 16.59 17 24.17 17 4 15 4 15 24.17 7.41 16.59 6 18 16 28 26 18 24.59 16.59z' : 'M16 4 6 14 7.41 15.41 15 7.83 15 28 17 28 17 7.83 24.59 15.41 26 14 16 4z') : 'M27.6 20.6 24 24.2 24 4 22 4 22 24.2 18.4 20.6 17 22 23 28 29 22z M9 4 3 10 4.4 11.4 8 7.8 8 28 10 28 10 7.8 13.6 11.4 15 10z';
            const nextDescending = active ? !tableSort.descending : key !== 'name';
            const action = `Sort by ${key === 'name' ? 'table name' : label.toLowerCase() + ' size'} ${nextDescending ? 'descending' : 'ascending'}`;
            return `<th scope="col" aria-sort="${active ? (tableSort.descending ? 'descending' : 'ascending') : 'none'}">${key === 'name' ? '<div class="csm-table-name-heading">' + selectAll('tables') : ''}<button type="button" class="csm-sort" data-sort="${key}" aria-label="${esc(action)}" title="${esc(action)}"><span>${esc(label)}</span><svg viewBox="0 0 32 32" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor"><path d="${path}"/></svg></button>${key === 'name' ? '</div>' : ''}</th>`;
        }).join('');
        $('tables').innerHTML = `<p class="csm-table-context">${siteColumns.map(([,label,site])=>`<span><strong>${label}:</strong> ${esc(siteLabel(site))}</span>`).join('')}</p><p class="csm-scroll-hint">Scroll horizontally to compare both sites.</p><div class="csm-table-scroll" role="region" aria-label="Database table comparison" tabindex="0"><table class="csm-data-table"><colgroup><col class="csm-table-name-column"><col class="csm-table-engine-column"><col><col></colgroup><caption class="screen-reader-text">Database tables. Activate a column heading to sort; activate it again to reverse the order. Sizes include data and indexes. Size sorting uses bytes, not row counts.</caption><thead><tr>${headers}</tr></thead><tbody>${rows.map(({item, target}) => {
            const position=max===min?0:(item.bytes-min)/(max-min);
            const band=position<=.5?'small':'large', mix=(position<=.5?position:position-.5)*200;
            const sourceCell=`<td class="csm-table-source" data-size-band="${band}" style="--csm-size-mix:${mix}%">${pill(item)}</td>`;
            const destinationCell=`<td class="csm-table-destination">${pill(target)}</td>`;
            return `<tr><th scope="row"><label><input type="checkbox" data-kind="tables" value="${esc(item.id)}" aria-label="Transfer ${esc(item.name)}" ${selected.has(item.id) ? 'checked' : ''} ${session ? 'disabled' : ''}><strong>${esc(item.name)}</strong></label></th><td class="csm-table-engine">${esc(item.engine)}</td>${pull?destinationCell+sourceCell:sourceCell+destinationCell}</tr>`;
        }).join('') || '<tr><td colspan="4">No database tables found on the source site.</td></tr>'}</tbody></table></div>`;
    }
    function updateSelection() {
        const counts = {};
        if($('preset-modified') && typeof presetBaseline!=='undefined')$('preset-modified').hidden=!presetBaseline || JSON.stringify({...currentSelection(),expanded_components:undefined})===presetBaseline;
        for (const kind of ['plugins', 'themes', 'tables', 'media_ids']) {
            counts[kind] = !sites && session?.selection ? (session.selection[kind] || []).length : $('enable-' + (kind === 'media_ids' ? 'media' : kind)).checked ? document.querySelectorAll(`input[data-kind="${kind}"]:checked`).length : 0;
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
        if(sites){const selected=($('enable-tables').checked?source().tables:[]).filter(t=>[...document.querySelectorAll('input[data-kind="tables"]:checked')].some(e=>e.value===t.id));const large=selected.filter(t=>t.bytes>=250*1048576).length;$('tables-summary').classList?.toggle('is-warning',$('enable-tables').checked && large>0);$('tables-summary').textContent=$('enable-tables').checked?`${selected.length} tables${large?` · ${large} large included`:''}`:'Not included';}
        if(sites)for(const kind of ['plugins','themes']) {
            const outcomes={};
            $(kind).querySelectorAll('.csm-code-component').forEach(row=>{
                const input=row.querySelector('input[data-kind]'), value=row.querySelector('[data-incoming]'), status=row.querySelector('[data-change-label]');
                const included=$('enable-'+kind).checked && input.checked;row.dataset.included=String(included);
                if(value)value.textContent=included?value.dataset.incoming:value.dataset.installed;
                const old=row.querySelector('del');if(old)old.hidden=!included;
                if(status)status.textContent=input.dataset.protected==='true'?status.dataset.changeLabel:included?(status.dataset.changeLabel==='Matching skipped' && $(kind+'-force').checked?'Matching · forced':status.dataset.changeLabel):'Not selected';
                if(included){const key=status?.dataset.changeLabel || '';outcomes[key]=(outcomes[key]||0)+1;}
            });
            const labels=Object.entries(outcomes).map(([label,n])=>`${n} ${label==='Hash not verified'?'unverified':label==='Matching skipped'?($(kind+'-force').checked?'matching forced':'matching skipped'):label==='Files differ'?(n===1?'file differs':'files differ'):label.toLowerCase()+(n===1?'':'s')}`);
            $(kind+'-summary').textContent=$('enable-'+kind).checked?(labels.join(' · ') || 'Empty selection'):'Not included';
        }
        document.querySelectorAll('.csm-sort').forEach(button => { button.disabled = busy || !sites; });
        document.querySelectorAll('input[data-component]').forEach(input => { input.disabled = busy || !!session || !sites || inventoryLoading || !!inventoryError; });
        for (const kind of componentKinds) $('disclose-' + kind).disabled = !sites && !inventoryLoading;
        $('remote-site').disabled = busy || !!session || !connectionState.connections.length;
        $('recent').disabled = busy || !!session || !connectionState.recents.length;
        const mode = !sites && session?.selection ? session.selection.media : $('enable-media').checked ? $('media-mode').value : 'none';
        document.querySelectorAll('[data-media-mode]').forEach(input=>{input.checked=input.dataset.mediaMode===$('media-mode').value;input.disabled=busy || !!session || !sites || input.dataset.mediaMode==='uploaded' && !source().media_dates;});
        if($('replace-summary'))$('replace-summary').textContent=$('replace').checked?'Adjust site addresses':'Off';
        const media = ['all','compare'].includes(mode) ? ((sites ? $('media-force').checked : session?.selection?.media_force) ? 'All media files · forced' : 'All media files') : mode === 'changed' ? 'Changed media files' : mode === 'uploaded' ? 'Attachments by upload date' : mode === 'selected' ? `${counts.media_ids} attachment${counts.media_ids === 1 ? '' : 's'} selected` : 'No media files';
        $('media-summary').textContent = media;
        $('selection-summary').textContent = `${counts.tables} table${counts.tables === 1 ? '' : 's'} · ${counts.plugins} plugin${counts.plugins === 1 ? '' : 's'} · ${counts.themes} theme${counts.themes === 1 ? '' : 's'} · ${media.toLowerCase()}`;
        if($('included-summary'))$('included-summary').textContent=`${componentKinds.filter(kind=>$('enable-'+kind).checked).length} components included`;

        const selected = counts.plugins + counts.themes + counts.tables > 0 || (mode !== 'none' && (mode !== 'selected' || counts.media_ids > 0));
        const validMediaDates = mode !== 'uploaded' || (!!($('media-after').value || $('media-before').value) && (!$('media-after').value || !$('media-before').value || $('media-after').value <= $('media-before').value));
        $('stage').disabled = busy || cancelling || inventoryLoading || !!inventoryError || presetLoading || (session?.retryAt > Date.now()) || !versionsMatch() || !transferVersionMatches() || (!session && (!sites || !selected || !validMediaDates || (counts.tables > 0 && $('replace').checked && !sites.replacements)));
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
        const pull=(session?.direction || direction())==='pull';
        return sites?`${pull?'Pull into':'Push to'} ${siteLabel(destination())}`:pull?'Pull site':'Push site';
    }
    function presetsAvailable() {
        return !!sites && !inventoryLoading && !inventoryError && versionsMatch() && transferVersionMatches();
    }
    function updateSetup() {
        $('action-destination').textContent = session?.destination || (sites ? destination().url : '');
        const ready=presetsAvailable();
        $('preset-undo').hidden=!presetUndo || !ready;
        $('recent-wrap').hidden=activePreset!=='recent' || !ready || presetLoading || !!session || !connectionState.recents.some(item=>item.remote_id===connectionState.selected);
        if($('preset-title'))$('preset-title').textContent=presetChosen?'Starting preset':'Choose a preset';
        $('preset-undo').disabled=busy || !!session || inventoryLoading || !!inventoryError;
        if(inventoryLoading || presetLoading || inventoryError)$('stage').disabled=true;
        document.querySelectorAll('input[data-component]').forEach(input=>input.disabled=busy || !!session || !sites || inventoryLoading || !!inventoryError);
        $('preset').disabled = busy || !!session || !ready;
        document.querySelectorAll('[data-verify-matching]').forEach(button=>button.disabled=busy || !!session || !sites);
        $('selection-workspace').hidden=(!presetChosen || !ready) && !session;
        if($('preset-choices'))$('preset-choices').querySelectorAll('button').forEach(button=>button.disabled=busy || !!session || !ready || !connectionState.selected || button.dataset.presetChoice==='recent' && !connectionState.recents.some(item=>item.remote_id===$('remote-site').value && item.direction===direction()));
        $('transfer-shortcuts').hidden=!connectionState.selected || !ready;
        $('flip-direction').disabled=busy || !!session || !connectionState.selected;
        const menu=$('site-menu');if(busy || session)menu.open=false;
        $('site-menu-toggle').setAttribute('aria-disabled',String(busy || !!session));
        for(const button of document.querySelectorAll('[data-select-site], [data-add-site], [data-connection-control], #csm-update-connection'))button.disabled=busy || !!session || button.hasAttribute('data-select-site') && button.dataset.selectSite===connectionState.selected;
        $('refresh').disabled=busy || !!session || !connectionState.selected;
        $('stage').hidden = busy && (!!session || transferView) || !session && !$('completion').hidden;
        document.querySelectorAll('[data-reuse-recent]').forEach(button=>button.disabled=busy || !!session);
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
            const verifiedMatch = kind !== 'tables' && target && item.version === target.version && !!item.content_hash && item.content_hash === target.content_hash;
            const showMatching = kind === 'tables' || $(kind+'-force')?.checked || $('show-matching-' + kind)?.checked || mode === 'selected';
            const matches = (!verifiedMatch || showMatching) && `${item.name} ${item.id}`.toLocaleLowerCase().includes(query) && (mode === 'all' || mode === 'selected' && input.checked || mode === 'updates' && !!(item.update || target?.update) || mode === 'different' && (!target || (item.version !== target.version && item.version_order !== 0 || !!item.content_hash && !!target.content_hash && item.content_hash !== target.content_hash)));
            row.hidden = !matches; total++; if (matches) shown++;
        });
        if($('options-summary-'+kind))$('options-summary-'+kind).textContent=query?'Search active':mode!=='all'?'Filter active':kind!=='tables' && $(kind+'-force')?.checked?'Forced retransfer':'';
        $('filter-count-' + kind).textContent = `${shown} of ${total}`;
        $('filter-empty-' + kind).hidden = shown > 0 || total === 0;
    }
    let presetUndo = null, presetChosen = false, presetBaseline = null, activePreset = '';
    const presetIcons={"everything": "<svg viewBox=\"0 0 36 36\" aria-hidden=\"true\" fill=\"currentColor\"><path d=\"M31.49 8.92206L19.49 2.12991C19.16 1.93985 18.75 1.95986 18.43 2.17993L4.43 11.803C4.16 11.993 4 12.3031 4 12.6232V26.2075C4 26.5676 4.19 26.8978 4.51 27.0778L16.51 33.87C16.66 33.96 16.83 34 17 34C17.2 34 17.4 33.94 17.57 33.8199L31.57 24.1869C31.84 23.9968 32 23.6867 32 23.3666V9.79234C32 9.43222 31.81 9.10212 31.49 8.92206ZM19.05 4.18056L28.78 9.6923L24.1 12.9133L14.37 7.40158L19.05 4.18056ZM16 31.2991L6 25.6374V13.9336L16 19.5954V31.2991ZM16.95 17.8349L7.22 12.3231L12.54 8.66198L22.27 14.1737L16.95 17.8349ZM30 22.8565L18 31.1091V19.5354L30 11.2828V22.8565Z\"/></svg>", "push-git": "<svg viewBox=\"0 0 36 36\" aria-hidden=\"true\" fill=\"currentColor\"><path d=\"M13.86 11.4917C13.58 11.0215 12.97 10.8614 12.49 11.1415L2.49 17.0642C2.19 17.2442 2 17.5744 2 17.9245C2 18.2747 2.18 18.6048 2.48 18.7849L12.48 24.8676C12.64 24.9677 12.82 25.0177 13 25.0177C13.34 25.0177 13.67 24.8476 13.86 24.5375C14.15 24.0673 14 23.447 13.53 23.1669L4.94 17.9345L13.51 12.8523C13.99 12.5722 14.14 11.9619 13.86 11.4817V11.4917ZM33.52 17.2242L23.52 11.1415C23.05 10.8514 22.43 11.0015 22.15 11.4717C21.86 11.9419 22.01 12.5622 22.48 12.8423L31.06 18.0546L22.49 23.1369C22.01 23.417 21.86 24.0273 22.14 24.5075C22.33 24.8276 22.66 24.9977 23 24.9977C23.17 24.9977 23.35 24.9577 23.51 24.8576L33.51 18.935C33.81 18.7549 34 18.4348 34 18.0846C34 17.7345 33.82 17.4043 33.52 17.2242ZM20.22 8.02013C19.68 7.91008 19.15 8.24023 19.03 8.78047L15.03 26.7885C14.91 27.3287 15.25 27.859 15.79 27.979C15.86 27.999 15.94 27.999 16.01 27.999C16.47 27.999 16.88 27.6789 16.99 27.2187L20.98 9.21066C21.1 8.67042 20.76 8.14018 20.22 8.02013Z\"/></svg>", "custom": "<svg viewBox=\"0 0 36 36\" aria-hidden=\"true\" fill=\"currentColor\"><path fill-rule=\"evenodd\" clip-rule=\"evenodd\" d=\"M11.9804 12.37C11.9881 10.5397 10.7556 8.93757 8.98767 8.48V5C8.98767 4.44772 8.54105 4 7.99011 4C7.43917 4 6.99255 4.44772 6.99255 5V8.48C5.19755 8.91093 3.93164 10.5197 3.93164 12.37C3.93164 14.2203 5.19755 15.8291 6.99255 16.26V31C6.99255 31.5523 7.43917 32 7.99011 32C8.54105 32 8.98767 31.5523 8.98767 31V16.26C10.7556 15.8024 11.9881 14.2003 11.9804 12.37ZM28.9389 11.94C30.7069 12.3976 31.9394 13.9997 31.9316 15.83C31.9394 17.6603 30.7069 19.2624 28.9389 19.72V31C28.9389 31.5523 28.4923 32 27.9414 32C27.3904 32 26.9438 31.5523 26.9438 31V19.72C25.1488 19.2891 23.8829 17.6803 23.8829 15.83C23.8829 13.9797 25.1488 12.3709 26.9438 11.94V5C26.9438 4.44772 27.3904 4 27.9414 4C28.4923 4 28.9389 4.44772 28.9389 5V11.94ZM25.9462 15.8301C25.9462 16.9346 26.8395 17.8301 27.9414 17.8301V17.8701C28.4775 17.8702 28.9911 17.654 29.3664 17.2702C29.7417 16.8865 29.9472 16.3674 29.9365 15.8301C29.9365 14.7255 29.0432 13.8301 27.9414 13.8301C26.8395 13.8301 25.9462 14.7255 25.9462 15.8301ZM5.99499 12.3701C5.99499 13.4747 6.88824 14.3701 7.99011 14.3701V14.4001C8.52448 14.4002 9.03654 14.1854 9.41157 13.8038C9.7866 13.4222 9.99325 12.9057 9.98524 12.3701C9.98524 11.2655 9.09199 10.3701 7.99011 10.3701C6.88824 10.3701 5.99499 11.2655 5.99499 12.3701ZM21.956 24.5C21.9638 22.6697 20.7312 21.0676 18.9633 20.61V5C18.9633 4.44772 18.5167 4 17.9658 4C17.4148 4 16.9682 4.44772 16.9682 5V20.61C15.1732 21.0409 13.9073 22.6497 13.9073 24.5C13.9073 26.3503 15.1732 27.9591 16.9682 28.39V31C16.9682 31.5523 17.4148 32 17.9658 32C18.5167 32 18.9633 31.5523 18.9633 31V28.39C20.7312 27.9324 21.9638 26.3303 21.956 24.5ZM15.9706 24.5C15.9706 25.6046 16.8639 26.5 17.9657 26.5V26.53C18.5001 26.5301 19.0122 26.3152 19.3872 25.9336C19.7622 25.5521 19.9689 25.0356 19.9609 24.5C19.9609 23.3954 19.0676 22.5 17.9657 22.5C16.8639 22.5 15.9706 23.3954 15.9706 24.5Z\"/></svg>", "recent": "<svg viewBox=\"0 0 36 36\" aria-hidden=\"true\" fill=\"currentColor\"><path d=\"M3.99805 10.26C6.82862 5.17851 12.1814 2.02037 17.998 2C25.1475 1.99879 31.4301 6.7409 33.3887 13.6169C35.3472 20.4928 32.5064 27.8337 26.4294 31.5999C20.3523 35.3662 12.5142 34.6437 7.22805 29.83C6.96367 29.5888 6.84803 29.225 6.92469 28.8754C7.00135 28.5259 7.25865 28.2438 7.59969 28.1354C7.94072 28.0271 8.31367 28.1088 8.57805 28.35C13.0467 32.4157 19.6193 33.1563 24.8806 30.187C30.142 27.2176 32.9051 21.2082 31.7342 15.2814C30.5634 9.3545 25.7226 4.84725 19.7274 4.10169C13.7322 3.35613 7.93492 6.54045 5.34805 12H10.798C11.3503 12 11.798 12.4477 11.798 13C11.798 13.5523 11.3503 14 10.798 14H1.99805V5.2C1.99805 4.64772 2.44576 4.2 2.99805 4.2C3.55033 4.2 3.99805 4.64772 3.99805 5.2V10.26Z\"/><path d=\"M16.998 10.83C16.998 10.2777 17.4458 9.83 17.998 9.83C18.5503 9.83 18.998 10.2777 18.998 10.83V18.49L23.998 21.88C24.4488 22.1878 24.5696 22.8001 24.2693 23.256C23.9691 23.7118 23.3588 23.8426 22.898 23.55L16.998 19.55V10.83Z\"/></svg>", "database": "<svg viewBox=\"0 0 36 36\" aria-hidden=\"true\" fill=\"currentColor\"><path d=\"M18 2C16.57 2 4 2.11 4 6V30C4 33.89 16.57 34 18 34C19.43 34 32 33.89 32 30V6C32 2.11 19.43 2 18 2ZM30 13.92C29.52 14.68 25.17 16 18 16C13.42 16 10.01 15.46 8 14.88V16.96C11.81 17.96 17.1 18.01 18 18.01C19.07 18.01 26.39 17.95 30 16.28V21.93C29.52 22.69 25.17 24.01 18 24.01C13.42 24.01 10.01 23.47 8 22.89V24.97C11.81 25.97 17.1 26.02 18 26.02C19.07 26.02 26.39 25.96 30 24.29V29.95C29.49 30.71 25.14 32.02 18 32.02C10.86 32.02 6.51 30.71 6 29.95V6H6.05C6.77 5.23 11.07 4 18 4C24.93 4 29.23 5.23 29.95 6C29.23 6.77 24.93 8 18 8C13.43 8 10.01 7.46 8 6.88V8.96C11.81 9.96 17.1 10.01 18 10.01C19.07 10.01 26.39 9.95 30 8.28V13.93V13.92Z\"/></svg>", "database-media": "<svg viewBox=\"0 0 36 36\" aria-hidden=\"true\" fill=\"currentColor\"><path fill-rule=\"evenodd\" clip-rule=\"evenodd\" d=\"M32 4H4C2.89543 4 2 4.89543 2 6V30C2 31.1046 2.89543 32 4 32H32C33.1046 32 34 31.1046 34 30V6C34 4.89543 33.1046 4 32 4ZM8.92 14C10.5769 14 11.92 12.6569 11.92 11C11.92 9.34315 10.5769 8 8.92 8C7.26315 8 5.92 9.34315 5.92 11C5.92 12.6569 7.26315 14 8.92 14ZM22.78 15.37L17.38 20.77L13.38 16.77C12.99 16.3823 12.36 16.3823 11.97 16.77L5.92 22.9V25.73L12.71 18.94L16 22.18L12.25 25.93H15L23.45 17.48L30 24V21.18L24.19 15.37C23.8 14.9823 23.17 14.9823 22.78 15.37ZM10.4053 10.3807C10.1545 9.7832 9.56803 9.39595 8.92 9.4V9.41C8.04414 9.41546 7.33546 10.1241 7.33 11C7.33001 11.648 7.72093 12.2321 8.32004 12.4791C8.91915 12.7262 9.60811 12.5875 10.0649 12.1278C10.5217 11.6681 10.6561 10.9783 10.4053 10.3807ZM4 6V30H32V6H4Z\"/></svg>"};
    function renderPresetChoices() {
        const choices=direction()==='push'?[['everything','Everything','All content'],['push-git','Version-controlled files','Tracked files'],['custom','Custom','Choose your own'],['recent','Most recent','Last push for these sites']]:[['everything','Everything','All content'],['database','Database only','Content and settings'],['database-media','Database + media','Content and uploads'],['recent','Most recent','Last pull for these sites']];
        $('preset-choices').innerHTML=choices.map(([id,label,hint])=>`<button type="button" class="csm-preset-choice" data-preset-choice="${id}" ${busy || session || !presetsAvailable() || !connectionState.selected?'disabled':''}><span class="csm-preset-icon">${presetIcons[id]}</span><span><strong>${label}</strong><small>${hint}</small></span></button>`).join('');
    }
    async function applyPreset(name, recentProfile=null) {
        if (!name || session || busy || !presetsAvailable()) return;
        $('recent-wrap').hidden=name!=='recent';
        const request=++presetRequest, site=$('remote-site').value, flow=direction();
        if(name==='recent' && !recentProfile)recentProfile=connectionState.recents.find(item=>item.remote_id===site && item.direction===flow) || null;
        const previous={...currentSelection(),_ui:{chosen:presetChosen,baseline:presetBaseline,active:activePreset}};
        previous.media=$('media-mode').value;
        for(const kind of ['plugins','themes','tables','media_ids'])previous[kind]=[...document.querySelectorAll(`input[data-kind="${kind}"]:checked`)].map(e=>e.value);
        presetLoading=true;updateSelection();
        try {
            let selection;
            if(name==='recent') {
                const recent=recentProfile || connectionState.recents.find(item=>item.remote_id===site && item.direction===flow);
                if(!recent)throw new Error('No recent migration for these sites and direction.');
                const profile=recent.selection?recent:await api('recent',{id:recent.id});
                await loadSelectedMedia(profile.selection);
                if(request!==presetRequest || site!==$('remote-site').value || flow!==direction())return;
                renderMediaGrid();selection=profile.selection;$('recent').value=profile.id;
                $('recent-wrap').hidden=false;
            } else if(name==='push-git') {
                const preset=await api('preset',{preset:name,site});
                selection={...currentSelection(),...preset.selection,enabled_components:['plugins','themes'],expanded_components:[]};
            } else {
                selection={...currentSelection(),tables:[],plugins:[],themes:[],media:'none',enabled_components:[],expanded_components:[]};
                const enabled=name==='everything'?componentKinds:name==='database'?['tables']:name==='database-media'?['tables','media']:[];
                for(const kind of enabled){if(kind==='media')selection.media='all';else selection[kind]=source()[kind].filter(item=>!item.protected).map(item=>item.id);}
                selection.enabled_components=enabled;
            }
            if(request!==presetRequest || site!==$('remote-site').value || flow!==direction())return;
            restoreSelection(selection);presetUndo=previous;
            presetChosen=true;activePreset=name;$('recent-wrap').open=name==='recent';presetBaseline=JSON.stringify({...currentSelection(),expanded_components:undefined});
            document.querySelectorAll('[data-preset-choice]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.presetChoice===name)));
            $('preset-status').textContent='Preset applied. Selections remain editable.';feedback('');
        } catch(error) {
            if(request===presetRequest && site===$('remote-site').value && flow===direction())throw error;
        } finally {if(request===presetRequest){presetLoading=false;updateSelection();startComparisons();}}
    }
    function renderComponentSizes() {
        const src=sites?source():null, estimate=src?.estimates;
        let total=0, complete=true, included=0, pendingMedia=false;
        for (const kind of componentKinds) {
            const cell=$(kind+'-source-size'), enabled=$('enable-'+kind).checked;
            cell.hidden=!enabled;
            if(!enabled)continue;
            included++;
            let size=null, ready=false, pending=false;
            if(src && kind==='tables') {
                const selected=new Set([...document.querySelectorAll('input[data-kind="tables"]:checked')].map(input=>input.value));
                size=src.tables.filter(table=>selected.has(table.id)).reduce((sum,table)=>sum+table.bytes,0);
                ready=true;
            } else if(src && ['plugins','themes'].includes(kind)) {
                const ids=[...document.querySelectorAll(`input[data-kind="${kind}"]:checked`)].map(input=>input.value);
                const sizes=ids.map(id=>estimate?.items?.[kind]?.[id]);
                size=sizes.reduce((sum,value)=>sum+(value || 0),0);
                ready=!ids.length || !!estimate?.complete && sizes.every(value=>value!=null);
            } else if(src && kind==='media') {
                const mode=$('media-mode').value;
                if(mode==='none' || mode==='selected' && !document.querySelectorAll('input[data-kind="media_ids"]:checked').length) { size=0;ready=true; }
                else if(['all','compare'].includes(mode)) { size=estimate?.bytes?.media;ready=!!estimate?.complete && size!=null; }
                else { pending=true;pendingMedia=true; }
            }
            cell.textContent=pending?'Size pending':size!=null && (ready || size>0)?`${ready?'~':'≥ '}${bytes(size)}`:'Estimating…';
            cell.title=pending?'This media selection is sized during preparation.':'Selected source data before compression and matching-file skips.';
            total+=size || 0;
            complete=complete && ready;
        }
        if($('database-summary'))$('database-summary').textContent=!src?'Loading component sizes…':!included?'No components included':complete?`~${bytes(total)} of source data selected`:`${bytes(total)} known · ${pendingMedia?'media size pending':'estimating total…'}`;
        renderSizeDetails();
        $('summary-size-note').textContent=estimate?.refreshing?'Refreshing source sizes…':'Estimated before compression. Matching files are skipped unless forced.';
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
        if (!presetsAvailable() || session || generation!==estimateGeneration) return;
        const site=$('remote-site').value;
        try {
            const result=await api('estimates',{site});
            if (session || generation!==estimateGeneration || site!==$('remote-site').value) return;
            for (const key of ['local','remote']) { sites[key].estimates=result[key]; }
            renderComponentSizes();
            if (!result.local.complete || result.local.refreshing || !result.remote.complete || result.remote.refreshing) estimateTimer=setTimeout(()=>loadEstimates(generation),3000);
        } catch (_) { if (!session && generation===estimateGeneration) $('summary-size-note').textContent='Size estimate unavailable. Try Refresh versions.'; }
    }
    function transferVersionMatches() { return !session || (!!sites && session.plugin_version === sites.local.plugin_version); }
    function versionsMatch() { return !!sites && sites.local.plugin_version === sites.remote.plugin_version && (!sites.remote.remote_build_update || sites.local.trickle_build === sites.remote.trickle_build); }
    function renderEndpoints() {
        const menu=$('site-menu'), peer=connectionState.connections.find(site=>site.id===connectionState.selected), pull=(session?.direction || direction())==='pull';
        const focused=menu.contains(document.activeElement)?document.activeElement:null;
        const local=sites?.local || CSM.local, remote=sites?.remote || peer;
        labelRoute();
        for(const [id,site] of [['source',pull?remote:local],['destination',pull?local:remote]]){
            const card=$(id).closest('.csm-endpoint'), isRemote=id===(pull?'source':'destination');
            card.classList.toggle('csm-empty-endpoint',!site);card.classList.toggle('csm-remote-endpoint',isRemote);
            if(!site){$(id).innerHTML=`<button type="button" class="button" data-add-site>Add ${pull?'source':'destination'}</button>`;continue;}
            const name=isRemote?(peer?.name || site.name || site.url):(site.name || site.url);
            const versions=[['Trickle',site.plugin_version],['WordPress',site.wordpress],['PHP',site.php]].filter(([,value])=>value);
            $(id).innerHTML=`${icon(site.icon,'site')}<span class="csm-site-identity" title="${esc([site.url,...versions.map(([label,version])=>label+' '+version)].join(' · '))}"><strong>${esc(name)}</strong><small>${esc(site.url)}</small>${versions.length?`<span class="csm-site-versions">${versions.map(([label,version])=>`<span>${label} <b>${esc(version)}</b></span>`).join('')}</span>`:''}</span>`;
        }
        $(pull?'source':'destination').append(menu);
        if(focused?.isConnected)focused.focus({preventScroll:true});
        menu.hidden=!connectionState.connections.length;
        $('site-menu-toggle').innerHTML=`<span>${esc(peer?.name || remote?.name || 'Choose site')}</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>`;
        $('update-connection').hidden=!peer;
    }
    function renderVersionStatus() {
        $('clear-update-comparisons').hidden=!remoteUpdate?.retained;
        $('clear-update-comparisons').disabled=busy || !!session;
        const peerUrl=connectionState.connections.find(peer=>peer.id===connectionState.selected)?.url;
        $('open-remote-trickle').hidden=!(remoteUpdate?.retained || remoteUpdate?.syncError) || !peerUrl;
        if(peerUrl)$('open-remote-trickle').href=peerUrl.replace(/\/$/,'')+'/wp-admin/tools.php?page=trickle&view=settings#csm-source-preparations';
        $('trickle-versions').hidden = !sites;
        $('install-local-build').hidden = !sites;
        $('install-local-build').textContent = sites?.remote.remote_build_update ? 'Sync remote Trickle' : 'Install the latest Trickle ZIP to enable syncing';
        $('install-local-build').disabled = busy || !!session || !sites?.remote.remote_build_update || !sites?.remote.can_update_trickle || sites?.remote.file_modifications===false;
        if (!sites) return;
        renderEndpoints();
        const same = versionsMatch(), local = sites.local.plugin_version;
        $('trickle-versions').hidden = same && transferVersionMatches();
        $('trickle-versions').classList.toggle('csm-version-mismatch', !same || !transferVersionMatches());
        $('trickle-version-status').textContent = same && transferVersionMatches() ? '' : 'Trickle version conflict';
        if (sites.remote.remote_build_update) {
            const mayUpdate=!!sites.remote.can_update_trickle;
            $('check-remote-update').hidden=true;
            $('update-remote').hidden=same||!mayUpdate;
            $('update-remote').disabled=busy||!!session||sites.remote.file_modifications===false;
            $('update-remote').textContent='Sync remote Trickle';
            $('trickle-version-status').textContent=same && transferVersionMatches()?'':'Trickle version conflict';
            $('trickle-update-status').textContent=session?'Finish the transfer before changing Trickle.':sites.remote.file_modifications===false?'WordPress disallows file modifications on the remote site.':!mayUpdate?'Use an administrator or read/write key to update Trickle.':remoteUpdate?.retained?'The remote site has retained state. Clear completed comparisons here, or open remote Trickle to resolve an unfinished transfer. Backups and migration recovery data are kept.':'The installed Trickle files differ between sites. Sync the remote site with this installation.';
            return;
        }
        const supported = sites.remote.remote_update === 1;
        const mayUpdate = !!sites.remote.can_update_trickle;
        $('check-remote-update').hidden = same || !supported;
        $('check-remote-update').disabled = busy || !!session;
        $('update-remote').hidden = same || !remoteUpdate?.can_update_to_target;
        $('update-remote').disabled = busy || !!session;
        $('update-remote').textContent = `Update remote Trickle to ${local}`;
        $('trickle-update-status').textContent = !transferVersionMatches() ? 'This retained transfer was prepared with an older Trickle version. Discard and prepare again; rollback and recovery remain available.' : same ? 'Push and pull use the same Trickle version on both sites.' : session ? 'Versions changed during a retained transfer. Recovery remains available; discard and prepare again after aligning versions.' : !supported ? 'Install this Trickle version on the remote site to enable remote updates.' : !mayUpdate ? 'Use an administrator or read/write key to update Trickle.' : remoteUpdate ? remoteUpdate.reason : 'Check whether the remote JSON feed offers the version installed on this site. Newer remote versions require updating this site first.';
    }
    const transferDate = stamp => new Date(stamp * 1000).toLocaleString(undefined, {dateStyle:'medium', timeStyle:'short'});
    function recentScope(profile) {
        const s=profile.selection || {}, parts=[];
        for(const [kind,label] of [['tables','table'],['plugins','plugin'],['themes','theme']]) {
            const count=(s[kind] || []).length;if(count)parts.push(`${count} ${label}${count===1?'':'s'}`);
        }
        if(['all','compare'].includes(s.media))parts.push(s.media_force?'All media · forced':'All media · unchanged files skipped');
        else if(s.media==='uploaded')parts.push(`Media uploaded ${s.media_after || 'any date'} → ${s.media_before || 'now'}`);
        else if(s.media==='selected')parts.push(`${(s.media_ids || []).length} selected attachments + image sizes`);
        else if(s.media && s.media!=='none')parts.push('Media · saved date filter');
        return parts;
    }
    function renderRecentMigrations() {
        const profiles=connectionState.recents.filter(item=>item.remote_id===connectionState.selected).sort((a,b)=>b.started_at-a.started_at);
        $('recent-wrap').hidden=activePreset!=='recent' || presetLoading || !!session || !profiles.length;
        $('recents-context').textContent=profiles.length?`${profiles.length} with ${connectionState.connections.find(item=>item.id===connectionState.selected)?.name || 'this site'}`:'';
        const card=profile=>{
            const done=profile.status==='applied', result=profile.result, s=profile.selection || {};
            const status={applied:'Completed',staged:'Staged · not applied',staging:'Incomplete',applying:'Applying',restored:'Rolled back',cancelled:'Cancelled',failed:'Failed',restoring:'Restoring'}[profile.status] || profile.status;
            const items=['tables','plugins','themes'].filter(kind=>(s[kind] || []).length).map(kind=>{
                const names=new Map((profile.scope?.[kind] || []).map(item=>[item.id,item.name+(item.version?' '+item.version:'')]));
                return `<div><dt>${kind==='tables'?'Database tables':kind==='plugins'?'Plugins':'Themes'}</dt><dd>${(s[kind] || []).map(id=>esc(names.get(id) || id)).join(', ')}</dd></div>`;
            }).join('');
            const actual=done && result?`${Number(result.tables || 0)} tables transferred · ${Number(result.files || 0)} files copied${result.unchanged_files?` · ${Number(result.unchanged_files)} unchanged files skipped`:''}`:'';
            return `<article class="csm-recent-migration"><div class="csm-recent-route"><strong>${profile.direction==='push'?'Push to':'Pull from'} ${esc(profile.remote_name || profile.remote_url)}</strong><span class="csm-recent-outcome" data-completed="${done}">${esc(status)}</span><time>${esc(transferDate(done && profile.completed_at || profile.started_at))}</time></div><p class="csm-recent-scope"><small>Selected scope</small>${recentScope(profile).map(part=>`<span>${esc(part)}</span>`).join('') || 'No components selected'}</p>${actual?`<p class="csm-recent-result">${esc(actual)}</p>`:''}<div class="csm-recent-actions"><details><summary>View selected items</summary><dl>${items}${s.media && s.media!=='none'?`<div><dt>Media</dt><dd>${esc(recentScope(profile).at(-1))}</dd></div>`:''}</dl></details><button type="button" class="button" data-reuse-recent="${esc(profile.id)}" ${busy || session?'disabled':''}>Use these settings</button></div></article>`;
        };
        $('recent-list').innerHTML=profiles.slice(0,3).map(card).join('')+(profiles.length>3?`<details class="csm-older-migrations"><summary>${profiles.length-3} older migrations</summary>${profiles.slice(3).map(card).join('')}</details>`:'');
    }
    async function reuseRecent(id) {
        if(busy || session)return;
        const profile=connectionState.recents.find(item=>item.id===id);
        if(!profile)throw new Error('This migration is no longer in recent history.');
        if(profile.remote_id!==connectionState.selected)await selectSite(profile.remote_id);
        if(direction()!==profile.direction){$('direction').value=profile.direction;$('direction').dispatchEvent(new Event('change'));}
        await applyPreset('recent',profile);
        $('preset-choices').scrollIntoView({block:'nearest'});
        document.querySelector('[data-preset-choice="recent"]')?.focus({preventScroll:true});
    }
    function renderSectionLoading() {
        const loading=inventoryLoading || !!inventoryError;
        $('inventory-status').hidden=!loading;
        $('inventory-status').textContent=inventoryError?`Could not load site details: ${inventoryError}`:inventoryLoading?'Checking the connection and Trickle versions…':'';
        for(const kind of componentKinds) {
            const panel=document.querySelector(`[data-component-panel="${kind}"]`), placeholder=document.querySelector(`[data-loading-section="${kind}"]`);
            panel.toggleAttribute('data-loading',loading);panel.setAttribute('aria-busy',String(inventoryLoading));
            placeholder.hidden=!loading;
            placeholder.querySelector('p').textContent=inventoryError?'These choices could not be loaded. Retry loading above.':`Loading ${kind==='tables'?'database tables':kind==='media'?'media library':kind}…`;
            if(loading)$(kind+'-summary').textContent=$('enable-'+kind).checked?(inventoryError?'Loading failed':'Loading choices…'):'Not included';
        }
    }
    function comparisonRelevant(kind) {
        if(!presetChosen)return kind!=='media';
        return $('enable-'+kind).checked && (kind==='media'?$('media-mode').value==='all' && !$('media-force').checked:!$(kind+'-force').checked && !$('file-verify').checked);
    }
    function invalidateComparisons() {
        comparisonGeneration++;comparisonState?.controller?.abort();
    }
    async function pauseComparisons() {
        invalidateComparisons();
        await comparisonPromise.catch(()=>{});
    }
    function startComparisons() {
        if(!presetsAvailable() || session || busy || transferView || sites.local.bounded_work!==2 || sites.remote.bounded_work!==2)return;
        if(!comparisonState || comparisonState.generation!==comparisonGeneration || comparisonState.inventory!==sites || comparisonState.direction!==direction()) {
            comparisonState={generation:comparisonGeneration,inventory:sites,direction:direction(),site:$('remote-site').value,id:crypto.randomUUID().replaceAll('-',''),done:new Set(),failed:new Set(),running:false};
        }
        const state=comparisonState;if(state.running || state.throttled)return;
        const src=state.direction==='pull'?state.inventory.remote:state.inventory.local, dst=state.direction==='pull'?state.inventory.local:state.inventory.remote;
        const current=()=>state.generation===comparisonGeneration && state.inventory===sites && state.site===$('remote-site').value && state.direction===direction() && !session && !busy;
        state.running=true;
        comparisonPromise=comparisonPromise.catch(()=>{}).then(async()=>{
            while(current()) {
                let task=null;
                for(const kind of ['plugins','themes']) {
                    if(!comparisonRelevant(kind))continue;
                    const item=src[kind].find(item=>!item.protected && !item.follow_symlinks && dst[kind].some(target=>target.id===item.id) && !item.content_hash && !state.done.has(kind+'/'+item.id) && !state.failed.has(kind+'/'+item.id));
                    if(item){task={kind,item,key:kind+'/'+item.id};break;}
                }
                if(!task && comparisonRelevant('media') && !state.done.has('media') && !state.failed.has('media') && src.media_compare && dst.media_compare)task={kind:'media',key:'media'};
                if(!task)break;
                const status=task.kind==='media'?$('media-comparison-status'):$('hash-status-'+task.kind);
                status.textContent=task.kind==='media'?'Comparing media in the background…':`Comparing ${task.item.name || task.item.id}…`;
                try {
                    let result;
                    do {
                        if(!current() || !comparisonRelevant(task.kind))break;
                        state.controller=new AbortController();
                        const wait=Math.max(0,(state.nextRequestAt || 0)-Date.now());
                        if(wait)await new Promise(resolve=>{
                            const signal=state.controller.signal;let timer;
                            const done=()=>{clearTimeout(timer);signal.removeEventListener('abort',done);resolve();};
                            signal.addEventListener('abort',done,{once:true});timer=setTimeout(done,wait);
                        });
                        if(!current() || !comparisonRelevant(task.kind)) { state.controller=null;break; }
                        state.nextRequestAt=Date.now()+1000;
                        result=await api(task.kind==='media'?'warm_media':'compare_component',{site:state.site,direction:state.direction,comparison:state.id,...(task.item?{kind:task.kind,component:task.item.id}:{})},null,state.controller);
                        state.controller=null;
                    } while(result.continue);
                    if(!current())break;
                    if(!result || result.continue)continue;
                    state.done.add(task.key);
                    if(task.item) {
                        task.item.content_hash=result.source;
                        const target=dst[task.kind].find(item=>item.id===task.item.id);if(target)target.content_hash=result.destination;
                        const selected=new Set([...document.querySelectorAll(`input[data-kind="${task.kind}"]:checked`)].map(e=>e.value));
                        components(task.kind);document.querySelectorAll(`input[data-kind="${task.kind}"]`).forEach(input=>input.checked=selected.has(input.value));
                        status.textContent='Versions and content hashes compared. Changes are checked again before transferring.';
                        lockSelection();updateSelection();
                    } else status.textContent=`${Number(result.stats?.files_selected || 0).toLocaleString()} media files new or different. Checked again before transfer.`;
                } catch(error) {
                    if(!current())break;
                    if(error.retryAfter>0) {
                        state.throttled=true;
                        status.textContent='The site requested a cooldown. Background comparisons paused. Use Refresh versions to retry after the cooldown.';
                        break;
                    }
                    state.failed.add(task.key);
                    status.textContent=`Comparison will be retried when transferring. ${error.message}`;
                }
            }
        }).finally(()=>{state.running=false;});
    }
    function renderConnections(state) {
        const changed=JSON.stringify(state.connections)!==JSON.stringify(connectionState.connections) || state.selected!==connectionState.selected || !$('saved-sites').children.length;
        connectionState = state; session = state.session;
        if(state.selected!==$('remote-site').value && !session)sites=null;
        $('remote-site').value = state.selected;
        renderRecentMigrations();
        $('retained-note').hidden = !session;
        if(changed)$('saved-sites').innerHTML=state.connections.map(site=>`<div class="csm-saved-site" data-saved-site="${esc(site.id)}">
            <input type="radio" name="csm-saved-site" data-select-site="${esc(site.id)}" aria-label="Select ${esc(site.name)}" ${site.id===state.selected?'checked':''}>
            <div class="csm-saved-site-details"><button type="button" class="csm-connection-name" data-rename-site="${esc(site.id)}" data-connection-control title="Rename connection" aria-label="Rename ${esc(site.name)}">${esc(site.name)}</button>
            <form data-connection-editor="${esc(site.id)}" hidden><label><span class="screen-reader-text">Connection label</span><input type="text" name="name" value="${esc(site.name)}" maxlength="120" required data-connection-control></label><div class="csm-connection-actions"><button type="submit" data-connection-control>Save</button><button type="button" data-cancel-rename data-connection-control>Cancel</button></div></form>
            <small>${esc(site.url)}</small></div><button type="button" class="csm-remove-connection" data-remove-site="${esc(site.id)}" data-connection-control aria-label="Remove ${esc(site.name)}">Remove</button></div>`).join('');
        $('workspace').hidden=false;renderEndpoints();
    }
    function clearInventory() {
        inventoryGeneration++;invalidateComparisons();presetRequest++;presetLoading=false;presetChosen=false;activePreset='';presetBaseline=null;presetUndo=null;inventoryError='';
        sites = null; remoteUpdate = null; resetComponents(); $('ignore-active-plugins').checked = false;
        $('media-mode').value = 'none'; $('media-verify').checked = false; $('media-force').checked = false; $('file-verify').checked = false; for (const kind of ['plugins','themes']) if ($(kind + '-force')) $(kind + '-force').checked = false; $('since').value = ''; $('media-after').value = ''; $('media-before').value = ''; $('replace').checked = true; $('recent').value = '';
        renderReplacements([]); renderMedia();
        for (const kind of ['plugins', 'themes', 'tables', 'media-list']) $(kind).replaceChildren();
        renderEndpoints();renderPresetChoices();
        $('version-note').textContent = ''; $('version-note').hidden = true;
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
        $('replacement-status').textContent = !available ? 'Resolve the Trickle version conflict to edit replacements.' : !$('replace').checked ? 'Find & replace is off.' : `${replacementRules.filter(rule => rule.enabled).length} rules enabled.`;
    }
    function currentSelection() {
        const selection = {transport:$('transport').value, enabled_components:componentKinds.filter(kind => $('enable-' + kind).checked), expanded_components:componentKinds.filter(kind => !$(kind + '-body').hidden), media:$('enable-media').checked ? $('media-mode').value : 'none', media_after:$('media-after').value, media_before:$('media-before').value, replace:$('replace').checked, sync_active_plugins:!$('ignore-active-plugins').checked, since:$('since').value ? Math.floor(new Date($('since').value + 'Z').getTime()/1000) : 0};
        for (const kind of ['plugins','themes']) selection[kind + '_force'] = $('enable-' + kind).checked && !!$(kind + '-force')?.checked;
        selection.file_verify = ($('enable-plugins').checked || $('enable-themes').checked) && !!$('file-verify')?.checked;
        selection.media_force = selection.media === 'all' && !!$('media-force')?.checked;
        selection.media_verify = selection.media === 'all' && !selection.media_force && !!$('media-verify')?.checked;
        for (const kind of ['plugins','themes','tables','media_ids']) selection[kind] = $('enable-' + (kind === 'media_ids' ? 'media' : kind)).checked ? [...document.querySelectorAll(`input[data-kind="${kind}"]:checked`)].map(input => kind === 'media_ids' ? Number(input.value) : input.value) : [];
        selection.follow_symlinks = !sites ? [] : ['plugins','themes'].flatMap(kind => source()[kind].filter(item => item.follow_symlinks && selection[kind].includes(item.id)).map(item => kind+'/'+item.id));
        if (sites?.replacements) selection.replacements = replacementRules.map(rule => ({...rule}));
        return selection;
    }
    function restoreSelection(selection, strict = true) {
        presetChosen=true;
        if (!session && sites) for (const kind of ['plugins','themes']) {
            let changed=false;
            for (const item of source()[kind]) { if (item.follow_symlinks) { delete item.follow_symlinks; changed=true; } }
            if (changed) components(kind);
        }
        if (strict) {
            const missing = [];
            for (const kind of ['plugins', 'themes', 'tables', 'media_ids']) {
                const available = new Set([...document.querySelectorAll(`input[data-kind="${kind}"]`)].map(input => input.value));
                for (const id of selection[kind] || []) if (!available.has(String(id))) missing.push(String(id));
            }
            if (missing.length) throw new Error(`Cannot reuse these settings: ${missing.slice(0, 5).join(', ')} are absent from the current source inventory. Make a new selection instead.`);
        }
        document.querySelectorAll('input[data-kind]').forEach(input => { input.checked = input.dataset.protected !== 'true' && (selection[input.dataset.kind] || []).map(String).includes(input.value); });
        for (const kind of componentKinds) {
            const hasItems = kind === 'media' ? selection.media !== 'none' : (selection[kind] || []).length > 0;
            setComponent(kind, hasItems || (selection.enabled_components || []).includes(kind));
            if (Array.isArray(selection.expanded_components)) setPanelOpen(kind, $('enable-' + kind).checked && selection.expanded_components.includes(kind));
        }
        showComponent(componentKinds.find(kind => !$(kind + '-body').hidden) || 'tables');
        for (const kind of ['plugins','themes']) if ($(kind + '-force')) $(kind + '-force').checked = !!(selection[kind + '_force'] ?? selection.file_force);
        $('transport').value = selection.transport || 'stream'; $('file-verify').checked = !!selection.file_verify; $('ignore-active-plugins').checked = selection.sync_active_plugins === false;
        $('media-mode').value = ['compare','changed'].includes(selection.media) && !session ? 'all' : selection.media; $('media-force').checked = !!selection.media_force; $('media-verify').checked = !!selection.media_verify; $('replace').checked = selection.replace;
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
        presetUndo=null;presetBaseline=null;activePreset='';presetChosen=!!session;renderPresetChoices();$('preset').value = ''; $('preset-status').textContent = 'Choose a starting scope above. Switch presets here at any time.';
        resetComponents(); $('media-mode').value = 'none'; $('media-force').checked = false;
        if (session) $('direction').value = session.direction;
        labelRoute();
        $('workspace').hidden = false;
        renderEndpoints();
        $('version-note').textContent = [source().wordpress !== destination().wordpress ? 'WordPress versions differ; core is not transferred.' : '', direction() === 'push' && !sites.remote.incoming_writes ? 'Enable incoming writes on the remote site before pushing.' : ''].filter(Boolean).join(' ');
        $('version-note').hidden = !$('version-note').textContent;
        renderReplacements(sites.replacements?.[direction()] ?? []);
        $('tables').replaceChildren();
        for (const kind of ['plugins','themes']) for (const item of source()[kind]) {
            item.follow_symlinks = !!session && (session.selection?.follow_symlinks || []).includes(kind+'/'+item.id);
        }
        for (const kind of ['plugins','themes','tables']) components(kind);
        mediaAnchor = null; renderMediaGrid();
        renderMedia();
        if (session?.selection) restoreSelection(session.selection, false);
        lockSelection(); updateSelection(); renderSkippedFolders();
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
        const generation=++inventoryGeneration, site=$('remote-site').value, revision=connectionRevision;
        inventoryLoading=true;inventoryError='';invalidateComparisons();presetRequest++;presetLoading=false;
        $('inventory-retry').hidden=true;renderSectionLoading();updateSetup();
        try {
            const next=inventory || await api('manifest',{refresh,site});
            if(generation!==inventoryGeneration || site!==$('remote-site').value)return;
            if(next.state && revision===connectionRevision)renderConnections(next.state);
            sites=next;remoteUpdate=null;
            if(versionsMatch() && transferVersionMatches())await loadSelectedMedia(session?.selection || preserve);
            if(generation!==inventoryGeneration || site!==$('remote-site').value)return;
            inventoryLoading=false;render();
            if(preserve)restoreSelection(preserve);
            if(session && summary?.id!==session.id){summary=await api('status',{id:session.id});showSummary();}
            $('connect-status').textContent='Paste a code to connect.';
            if(session?.automatic)await migrate();
        } catch(error) {
            if(generation!==inventoryGeneration)return;
            inventoryError=error.message;$('inventory-retry').hidden=false;
            if(session)throw error;
        } finally {
            if(generation===inventoryGeneration){inventoryLoading=false;renderSectionLoading();updateSelection();startComparisons();}
        }
    }
    function startInventoryLoad(refresh=false) { return load(refresh).catch(fail); }

    function renderSkippedFolders() {
        const folders = summary?.skipped_folders || [];
        const container = $('skipped-folders');
        container.hidden = !folders.length;
        const groups = {plugins:[], themes:[], folders:[]};
        for (const path of folders) {
            const [kind, ...parts] = path.split('/');
            const group = kind === 'plugins' || kind === 'themes' ? kind : 'folders';
            const item = sites && group !== 'folders' ? source()[kind]?.find(item => item.id === parts.join('/')) : null;
            groups[group].push(`<li title="${esc(path)}">${esc(item?.name || path)}</li>`);
        }
        container.innerHTML = Object.entries(groups).filter(([, items]) => items.length).map(([kind, items]) =>
            `<div class="csm-skipped-group"><h3>Skipped ${kind}</h3><ul>${items.join('')}</ul></div>`
        ).join('');
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
        renderSkippedFolders();
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
        try { await load(); }
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
        $('auth-username').value='';$('auth-password').value='';$('auth-remove').checked=false;
        $('auth-remove-label').hidden=!peer?.has_http_auth;$('site-protection').open=!!peer?.has_http_auth;
        $('auth-help').textContent=peer?.has_http_auth?'Site-protection credentials are saved. Leave both fields blank to keep them.':'Use the hosting provider’s site-protection credentials.';
        $('connect-status').textContent='Paste a code to connect.';$('connect-status').dataset.tone='';
        $('connection-dialog').showModal();$('remote-code').focus();
    }
    async function selectSite(id) {
        if(busy || session)return;
        $('site-menu').open=false;
        await run(async()=>{
            try{renderConnections(await api('select',{site:id}));}
            catch(error){renderConnections(connectionState);throw error;}
            clearInventory();startInventoryLoad();$('site-menu-toggle').focus();
        });
    }
    function closeConnectionEditor(form) {
        form.hidden=true;
        const button=form.closest('[data-saved-site]').querySelector('[data-rename-site]');
        button.hidden=false;button.focus();
    }
    $('saved-sites').addEventListener('click',event=>{
        if(busy || session)return;
        const rename=event.target.closest('[data-rename-site]');
        if(rename){
            for(const form of $('saved-sites').querySelectorAll('[data-connection-editor]')){
                form.hidden=true;form.closest('[data-saved-site]').querySelector('[data-rename-site]').hidden=false;
            }
            const form=rename.closest('[data-saved-site]').querySelector('form');
            form.elements.name.value=connectionState.connections.find(site=>site.id===rename.dataset.renameSite).name;
            rename.hidden=true;form.hidden=false;form.elements.name.focus();form.elements.name.select();
        }
        if(event.target.closest('[data-cancel-rename]'))closeConnectionEditor(event.target.closest('form'));
        const remove=event.target.closest('[data-remove-site]');
        if(remove){
            const peer=connectionState.connections.find(site=>site.id===remove.dataset.removeSite);
            if(!confirm(`Remove “${peer.name}” from saved connections? This only forgets this connection. The remote site and backups are unchanged. You will need its connection code to add it again.`))return;
            const selected=connectionState.selected===peer.id;
            run(async()=>{
                await api('remove_connection',{site:peer.id});
                if(selected){clearInventory();if(connectionState.selected)startInventoryLoad();}
                feedback('Saved connection removed.');
                const target=$('site-menu').hidden?document.querySelector('[data-add-site]'):$('site-menu-toggle');target?.focus();
            });
        }
    });
    $('saved-sites').addEventListener('submit',event=>{
        const form=event.target.closest('[data-connection-editor]');if(!form)return;
        event.preventDefault();if(busy || session)return;
        const name=form.elements.name.value.trim();
        if(!name){form.elements.name.focus();return;}
        const id=form.dataset.connectionEditor;
        run(async()=>{
            await api('rename_connection',{site:id,name});feedback('Connection label saved.');
            $('saved-sites').querySelector(`[data-rename-site="${id}"]`)?.focus();
        });
    });
    $('saved-sites').addEventListener('keydown',event=>{
        const form=event.target.closest('[data-connection-editor]');
        if(event.key==='Escape' && form && !busy){event.preventDefault();event.stopPropagation();closeConnectionEditor(form);}
    });
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
        clearTimeout(connectTimer);$('remote-code').value='';$('auth-username').value='';$('auth-password').value='';
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
                const data={pairing:code,name:$('remote-name').value};
                if($('auth-remove').checked)data.http_auth={};
                else if($('auth-username').value || $('auth-password').value)data.http_auth={username:$('auth-username').value,password:$('auth-password').value};
                const result=await api('connect',data);
                $('remote-code').value='';$('remote-name').value='';
                renderConnections(result.state);clearInventory();startInventoryLoad();
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
        if(!$('site-protection').open)connectTimer=setTimeout(connectFromCode,600);
    });
    $('site-protection').addEventListener('toggle',()=>{if($('site-protection').open)clearTimeout(connectTimer);});
    $('connect-site').addEventListener('click',()=>{clearTimeout(connectTimer);connectFromCode();});
    $('check-remote-update').addEventListener('click', () => run(async () => {
        remoteUpdate = await api('remote_update_info', {site:$('remote-site').value}); renderVersionStatus();
    }));
    async function installLocalBuild() {
        if (!sites?.remote.remote_build_update || !sites.remote.can_update_trickle || session) return;
        $('site-menu').open=false;feedback('Syncing remote Trickle…');
        try { await api('update_remote',{site:$('remote-site').value}); }
        catch(error) {
            remoteUpdate={retained:/Finish and discard retained transfers/.test(error.message),syncError:true};renderVersionStatus();
            throw error;
        }
        await load();feedback('Remote Trickle matches this site.');
    }
    async function refreshSourcePreparations() {
        const result=await api('retained_sources');
        $('source-preparations-status').textContent=result.sources.length?`${result.sources.length} retained preparation${result.sources.length===1?'':'s'}`:'No retained source preparations.';
        $('source-preparations-list').innerHTML=result.sources.map(item=>`<div class="csm-backup-row"><div><strong>${item.snapshot?'Source snapshot':'Read-only comparison'}</strong><p>${esc(item.kinds.join(' + '))} · ${bytes(item.bytes)} · ${esc(transferDate(item.created))}</p><small>${esc(item.id)}</small>${!item.can_discard?'<p>Resolve this transfer using its recovery controls first.</p>':''}</div><button type="button" class="button" data-discard-source="${esc(item.id)}" ${item.can_discard?'':'disabled'}>Cancel preparation</button></div>`).join('');
    }
    $('refresh-source-preparations').addEventListener('click',()=>run(refreshSourcePreparations));
    $('source-preparations').addEventListener('toggle',()=>{if($('source-preparations').open)run(refreshSourcePreparations);});
    $('source-preparations-list').addEventListener('click',event=>{
        const button=event.target.closest('[data-discard-source]');if(!button)return;
        if(!confirm('Cancel this source preparation and discard its export? Destination backups will be kept. Any connected site using it will need to cancel or prepare again.'))return;
        run(async()=>{await api('discard_source_preparation',{id:button.dataset.discardSource});await refreshSourcePreparations();feedback('Source preparation cancelled. Retry the Trickle sync from your controlling site.');});
    });
    if(location.hash==='#csm-source-preparations')$('source-preparations').open=true;
    $('clear-update-comparisons').addEventListener('click',()=>run(async()=>{
        if(!remoteUpdate?.retained || session || !sites?.remote.can_update_trickle)return;
        await pauseComparisons();let offset=0,cleared=0,result;
        do {
            feedback('Checking completed comparisons on the remote site…');
            result=await api('clear_update_comparisons',{site:$('remote-site').value,offset});
            offset=result.checked;cleared+=result.cleared;
        } while(result.continue);
        remoteUpdate=await api('remote_update_info',{site:$('remote-site').value});
        remoteUpdate.retained=!remoteUpdate.ready;renderVersionStatus();
        if(!remoteUpdate.ready)throw new Error(`${cleared} completed comparisons cleared. ${remoteUpdate.reason} Open remote Trickle to resolve the remaining transfer; its recovery data has been kept.`);
        feedback(`${cleared} completed comparisons cleared. Retry Sync remote Trickle.`);
    }));
    $('install-local-build').addEventListener('click',()=>run(installLocalBuild));
    $('update-remote').addEventListener('click', () => run(async () => {
        if (sites?.remote.remote_build_update) { await installLocalBuild(); return; }
        if (!remoteUpdate?.can_update_to_target) return;
        if (!confirm(`Update Trickle on ${sites.remote.url} from ${sites.remote.plugin_version} to ${sites.local.plugin_version}? This updates the migration plugin before any transfer begins.`)) return;
        feedback('Updating remote Trickle through its configured JSON feed…');
        await api('update_remote', {site:$('remote-site').value});
        await load(true); feedback('Remote Trickle updated. Versions match; choose what to transfer.');
    }));
    $('collapse-all').addEventListener('click',()=>{for(const kind of componentKinds)setPanelOpen(kind,false);});
    $('preset-undo').addEventListener('click',()=>{if(!presetUndo || busy || session)return;const saved=presetUndo;restoreSelection(saved);for(const kind of componentKinds){setComponent(kind,saved.enabled_components.includes(kind));setPanelOpen(kind,saved.expanded_components.includes(kind));}presetChosen=saved._ui.chosen;presetBaseline=saved._ui.baseline;activePreset=saved._ui.active;presetUndo=null;document.querySelectorAll('[data-preset-choice]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.presetChoice===activePreset)));$('preset-status').textContent='Previous selections restored.';updateSelection();});
    $('preset-choices').addEventListener('click',event=>{const button=event.target.closest('[data-preset-choice]');if(button)applyPreset(button.dataset.presetChoice).catch(fail);});
    $('table-order').addEventListener('change',()=>{tableSort.key=$('table-order').value;tableSort.descending=tableSort.key!=='name';renderTables();updateSelection();});
    document.querySelectorAll('[data-media-mode]').forEach(input=>input.addEventListener('change',()=>{if(input.checked){$('media-mode').value=input.dataset.mediaMode;$('media-mode').dispatchEvent(new Event('change'));}}));
    for(const kind of ['plugins','themes']) {
        $('show-matching-'+kind).addEventListener('change',()=>filterComponents(kind));
        $('show-hashes-'+kind).addEventListener('change',()=>$(kind).classList.toggle('csm-show-hashes',$('show-hashes-'+kind).checked));
    }
    $('workspace').addEventListener('click',event=>{
        const button=event.target.closest('[data-verify-matching]');if(!button)return;
        run(async()=>{
            await pauseComparisons();
            const kind=button.dataset.verifyMatching, dst=new Map(destination()[kind].map(item=>[item.id,item]));
            const checked=new Set([...document.querySelectorAll(`input[data-kind="${kind}"]:checked`)].map(e=>e.value));
            for(const item of source()[kind]) {
                const target=dst.get(item.id);if(item.protected || !target || item.version!==target.version)continue;
                $('hash-status-'+kind).textContent='Verifying '+item.name+'…';
                const comparison=crypto.randomUUID().replaceAll('-','');let result;
                do { result=await api('compare_component',{site:$('remote-site').value,direction:direction(),kind,component:item.id,comparison}); } while(result.continue);
                item.content_hash=result.source;target.content_hash=result.destination;
            }
            components(kind);document.querySelectorAll(`input[data-kind="${kind}"]`).forEach(e=>e.checked=checked.has(e.value));
            $('hash-status-'+kind).textContent='Matching requires equal versions and verified content hashes.';updateSelection();
        });
    });
    $('preset').addEventListener('change', () => applyPreset($('preset').value).catch(fail));
    document.querySelectorAll('[data-search-kind]').forEach(input => input.addEventListener('input', () => filterComponents(input.dataset.searchKind)));
    document.querySelectorAll('[data-filter-kind]').forEach(input => input.addEventListener('change', () => filterComponents(input.dataset.filterKind)));
    $('refresh').addEventListener('click', () => { $('site-menu').open=false;startInventoryLoad(true); });
    $('inventory-retry').addEventListener('click',()=>startInventoryLoad());
    $('recent-list').addEventListener('click',event=>{const button=event.target.closest('[data-reuse-recent]');if(button)reuseRecent(button.dataset.reuseRecent).catch(fail);});
    $('flip-direction').addEventListener('click',()=>{
        if(busy || session || !connectionState.selected)return;
        $('direction').value=direction()==='pull'?'push':'pull';$('direction').dispatchEvent(new Event('change'));
    });
    $('direction').addEventListener('change', () => { try{localStorage.setItem('trickle-last-direction',direction());}catch{} invalidateComparisons();presetRequest++;presetLoading=false;presetChosen=false;activePreset='';$('media-mode').value='none';$('replace').checked=true;$('recent').value='';if(sites)render();else{renderPresetChoices();renderEndpoints();updateSetup();}renderRecentMigrations();startComparisons(); });
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
    $('workspace').addEventListener('click', event => {
        const button=event.target.closest('[data-follow-links]');
        if (!button) return;
        event.preventDefault(); event.stopPropagation();
        if (busy || session) return;
        const kind=button.dataset.followKind, id=button.dataset.followLinks;
        const item=source()[kind].find(item=>item.id===id);
        if (!item?.symlinks || !confirm(`Include linked files from ${item.name}? Their contents will be copied as ordinary files. Exclusions still apply.`)) return;
        const checked=new Set([...document.querySelectorAll(`input[data-kind="${kind}"]:checked`)].map(input=>input.value));
        checked.add(id); item.follow_symlinks=true; components(kind);
        document.querySelectorAll(`input[data-kind="${kind}"]`).forEach(input=>{input.checked=input.dataset.protected!=='true' && checked.has(input.value);});
        $('recent').value=''; lockSelection(); updateSelection();
    });
    document.addEventListener('scroll', event => {
        const scroller = event.target;
        if (scroller.matches?.('.csm-table-scroll, .csm-code-scroll')) {
            scroller.classList.toggle('csm-scrolled', scroller.scrollTop > 0);
        }
    }, {capture:true, passive:true});
    $('workspace').addEventListener('change', event => {
        if (!session && !$('completion').hidden) { $('completion').hidden = true; $('transfer-meter').hidden = true; }
        const checkbox = event.target;
        if (checkbox.matches('input[data-component]')) {
            if (busy || session) return;
            const kind=checkbox.dataset.component;
            const initialize=checkbox.checked && (kind==='media'?$('media-mode').value==='none':!document.querySelector(`input[data-kind="${kind}"]:checked`));
            setComponent(kind, checkbox.checked, initialize);
            $('recent').value = '';
        }
        if (checkbox.matches('.csm-select-all')) {
            document.querySelectorAll(`input[data-kind="${checkbox.dataset.selectKind}"]:not(:disabled)`).forEach(input => { input.checked = checkbox.checked; });
        }
        updateSelection();startComparisons();
    });
    document.querySelectorAll('.csm-panel-disclosure').forEach(button => button.addEventListener('click', () => {
        setPanelOpen(button.dataset.disclose, $(button.dataset.disclose + '-body').hidden);
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
        if(!session)await pauseComparisons();
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
            // Transfer prepares its immutable snapshot first; all streams must still stage before commit.
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
    $('direction').value = session?.direction || (()=>{try{return localStorage.getItem('trickle-last-direction');}catch{return null;}})() || connectionState.recents.find(item => ['push','pull'].includes(item.direction))?.direction || 'pull';
    renderConnections(connectionState);renderPresetChoices();updateSelection();
    if (CSM.state.recovery_error) { fail(new Error(`Recovery needs attention: ${CSM.state.recovery_error}. The site remains protected. Check Diagnostics, then retry recovery.`)); const retry=document.createElement('button'); retry.type='button'; retry.className='button'; retry.textContent='Retry recovery'; retry.addEventListener('click',()=>run(async()=>{ let result; do { result=await api('recover_destination'); } while (result.continue); location.reload(); })); ($('error').querySelector('.csm-error-actions') || $('error')).append(retry); }
    else if (CSM.state.destination_recovery) run(async () => {
        feedback('Recovering…'); let result; do { result=await api('recover_destination'); } while (result.continue);
        if (connectionState.selected || session) await load();
        else feedback('Destination restored.');
    });
    else if(session)run(()=>load());
    else if(connectionState.selected)startInventoryLoad();
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
