/* global CSM, CSMRequest */
(() => {
    'use strict';
    const $ = id => document.getElementById(`csm-${id}`);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let inventory = null, busy = false;
    async function api(op, data = {}) {
        const result = await CSMRequest(op, data);
        if (result?.nonce) CSM.nonce = result.nonce;
        return result;
    }
    async function run(fn) {
        if (busy) return;
        busy = true; $('error').hidden = true;
        document.querySelector('.csm').setAttribute('aria-busy', 'true');
        document.querySelectorAll('.csm button, .csm select').forEach(el => { el.disabled = true; });
        try { await fn(); }
        catch (error) { $('error').textContent = error.message; $('error').hidden = false; }
        finally {
            busy = false; document.querySelector('.csm').setAttribute('aria-busy', 'false');
            document.querySelectorAll('.csm button, .csm select').forEach(el => { el.disabled = false; });
        }
    }
    async function load() {
        $('backup-status').textContent = 'Loading backups…'; $('backup-items').replaceChildren();
        inventory = await api('backups', {site:$('backup-site').value});
        $('backup-items').innerHTML = inventory.backups.map((item, i) => {
            const date = new Date(item.created * 1000).toLocaleString(undefined, {dateStyle:'medium',timeStyle:'medium'});
            const counts = [[item.tables.length,'table'],[item.plugins.length,'plugin'],[item.themes.length,'theme']].filter(([count]) => count).map(([count,label]) => `${count} ${label}${count === 1 ? '' : 's'}`);
            if (item.media) counts.push('Replaced media');
            const names = [...item.tables, ...item.plugins, ...item.themes].join(', ');
            return `<article class="csm-backup-row" id="csm-backup-${esc(item.id)}"${new URLSearchParams(location.search).get('backup') === item.id ? ' tabindex="-1" data-highlighted="true"' : ''}><div><h2>Before ${esc(date)}${i === 0 ? ' <span class="csm-backup-latest">Latest</span>' : ''}</h2><p>${esc(counts.join(' · '))}</p>${item.temporary ? '<p class="csm-note">Temporary storage; the host may clear these files.</p>' : ''}${item.source ? `<p class="csm-backup-source">From ${esc(item.source)}</p>` : ''}<details><summary>Included items</summary><p>${esc(names || 'Media files')}${item.media && names ? ', replaced media files' : ''}</p></details></div><button type="button" class="button" data-restore-backup="${esc(item.id)}">Restore</button></article>`;
        }).join('');
        $('backup-status').textContent = inventory.backups.length ? `${inventory.backups.length} of 3 backups · ${inventory.destination}` : 'No backups yet.';
        const selectedBackup = $('backup-items').querySelector('[data-highlighted]');
        if (selectedBackup) { selectedBackup.focus({preventScroll:true}); selectedBackup.scrollIntoView({block:'nearest'}); }
        if (inventory.recovery) await restore(inventory.recovery.backup_id, false);
    }
    async function restore(id, ask = true) {
        const item = inventory.backups.find(backup => backup.id === id);
        if (!item) throw new Error('Refresh the backup list.');
        if (ask && !confirm(`Restore the backup from ${new Date(item.created * 1000).toLocaleString()} on ${inventory.destination}? Later changes to its included items will be overwritten.`)) return;
        $('backup-status').textContent = 'Restoring…';
        const site = $('backup-site').value;
        let stopped = false, timer;
        const poll = async () => {
            try {
                const progress = await api('backup_progress', {site,id});
                if (!stopped && progress.phase) $('backup-status').textContent = [progress.phase, progress.component].filter(Boolean).join(' · ');
            } catch (_) { /* The restore request and scheduled recovery remain authoritative. */ }
            if (!stopped) timer = setTimeout(poll, 1500);
        };
        timer = setTimeout(poll, 500);
        try {
            let result; do { result=await api('restore_backup', {site,id}); } while (result.continue);
            $('backup-status').textContent = 'Backup restored.';
        } finally { stopped = true; clearTimeout(timer); }
    }
    for (const peer of CSM.state.connections) {
        const option = document.createElement('option'); option.value = peer.id; option.textContent = `${peer.name} · ${peer.url}`; $('backup-site').append(option);
    }
    const selected = new URLSearchParams(location.search).get('site');
    if (CSM.state.connections.some(peer => peer.id === selected)) $('backup-site').value = selected;
    $('backup-site').addEventListener('change', () => run(load));
    $('backup-refresh').addEventListener('click', () => run(load));
    $('backup-items').addEventListener('click', event => {
        const button = event.target.closest('[data-restore-backup]');
        if (button) run(() => restore(button.dataset.restoreBackup));
    });
    run(load);
})();
