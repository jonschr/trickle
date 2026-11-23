<?php
defined( 'ABSPATH' ) || exit;
$view = in_array( $_GET['view'] ?? '', array( 'settings', 'backups', 'diagnostics' ), true ) ? $_GET['view'] : 'migrate';
?>
<nav class="nav-tab-wrapper csm-tabs" aria-label="Trickle pages">
	<?php
	foreach ( array(
		'migrate'     => 'Migrate',
		'backups'     => 'Backups',
		'settings'    => 'Settings',
		'diagnostics' => 'Diagnostics',
	) as $key => $label ) :
		?>
		<a class="nav-tab<?php echo $view === $key ? ' nav-tab-active' : ''; ?>"
									<?php
									if ( $view === $key ) {
										echo 'aria-current="page"';}
									?>
		data-csm-tab="<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( admin_url( 'tools.php?page=trickle' . ( $key === 'migrate' ? '' : '&view=' . $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
	<?php endforeach; ?>
</nav>
