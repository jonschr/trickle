<?php defined('ABSPATH') || exit; ?>
<div class="wrap csm csm-backups">
    <?php $show_connection_copy = false; require __DIR__ . '/app-header.php'; ?>
    <div class="csm-workspace-heading"><h2>Backups</h2><p>The three most recent transfer backups, stored on the destination.</p></div>
    <?php if ($loader_error): ?><div class="notice notice-warning" role="alert"><p><?php echo esc_html($loader_error); ?></p></div><?php endif; ?>
    <div id="csm-error" class="notice notice-error" role="alert" hidden></div>
    <div class="csm-backup-toolbar"><label for="csm-backup-site">Backups on<select id="csm-backup-site"><option value="">This site · <?php echo esc_html(site_url()); ?></option></select></label><button type="button" class="button" id="csm-backup-refresh">Refresh</button></div>
    <p id="csm-backup-status" role="status" aria-live="polite">Loading backups…</p>
    <div id="csm-backup-items"></div>
    <p class="csm-note">Each backup covers its selected tables, plugins and themes, plus media files replaced by that transfer. Restoring overwrites later changes to those items.</p>
</div>
