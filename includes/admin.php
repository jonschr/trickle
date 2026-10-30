<?php defined('ABSPATH') || exit; ?>
<div class="wrap csm">
    <header class="csm-heading"><div class="csm-title-row"><h1>Trickle <span>Preview 0.1</span></h1><button type="button" class="button" id="csm-copy"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg><span>Copy connection code</span></button></div><p>Choose what moves. Review it. Apply it to the destination.</p></header>
    <div id="csm-error" class="notice notice-error" role="alert" hidden></div>
    <p id="csm-feedback" class="csm-feedback" role="status" aria-live="polite" hidden></p>
    <details class="csm-card" id="csm-connection" open>
        <summary><span>Connection &amp; access</span><span id="csm-connection-summary" class="csm-summary">Connect another WordPress site</span></summary>
        <div class="csm-connection-grid csm-panel-body">
            <div><h2>This site</h2><p class="csm-site-url"><strong><?php echo esc_html(site_url()); ?></strong></p>
                <label><input type="checkbox" id="csm-allow-read" <?php checked(!empty($settings['pull'])); ?>> Allow authenticated remote reads</label>
                <label><input type="checkbox" id="csm-allow-write" <?php checked(!empty($settings['push'])); ?>> Allow authenticated incoming writes</label>
                <p id="csm-permissions-status" class="csm-inline-status" role="status" aria-live="polite">Permission changes save automatically.</p>
                <p>The first line identifies this site; the second line is its private key. Copy both lines into the controlling site.</p>
                <textarea id="csm-pairing" readonly rows="3" aria-label="This site's private connection code" hidden><?php echo esc_textarea(self::pairing()); ?></textarea>
                <button type="button" class="button-link" id="csm-reveal" aria-controls="csm-pairing" aria-expanded="false">Show connection code</button>
                <p><button type="button" class="button" id="csm-rotate">Rotate connection key</button></p>
            </div>
            <div><h2>Remote site</h2><label for="csm-remote-code">Connection code from the remote site's Trickle page</label><textarea id="csm-remote-code" rows="4" autocomplete="off" spellcheck="false" aria-describedby="csm-connect-status"></textarea><p id="csm-connect-status" class="csm-inline-status" role="status" aria-live="polite">Paste a complete connection code to connect automatically.</p><p>Check the site URL on the first line before pasting. Keep the connection code private.</p>
                <p>The controlling site initiates both push and pull. The remote site never needs to reach your local URL.</p>
            </div>
        </div>
    </details>
    <div id="csm-workspace" hidden>
        <section class="csm-card csm-route" aria-label="Transfer route">
            <div class="csm-direction"><label for="csm-direction">Transfer direction</label><select id="csm-direction"><option value="pull">Pull from remote</option><option value="push">Push to remote</option></select></div>
            <dl><div><dt>Source</dt><dd id="csm-source"></dd></div><div><dt>Destination</dt><dd id="csm-destination"></dd></div></dl>
            <button type="button" class="button" id="csm-refresh">Refresh versions</button>
        </section>
        <p class="csm-note" id="csm-version-note"></p>
        <section class="csm-actions" aria-label="Transfer actions">
            <div class="csm-action-copy"><h2 id="csm-action-title">Choose what to transfer</h2><p id="csm-selection-summary">No components selected</p><p id="csm-status" role="status" aria-live="polite">Nothing changes on the live destination until you apply the staged transfer.</p><progress id="csm-progress" aria-label="Transfer in progress" hidden></progress></div>
            <div class="csm-buttons"><button type="button" id="csm-stage" class="button button-primary">Stage transfer</button><button type="button" id="csm-apply" class="button button-primary" hidden>Apply staged transfer</button><button type="button" id="csm-rollback" class="button" hidden>Restore destination backup</button><button type="button" id="csm-discard" class="button" hidden>Discard transfer &amp; backup</button></div>
        </section>
        <div class="csm-panels">
            <details class="csm-card csm-panel" open><summary><span>Plugins</span><span id="csm-plugins-summary" class="csm-summary">None selected</span></summary><div class="csm-panel-body"><p class="csm-panel-intro">Compare installed versions and available updates on both sites.</p><div id="csm-plugins"></div></div></details>
            <details class="csm-card csm-panel"><summary><span>Themes</span><span id="csm-themes-summary" class="csm-summary">None selected</span></summary><div class="csm-panel-body"><p class="csm-panel-intro">Parent themes are selected with their child theme.</p><div id="csm-themes"></div></div></details>
            <details class="csm-card csm-panel"><summary><span>Database tables</span><span id="csm-tables-summary" class="csm-summary">None selected</span></summary><div class="csm-panel-body"><p class="csm-panel-intro">Selected tables replace their destination counterparts. Rows are not merged; counts are estimates.</p><div id="csm-tables"></div><label class="csm-option"><input type="checkbox" id="csm-replace" checked> Replace source URLs and paths, including serialized values</label></div></details>
            <details class="csm-card csm-panel"><summary><span>Media library</span><span id="csm-media-summary" class="csm-summary">No media files</span></summary><div class="csm-panel-body"><label for="csm-media-mode">Files to transfer</label><select id="csm-media-mode"><option value="none">No media files</option><option value="all">All media files</option><option value="changed">Files changed since the last successful media transfer</option><option value="selected">Selected attachments and their image sizes</option></select><div id="csm-since-wrap" hidden><label for="csm-since">Changed after (UTC)</label><input type="datetime-local" id="csm-since"><p id="csm-watermark-note"></p></div><div id="csm-media-list" hidden></div><p>Media files and attachment database records are separate. Include posts/postmeta when bringing over new attachments. Changed files are detected by modification time; deleted files are not synchronized.</p></div></details>
        </div>
        <details class="csm-requirements"><summary>Connection requirements &amp; transfer limits</summary><p>Both sites need this plugin and 64-bit PHP 8.1+, cURL, writable private temporary storage, and writable selected destination files. Production connections require verified HTTPS. Endpoint: <code>?trickle=…</code>; requests use signed <code>X-CSM-*</code> headers.</p><p>This preview supports single sites and InnoDB tables without foreign keys. It transfers selected plugins, themes, tables and media, not WordPress core or wp-config.php. Destination URLs, Trickle credentials, plugin activation state and cron schedule are preserved. Database transfers can replace user accounts and other integration settings; keep destination login access and backups available.</p><p>Long streams can still hit hosting timeouts and upload limits. Transfers stage privately and validate checksums before applying; retry an interrupted stream from its beginning. Rollback copies remain until you discard the transfer. Rollback restores the earlier data and will overwrite changes made after applying.</p></details>
    </div>
</div>
