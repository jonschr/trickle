/* Shared header and settings connection-code actions on every Trickle admin page. */
(() => {
    'use strict';
    const $ = id => document.getElementById(`csm-${id}`);
    for (const button of [$('copy'), $('copy-settings')].filter(Boolean)) {
        let copyResetTimer;
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText($('pairing').value);
                const label = button.querySelector('span');
                button.style.minWidth = `${button.offsetWidth}px`;
                label.textContent = 'Code copied';
                clearTimeout(copyResetTimer);
                copyResetTimer = setTimeout(() => { label.textContent = 'Copy connection code'; }, 2000);
            } catch (_) {
                if ($('connection')) {
                    document.querySelector('[data-csm-tab="settings"]').click();
                    $('connection').open = true;
                    $('reveal').setAttribute('aria-expanded', 'true');
                    $('reveal').textContent = 'Hide connection code';
                }
                $('pairing').hidden = false;
                $('pairing').focus(); $('pairing').select();
                $('feedback').textContent = 'Your browser could not copy automatically. The connection code is selected; copy it with your keyboard.';
                $('feedback').hidden = false;
            }
        });
    }
})();
