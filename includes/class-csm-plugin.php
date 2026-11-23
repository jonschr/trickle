<?php
/**
 * Signed peer endpoints and migration lifecycle coordination.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-csm-timing.php';
require_once __DIR__ . '/class-csm-directories.php';

/**
 * Signed peer endpoints and migration lifecycle coordination.
 */
final class CSM_Plugin {
	/**
	 * Generate the recovery loader with this installation's plugin entry point.
	 */
	public static function loader_code(): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Generate an escaped PHP string literal for the recovery loader; this produces no debug output. Read exact local file or input-stream bytes; no outbound HTTP request is made.
		return str_replace( '__CSM_PLUGIN__', var_export( plugin_basename( CSM_FILE ), true ), file_get_contents( __DIR__ . '/loader.php' ) );
	}

	/**
	 * Install the current recovery loader without overwriting an unrelated MU plugin.
	 *
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function install_loader(): void {
		$path = WPMU_PLUGIN_DIR . '/0-trickle-loader.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
		if ( is_file( $path ) && ! str_contains( file_get_contents( $path ), 'TRICKLE_LOADER' ) ) {
			throw new RuntimeException( 'An unrelated file occupies the Trickle loader path.' ); }
		if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			throw new RuntimeException( 'Trickle needs permission to install its recovery loader in wp-content/mu-plugins.' );
		}
		CSM_Support::atomic_bytes( $path, self::loader_code(), 0644 );
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true ); }
	}

	/**
	 * Install recovery support and schedule the eligible administrator welcome redirect.
	 *
	 * @param bool $network_wide Whether WordPress is activating the plugin across a multisite network.
	 */
	public static function activate( bool $network_wide = false ): void {
		self::install_loader();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core activation already authorizes this hook; the flag only suppresses an optional welcome redirect.
		if ( $network_wide || ! is_admin() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || isset( $_REQUEST['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) {
			return; }
		set_transient( '_csm_activation_redirect_' . get_current_user_id(), true, 60 );
	}

	/**
	 * Consume the current administrator's one-time post-activation redirect.
	 */
	public static function activation_redirect(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! current_user_can( 'manage_options' ) ) {
			return; }
		$key = '_csm_activation_redirect_' . get_current_user_id();
		if ( ! get_transient( $key ) ) {
			return; }
		delete_transient( $key );
		wp_safe_redirect( admin_url( 'tools.php?page=trickle&view=settings' ) );
		exit;
	}

	/**
	 * Check that the installed recovery loader exactly matches the generated source.
	 */
	public static function loader_ready(): bool {
		$path = WPMU_PLUGIN_DIR . '/0-trickle-loader.php';
		return is_file( $path ) && ! is_link( $path ) && hash_equals( hash( 'sha256', self::loader_code() ), hash_file( 'sha256', $path ) );
	}

	/**
	 * Register native plugin hooks and refresh required recovery support.
	 */
	public static function boot(): void {
		if ( ! get_option( '_csm_key' ) ) {
			add_option( '_csm_key', bin2hex( random_bytes( 32 ) ), '', false ); }
		// Upgrades do not run activation hooks; refresh the security-sensitive MU loader when its version or contents change.
		if ( ( get_option( '_csm_loader_version' ) !== CSM_VERSION || get_option( '_csm_loader_hash' ) !== hash( 'sha256', self::loader_code() ) ) && wp_is_file_mod_allowed( 'trickle' ) ) {
			try {
				if ( ! self::loader_ready() ) {
					self::install_loader(); }
				update_option( '_csm_loader_version', CSM_VERSION, false );
				update_option( '_csm_loader_hash', hash( 'sha256', self::loader_code() ), false );
			} catch ( Throwable $error ) {
				CSM_Log::write( 'error', 'Recovery loader refresh failed', array( 'error' => $error->getMessage() ) ); }
		}
		if ( defined( 'CSM_RECOVERY_CRON' ) ) {
			self::recover_interrupted( (string) get_option( '_csm_active_job' ) );
			// Other plugins were isolated; leave their due cron events untouched for the next request.
			exit;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only: endpoint performs action validation and peer authentication before dispatch.
		if ( isset( $_GET['trickle'] ) ) {
			self::endpoint(); }
		add_action(
			'admin_menu',
			static function () {
				add_management_page( 'Trickle Migrate', 'Trickle Migrate', 'manage_options', 'trickle', array( self::class, 'page' ) );
			}
		);
		add_action( 'admin_init', array( self::class, 'activation_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'wp_ajax_csm_admin', array( self::class, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_csm_admin', array( self::class, 'ajax' ) );
		add_action( 'trickle_prune_sources', array( CSM_Segments::class, 'prune_sources' ) );
		if ( ! wp_next_scheduled( 'trickle_prune_sources' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'trickle_prune_sources' ); }
		add_action( 'trickle_cancel_transfer', array( CSM_Controller::class, 'retry_cancel' ) );
		add_action( 'trickle_cleanup_source', array( CSM_Controller::class, 'retry_source_cleanup' ), 10, 2 );
		add_action( 'trickle_recover', array( self::class, 'recover_interrupted' ) );
	}

	/**
	 * Resume the recorded interrupted apply or restoration through its recovery journal.
	 *
	 * @param string $id Transfer identifier.
	 */
	public static function recover_interrupted( string $id ): void {
		try {
			$job = CSM_Jobs::get( $id );
		} catch ( Throwable $e ) {
			return; }
		if ( ! in_array( $job['status'], array( 'applying', 'backup_copy', 'restoring' ), true ) || get_option( '_csm_recovery_blocked_' . $id ) ) {
			return; }
		$heartbeat = 0;
		foreach ( array( '-heartbeat', '-apply.json', '-restore.json' ) as $suffix ) {
			$path = CSM_Support::private_dir() . '/' . $id . $suffix;
			clearstatcache( true, $path );
			if ( is_file( $path ) ) {
				$heartbeat = max( $heartbeat, filemtime( $path ) ); }
		}
		if ( $heartbeat > time() - 30 ) {
			wp_schedule_single_event( time() + 30, 'trickle_recover', array( $id ) );
			return; }
		try {
			CSM_Support::lock(); } catch ( Throwable $e ) {
			wp_schedule_single_event( time() + 60, 'trickle_recover', array( $id ) );
			return; }
			try {
				$job = CSM_Jobs::get( $id );
				if ( ! in_array( $job['status'], array( 'applying', 'backup_copy', 'restoring' ), true ) ) {
					return; }
				CSM_Support::work( $id, false );
				$previous = CSM_Progress::read( $id );
				CSM_Log::write(
					'warning',
					'Recovering interrupted transfer',
					array(
						'transfer_id' => $id,
						'phase'       => $previous['phase'] ?? '',
						'component'   => $previous['component'] ?? '',
						'count'       => $previous['count'] ?? null,
						'total'       => $previous['total'] ?? null,
					)
				);
				CSM_Progress::start( $id, 'Recovering interrupted transfer' );
				$restore = get_option( '_csm_backup_restore' );
				if ( ( $restore['job_id'] ?? '' ) === $id ) {
					$result = CSM_Backups::restore( $restore['backup_id'] );
					if ( ! empty( $result['continue'] ) ) {
									return; }
				} else {
						$result = self::rollback( $job );
					if ( ! empty( $result['continue'] ) ) {
						return; }
				}
				CSM_Log::write( 'info', 'Interrupted transfer recovered', array( 'transfer_id' => $id ) );
			} catch ( Throwable $e ) {
				$current = CSM_Jobs::get( $id );
				$cursor  = CSM_Support::private_dir() . '/' . $id . '-restore.json';
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
				$stamp    = hash( 'sha256', CSM_Support::json( array( $current['status'], $current['backup_copy'] ?? null, is_file( $cursor ) ? file_get_contents( $cursor ) : $current['restore_cursor'] ?? 0 ) ) );
				$failures = get_option( '_csm_recovery_attempts_' . $id, array() );
				$count    = ( $failures['stamp'] ?? '' ) === $stamp ? ( $failures['count'] ?? 0 ) + 1 : 1;
				update_option(
					'_csm_recovery_attempts_' . $id,
					array(
						'stamp' => $stamp,
						'count' => $count,
					),
					false
				);
				if ( $count >= 5 ) {
					update_option( '_csm_recovery_blocked_' . $id, $e->getMessage(), false );
					CSM_Log::write(
						'error',
						'Recovery needs attention; site remains protected',
						array(
							'transfer_id' => $id,
							'error'       => $e->getMessage(),
						)
					);
				} else {
					CSM_Log::write(
						'error',
						'Automatic recovery will retry',
						array(
							'transfer_id' => $id,
							'error'       => $e->getMessage(),
						)
					);
					wp_schedule_single_event( time() + 60, 'trickle_recover', array( $id ) );
				}
			} finally {
				CSM_Support::unlock(); }
	}

	/**
	 * Require the PHP and runtime capabilities needed by the migration protocol.
	 *
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function supported(): void {
		if ( is_multisite() ) {
			throw new RuntimeException( 'This release supports single-site WordPress only.' ); }
		if ( PHP_INT_SIZE < 8 ) {
			throw new RuntimeException( '64-bit PHP is required.' ); }
	}

	/**
	 * Build site identity, capabilities and selected component inventory for a peer.
	 *
	 * @param bool $refresh Whether to bypass or refresh reusable inventory state.
	 * @param bool $media Whether to include media metadata in the inventory.
	 */
	public static function manifest( bool $refresh = false, bool $media = true ): array {
		global $wpdb;
		self::supported();
		$inventory = CSM_Files::inventory( $refresh, $media );
		try {
			$estimates = CSM_Estimates::read( true ); } catch ( Throwable $error ) {
			$estimates = null;
			CSM_Log::write( 'error', 'Size estimate unavailable', array( 'error' => $error->getMessage() ) ); }
			return array_merge(
				$inventory,
				CSM_Database::identity(),
				CSM_Updates::capabilities(),
				array(
					'file_modifications'   => wp_is_file_mod_allowed( 'trickle' ),
					'active_plugins'       => CSM_Activation::current(),
					'installed_plugins'    => array_keys( get_plugins() ),
					'trickle_plugin'       => plugin_basename( CSM_FILE ),
					'can_receive'          => ! empty( CSM_Access::settings()['push'] ) && ( ! isset( $GLOBALS['_csm_credential'] ) || ! empty( $GLOBALS['_csm_credential']['write'] ) ),
					'bounded_work'         => 2,
					'live_files'           => 1,
					'segments'             => 1,
					'resumable_stream'     => 1,
					'component_compare'    => 1,
					'metadata_compare'     => 1,
					'file_policy'          => 1,
					'canonical_url'        => (string) get_option( 'siteurl' ),
					'instance_fingerprint' => CSM_Controller::fingerprint(),
					'media_manifest'       => 1,
					'media_compare'        => 1,
					'media_browsing'       => 1,
					'media_dates'          => 1,
					'replacement_rules'    => 1,
					'protocol'             => 1,
					'plugin_version'       => CSM_VERSION,
					'estimates'            => $estimates,
					'name'                 => get_bloginfo( 'name' ),
					'icon'                 => get_site_icon_url( 64 ),
					'prefix'               => $wpdb->prefix,
					'wordpress'            => get_bloginfo( 'version' ),
					'php'                  => PHP_VERSION,
					'tables'               => CSM_Database::inventory(),
					'isolation'            => self::loader_ready(),
					'incoming_writes'      => ! empty( CSM_Access::settings()['push'] ),
				)
			);
	}

	/**
	 * Authenticate and dispatch the signed native peer endpoint.
	 *
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function endpoint(): void {
		$action    = '';
		$streaming = false;
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Peer actions use the signed request and one-use nonce verified below by CSM_Transport::authenticate().
			if ( ! is_string( $_GET['trickle'] ?? null ) ) {
				throw new RuntimeException( 'Invalid action.', 400 );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This selects the signed action; authentication below consumes its replay-protected nonce.
			$action = sanitize_key( wp_unslash( $_GET['trickle'] ) );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the exact POST method is accepted; all other values are rejected.
			if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
				throw new RuntimeException( 'POST required.', 405 ); }
			self::supported();
			CSM_Support::long_request();
			if ( ! in_array( $action, array( 'live_files', 'live_receive', 'live_state', 'manifest', 'version', 'update_info', 'update_trickle', 'build_info', 'update_trickle_build', 'update_trickle_zip', 'backups', 'restore_backup', 'media', 'media_hashes', 'file_policy', 'component_hashes', 'component_manifest', 'media_manifest', 'bundle', 'bundle_stream', 'stream_data', 'bundle_chunk', 'bundle_discard', 'cancel', 'estimates', 'segment_state', 'segment_data', 'export', 'prepare', 'receive', 'status', 'preview', 'progress', 'commit', 'rollback', 'discard' ), true ) ) {
				throw new RuntimeException( 'Unknown action.', 404 ); }
			$data    = CSM_Transport::authenticate( $action );
			$work_id = $data['id'] ?? $data['selection']['comparison_id'] ?? '';
			if ( is_string( $work_id ) ) {
				CSM_Timing::begin( $work_id, $action ); }
			if ( is_string( $work_id ) && preg_match( '/^[a-f0-9]{32}$/D', $work_id ) && in_array( $action, array( 'component_hashes', 'media_hashes' ), true ) ) {
				$data['id'] = $work_id;
				CSM_Segments::lease( $work_id );
				CSM_Support::work( $work_id );
				CSM_Progress::start( $work_id, 'Comparing file metadata', $data['progress_run'] ?? null );
			}
			if ( ! empty( $data['id'] ) && preg_match( '/^[a-f0-9]{32}$/D', $data['id'] ) ) {
				CSM_Support::work( $data['id'], ! in_array( $action, array( 'cancel', 'rollback', 'discard', 'bundle_discard', 'status', 'progress' ), true ) ); }
			if ( in_array( $action, array( 'component_hashes', 'media_hashes', 'file_policy', 'export', 'receive' ), true ) ) {
				CSM_Support::begin_source_budget();
				if ( function_exists( 'set_time_limit' ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts may forbid runtime changes; bounded work and resumable checkpoints remain mandatory.
					@set_time_limit( 50 ); }
			}
			// Native upgrades may leave the previous MU loader until an administrator visits Trickle.
			if ( ! self::loader_ready() && wp_is_file_mod_allowed( 'trickle' ) ) {
				try {
					self::install_loader(); } catch ( Throwable $error ) {
					CSM_Log::write( 'error', 'Recovery loader refresh failed', array( 'error' => $error->getMessage() ) ); }
			}
			if ( ( ( 'live_files' === $action || ( in_array( $action, array( 'export', 'bundle' ), true ) && ( $data['kind'] ?? '' ) === 'files' ) ) || ( 'prepare' === $action && in_array( 'files', self::kinds( $data['selection'] ?? array() ), true ) ) ) && ( ( $data['selection']['file_policy'] ?? 0 ) !== 1 ) ) {
				throw new RuntimeException( 'Update Trickle on both sites before transferring files with Git and environment-file protection.' ); }
			if ( in_array( $action, array( 'commit', 'rollback', 'restore_backup' ), true ) ) {
				ignore_user_abort( true ); }
			if ( ! in_array( $action, array( 'progress', 'status' ), true ) ) {
				CSM_Log::write(
					'info',
					'Authenticated incoming request',
					array(
						'operation'   => $action,
						'transfer_id' => $data['id'] ?? null,
					)
				); }
			self::prune_nonces();
			if ( 'version' === $action ) {
				self::respond(
					array(
						'url'           => site_url(),
						'canonical_url' => (string) get_option( 'siteurl' ),
					) + ( ! empty( $data['update_check'] ) ? CSM_Updates::build_info() : CSM_Updates::capabilities() ) + array(
						'plugin_version'       => CSM_VERSION,
						'instance_fingerprint' => CSM_Controller::fingerprint(),
					)
				); }
			if ( 'build_info' === $action ) {
				self::respond( CSM_Updates::build_info() ); }
			if ( 'update_trickle_build' === $action ) {
				CSM_Support::lock();
				self::respond( CSM_Updates::install_build( $data ) ); }
			if ( 'update_trickle_zip' === $action ) {
				CSM_Support::lock();
				$input = fopen( 'php://input', 'rb' );
				try {
					$result = CSM_Updates::install_zip( $data, $input );
				} finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
					fclose( $input ); }
				self::respond( $result );
			}
			if ( 'update_info' === $action ) {
				self::respond( CSM_Updates::info() ); }
			if ( 'update_trickle' === $action ) {
				CSM_Support::lock();
				self::respond( CSM_Updates::install( $data['target'] ?? '', $data['from'] ?? '' ) ); }
			if ( 'backups' === $action ) {
				self::respond( CSM_Backups::listing() ); }
			if ( 'restore_backup' === $action ) {
				CSM_Support::lock();
				CSM_Progress::start( $data['id'] ?? '', 'Restoring saved backup', $data['progress_run'] ?? null );
				self::respond( CSM_Backups::restore( $data['id'] ?? '' ) ); }
			if ( 'estimates' === $action ) {
				self::respond( CSM_Estimates::read( true ) ); }
			if ( 'manifest' === $action ) {
				$manifest = self::manifest( ! empty( $data['refresh'] ), ! isset( $data['selection'] ) );
				if ( isset( $data['selection'] ) ) {
					$manifest['git_folders'] = CSM_Files::git_folders( $data['selection'], ! empty( $data['source'] ) ); }
				self::respond( $manifest );
			}
			if ( 'media' === $action ) {
				self::respond( CSM_Files::media( (int) ( $data['page'] ?? 1 ), $data['ids'] ?? array() ) ); }
			if ( 'live_state' === $action ) {
				self::respond( CSM_Live_Stream::state( CSM_Jobs::get( $data['id'] ?? '' ) ) ); }
			if ( 'live_receive' === $action ) {
				CSM_Support::lock();
				$job = CSM_Jobs::get( $data['id'] ?? '' );
				CSM_Access::assert_owner( $job );
				CSM_Progress::start( $job['id'], 'Receiving file frames', $data['progress_run'] ?? null );
				$input = fopen( 'php://input', 'rb' );
				try {
					$result = CSM_Live_Stream::receive( $job, $data['selection_hash'] ?? '', $data['seq'] ?? -1, $input, $data['offset'] ?? 0 ); } finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
					fclose( $input ); }
					self::respond( $result );
			}
			if ( 'live_files' === $action ) {
				CSM_Support::lock();
				CSM_Progress::start( $data['id'] ?? '', 'Streaming source files', $data['progress_run'] ?? null );
				$generator = CSM_Live_Stream::source( $data['id'] ?? '', $data['selection'] ?? array(), $data['seq'] ?? -1, $data['offset'] ?? 0 );
				$generator->rewind();
				while ( ob_get_level() ) {
					ob_end_clean(); }
				nocache_headers();
				header( 'Content-Type: application/octet-stream' );
				header( 'X-Accel-Buffering: no' );
				$streaming = true;
				$out       = fopen( 'php://output', 'wb' );
				try {
					foreach ( $generator as $bytes ) {
						CSM_Support::write( $out, $bytes );
						flush(); }
				} finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
					fclose( $out ); }
				exit;
			}
			if ( 'bundle' === $action ) {
				if ( ( $data['format'] ?? 0 ) !== 2 ) {
					throw new RuntimeException( 'Install this Trickle build on both sites before packaging.' ); }
				CSM_Support::lock();
				CSM_Progress::start( $data['id'] ?? '', 'Building resumable source stream', $data['progress_run'] ?? null );
				self::respond( CSM_Segments::bundle( $data['id'] ?? '', $data['kind'] ?? '', $data['selection'] ?? array() ) );
			}
			if ( 'bundle_chunk' === $action ) {
				$bytes = CSM_Segments::chunk( $data['id'] ?? '', $data['kind'] ?? '', (int) ( $data['offset'] ?? -1 ) );
				while ( ob_get_level() ) {
					ob_end_clean(); }
				nocache_headers();
				header( 'Content-Type: application/octet-stream' );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary protocol bytes under application/octet-stream; HTML escaping would corrupt the payload.
				echo $bytes;
				exit;
			}
			if ( 'cancel' === $action ) {
				CSM_Segments::cancel( $data['id'] ?? '' );
				self::respond( array( 'cancel_requested' => true ) ); }
			if ( 'bundle_discard' === $action ) {
				if ( ! empty( $data['comparison_only'] ) ) {
					self::respond( array( 'comparison_cleared' => CSM_Segments::release_comparison( $data['id'] ?? '' ) ) ); }
				CSM_Segments::cleanup_source( $data['id'] ?? '' );
				self::respond( array() );
			}
			if ( 'bundle_stream' === $action ) {
				$offset = $data['offset'] ?? -1;
				if ( ! is_int( $offset ) ) {
					throw new RuntimeException( 'Invalid source offset.' ); }
				$length = $data['length'] ?? ( $data['bundle']['size'] - $offset );
				if ( ! is_int( $length ) ) {
					throw new RuntimeException( 'Invalid source window length.' ); }
				$generator = CSM_Segments::source( $data['id'] ?? '', $data['kind'] ?? '', $offset, $data['bundle'] ?? array(), $length );
				$generator->rewind();
				while ( ob_get_level() ) {
					ob_end_clean(); }
				nocache_headers();
				header( 'Content-Type: application/octet-stream' );
				header( 'Content-Length: ' . $length );
				header( 'X-Accel-Buffering: no' );
				$streaming = true;
				$out       = fopen( 'php://output', 'wb' );
				foreach ( $generator as $bytes ) {
					CSM_Support::write( $out, $bytes );
					flush();
					if ( connection_aborted() ) {
						break; }
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $out );
				exit;
			}
			if ( in_array( $action, array( 'component_manifest', 'media_manifest' ), true ) ) {
				$raw = CSM_Support::json( 'media_manifest' === $action ? CSM_Media::manifest( $data ) : CSM_Components::manifest( $data ) );
				if ( strlen( $raw ) > 32 * 1024 * 1024 ) {
					throw new RuntimeException( 'Component manifest exceeds its size limit.' ); }
				$bytes = gzencode( $raw, 3 );
				while ( ob_get_level() ) {
					ob_end_clean(); }
				nocache_headers();
				header( 'Content-Type: application/octet-stream' );
				header( 'Content-Length: ' . strlen( $bytes ) );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary protocol bytes under application/octet-stream; HTML escaping would corrupt the payload.
				echo $bytes;
				exit;
			}
			if ( 'component_hashes' === $action ) {
				$result = CSM_Components::inventory( $data );
				CSM_Progress::update( array( 'component' => '' ) );
				CSM_Progress::finish( 'Plugin and theme metadata ready' );
				if ( ! empty( $data['selection']['comparison_only'] ) ) {
					CSM_Segments::release_comparison( $work_id );
				} self::respond( $result ); }
			if ( 'file_policy' === $action ) {
				self::respond( array( 'git_folders' => CSM_Files::git_folders( $data['selection'] ?? array(), ! empty( $data['source'] ) ) ) ); }
			if ( 'media_hashes' === $action ) {
				$result = CSM_Media::inventory( $data );
				CSM_Progress::update( array( 'component' => '' ) );
				CSM_Progress::finish( 'Media metadata ready' );
				if ( ! empty( $data['selection']['comparison_only'] ) ) {
					CSM_Segments::release_comparison( $work_id );
				} self::respond( $result ); }
			if ( 'progress' === $action ) {
				CSM_Segments::assert_progress_owner( $data['id'] ?? '' );
				self::respond( CSM_Progress::read( $data['id'] ?? '' ) ); }
			if ( 'preview' === $action ) {
				$job = CSM_Jobs::get( $data['id'] ?? '' );
				CSM_Access::assert_owner( $job );
				self::respond( self::preview_page( $job, (int) ( $data['offset'] ?? 0 ) ) ); }
			if ( 'status' === $action ) {
				$job = CSM_Jobs::get( $data['id'] ?? '' );
				CSM_Access::assert_owner( $job );
				self::respond( self::summary( $job ) ); }
			if ( 'export' === $action ) {
				CSM_Support::lock();
				if ( ! empty( $data['progress_id'] ) ) {
					CSM_Progress::start( $data['progress_id'], 'Exporting ' . ( $data['kind'] ?? 'stream' ), $data['progress_run'] ?? null ); }
				$selection = CSM_Files::selection( $data['selection'] ?? array() );
				CSM_Database::validate( $selection['tables'] ?? array() );
				if ( ! in_array( $data['kind'] ?? '', array( 'database', 'files' ), true ) ) {
					throw new RuntimeException( 'Invalid stream kind.' ); }
				while ( ob_get_level() ) {
					ob_end_clean(); }
				nocache_headers();
				header( 'Content-Type: application/octet-stream' );
				header( 'X-Accel-Buffering: no' );
				header( 'Content-Disposition: attachment; filename="trickle-' . $data['kind'] . '.csm"' );
				$streaming = true;
				$generator = 'database' === $data['kind'] ? CSM_Database::stream( $selection ) : CSM_Files::stream( $selection );
				$out       = fopen( 'php://output', 'wb' );
				foreach ( CSM_Support::gzip( $generator ) as $bytes ) {
					CSM_Support::write( $out, $bytes );
					flush();
					if ( connection_aborted() ) {
						throw new RuntimeException( 'Receiving connection closed during export.' ); }
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $out );
				CSM_Progress::finish( 'Export verified' );
				exit;
			}
			CSM_Support::lock();
			if ( 'prepare' === $action ) {
				self::respond( self::prepare( $data['selection'] ?? array(), $data['context'] ?? array() ) ); }
			$id  = $data['id'] ?? '';
			$job = CSM_Jobs::get( $id );
			CSM_Access::assert_owner( $job );
			if ( 'segment_state' === $action ) {
				self::respond( CSM_Segments::state( $job, $data['kind'] ?? '', $data['bundle'] ?? array() ) ); }
			if ( 'segment_data' === $action ) {
				ignore_user_abort( true );
				$bytes = file_get_contents( 'php://input', false, null, 0, CSM_Segments::SIZE + 1 );
				self::respond( CSM_Segments::receive( $job, $data['kind'] ?? '', $data['bundle'] ?? array(), (int) ( $data['offset'] ?? -1 ), $bytes, $data['sha256'] ?? '' ) );
			}
			if ( 'stream_data' === $action ) {
				CSM_Updates::assert_job( $job );
				if ( ! is_int( $data['offset'] ?? null ) ) {
					throw new RuntimeException( 'Invalid destination offset.' ); }
				if ( isset( $data['length'] ) && ! is_int( $data['length'] ) ) {
					throw new RuntimeException( 'Invalid upload window length.' ); }
				CSM_Progress::start( $job['id'], 'Receiving resumable stream', $data['progress_run'] ?? null );
				$fp = fopen( 'php://input', 'rb' );
				try {
					self::respond( CSM_Segments::receive_stream( $job, $data['kind'] ?? '', $data['bundle'] ?? array(), $data['offset'], $fp, $data['length'] ?? null ) ); } finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
					fclose( $fp ); }
			}
			if ( 'receive' === $action ) {
				$fp = fopen( 'php://input', 'rb' );
				try {
					$job = self::receive( $job, $data['kind'] ?? '', $fp, $data['progress_run'] ?? null ); } finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
					fclose( $fp ); }
					self::respond( self::summary( $job ) );
			}
			if ( 'commit' === $action ) {
				self::respond( self::commit( $job, $data['progress_run'] ?? null ) ); }
			if ( 'rollback' === $action ) {
				CSM_Progress::start( $id, 'Restoring destination backup', $data['progress_run'] ?? null );
				self::respond( self::rollback( $job ) ); }
			if ( 'discard' === $action ) {
				self::discard( $job );
				self::respond( array() ); }
			self::respond( self::summary( $job ) );
		} catch ( CSM_Yield $e ) {
			self::respond( array( 'continue' => true ) );
		} catch ( Throwable $e ) {
			CSM_Log::write(
				'error',
				'Incoming request failed',
				array(
					'operation' => $action,
					'error'     => $e->getMessage(),
				)
			);
			CSM_Progress::fail( $e->getMessage() );
			if ( $streaming ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Emergency server-side failure reporting is required when the response stream or recovery startup cannot report safely.
				error_log( 'Trickle export stopped: ' . $e->getMessage() );
				exit; }
			self::respond(
				$e instanceof CSM_Network_Error ? array(
					'retryable'   => true,
					'retry_after' => $e->retry_after,
					'busy'        => $e instanceof CSM_Busy_Error,
				) : array(),
				$e->getMessage(),
				$e instanceof CSM_Busy_Error ? 409 : ( in_array( $e->getCode(), array( 403, 404, 405 ), true ) ? $e->getCode() : 400 )
			);
		}
	}

	/**
	 * Terminate the request with a JSON success or error envelope and matching content type.
	 *
	 * @param array  $data Operation-specific request fields, validated by the receiving operation.
	 * @param string $error Error description placed in the JSON response envelope.
	 * @param int    $status HTTP response status code.
	 */
	public static function respond( array $data, string $error = '', int $status = 200 ): never {
		while ( ob_get_level() ) {
			ob_end_clean(); }
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		printf(
			"%s\n",
			wp_json_encode(
				$error ? array(
					'ok'    => false,
					'error' => $error,
				) + $data : array(
					'ok'   => true,
					'data' => $data,
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
			)
		);
		exit;
	}

	/**
	 * Remove expired signed-request nonce records from private replay protection.
	 */
	public static function prune_nonces(): void {
		global $wpdb;
		// Expired nonce records cannot be replayed because their signed timestamps have expired too.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded nonce pruning uses a validated table identifier and prepared values; freshness is required.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM ' . CSM_Support::ident( $wpdb->options ) . ' WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 100', $wpdb->esc_like( '_csm_nonce_' ) . '%', time() - 600 ) );
		foreach ( $names as $name ) {
			delete_option( $name ); }
	}

	/**
	 * Create a destination staging job for a validated migration selection.
	 *
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 * @param array $context Destination preparation metadata supplied by the controlling request.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function prepare( array $selection, array $context = array() ): array {
		$active = get_option( '_csm_active_job' );
		if ( $active ) {
			$previous = CSM_Jobs::get( $active );
			if ( 'applied' === $previous['status'] ) {
				CSM_Backups::retain( $previous ); } elseif ( 'restored' === $previous['status'] ) {
				self::discard( $previous ); } else {
					throw new RuntimeException( 'Finish or cancel the active transfer before starting another on this destination. Saved backups do not block new transfers.' ); }
		}
		$selection  = CSM_Activation::selection( CSM_Files::media_selection( CSM_Database::replacement_selection( $selection ) ) );
		$selection += array(
			'tables'    => array(),
			'plugins'   => array(),
			'themes'    => array(),
			'media'     => 'none',
			'media_ids' => array(),
			'replace'   => true,
		);
		foreach ( array( 'tables', 'plugins', 'themes' ) as $kind ) {
			if ( ! is_array( $selection[ $kind ] ) ) {
				throw new RuntimeException( 'Invalid selection.' ); }
			foreach ( $selection[ $kind ] as $value ) {
				if ( ! is_string( $value ) ) {
					throw new RuntimeException( 'Invalid component.' ); }
			}
		}
		if ( ! $selection['tables'] && ! $selection['plugins'] && ! $selection['themes'] && 'none' === $selection['media'] ) {
			throw new RuntimeException( 'Select something to transfer.' ); }
		$job = array(
			'plugin_version' => CSM_VERSION,
			'credential'     => CSM_Access::owner(),
			'source'         => esc_url_raw( $context['source'] ?? '', array( 'http', 'https' ) ),
			'id'             => bin2hex( random_bytes( 16 ) ),
			'status'         => 'staging',
			'created'        => time(),
			'selection'      => $selection,
			'received'       => array(),
			'database'       => array(),
			'files'          => array(),
			'ops'            => array(),
		);
		CSM_Timing::begin( $job['id'], 'prepare' );
		CSM_Support::private_dir();
		CSM_Jobs::save( $job );
		update_option( '_csm_active_job', $job['id'], false );
		return self::summary( $job ) + array( 'initial_checkpoints' => array_fill_keys( self::kinds( $selection ), array( 'offset' => 0 ) ) );
	}

	/**
	 * Receive a selected stream and advance its verified destination staging.
	 *
	 * @param array       $job Destination job and its durable staging or recovery state.
	 * @param string      $kind Component or stream kind selected by the caller.
	 * @param resource    $fp Caller-owned stream handle positioned at the next record or write offset.
	 * @param string|null $run Optional existing progress activity identifier.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 * @throws Throwable If underlying migration work fails and its original error is rethrown.
	 */
	public static function receive( array $job, string $kind, $fp, ?string $run = null ): array {
		CSM_Updates::assert_job( $job );
		if ( 'staging' !== $job['status'] || ! in_array( $kind, self::kinds( $job['selection'] ), true ) ) {
			throw new RuntimeException( 'Transfer is not awaiting this stream.' ); }
		CSM_Progress::start( $job['id'], 'Receiving ' . $kind, $run );
		unset( $job['received'][ $kind ] );
		if ( 'files' === $kind ) {
			$job['files'] = array(); }
		CSM_Jobs::save( $job );
		CSM_Support::decompress( $fp );
		try {
			if ( 'database' === $kind ) {
				foreach ( $job['database'] as $table ) {
					CSM_Support::query( 'DROP TABLE IF EXISTS ' . CSM_Support::ident( $table['stage'] ) ); }
				CSM_Database::stage( $fp, $job );
			} else {
				CSM_Files::cleanup_media_parts( $job );
				CSM_Support::remove_tree( CSM_Support::private_dir() . '/' . $job['id'] );
				CSM_Files::stage( $fp, $job );
			}
			$job['received'][ $kind ] = true;
			unset( $job['error'] );
		} catch ( Throwable $e ) {
			CSM_Progress::fail( $e->getMessage() );
			unset( $job['received'][ $kind ] );
			$job['error'] = $e->getMessage();
			CSM_Jobs::save( $job );
			throw $e;
		}
		if ( ! array_diff( self::kinds( $job['selection'] ), array_keys( $job['received'] ) ) ) {
			$preview = self::preview( $job );
			$raw     = CSM_Support::json( $preview['items'] );
			$path    = CSM_Support::private_dir() . '/' . $job['id'] . '-preview.json';
			CSM_Support::atomic_bytes( $path, $raw );
			$preview['file_hash'] = hash( 'sha256', $raw );
			$preview['items']     = array_slice( $preview['items'], 0, 200 );
			$job['preview']       = $preview;
		}
		CSM_Jobs::save( $job );
		CSM_Progress::finish( ucfirst( $kind ) . ' verified' );
		return $job;
	}

	/**
	 * Advance a received stream through bounded verification and staging work.
	 *
	 * @param array    $job Destination job and its durable staging or recovery state.
	 * @param string   $kind Component or stream kind selected by the caller.
	 * @param resource $fp Caller-owned stream handle positioned at the next record or write offset.
	 * @param bool     $continuing Whether to preserve the current work-step deadline.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function receive_step( array $job, string $kind, $fp, bool $continuing = false ): array {
		CSM_Support::step( 8, $continuing );
		CSM_Updates::assert_job( $job );
		if ( 'staging' !== $job['status'] || ! in_array( $kind, self::kinds( $job['selection'] ), true ) ) {
			throw new RuntimeException( 'Transfer is not awaiting this stream.' ); }
		if ( ! empty( $job['received'][ $kind ] ) ) {
			return $job; }
		$already = ! empty( $job['import_done'][ $kind ] );
		$done    = $already || ( 'database' === $kind ? CSM_Database::stage_step( $fp, $job, true ) : CSM_Files::stage_step( $fp, $job, true ) );
		if ( $done ) {
			$job['import_done'][ $kind ] = true; }
		if ( $done ) {
			$job['received'][ $kind ] = true;
			unset( $job['error'] ); }
		if ( ! array_diff( self::kinds( $job['selection'] ), array_keys( $job['received'] ) ) ) {
			if ( CSM_Support::due() ) {
				unset( $job['received'][ $kind ] );
				CSM_Jobs::save( $job );
				return $job; }
			$preview = self::preview_step( $job, true );
			if ( $preview ) {
				$job['preview'] = $preview; } else {
				unset( $job['received'][ $kind ] ); }
		}
		CSM_Jobs::save( $job );
		if ( ! empty( $job['received'][ $kind ] ) ) {
			CSM_Progress::finish( ucfirst( $kind ) . ' verified' ); }
		return $job;
	}

	/**
	 * Return the stream kinds required by the approved selection.
	 *
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 */
	public static function kinds( array $selection ): array {
		$result = array();
		if ( ! empty( $selection['tables'] ) ) {
			$result[] = 'database'; }
		if ( ! empty( $selection['plugins'] ) || ! empty( $selection['themes'] ) || ( $selection['media'] ?? 'none' ) !== 'none' ) {
			$result[] = 'files'; }
		return $result;
	}

	/**
	 * Build the destination job status and component counts for the controller.
	 *
	 * @param array $job Destination job and its durable staging or recovery state.
	 */
	public static function summary( array $job ): array {
		return array(
			'id'                 => $job['id'],
			'status'             => $job['status'],
			'tables'             => count( $job['database'] ?? array() ),
			'rows'               => $job['rows'] ?? 0,
			'files'              => count( array_filter( $job['files'] ?? array(), static fn( $file ) => empty( $file['unchanged'] ) ) ),
			'unchanged_files'    => count( array_filter( $job['files'] ?? array(), static fn( $file ) => ! empty( $file['unchanged'] ) ) ),
			'activation_missing' => $job['activation_missing'] ?? array(),
			'verification'       => $job['verification'] ?? null,
			'preview'            => $job['preview'] ?? null,
			'skipped_folders'    => $job['selection']['skip_paths'] ?? array(),
			'created'            => $job['created'],
			'applied'            => $job['applied'] ?? null,
			'bytes'              => array_sum( array_column( array_filter( $job['files'] ?? array(), static fn( $file ) => empty( $file['unchanged'] ) ), 'size' ) ),
			'ready'              => ! array_diff( self::kinds( $job['selection'] ), array_keys( $job['received'] ) ),
			'progress'           => CSM_Progress::read( $job['id'] ),
			'media_watermark'    => $job['media_watermark'] ?? null,
			'error'              => $job['error'] ?? null,
			'pending'            => array_values( array_diff( self::kinds( $job['selection'] ), array_keys( $job['received'] ) ) ),
		);
	}

	/**
	 * Calculate the selected file actions and aggregate database replacement work.
	 *
	 * @param array $job Destination job and its durable staging or recovery state.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function changes( array $job ): array {
		$new     = array();
		$paths   = array();
		$checked = 0;
		foreach ( $job['files'] as $file ) {
			CSM_Progress::update(
				array(
					'phase'     => 'Verifying staged file',
					'component' => $file['path'],
					'count'     => $checked++,
					'total'     => count( $job['files'] ),
					'unit'      => 'files',
					'bytes'     => 0,
				)
			);
			$target = CSM_Files::destination( $file['path'], $job['selection'] );
			if ( ! empty( $file['unchanged'] ) ) {
				if ( ! ( isset( $file['sha256'] ) ? CSM_Support::verify_file( $target, $file['sha256'], $file['verification_receipt'] ) : CSM_Files::verify_unchanged( $target, $file ) ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( 'An unchanged destination file changed after staging: ' . $file['path'] . '. Discard and prepare again.' ); }
				$new[ $file['path'] ] = $file;
				continue;
			}
			if ( ! is_file( $file['stage'] ) || ! hash_equals( $file['sha256'], CSM_Support::hash_file( $file['stage'] ) ) ) {
				throw new RuntimeException( 'A staged file is missing or changed.' ); }
			$new[ $file['path'] ]   = $file;
			$paths[ $file['path'] ] = $target;
		}
		CSM_Progress::update( array( 'count' => $checked ) );
		// Selected plugin/theme directories are replacements, not merges that leave obsolete PHP files behind.
		foreach ( array( 'plugins', 'themes' ) as $kind ) {
			foreach ( $job['selection'][ $kind ] as $component ) {
				CSM_Progress::update(
					array(
						'phase'     => 'Checking destination component',
						'component' => $kind . '/' . $component,
						'count'     => 0,
						'total'     => null,
						'unit'      => '',
						'bytes'     => 0,
					)
				);
				$root = CSM_Files::root( $kind, $component );
				$path = $root . '/' . CSM_Files::safe_relative( $component );
				if ( ! file_exists( $path ) ) {
					continue; }
				if ( is_link( $path ) ) {
					throw new RuntimeException( 'Destination component is a symbolic link.' ); }
				foreach ( CSM_Files::scan( $path, $kind . '/' . $component, $job['selection'] + array( 'protect_source_git' => true ) ) as $file ) {
					$relative = $kind . '/' . substr( $file, strlen( $root ) + 1 );
					if ( ! empty( $new[ $relative ]['unchanged'] ) ) {
						continue; }
					$paths[ $relative ] = CSM_Files::destination( $relative, $job['selection'] );
				}
			}
		}
		$checked = 0;
		foreach ( $paths as $relative => $target ) {
			CSM_Progress::update(
				array(
					'phase'     => 'Checking destination file',
					'component' => $relative,
					'count'     => $checked++,
					'total'     => count( $paths ),
					'unit'      => 'files',
					'bytes'     => 0,
				)
			);
			CSM_Files::assert_writable( $target, $relative );
		}
		CSM_Progress::update( array( 'count' => $checked ) );
		return array(
			'new'   => $new,
			'paths' => $paths,
		);
	}

	/**
	 * Build an actionable error for an incompatible selected component file.
	 *
	 * @param array  $job Destination job and its durable staging or recovery state.
	 * @param array  $file Recorded file metadata and expected content digest.
	 * @param string $problem Actionable compatibility failure description.
	 */
	private static function compatibility_error( array $job, array $file, string $problem ): RuntimeException {
		$parts = explode( '/', $file['path'] );
		$kind  = array(
			'plugins' => 'plugin',
			'themes'  => 'theme',
		)[ $parts[0] ] ?? 'file';
		$name  = 'file' === $kind ? basename( $file['path'] ) : $parts[1];
		if ( 'file' !== $kind ) {
			foreach ( $job['files'] as $candidate ) {
				$path = explode( '/', $candidate['path'] );
				if ( $path[0] !== $parts[0] || ( $path[1] ?? '' ) !== $parts[1] ) {
					continue; }
				if ( ( 'theme' === $kind && count( $path ) === 3 && 'style.css' === $path[2] ) || ( 'plugin' === $kind && count( $path ) <= 3 && str_ends_with( $candidate['path'], '.php' ) ) ) {
					$header = get_file_data( ! empty( $candidate['unchanged'] ) ? CSM_Files::destination( $candidate['path'], $job['selection'] ) : $candidate['stage'], array( 'name' => 'theme' === $kind ? 'Theme Name' : 'Plugin Name' ) );
					if ( trim( $header['name'] ) ) {
						$name = wp_strip_all_tags( $header['name'] );
						break; }
				}
			}
		}
		return new RuntimeException( ucfirst( $kind ) . ' "' . $name . '": ' . $problem . '. Update or deselect this ' . $kind . ', then retry. File: ' . $file['path'] . '.' );
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- Both compatibility_error() calls return RuntimeException, the same type as the direct throw.
	/**
	 * Require staged plugin and theme entry points to meet destination requirements.
	 *
	 * @param array $job Destination job and its durable staging or recovery state.
	 * @param array $file Recorded file metadata and expected content digest.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function check_compatibility( array $job, array $file ): void {
		$timing = hrtime( true );
		try {
			if ( ! empty( $file['unchanged'] ) ) {
				return; }
			$parts = explode( '/', $file['path'] );
			if ( str_ends_with( $file['path'], '.php' ) || ( 'themes' === $parts[0] && count( $parts ) === 3 && 'style.css' === $parts[2] ) ) {
				CSM_Progress::update(
					array(
						'phase'     => 'Checking PHP compatibility',
						'component' => $file['path'],
						'count'     => 0,
						'total'     => count( $job['files'] ),
						'unit'      => 'files',
						'bytes'     => 0,
					)
				); }
			if ( ( 'plugins' === $parts[0] && count( $parts ) <= 3 && str_ends_with( $file['path'], '.php' ) ) || ( 'themes' === $parts[0] && count( $parts ) === 3 && 'style.css' === $parts[2] ) ) {
				$headers = get_file_data(
					$file['stage'],
					array(
						'php' => 'Requires PHP',
						'wp'  => 'Requires at least',
					)
				);
				if ( $headers['php'] && version_compare( PHP_VERSION, $headers['php'], '<' ) ) {
					throw self::compatibility_error( $job, $file, 'requires PHP ' . $headers['php'] . '; destination uses PHP ' . PHP_VERSION ); }
				if ( $headers['wp'] && version_compare( get_bloginfo( 'version' ), $headers['wp'], '<' ) ) {
					throw new RuntimeException( $file['path'] . ' requires WordPress ' . $headers['wp'] ); }
			}
			if ( str_ends_with( $file['path'], '.php' ) ) {
				try {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
					token_get_all( file_get_contents( $file['stage'] ), TOKEN_PARSE ); } catch ( ParseError $e ) {
					throw self::compatibility_error( $job, $file, 'PHP parse error on destination PHP ' . PHP_VERSION . ' (line ' . $e->getLine() . ': ' . $e->getMessage() . ')' ); }
			}
		} finally {
			CSM_Timing::add( 'validation.php', $timing, 0 ); }
	}


	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Advance bounded pre-apply checks while retaining the preview cursor.
	 *
	 * @param array &$job Destination job and its durable staging or recovery state.
	 * @param bool  $continuing Whether to preserve the current work-step deadline.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function preview_step( array &$job, bool $continuing = false ): ?array {
		$timing = hrtime( true );
		try {
			$state = $job['preview_work'] ?? array(
				'phase'     => 'files',
				'index'     => 0,
				'new'       => array(),
				'paths'     => array(),
				'items'     => array(),
				'counts'    => array(
					'add'       => 0,
					'replace'   => 0,
					'delete'    => 0,
					'unchanged' => 0,
				),
				'backup'    => 0,
				'signature' => array(),
			);
			CSM_Support::step( 8, $continuing );
			if ( 'files' === $state['phase'] ) {
				$file_count = count( $job['files'] );
				while ( $state['index'] < $file_count ) {
					$file   = $job['files'][ $state['index'] ];
					$target = CSM_Files::destination( $file['path'], $job['selection'] );
					$check  = ! empty( $file['unchanged'] ) ? $target : $file['stage'];
					CSM_Progress::update(
						array(
							'phase'     => 'Verifying staged file',
							'component' => $file['path'],
							'count'     => $state['index'],
							'total'     => count( $job['files'] ),
							'unit'      => 'files',
						)
					);
					if ( ! ( ! empty( $file['unchanged'] ) && ! isset( $file['sha256'] ) ? CSM_Files::verify_unchanged( $check, $file ) : CSM_Support::verify_file( $check, $file['sha256'], $file['verification_receipt'] ) ) ) {
						throw new RuntimeException( 'A staged or unchanged file is missing or changed: ' . $file['path'] ); }
					self::check_compatibility( $job, $file );
					$job['files'][ $state['index'] ] = $file;
					$state['new'][ $file['path'] ]   = $file;
					if ( ! empty( $file['unchanged'] ) ) {
						++$state['counts']['unchanged']; } else {
						$state['paths'][ $file['path'] ] = $target; }
						++$state['index'];
						if ( CSM_Support::due() ) {
							$job['preview_work'] = $state;
							CSM_Jobs::save( $job );
							return null; }
				}
				$state['phase'] = 'obsolete';
				$state['index'] = 0;
			}
			if ( 'obsolete' === $state['phase'] ) {
				$roots = array(); foreach ( array( 'plugins', 'themes' ) as $kind ) {
					foreach ( $job['selection'][ $kind ] as $component ) {
						$roots[] = array( CSM_Files::root( $kind, $component ) . '/' . CSM_Files::safe_relative( $component ), $kind . '/' . $component );
					}
				}
				$state['walk'] ??= array();
				foreach ( CSM_Files::walk( $state['walk'], $roots, $job['selection'] + array( 'protect_source_git' => true ) ) as [$relative, $file] ) {
					if ( empty( $state['new'][ $relative ]['unchanged'] ) ) {
						$state['paths'][ $relative ] = CSM_Files::destination( $relative, $job['selection'] ); }
					if ( CSM_Support::due() ) {
						$job['preview_work'] = $state;
						CSM_Jobs::save( $job );
						return null; }
				}
				if ( $state['walk']['pending'] || $state['walk']['active'] ) {
					$job['preview_work'] = $state;
					CSM_Jobs::save( $job );
					return null; }
				unset( $state['walk'] );
				unset( $state['walked'] );
				$state['phase'] = 'plan';
				$state['index'] = 0;
			}
			$paths      = array_keys( $state['paths'] );
			$path_count = count( $paths );
			while ( $state['index'] < $path_count ) {
				$relative = $paths[ $state['index'] ];
				$target   = $state['paths'][ $relative ];
				$exists   = is_file( $target );
				$file     = $state['new'][ $relative ] ?? null;
				CSM_Files::assert_writable( $target, $relative );
				$action = ! $file ? 'delete' : ( $exists ? 'replace' : 'add' );
				$size   = $exists ? filesize( $target ) : 0;
				++$state['counts'][ $action ];
				$state['backup']                += $size;
				$state['items'][]                = array(
					'path'   => $relative,
					'action' => $action,
					'before' => $size,
					'after'  => $file['size'] ?? 0,
				);
				$state['signature'][ $relative ] = array( $action, $exists ? CSM_Support::hash_file( $target ) : null, $file['sha256'] ?? null );
				CSM_Progress::update(
					array(
						'phase'     => 'Checking destination file',
						'component' => $relative,
						'count'     => ++$state['index'],
						'total'     => count( $paths ),
						'unit'      => 'files',
					)
				);
				if ( CSM_Support::due() ) {
					$job['preview_work'] = $state;
					CSM_Jobs::save( $job );
					return null; }
			}
			$state['backup_work'] ??= null;
			$backup_bytes           = CSM_Directories::backup_bytes( $state['paths'], $job['selection'], $state['backup_work'] );
			if ( null === $backup_bytes ) {
				$job['preview_work'] = $state;
				CSM_Jobs::save( $job );
				return null; }
			$state['backup'] = $backup_bytes;
			$free            = ( function_exists( 'disk_free_space' ) ? disk_free_space( CSM_Support::private_dir() ) : false );
			if ( false !== $free && $free < $state['backup'] + 16777216 ) {
				throw new RuntimeException( 'Insufficient private storage for the destination rollback backup.' ); }
			ksort( $state['signature'], SORT_STRING );
			usort(
				$state['items'],
				static function ( $a, $b ) {
					$rank = array(
						'delete'  => 0,
						'replace' => 1,
						'add'     => 2,
					)[ $a['action'] ] <=> array(
						'delete'  => 0,
						'replace' => 1,
						'add'     => 2,
					)[ $b['action'] ];
					return $rank ? $rank : strcmp( $a['path'], $b['path'] );
				}
			);
			$raw  = CSM_Support::json( $state['items'] );
			$path = CSM_Support::private_dir() . '/' . $job['id'] . '-preview.json';
			CSM_Support::atomic_bytes( $path, $raw );
			$preview          = array(
				'counts'       => $state['counts'],
				'backup_bytes' => $state['backup'],
				'items'        => array_slice( $state['items'], 0, 200 ),
				'total'        => count( $state['items'] ),
				'warnings'     => $job['database'] ? array( 'Selected database tables replace destination tables completely.' ) : array(),
				'token'        => hash( 'sha256', CSM_Support::json( $state['signature'] ) ),
				'checked_at'   => time(),
				'file_hash'    => hash( 'sha256', $raw ),
			);
			$job['file_plan'] = array(
				'new'       => $state['new'],
				'paths'     => $state['paths'],
				'signature' => $state['signature'],
			);
			unset( $job['preview_work'] );
			CSM_Jobs::save( $job );
			return $preview;
		} finally {
			CSM_Timing::add( 'apply.preview', $timing, 0 ); }
	}

	/**
	 * Build the checked file-change preview for a staged destination job.
	 *
	 * @param array $job Destination job and its durable staging or recovery state.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function preview( array $job ): array {
		$plan      = self::changes( $job );
		$items     = array();
		$counts    = array(
			'add'       => 0,
			'replace'   => 0,
			'delete'    => 0,
			'unchanged' => 0,
		);
		$backup    = 0;
		$signature = array();
		foreach ( $job['files'] as $file ) {
			if ( ! empty( $file['unchanged'] ) ) {
				++$counts['unchanged']; }
		}
		foreach ( $plan['paths'] as $relative => $target ) {
			$exists = is_file( $target );
			$file   = $plan['new'][ $relative ] ?? null;
			$action = ! $file ? 'delete' : ( $exists ? 'replace' : 'add' );
			++$counts[ $action ];
			$size                   = $exists ? filesize( $target ) : 0;
			$backup                += $size;
			$items[]                = array(
				'path'   => $relative,
				'action' => $action,
				'before' => $size,
				'after'  => $file['size'] ?? 0,
			);
			$signature[ $relative ] = array( $action, $exists ? CSM_Support::hash_file( $target ) : null, $file['sha256'] ?? null );
		}
		$warnings = array();
		if ( $job['database'] ) {
			$warnings[] = 'Selected database tables replace destination tables completely. Concurrent orders, submissions, and content are not merged.'; }
		if ( $plan['paths'] ) {
			$warnings[] = 'Plugins and themes are replaced as complete components. A temporary maintenance screen protects normal visitors during apply; recovery access stays available.'; }
		$compat_checked = 0;
		foreach ( $job['files'] as $file ) {
			self::check_compatibility( $job, $file );
			++$compat_checked; }
		if ( $compat_checked ) {
			CSM_Progress::update(
				array(
					'phase'     => 'Checking PHP compatibility',
					'component' => '',
					'count'     => $compat_checked,
					'total'     => count( $job['files'] ),
					'unit'      => 'files',
					'bytes'     => 0,
				)
			); }
		$backup_work = null;
		$backup      = CSM_Directories::backup_bytes( $plan['paths'], $job['selection'], $backup_work, false );
		$free        = ( function_exists( 'disk_free_space' ) ? disk_free_space( CSM_Support::private_dir() ) : false );
		if ( false !== $free && $free < $backup + 16 * 1024 * 1024 ) {
			throw new RuntimeException( 'Insufficient private storage for the destination rollback backup.' ); }
		usort(
			$items,
			static function ( $a, $b ) {
				$rank = array(
					'delete'  => 0,
					'replace' => 1,
					'add'     => 2,
				)[ $a['action'] ] <=> array(
					'delete'  => 0,
					'replace' => 1,
					'add'     => 2,
				)[ $b['action'] ];
				return $rank ? $rank : strcmp( $a['path'], $b['path'] );
			}
		);
		ksort( $signature, SORT_STRING );
		return array(
			'counts'       => $counts,
			'backup_bytes' => $backup,
			'items'        => $items,
			'total'        => count( $items ),
			'warnings'     => $warnings,
			'token'        => hash( 'sha256', CSM_Support::json( $signature ) ),
			'checked_at'   => time(),
		);
	}

	/**
	 * Read one bounded page of the destination file-change preview.
	 *
	 * @param array $job Destination job and its durable staging or recovery state.
	 * @param int   $offset Zero-based byte, row or preview offset for this operation.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function preview_page( array $job, int $offset = 0 ): array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Missing local input is checked immediately and rejected or treated as an absent cache. Read exact local file or input-stream bytes; no outbound HTTP request is made.
		$raw = @file_get_contents( CSM_Support::private_dir() . '/' . $job['id'] . '-preview.json' );
		if ( $offset < 0 || false === $raw || ! hash_equals( $job['preview']['file_hash'] ?? '', hash( 'sha256', $raw ) ) ) {
			throw new RuntimeException( 'Transfer preview is unavailable or changed. Prepare again.' ); }
		$items = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		return array(
			'items' => array_slice( $items, $offset, 200 ),
			'more'  => $offset + 200 < count( $items ),
			'total' => count( $items ),
		);
	}

	/**
	 * Apply a staged job through durable backups, publication and installed-state checks.
	 *
	 * @param array       $job Destination job and its durable staging or recovery state.
	 * @param string|null $run Optional existing progress activity identifier.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function commit( array $job, ?string $run = null ): array {
		CSM_Updates::assert_job( $job );
		if ( 'restoring' === $job['status'] ) {
			$result = self::rollback( $job );
			if ( empty( $result['continue'] ) && ! empty( $job['error'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Apply failed; changes rolled back. ' . $job['error'] ); }
			return $result;
		}
		CSM_Progress::start( $job['id'], 'Checking destination', $run );
		$actor = current_user_can( 'manage_options' ) ? wp_get_current_user() : null;
		if ( ! in_array( $job['status'], array( 'staging', 'applying' ), true ) || ! self::summary( $job )['ready'] ) {
			throw new RuntimeException( 'Both selected streams must be complete before applying.' ); }
		if ( $job['files'] && ! wp_is_file_mod_allowed( 'trickle' ) ) {
			throw new RuntimeException( 'WordPress configuration disallows file modifications on this destination.' ); }
		if ( $job['files'] && ! self::loader_ready() ) {
			throw new RuntimeException( 'Activate Trickle again to install its required recovery loader before applying file transfers.' ); }
		$base              = $job['backup_dir'] ?? CSM_Support::backup_dir() . '/' . $job['id'] . '-backup';
		$job['backup_dir'] = $base;
		if ( empty( $job['apply_work'] ) ) {
			CSM_Activation::capture( $job );
			CSM_Jobs::save( $job );
			if ( CSM_Jobs::get( $job['id'] ) !== $job ) {
				throw new RuntimeException( 'Could not persist the transfer recovery state.' ); }
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private staging with explicit permissions; no FTP backend may replace it.
		if ( ! is_dir( $base ) && ! mkdir( $base, 0700 ) ) {
			throw new RuntimeException( 'Cannot create rollback storage.' ); }
		CSM_Support::step();
		if ( empty( $job['apply_work'] ) ) {
			$preview = self::preview_step( $job );
			if ( ! $preview ) {
				return self::summary( $job ) + array( 'continue' => true ); }
			if ( isset( $job['preview'] ) && ! hash_equals( $job['preview']['token'], $preview['token'] ) ) {
				throw new RuntimeException( 'Destination code changed after preview. Discard and prepare again.' ); }
			$plan              = $job['file_plan'];
			$backup_paths      = array_filter( $plan['paths'], static fn( $path )=>str_starts_with( $path, 'uploads/' ), ARRAY_FILTER_USE_KEY );
			$job['apply_work'] = array(
				'phase'            => 'backup',
				'backup_paths'     => $backup_paths,
				'ops'              => array(),
				'cursor'           => 0,
				'whole_components' => true,
			);
			CSM_Jobs::save( $job );
		}
		$work        =&$job['apply_work'];
		$cursor_path = CSM_Support::private_dir() . '/' . $job['id'] . '-apply.json';
		if ( is_file( $cursor_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
			$work = array_replace( $work, json_decode( file_get_contents( $cursor_path ), true ) ); }
		$paths = $job['file_plan']['paths'];
		if ( ! empty( $work['whole_components'] ) && ! CSM_Timing::measure(
			'apply.components',
			static function () use ( &$job ) {
				return CSM_Directories::prepare( $job );
			}
		) ) {
			return self::summary( $job ) + array( 'continue' => true ); }
		if ( ! self::backup_files( $job, $base ) ) {
			return self::summary( $job ) + array( 'continue' => true ); }
		if ( 'applying' !== $job['status'] || empty( $job['ops_hash'] ) ) {
			self::save_recovery_plan( $job, $base ); }
		wp_clear_scheduled_hook( 'trickle_recover', array( $job['id'] ) );
		wp_schedule_single_event( time() + 60, 'trickle_recover', array( $job['id'] ) );
		if ( $paths || $job['database'] || isset( $job['active_plugins_before'] ) ) {
			update_option(
				'_csm_maintenance',
				array(
					'id'    => $job['id'],
					'until' => time() + 15 * MINUTE_IN_SECONDS,
				),
				false
			); }
		if ( ! CSM_Timing::measure( 'apply.runtime_lock', static fn()=>CSM_Support::runtime_lock( true ) ) ) {
			CSM_Progress::update(
				array(
					'phase'     => 'Waiting for active site requests',
					'component' => '',
				),
				true
			);
			return self::summary( $job ) + array( 'continue' => true );
		}
		try {
			if ( ! self::replace_files( $job, $cursor_path ) ) {
				return self::summary( $job ) + array( 'continue' => true ); }
			CSM_Support::check_cancel();
			if ( empty( $job['db_applied'] ) ) {
				$token                 = $actor ? wp_get_session_token() : '';
				$authenticated_session = '' !== $token ? WP_Session_Tokens::get_instance( $actor->ID )->get( $token ) : null;
				CSM_Timing::measure(
					'apply.database',
					static function () use ( &$job ) {
						CSM_Database::replace_tables( $job );
					}
				);
				$authentication    = self::retain_controller( $actor, $job, $authenticated_session ? $authenticated_session : null );
				$job['db_applied'] = true;
				CSM_Jobs::save( $job );
			} else {
				$authentication = self::retain_controller( $actor, $job ); }
			return self::finish_apply( $job, $authentication );
		} catch ( Throwable $e ) {
			CSM_Progress::fail( $e->getMessage() );
			$job['error'] = $e->getMessage();
			CSM_Jobs::save( $job );
			try {
				$recovered = self::rollback( $job );
				if ( ! empty( $recovered['continue'] ) ) {
					return $recovered;
				}
			} catch ( Throwable $rollback_error ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( $e->getMessage() . ' Rollback needs attention: ' . $rollback_error->getMessage() ); }
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Apply failed; changes rolled back. ' . $e->getMessage() );
		}
	}

	/**
	 * Advance resumable destination file backups before replacement.
	 *
	 * @param array  &$job Destination job and its durable staging or recovery state.
	 * @param string $base Absolute private backup/recovery storage directory.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function backup_files( array &$job, string $base ): bool {
		$timing = hrtime( true );
		try {
			$work = &$job['apply_work'];
			$new  = $job['file_plan']['new'];
			if ( 'backup' === $work['phase'] ) {
				$backup_paths = $work['backup_paths'];
				$keys         = array_keys( $backup_paths );
				// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- Recovery operations are appended after each checkpoint; re-read the journal length.
				while ( count( $work['ops'] ) < count( $keys ) ) {
					$relative = $keys[ count( $work['ops'] ) ];
					$target   = $backup_paths[ $relative ];
					if ( CSM_Files::destination( $relative, $job['selection'] ) !== $target ) {
						throw new RuntimeException( 'Destination path changed after preview: ' . $relative );
					} $existed = is_file( $target );
					$backup    = $base . '/' . count( $work['ops'] );
					CSM_Progress::update(
						array(
							'phase'     => 'Backing up destination file',
							'component' => $relative,
							'count'     => count( $work['ops'] ),
							'total'     => count( $keys ),
							'unit'      => 'files',
						)
					);
					if ( $existed && ! self::copy_step( $target, $backup, $work['copy'] ) ) {
						CSM_Jobs::save( $job );
						return false; }
					$digest   = $existed ? CSM_Support::hash_file( $backup ) : null;
					$expected = array_key_exists( $relative, $job['file_plan']['signature'] ) ? $job['file_plan']['signature'][ $relative ][1] : ( $new[ $relative ]['sha256'] ?? null );
					if ( $expected !== $digest ) {
						throw new RuntimeException( 'Destination changed while backing up: ' . $relative ); }
					$work['ops'][] = array(
						'path'    => $relative,
						'backup'  => $backup,
						'existed' => $existed,
						'mtime'   => $existed ? filemtime( $target ) : 0,
						'mode'    => $existed ? ( fileperms( $target ) & 0777 ) : 0644,
						'sha256'  => $digest,
					);
					unset( $work['copy'] );
					if ( CSM_Support::due() ) {
						CSM_Jobs::save( $job );
						return false; }
				}
				$work['phase'] = 'apply';
				CSM_Jobs::save( $job );
			}
			return true;
		} finally {
			CSM_Timing::add( 'apply.media_backup', $timing, 0 ); }
	}

	/**
	 * Persist the immutable recovery plan before mutating destination files.
	 *
	 * @param array  &$job Destination job and its durable staging or recovery state.
	 * @param string $base Absolute private backup/recovery storage directory.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function save_recovery_plan( array &$job, string $base ): void {
		$timing = hrtime( true );
		try {
			$ops = array_merge( $job['apply_work']['directories']['ops'] ?? array(), $job['apply_work']['ops'] );
			// Persist the complete recovery plan before mutation, without rewriting the large job per file.
			$plan = CSM_Support::json( $ops );
			CSM_Support::atomic_bytes( $base . '/operations.json', $plan );
			$job['backup_dir']    = $base;
			$job['ops_hash']      = hash( 'sha256', $plan );
			$job['ops']           = array();
			$job['code_snapshot'] = false;
			$components           = array(
				'plugins' => array(),
				'themes'  => array(),
				'media'   => false,
			);
			foreach ( $ops as $op ) {
				[$kind, $component] = explode( '/', $op['path'], 3 );
				if ( 'uploads' === $kind ) {
					$components['media'] = true; } elseif ( isset( $components[ $kind ] ) && ! in_array( $component, $components[ $kind ], true ) ) {
					$components[ $kind ][] = $component; }
			}
			$job['backup_components'] = $components;
			$job['status']            = 'applying';
			CSM_Jobs::save( $job );
			if ( CSM_Jobs::get( $job['id'] ) !== $job ) {
				throw new RuntimeException( 'Could not persist the transfer recovery state.' ); }
		} finally {
			CSM_Timing::add( 'apply.recovery_plan', $timing, 0 ); }
	}

	/**
	 * Advance journaled file publication with durable installation cursors.
	 *
	 * @param array  &$job Destination job and its durable staging or recovery state.
	 * @param string $cursor_path Absolute path to the durable replacement cursor.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function replace_files( array &$job, string $cursor_path ): bool {
		$timing = hrtime( true );
		try {
			$work  = &$job['apply_work'];
			$new   = $job['file_plan']['new'];
			$paths = $job['file_plan']['paths'];
			foreach ( $job['apply_work']['directories']['ops'] ?? array() as $index => $op ) {
				if ( $index < ( $work['directory_cursor'] ?? 0 ) ) {
					continue; }
				CSM_Progress::update(
					array(
						'phase'     => 'Replacing component',
						'component' => $op['path'],
					)
				);
				if ( ! CSM_Directories::replace( $op, $job['selection'] ) ) {
					CSM_Jobs::save( $job );
					return false; }
				// Each swap has its own durable journal; replaying a completed swap is harmless.
				$work['directory_cursor'] = $index + 1;
				if ( CSM_Support::due() ) {
					CSM_Jobs::save( $job );
					return false; }
			}
			$replacement = array(
				'plugins' => array(
					'done'  => 0,
					'total' => 0,
				),
				'themes'  => array(
					'done'  => 0,
					'total' => 0,
				),
				'media'   => array(
					'done'  => 0,
					'total' => 0,
				),
			);
			$position    = 0;
			foreach ( $paths as $relative => $target ) {
				$kind = explode( '/', $relative, 2 )[0];
				if ( 'uploads' === $kind ) {
					$kind = 'media';
				}
				if ( isset( $replacement[ $kind ] ) ) {
					++$replacement[ $kind ]['total'];
					if ( $position < $work['cursor'] ) {
						++$replacement[ $kind ]['done'];
					}
				}
				++$position;
			}
			CSM_Progress::update( array( 'replacement' => $replacement ) );
			$completed = $work['cursor'];
			$index     = 0;
			foreach ( $paths as $relative => $target ) {
				if ( $index++ < $work['cursor'] ) {
					continue; }
				if ( ! empty( $work['whole_components'] ) && ! str_starts_with( $relative, 'uploads/' ) ) {
					$kind = explode( '/', $relative, 2 )[0];
					if ( isset( $replacement[ $kind ] ) ) {
						++$replacement[ $kind ]['done'];
					}
					CSM_Progress::update(
						array(
							'count'       => ++$completed,
							'replacement' => $replacement,
						)
					);
					$work['cursor'] = $completed;
					do_action( 'csm_checkpoint', $job['id'], 'apply', $completed );
					// Component journals already protect these files; checkpoint the counters as a batch.
					if ( CSM_Support::due() ) {
						CSM_Support::atomic_json(
							$cursor_path,
							array(
								'cursor'  => $completed,
								'install' => null,
							)
						);
						CSM_Jobs::save( $job );
						return false; }
					continue;
				}
				if ( CSM_Files::destination( $relative, $job['selection'] ) !== $target ) {
					throw new RuntimeException( 'Destination path changed after preview: ' . $relative ); }
				CSM_Progress::update(
					array(
						'phase'     => isset( $new[ $relative ] ) ? 'Replacing file' : 'Removing obsolete file',
						'component' => $relative,
						'count'     => $completed,
						'total'     => count( $paths ),
						'unit'      => 'files',
					)
				);
				if ( isset( $new[ $relative ] ) ) {
					$file = $new[ $relative ];
					if ( ! empty( $file['media_part'] ) ) {
						CSM_Files::publish_media( $job, $file, $target );
					} else {
						$done = self::install_file_step(
							$file['stage'],
							$target,
							$file['mtime'],
							$file['mode'],
							$work['install'],
							static function ( $state ) use ( $cursor_path, &$work ) {
								CSM_Support::atomic_json(
									$cursor_path,
									array(
										'cursor'  => $work['cursor'],
										'install' => $state,
									)
								);
							}
						);
						if ( ! $done ) {
							CSM_Support::atomic_json(
								$cursor_path,
								array(
									'cursor'  => $work['cursor'],
									'install' => $work['install'],
								)
							);
							CSM_Jobs::save( $job );
							return false;
						}
						unset( $work['install'] );
					}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the validated local file during cleanup or recoverable publication.
				} elseif ( is_file( $target ) && ! unlink( $target ) ) {
					throw new RuntimeException( 'Could not remove obsolete file.' );
				}
				$kind = explode( '/', $relative, 2 )[0];
				if ( 'uploads' === $kind ) {
					$kind = 'media';
				}
				if ( isset( $replacement[ $kind ] ) ) {
					++$replacement[ $kind ]['done'];
				}
				CSM_Progress::update(
					array(
						'count'       => ++$completed,
						'replacement' => $replacement,
					)
				);
				$work['cursor'] = $completed;
				CSM_Support::atomic_json(
					$cursor_path,
					array(
						'cursor'  => $completed,
						'install' => null,
					)
				);
				do_action( 'csm_checkpoint', $job['id'], 'apply', $completed );
				if ( CSM_Support::due() ) {
					CSM_Jobs::save( $job );
					return false; }
			}
			if ( ! empty( $work['whole_components'] ) && $paths ) {
				CSM_Support::atomic_json(
					$cursor_path,
					array(
						'cursor'  => $work['cursor'],
						'install' => null,
					)
				); }
			return true;
		} finally {
			CSM_Timing::add( 'apply.replace_files', $timing, 0 ); }
	}

	/**
	 * Verify installed file and table state within the current work budget.
	 *
	 * @param array &$job Destination job and its durable staging or recovery state.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function verify_installed_step( array &$job ): bool {
		$timing = hrtime( true );
		try {
			$state   = &$job['verification_work'];
			$state ??= array(
				'phase'             => 'files',
				'cursor'            => 0,
				'scoped_components' => true,
			);
			$paths   = $job['file_plan']['paths'];
			$new     = $job['file_plan']['new'];
			if ( ! empty( $job['apply_work']['whole_components'] ) ) {
				// Older cursors index the complete list; changing that list mid-resume could skip files.
				$ops     = ! empty( $state['scoped_components'] ) ? ( $job['apply_work']['directories']['ops'] ?? null ) : null;
				$rebuilt = null === $ops ? null : array_fill_keys( array_column( $ops, 'path' ), true );
				foreach ( $new as $relative => $file ) {
					// Only rebuilt components have new copies of otherwise unchanged files to verify.
					$parts     = explode( '/', $relative, 3 );
					$component = $parts[0] . '/' . ( $parts[1] ?? '' );
					if ( null === $rebuilt || isset( $rebuilt[ $component ] ) ) {
						$paths[ $relative ] = true; }
				}
			}
			if ( 'files' === $state['phase'] ) {
				foreach ( array_keys( $paths ) as $index => $relative ) {
					if ( $index < $state['cursor'] ) {
						continue; }
					CSM_Support::check_cancel();
					CSM_Progress::update(
						array(
							'phase'     => 'Verifying installed file',
							'component' => $relative,
							'count'     => $index,
							'total'     => count( $paths ),
							'unit'      => 'files',
						)
					);
					$target = CSM_Files::destination( $relative, $job['selection'] );
					if ( isset( $new[ $relative ] ) ) {
						if ( ! is_file( $target ) || ! hash_equals( $new[ $relative ]['sha256'], CSM_Support::hash_file( $target ) ) ) {
							throw new RuntimeException( 'Installed file checksum failed: ' . $relative ); }
					} else {
						// OPcache file overrides can report removed PHP files as present after a directory swap.
						if ( function_exists( 'opcache_invalidate' ) ) {
							opcache_invalidate( $target, true ); }
						clearstatcache( true, $target );
						if ( file_exists( $target ) || is_link( $target ) ) {
							throw new RuntimeException( 'Obsolete file remains after replacement: ' . $relative ); }
					}
					$state['cursor'] = $index + 1;
					if ( CSM_Support::due() ) {
						CSM_Jobs::save( $job );
						return false; }
				}
				$state['phase']  = 'tables';
				$state['cursor'] = 0;
			}
			foreach ( $job['database'] as $index => $table ) {
				if ( $index < $state['cursor'] ) {
					continue; }
				CSM_Support::check_cancel();
				CSM_Progress::update(
					array(
						'phase'     => 'Verifying installed table',
						'component' => $table['target'],
						'count'     => $index,
						'total'     => count( $job['database'] ),
						'unit'      => 'tables',
					)
				);
				if ( ! CSM_Database::table_exists( $table['target'] ) || CSM_Database::table_exists( $table['stage'] ) || ( ! empty( $table['existed'] ) && ! CSM_Database::table_exists( $table['backup'] ) ) ) {
					throw new RuntimeException( 'Table replacement verification failed: ' . $table['target'] ); }
				$state['cursor'] = $index + 1;
				if ( CSM_Support::due() ) {
					CSM_Jobs::save( $job );
					return false; }
			}
			CSM_Progress::update(
				array(
					'phase'     => 'Checking destination state',
					'component' => '',
					'count'     => 0,
					'total'     => null,
					'unit'      => '',
				),
				true
			);
			if ( isset( $job['active_plugins_before'] ) ) {
				$expected = array_values( array_diff( $job['source_active_plugins'], $job['activation_missing'] ?? array() ) );
				$own      = plugin_basename( CSM_FILE );
				if ( ! in_array( $own, $expected, true ) ) {
					$expected[] = $own; }
				if ( CSM_Activation::current() !== $expected ) {
					throw new RuntimeException( 'Plugin activation changed during replacement verification.' ); }
			}
			$job['verification'] = array(
				'verified' => true,
				'files'    => count( $paths ),
				'tables'   => count( $job['database'] ),
			);
			return true;
		} finally {
			CSM_Timing::add( 'apply.verify_installed', $timing, 0 ); }
	}

	/**
	 * Finalize verified installation and retain its destination backup and session state.
	 *
	 * @param array &$job Destination job and its durable staging or recovery state.
	 * @param array $authentication Preserved controller account and session state.
	 */
	private static function finish_apply( array &$job, array $authentication ): array {
		if ( empty( $job['activation_applied'] ) ) {
			CSM_Timing::measure(
				'apply.activation',
				static function () use ( &$job ) {
					CSM_Activation::apply( $job );
				}
			);
			$job['activation_applied'] = true;
			CSM_Jobs::save( $job ); }
		if ( ! self::verify_installed_step( $job ) ) {
			return self::summary( $job ) + array( 'continue' => true ) + $authentication; }
		$job['status']  = 'applied';
		$job['applied'] = time();
		CSM_Jobs::save( $job );
		// Install and removal paths already invalidate their files; keep unrelated cached code warm.
		if ( ! function_exists( 'opcache_invalidate' ) && function_exists( 'opcache_reset' ) ) {
			opcache_reset(); }
		self::end_maintenance( $job['id'] );
		wp_clear_scheduled_hook( 'trickle_recover', array( $job['id'] ) );
		unset( $job['apply_work'], $job['file_plan'], $job['verification_work'] );
		CSM_Jobs::save( $job );
		CSM_Progress::finish( 'Transfer complete' );
		$result = array_merge( self::summary( $job ), $authentication );
		CSM_Timing::measure(
			'cleanup.retain',
			static function () use ( &$job ) {
				CSM_Backups::retain( $job );
			}
		);
		return $result;
	}

	/**
	 * Advance file restoration from the recorded recovery operations.
	 *
	 * @param array &$job Destination job and its durable staging or recovery state.
	 * @param array $ops Immutable recorded file recovery operations.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function restore_files( array &$job, array $ops ): bool {
		$restore_path = CSM_Support::private_dir() . '/' . $job['id'] . '-restore.json';
		if ( is_file( $restore_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
			$cursor                 = json_decode( file_get_contents( $restore_path ), true );
			$job['restore_cursor']  = $cursor['cursor'];
			$job['restore_install'] = $cursor['install'] ?? null; }
		$restored = $job['restore_cursor'] ?? 0;
		$index    = 0;
		foreach ( array_reverse( $ops ) as $op ) {
			if ( $index++ < $restored ) {
				continue; }
			CSM_Progress::update(
				array(
					'phase'     => 'Restoring file',
					'component' => $op['path'],
				)
			);
			if ( ! empty( $op['directory'] ) ) {
				if ( ! CSM_Directories::restore( $job, $op, $index ) ) {
					return false; }
				$job['restore_cursor'] = $index;
				CSM_Support::atomic_json(
					$restore_path,
					array(
						'cursor'  => $index,
						'install' => null,
					)
				);
				CSM_Jobs::save( $job );
				CSM_Support::remove_tree( dirname( $op['backup'] ) . '/restore-' . $job['id'] . '-' . $index );
				if ( CSM_Support::due() ) {
					return false; }
				continue;
			}
			if ( CSM_Files::excluded( $op['path'] ) ) {
				continue; }
			$target = CSM_Files::destination( $op['path'], $job['selection'] );
			if ( $op['existed'] ) {
				$verify = empty( $job['restore_install']['copy']['stamp'] ) && isset( $op['sha256'] );
				if ( ! is_file( $op['backup'] ) || ( $verify && ! hash_equals( $op['sha256'], CSM_Support::hash_file( $op['backup'] ) ) ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( 'Backup file is missing or changed: ' . $op['path'] );
				}
				$done = self::install_file_step(
					$op['backup'],
					$target,
					$op['mtime'],
					$op['mode'],
					$job['restore_install'],
					static function ( $state ) use ( $restore_path, &$job ) {
						CSM_Support::atomic_json(
							$restore_path,
							array(
								'cursor'  => $job['restore_cursor'] ?? 0,
								'install' => $state,
							)
						);
					}
				);
				if ( ! $done ) {
					CSM_Support::atomic_json(
						$restore_path,
						array(
							'cursor'  => $job['restore_cursor'] ?? 0,
							'install' => $job['restore_install'],
						)
					);
					CSM_Jobs::save( $job );
					return false;
				}
				unset( $job['restore_install'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the validated local file during cleanup or recoverable publication.
			} elseif ( is_file( $target ) && ! unlink( $target ) ) {
				throw new RuntimeException( 'Could not remove restored file.' );
			}
			$job['restore_cursor'] = $index;
			CSM_Support::atomic_json(
				$restore_path,
				array(
					'cursor'  => $index,
					'install' => null,
				)
			);
			if ( CSM_Support::due() ) {
				CSM_Jobs::save( $job );
				return false; }
		}
		return true;
	}

	/**
	 * Clear maintenance protection owned by the specified transfer.
	 *
	 * @param string $id Transfer identifier.
	 */
	private static function end_maintenance( string $id ): void {
		if ( ( get_option( '_csm_maintenance', array() )['id'] ?? '' ) === $id ) {
			delete_option( '_csm_maintenance' ); }
		delete_option( '_csm_recovery_attempts_' . $id );
		delete_option( '_csm_recovery_blocked_' . $id );
	}

	/**
	 * Copy bounded file bytes while retaining resumable source and destination offsets.
	 *
	 * @param string        $source Source filesystem path or source inventory, as specified by the parameter type.
	 * @param string        $target Destination filesystem path.
	 * @param array|null    &$state Resumable work state; updated in place when passed by reference.
	 * @param bool          $sealed Whether the destination part is protected between resumable copy steps.
	 * @param resource|null $target_handle Optional caller-owned output stream already opened for this destination.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function copy_step( string $source, string $target, ?array &$state, bool $sealed = false, $target_handle = null ): bool {
		$timing = hrtime( true );
		$copied = 0;
		clearstatcache( true, $source );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing or replaced file is handled by the following metadata validation or fallback.
		$stat = @stat( $source );
		if ( ! $stat || ! is_file( $source ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Copy source is missing: ' . basename( $source ) ); }
		$stamp   = array( $stat['size'], $stat['mtime'], $stat['ctime'], $stat['ino'] );
		$state ??= array(
			'offset' => 0,
			'stamp'  => $stamp,
		);
		if ( $stamp !== $state['stamp'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'File changed during copy: ' . basename( $source ) ); }
		if ( $state['offset'] > 0 && ( ! is_file( $target ) || filesize( $target ) < $state['offset'] ) ) {
			throw new RuntimeException( 'Copy checkpoint is missing or truncated.' ); }
		if ( $sealed && ! is_resource( $target_handle ) && is_file( $target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
			chmod( $target, 0600 ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$in = fopen( $source, 'rb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$out = $target_handle ?? fopen( $target, 'c+b' );
		if ( $sealed && ! is_resource( $target_handle ) && is_file( $target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
			chmod( $target, 0000 ); }
		if ( ! $in || ! $out || ! ftruncate( $out, $state['offset'] ) || fseek( $in, $state['offset'] ) !== 0 || fseek( $out, $state['offset'] ) !== 0 ) {
			throw new RuntimeException( 'Cannot resume file copy.' ); }
		try {
			while ( $state['offset'] < $stat['size'] ) {
				CSM_Support::check_cancel();
				$bytes = stream_copy_to_stream( $in, $out, min( 262144, $stat['size'] - $state['offset'] ) );
				if ( false === $bytes || 0 === $bytes ) {
					throw new RuntimeException( 'File copy was truncated.' ); }
				$state['offset'] += $bytes;
				$copied          += $bytes;
				if ( CSM_Support::due() ) {
					break; }
			}
			fflush( $out );
			return $state['offset'] === $stat['size'];
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $in );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $out );
			CSM_Timing::add( 'files.copy', $timing, $copied ); }
	}

	/**
	 * Install a file in bounded steps with durable publication state and metadata.
	 *
	 * @param string        $source Source filesystem path or source inventory, as specified by the parameter type.
	 * @param string        $target Destination filesystem path.
	 * @param int           $mtime Source modification time to restore after publication.
	 * @param int           $mode Destination permission bits.
	 * @param array|null    &$state Resumable work state; updated in place when passed by reference.
	 * @param callable|null $checkpoint Optional callback persisting a completed file-installation checkpoint.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function install_file_step( string $source, string $target, int $mtime, int $mode, ?array &$state, ?callable $checkpoint = null ): bool {
		if ( ! is_dir( dirname( $target ) ) && ! wp_mkdir_p( dirname( $target ) ) ) {
			throw new RuntimeException( 'Cannot create destination directory.' ); }
		$setup  = hrtime( true );
		$handle = null;
		try {
			if ( ! $state ) {
				$temp = tempnam( dirname( $target ), '.csm-' );
				if ( ! $temp ) {
					throw new RuntimeException( 'Cannot create replacement file.' ); }
				// Keep the descriptor open so copying need not unseal and reseal the new file.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
				$handle = fopen( $temp, 'c+b' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
				if ( ! $handle || ! chmod( $temp, 0000 ) ) {
					if ( is_resource( $handle ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
						fclose( $handle );
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
					} @unlink( $temp );
					throw new RuntimeException( 'Cannot seal replacement file.' ); }
				$state = array(
					'temp' => $temp,
					'copy' => null,
				);
				if ( $checkpoint ) {
					$checkpoint( $state ); }
			}
			if ( ! is_file( $state['temp'] ) ) {
				$state = null;
				return self::install_file_step( $source, $target, $mtime, $mode, $state, $checkpoint ); }
			CSM_Timing::add( 'install.setup', $setup );
			if ( ! self::copy_step( $source, $state['temp'], $state['copy'], true, $handle ) ) {
				return false; }
			$publish = hrtime( true );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Retain source timestamps or the recovery heartbeat on local storage.
			touch( $state['temp'], $mtime );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename is the atomic publication/recovery boundary.
			if ( ! rename( $state['temp'], $target ) ) {
				throw new RuntimeException( 'Could not replace destination file.' ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
			if ( ! chmod( $target, $mode ) ) {
				throw new RuntimeException( 'Could not set destination file permissions.' ); }
			if ( function_exists( 'opcache_invalidate' ) ) {
				opcache_invalidate( $target, true ); }
			CSM_Timing::add( 'install.publish', $publish );
			return true;
		} finally {
			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $handle ); }
		}
	}

	/**
	 * Read the immutable recovery operations associated with a job.
	 *
	 * @param array $job Destination job and its durable staging or recovery state.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function recovery_operations( array $job ): array {
		if ( ! isset( $job['ops_hash'] ) ) {
			return $job['ops']; }
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Missing local input is checked immediately and rejected or treated as an absent cache. Read exact local file or input-stream bytes; no outbound HTTP request is made.
		$plan = @file_get_contents( ( $job['backup_dir'] ?? CSM_Support::private_dir() . '/' . $job['id'] . '-backup' ) . '/operations.json' );
		if ( false === $plan || ! hash_equals( $job['ops_hash'], hash( 'sha256', $plan ) ) ) {
			throw new RuntimeException( 'The file recovery plan is missing or changed.' ); }
		$ops = json_decode( $plan, true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $ops ) || ! array_is_list( $ops ) ) {
			throw new RuntimeException( 'Invalid file recovery plan.' ); }
		return $ops;
	}

	/**
	 * Restore original destination state through the retained recovery plan.
	 *
	 * @param array $job Destination job and its durable staging or recovery state.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function rollback( array $job ): array {
		CSM_Support::work( $job['id'], false );
		CSM_Progress::update(
			array(
				'phase'     => 'Restoring destination backup',
				'component' => '',
				'count'     => 0,
				'total'     => null,
				'unit'      => '',
			)
		);
		$actor                 = current_user_can( 'manage_options' ) ? wp_get_current_user() : null;
		$authenticated_session = $actor ? WP_Session_Tokens::get_instance( $actor->ID )->get( wp_get_session_token() ) : null;
		if ( ! in_array( $job['status'], array( 'applied', 'applying', 'restoring' ), true ) ) {
			throw new RuntimeException( 'Only applied or interrupted transfers can be restored.' ); }
		$active = get_option( '_csm_active_job' );
		if ( $active && $active !== $job['id'] ) {
			throw new RuntimeException( 'Finish the active transfer before restoring another backup.' ); }
		$job['status'] = 'restoring';
		CSM_Jobs::save( $job );
		CSM_Support::work( $job['id'], false );
		CSM_Support::step();
		if ( empty( get_option( '_csm_maintenance' ) ) ) {
			update_option(
				'_csm_maintenance',
				array(
					'id'    => $job['id'],
					'until' => time() + 900,
				),
				false
			); }
		wp_clear_scheduled_hook( 'trickle_recover', array( $job['id'] ) );
		wp_schedule_single_event( time() + 60, 'trickle_recover', array( $job['id'] ) );
		$apply_cursor = CSM_Support::private_dir() . '/' . $job['id'] . '-apply.json';
		if ( is_file( $apply_cursor ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
			$cursor = json_decode( file_get_contents( $apply_cursor ), true );
			if ( isset( $job['apply_work'] ) ) {
				$job['apply_work'] = array_replace( $job['apply_work'], $cursor ); }
		}
		if ( ! empty( $job['apply_work']['install']['temp'] ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
			@unlink( $job['apply_work']['install']['temp'] );
			unset( $job['apply_work']['install'] ); }
		if ( ! CSM_Support::runtime_lock( true ) ) {
			CSM_Progress::update(
				array(
					'phase'     => 'Waiting for active site requests',
					'component' => '',
				),
				true
			);
			return self::summary( $job ) + array( 'continue' => true );
		}
		$ops = self::recovery_operations( $job );
		CSM_Database::restore_tables( $job );
		$authentication = self::retain_controller( $actor, $job, $authenticated_session ? $authenticated_session : null );
		if ( ! self::restore_files( $job, $ops ) ) {
			return self::summary( $job ) + array( 'continue' => true ) + $authentication; }
		CSM_Activation::restore( $job );
		CSM_Files::cleanup_media_parts( $job );
		self::end_maintenance( $job['id'] );
		wp_clear_scheduled_hook( 'trickle_recover', array( $job['id'] ) );
		$job['status'] = 'restored';
		CSM_Jobs::save( $job );
		CSM_Backups::forget( $job['id'] );
		if ( get_option( '_csm_active_job' ) === $job['id'] ) {
			delete_option( '_csm_active_job' ); }
		CSM_Progress::finish( 'Destination restored' );
		return array_merge( self::summary( $job ), $authentication );
	}

	/**
	 * Preserve the controlling administrator's account and authenticated session state.
	 *
	 * @param WP_User|null $actor Controlling local administrator, or null for a peer-authorized operation.
	 * @param array        $job Destination job and its durable staging or recovery state.
	 * @param array|null   $authenticated_session Previously authenticated controller session metadata, when available.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function retain_controller( ?WP_User $actor, array $job, ?array $authenticated_session = null ): array {
		global $wpdb;
		if ( ! $actor || ! array_intersect( array_column( $job['database'], 'target' ), array( $wpdb->users, $wpdb->usermeta ) ) ) {
			return array(); }
		// Keep the authenticated local operator in control even when importing a different users table.
		clean_user_cache( $actor->ID );
		$user = get_user_by( 'login', $actor->user_login );
		if ( ! $user ) {
			$id = wp_insert_user(
				array(
					'user_login'   => $actor->user_login,
					'user_pass'    => wp_generate_password( 40 ),
					'display_name' => $actor->display_name,
					'role'         => 'administrator',
				)
			);
			if ( is_wp_error( $id ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Could not preserve the controlling administrator: ' . $id->get_error_message() ); }
			$user = new WP_User( $id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Restore the existing password hash without rehashing; wpdb escapes values and the user cache is cleared below.
		if ( $wpdb->update( $wpdb->users, array( 'user_pass' => $actor->user_pass ), array( 'ID' => $user->ID ) ) === false ) {
			throw new RuntimeException( 'Could not preserve administrator credentials.' ); }
		clean_user_cache( $user->ID );
		$user = new WP_User( $user->ID );
		$user->set_role( 'administrator' );
		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$token   = wp_get_session_token();
		if ( '' !== $token ) {
			$session = $authenticated_session;
			if ( null === $session ) {
				$session = $manager->get( $token );
				$session = $session ? $session : array();
			}
			$session['expiration'] ??= (int) ( wp_parse_auth_cookie( '', 'logged_in' )['expiration'] ?? time() + 2 * DAY_IN_SECONDS );
			$manager->update( $token, $session );
			// Cookies identify the username and token, so they remain valid even if its numeric ID changed.
			// Keeping the browser's cookies also preserves Remember Me and its original expiration.
		} else {
			$token = $manager->create( time() + 2 * DAY_IN_SECONDS );
			wp_set_auth_cookie( $user->ID, false, '', $token );
			$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user->ID, time() + 2 * DAY_IN_SECONDS, 'logged_in', $token );
		}
		wp_set_current_user( $user->ID );
		return array( 'nonce' => wp_create_nonce( 'csm_admin' ) );
	}

	/**
	 * Remove owned transfer staging and recovery data without reverting applied changes.
	 *
	 * @param array $job Destination job and its durable staging or recovery state.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function discard( array $job ): void {
		if ( ( get_option( '_csm_backup_restore', array() )['backup_id'] ?? '' ) === $job['id'] ) {
			throw new RuntimeException( 'Recovery is using this backup. It cannot be discarded yet.' ); }
		if ( in_array( $job['status'], array( 'applying', 'backup_copy', 'restoring' ), true ) ) {
			throw new RuntimeException( 'Recovery is in progress. Its backup cannot be discarded.' ); }
		CSM_Files::cleanup_media_parts( $job );
		CSM_Segments::cleanup( $job['id'] );
		CSM_Database::drop_private_tables( array_merge( array_column( $job['database'], 'stage' ), array_column( $job['database'], 'backup' ) ) );
		CSM_Support::remove_tree( CSM_Support::private_dir() . '/' . $job['id'] );
		CSM_Support::remove_tree( CSM_Support::private_dir() . '/' . $job['id'] . '-backup' );
		if ( isset( $job['backup_dir'] ) ) {
			CSM_Support::remove_tree( $job['backup_dir'] ); }
		CSM_Support::remove_tree( CSM_Support::private_dir() . '/' . $job['id'] . '-incoming' );
		CSM_Support::remove_tree( CSM_Support::private_dir() . '/' . $job['id'] . '-preview.json' );
		foreach ( array( '-apply.json', '-restore.json', '-retry.json', '-heartbeat' ) as $suffix ) {
			CSM_Support::remove_tree( CSM_Support::private_dir() . '/' . $job['id'] . $suffix ); }
		CSM_Progress::remove( $job['id'] );
		CSM_Jobs::forget( $job['id'] );
		CSM_Backups::forget( $job['id'] );
		if ( get_option( '_csm_active_job' ) === $job['id'] ) {
			delete_option( '_csm_active_job' ); }
	}

	/**
	 * Resolve the controller's selected saved connection for trusted internal use.
	 */
	public static function peer(): array {
		return CSM_Controller::peer(); }

	/**
	 * Authorize and dispatch administrator dashboard operations with nonce verification.
	 *
	 * @throws RuntimeException Internally for invalid dashboard data; caught and returned as JSON.
	 */
	public static function ajax(): void {
		$action = is_string( $_POST['op'] ?? null ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! is_string( $_POST['nonce'] ?? null ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'csm_admin' ) ) {
			CSM_Log::write(
				'warning',
				'Dashboard control authentication rejected',
				array(
					'operation'   => $action,
					'http_status' => 403,
				)
			);
			self::respond( array(), 'Administrator session expired. Reopen Trickle to reconnect.', 403 );
		}
		$responded = false;
		$started   = microtime( true );
		$id        = null;
		register_shutdown_function(
			static function () use ( &$responded, &$id, $action, $started ) {
				if ( $responded ) {
					return; }
				$previous = $id ? CSM_Progress::read( $id ) : array();
				$fatal    = error_get_last();
				CSM_Log::write(
					'error',
					'Dashboard request ended without a response',
					array(
						'operation'   => $action,
						'transfer_id' => $id,
						'phase'       => $previous['phase'] ?? '',
						'component'   => $previous['component'] ?? '',
						'duration_ms' => (int) ( ( microtime( true ) - $started ) * 1000 ),
						'error'       => $fatal && in_array( $fatal['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ? $fatal['message'] : 'PHP exited before the operation returned.',
					)
				);
			}
		);
		try {
			if ( '' === $action ) {
				throw new RuntimeException( 'Invalid dashboard action.' );
			}
			if ( ! is_string( $_POST['data'] ?? '{}' ) ) {
				throw new RuntimeException( 'Invalid dashboard JSON.' );
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- String JSON is unslashed, decoded with exceptions, checked as structured data and validated by the selected operation.
			$data = json_decode( wp_unslash( $_POST['data'] ?? '{}' ), true, 64, JSON_THROW_ON_ERROR );
			if ( ! is_array( $data ) ) {
				throw new RuntimeException( 'Dashboard data must be a JSON object or array.' );
			}
			if ( preg_match( '/^[a-f0-9]{32}$/D', $data['id'] ?? '' ) ) {
				$id = $data['id']; }
			$result    = CSM_Controller::execute( $action, $data );
			$responded = true;
			self::respond( $result );
		} catch ( Throwable $e ) {
			CSM_Log::write(
				'error',
				'Dashboard request failed',
				array(
					'operation'   => $action,
					'transfer_id' => $id,
					'error'       => $e->getMessage(),
				)
			);
			$responded = true;
			self::respond(
				$e instanceof CSM_Network_Error ? array(
					'retryable'       => true,
					'busy'            => $e instanceof CSM_Busy_Error,
					'retry_exhausted' => $e->exhausted,
					'retry_after'     => $e->retry_after,
				) : array(),
				$e->getMessage(),
				400
			);
		}
	}

	/**
	 * Build the private site URL and administrator connection-key pairing text.
	 */
	public static function pairing(): string {
		return site_url() . "\n" . get_option( '_csm_key' ); }

	/**
	 * Validate pasted pairing text and normalize its peer URL and key.
	 *
	 * @param string $code Pasted site URL and private connection-key pairing text.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function parse_pairing( string $code ): array {
		$code = trim( $code );
		if ( preg_match( '#^(https?://\S+)\s+([a-f0-9]{64})$#D', $code, $matches ) ) {
			$peer = array(
				'url' => $matches[1],
				'key' => $matches[2],
			);
		} else {
			// Codes issued by the first preview encoded the same URL/key pair as base64 JSON.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Protocol encoding preserves binary bytes; decoded input is validated before use.
			$decoded = base64_decode( $code, true );
			$peer    = false === $decoded ? null : json_decode( $decoded, true );
		}
		if ( ! is_array( $peer ) || ! is_string( $peer['url'] ?? null ) || ! is_string( $peer['key'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $peer['key'] ) ) {
			throw new RuntimeException( 'Invalid connection code. Copy both the site URL and the key from the remote Trickle page.' );
		}
		return array(
			'url' => CSM_Transport::url( $peer['url'] ),
			'key' => $peer['key'],
		);
	}

	/**
	 * Enqueue assets only for the Trickle administration screen.
	 *
	 * @param string $hook Current WordPress administration screen hook.
	 */
	public static function assets( string $hook ): void {
		if ( 'tools_page_trickle' !== $hook ) {
			return; }
		wp_enqueue_style( 'trickle', plugins_url( 'assets/admin.css', CSM_FILE ), array(), CSM_VERSION . '.' . filemtime( dirname( CSM_FILE ) . '/assets/admin.css' ) );
		wp_enqueue_style( 'trickle-workspace', plugins_url( 'assets/workspace.css', CSM_FILE ), array( 'trickle' ), CSM_VERSION . '.' . filemtime( dirname( CSM_FILE ) . '/assets/workspace.css' ) );
		wp_enqueue_style( 'trickle-clarity', plugins_url( 'assets/clarity.css', CSM_FILE ), array( 'trickle-workspace' ), CSM_VERSION . '.' . filemtime( dirname( CSM_FILE ) . '/assets/clarity.css' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation uses a strict allowlist/literal branch; no mutation or arbitrary include path is accepted.
		$script = ( $_GET['view'] ?? '' ) === 'backups' ? 'backups' : 'admin';
		if ( 'admin' === $script ) {
			wp_enqueue_script( 'trickle-carbon-date-picker', plugins_url( 'vendor/carbon/date-picker.min.js', CSM_FILE ), array(), '2.68.0', true );
			wp_enqueue_script( 'trickle-media-dates', plugins_url( 'assets/media-dates.js', CSM_FILE ), array( 'trickle-carbon-date-picker' ), CSM_VERSION . '.' . filemtime( dirname( CSM_FILE ) . '/assets/media-dates.js' ), true );
			wp_enqueue_script( 'trickle-progress', plugins_url( 'assets/transfer-progress.js', CSM_FILE ), array(), CSM_VERSION . '.' . filemtime( dirname( CSM_FILE ) . '/assets/transfer-progress.js' ), true );
		}
		wp_enqueue_script( 'trickle-header', plugins_url( 'assets/app-header.js', CSM_FILE ), array(), CSM_VERSION . '.' . filemtime( dirname( CSM_FILE ) . '/assets/app-header.js' ), true );
		wp_enqueue_script( 'trickle-request', plugins_url( 'assets/request.js', CSM_FILE ), array(), CSM_VERSION . '.' . filemtime( dirname( CSM_FILE ) . '/assets/request.js' ), true );
		wp_enqueue_script( 'trickle', plugins_url( 'assets/' . $script . '.js', CSM_FILE ), 'admin' === $script ? array( 'trickle-request', 'trickle-progress', 'trickle-media-dates' ) : array( 'trickle-request' ), CSM_VERSION . '.' . filemtime( dirname( CSM_FILE ) . '/assets/' . $script . '.js' ), true );
		if ( 'admin' === $script ) {
			wp_enqueue_script( 'trickle-tabs', plugins_url( 'assets/admin-tabs.js', CSM_FILE ), array( 'trickle' ), CSM_VERSION . '.' . filemtime( dirname( CSM_FILE ) . '/assets/admin-tabs.js' ), true ); }
		wp_localize_script(
			'trickle',
			'CSM',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'csm_admin' ),
				'state' => CSM_Controller::state(),
				'local' => array(
					'url'            => site_url(),
					'name'           => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'icon'           => get_site_icon_url( 64 ),
					'wordpress'      => get_bloginfo( 'version' ),
					'plugin_version' => CSM_VERSION,
					'php'            => PHP_VERSION,
				),
			)
		);
	}

	/**
	 * Render the selected Trickle administration view for an authorized administrator.
	 */
	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$loader_error = '';
		if ( ! self::loader_ready() && wp_is_file_mod_allowed( 'trickle' ) ) {
			try {
				self::install_loader(); } catch ( Throwable $e ) {
				$loader_error = $e->getMessage();
				CSM_Log::write( 'error', 'Recovery loader update failed', array( 'error' => $loader_error ) ); }
		}
		$transfer_defaults = get_option( '_csm_transfer_defaults', array() ) + array(
			'transport'              => 'stream',
			'request_interval'       => 0,
			'setup_request_interval' => .5,
		);
		if ( defined( 'CSM_REQUEST_INTERVAL' ) ) {
			$transfer_defaults['request_interval'] = CSM_REQUEST_INTERVAL; }
		if ( defined( 'CSM_SETUP_REQUEST_INTERVAL' ) ) {
			$transfer_defaults['setup_request_interval'] = CSM_SETUP_REQUEST_INTERVAL; }
		$settings = CSM_Access::settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation uses a strict allowlist/literal branch; no mutation or arbitrary include path is accepted.
		require __DIR__ . ( ( $_GET['view'] ?? '' ) === 'backups' ? '/backup-page.php' : '/admin.php' );
	}
}
