<?php
/**
 * Early request isolation and interrupted migration recovery.
 *
 * @package Trickle
 */

// TRICKLE_LOADER: generated on activation; normal site requests are unaffected.
defined( 'ABSPATH' ) || exit;
$csm_recovery_job  = get_option( '_csm_active_job' );
$csm_recovery_cron = defined( 'DOING_CRON' ) && DOING_CRON && $csm_recovery_job && in_array( get_option( '_csm_job_' . $csm_recovery_job, array() )['status'] ?? '', array( 'applying', 'backup_copy', 'restoring' ), true );
if ( $csm_recovery_cron ) {
	define( 'CSM_RECOVERY_CRON', true ); }
$csm_plugin = __CSM_PLUGIN__;
$csm_active = get_option( 'active_plugins', array() );
// Request parameters alone must never disable plugins on login or other WordPress routes.
$csm_request = $csm_recovery_cron;
if ( in_array( $csm_plugin, $csm_active, true ) && ! $csm_request ) {
	$csm_request = ( static function () use ( $csm_plugin ): bool {
		$script = realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' );
		$post   = ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST';
		// Managed hosts may keep WordPress core outside their public front controller.
		$path      = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		$base_path = rtrim( (string) parse_url( (string) get_option( 'siteurl' ), PHP_URL_PATH ), '/' );
		$endpoint  = is_string( $path ) && str_starts_with( $path, '/' ) && in_array( rtrim( $path, '/' ), array( $base_path, $base_path . '/index.php' ), true );
		if ( $post && $endpoint && is_string( $_GET['trickle'] ?? null ) && 'manifest' !== $_GET['trickle'] ) {
			$base = WP_PLUGIN_DIR . '/' . dirname( $csm_plugin ) . '/includes/';
			foreach ( array( 'support', 'access', 'transport' ) as $part ) {
				require_once $base . $part . '.php'; }
			try {
				CSM_Transport::signed_credential( $_GET['trickle'] );
				return true; } catch ( Throwable $error ) {
				return false; }
		}
		$ajax = $post && defined( 'DOING_AJAX' ) && DOING_AJAX && realpath( ABSPATH . 'wp-admin/admin-ajax.php' ) === $script
			&& ( $_POST['action'] ?? '' ) === 'csm_admin' && ( $_REQUEST['action'] ?? '' ) === 'csm_admin'
			&& ! in_array( $_POST['op'] ?? '', array( 'manifest', 'connect' ), true );
		$page = ! $post && realpath( ABSPATH . 'wp-admin/tools.php' ) === $script && ( $_GET['page'] ?? '' ) === 'trickle';
		if ( ! $ajax && ! $page ) {
			return false; }
		// Core user/session APIs are available here, before ordinary plugins load.
		wp_cookie_constants();
		require_once ABSPATH . WPINC . '/pluggable.php';
		if ( ! current_user_can( 'manage_options' ) ) {
			return false; }
		return $page || ( is_string( $_POST['nonce'] ?? null ) && wp_verify_nonce( $_POST['nonce'], 'csm_admin' ) !== false );
	} )();
}
$csm_runtime_busy = false;
if ( in_array( $csm_plugin, $csm_active, true ) && ! $csm_request && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once ABSPATH . WPINC . '/pluggable.php';
	if ( ! class_exists( 'CSM_Support', false ) ) {
		require_once WP_PLUGIN_DIR . '/' . dirname( $csm_plugin ) . '/includes/support.php'; }
	$csm_runtime_busy = ! CSM_Support::runtime_lock( false );
}
// Read maintenance after taking the shared lock, so apply cannot race ordinary plugin startup.
$csm_maintenance = get_option( '_csm_maintenance', array() );
$csm_protected   = ! empty( $csm_maintenance['id'] ) && in_array( get_option( '_csm_job_' . $csm_maintenance['id'], array() )['status'] ?? '', array( 'applying', 'backup_copy', 'restoring' ), true );
if ( in_array( $csm_plugin, $csm_active, true ) && ! $csm_request && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ( $csm_runtime_busy || $csm_protected || ( ! empty( $csm_maintenance['until'] ) && $csm_maintenance['until'] > time() ) ) ) {
	if ( $csm_protected && ! get_option( '_csm_recovery_blocked_' . $csm_maintenance['id'] ) && ! wp_next_scheduled( 'trickle_recover', array( $csm_maintenance['id'] ) ) ) {
		wp_schedule_single_event( time() + 5, 'trickle_recover', array( $csm_maintenance['id'] ) ); }
	if ( $csm_protected && ! get_option( '_csm_recovery_blocked_' . $csm_maintenance['id'] ) && function_exists( 'spawn_cron' ) ) {
		if ( ! defined( 'WP_CRON_LOCK_TIMEOUT' ) ) {
			define( 'WP_CRON_LOCK_TIMEOUT', 60 ); }
		try {
			spawn_cron();
		} catch ( Throwable $error ) {
			error_log( 'Trickle recovery cron could not start: ' . $error->getMessage() ); }
	}
	status_header( 503 );
	header( 'Retry-After: 60' );
	header( 'Cache-Control: no-store' );
	wp_die( 'A site update is being applied. Please try again shortly.', 'Site update in progress', array( 'response' => 503 ) );
}

if ( $csm_request && in_array( $csm_plugin, $csm_active, true ) ) {
	define( 'CSM_ORIGINAL_ACTIVE_PLUGINS', $csm_active );
	define( 'CSM_ORIGINAL_TEMPLATE', get_option( 'template' ) );
	define( 'CSM_ORIGINAL_STYLESHEET', get_option( 'stylesheet' ) );
	$GLOBALS['_csm_active_plugins_filter'] = static fn( $plugins ) => array_values( array_intersect( $plugins, array( $csm_plugin ) ) );
	add_filter( 'option_active_plugins', $GLOBALS['_csm_active_plugins_filter'], PHP_INT_MAX );
	add_filter( 'option_template', static fn() => '__csm_transfer_no_theme__', PHP_INT_MAX );
	add_filter( 'option_stylesheet', static fn() => '__csm_transfer_no_theme__', PHP_INT_MAX );
}
unset( $csm_request, $csm_plugin, $csm_active, $csm_maintenance, $csm_protected, $csm_recovery_job, $csm_recovery_cron, $csm_runtime_busy );
