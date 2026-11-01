<?php defined('ABSPATH') || exit; ?>
<div class="wrap csm csm-backups">
    <header class="csm-heading"><div class="csm-title-row"><h1>Trickle <span>Backups</span></h1></div><p>The three most recent transfer backups, stored on the destination.</p></header>
    <?php require __DIR__ . '/navigation.php'; ?>
    <?php if ($loader_error): ?><div class="notice notice-warning" role="alert"><p><?php echo esc_html($loader_error); ?></p></div><?php endif; ?>
    <div id="csm-error" class="notice notice-error" role="alert" hidden></div>
    <div class="csm-backup-toolbar"><label for="csm-backup-site">Backups on<select id="csm-backup-site"><option value="">This site · <?php echo esc_html(site_url()); ?></option></select></label><button type="button" class="button" id="csm-backup-refresh">Refresh</button></div>
    <p id="csm-backup-status" role="status" aria-live="polite">Loading backups…</p>
    <div id="csm-backup-items"></div>
    <p class="csm-note">Each backup covers its selected tables, plugins and themes, plus media files replaced by that transfer. Restoring overwrites later changes to those items.</p>
</div>
