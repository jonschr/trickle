<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap csm csm-backups">
	<?php require __DIR__ . '/app-header.php'; ?>
	<div id="csm-app-notices">
	<?php
	if ( $loader_error ) :
		?>
		<div class="notice notice-warning inline" role="alert"><p><?php echo esc_html( $loader_error ); ?></p></div><?php endif; ?>
	<div id="csm-error" class="notice notice-error inline csm-error" role="alert" hidden></div>
		<p id="csm-feedback" class="csm-feedback" role="status" aria-live="polite" hidden></p>
	</div>
	<div class="csm-content">
	<textarea id="csm-pairing" readonly rows="3" aria-label="This site's private connection code" hidden><?php echo esc_textarea( self::pairing() ); ?></textarea>
	<div class="csm-workspace-heading"><h2>Backups</h2><p>The three most recent transfer backups, stored on the destination.</p></div>
	<div class="csm-backup-toolbar"><label for="csm-backup-site">Backups on<select id="csm-backup-site"><option value="">This site · <?php echo esc_html( site_url() ); ?></option></select></label><button type="button" class="button" id="csm-backup-refresh">Refresh</button></div>
	<p id="csm-backup-status" role="status" aria-live="polite">Loading backups…</p>
	<div id="csm-backup-items"></div>
	<p class="csm-note">Each backup covers its selected tables, plugins and themes, plus media files replaced by that transfer. Restoring overwrites later changes to those items.</p>
	</div>
</div>
