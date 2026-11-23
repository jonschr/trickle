<?php
/**
 * Plugin build validation, packaging and authenticated installation.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin build validation, packaging and authenticated installation.
 */
final class CSM_Updates {
	/**
	 * Require the sender and receiver to use the same Trickle version.
	 *
	 * @param mixed $version Peer version to compare with this installation.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	public static function assert_version( $version ): void {
		if ( ! is_string( $version ) || CSM_VERSION !== $version ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Trickle versions must match before transferring. This site: ' . CSM_VERSION . '; connected site: ' . ( is_string( $version ) && '' !== $version ? $version : 'unknown' ) . '. Update the older installation, then refresh versions.' );
		}
	}

	/**
	 * Require an advertised peer build to match the local runtime fingerprint.
	 *
	 * @param array $remote Peer capability response containing its optional runtime fingerprint.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	public static function assert_build( array $remote ): void {
		if ( ! empty( $remote['remote_build_update'] ) && ( ! is_string( $remote['trickle_build'] ?? null ) || ! hash_equals( self::fingerprint(), $remote['trickle_build'] ) ) ) {
			throw new RuntimeException( 'Trickle builds must match before transferring. Install the local Trickle build on the remote site.' );
		}
	}

	/**
	 * Reject continued transfer work prepared under a different plugin version.
	 *
	 * This guard applies to continued transfer work, not rollback or recovery controls.
	 *
	 * @param array $record Persisted transfer record carrying its preparing plugin version.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	public static function assert_job( array $record ): void {
		if ( ( $record['plugin_version'] ?? '' ) !== CSM_VERSION ) {
			throw new RuntimeException( 'Trickle changed since this transfer was prepared. Discard and prepare again. Existing rollback and recovery controls remain available.' ); }
	}

	/**
	 * Advertise this build and its authenticated update capabilities.
	 *
	 * @return array Feature flags, runtime fingerprint and current connection write permission.
	 */
	public static function capabilities(): array {
		return array(
			'binary_build_update'    => 1,
			'combined_setup'         => 1,
			'remote_update'          => 1,
			'remote_build_update'    => 1,
			'trickle_build'          => self::fingerprint(),
			'remote_updates_allowed' => true,
			'can_update_trickle'     => ! isset( $GLOBALS['_csm_credential'] ) || ! empty( $GLOBALS['_csm_credential']['write'] ),
		);
	}

	/**
	 * Resolve the configured JSON release updater.
	 *
	 * @return object Configured updater instance; no release request is made here.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	private static function checker() {
		$checker = $GLOBALS['trickle_update_checker'] ?? null;
		if ( ! $checker ) {
			throw new RuntimeException( 'Trickle JSON updater is unavailable. Reinstall Trickle manually.' ); }
		return $checker;
	}

	/**
	 * Fetch release metadata and require a usable HTTPS package URL.
	 *
	 * @return object Validated release metadata from the configured JSON feed.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	private static function release() {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Updater warnings must not corrupt JSON; the release response is validated immediately below.
		$info = @self::checker()->requestInfo();
		if ( ! $info || ! is_string( $info->version ?? null ) || ! is_string( $info->download_url ?? null ) || wp_parse_url( $info->download_url, PHP_URL_SCHEME ) !== 'https' || ! wp_parse_url( $info->download_url, PHP_URL_HOST ) ) {
			throw new RuntimeException( 'The configured Trickle JSON feed did not return a usable HTTPS release. Try again after the release is published.' );
		}
		return $info;
	}

	/**
	 * Combine published release metadata with native update readiness.
	 *
	 * @return array Installed/release versions, requirements, capabilities and any blocking reason.
	 */
	public static function info(): array {
		$info   = self::release();
		$reason = '';
		try {
			self::preflight();
		} catch ( Throwable $e ) {
			$reason = $e->getMessage(); }
		return array(
			'ready'          => '' === $reason,
			'blocked_reason' => $reason,
		) + self::capabilities() + array(
			'installed'          => CSM_VERSION,
			'release'            => $info->version,
			'file_modifications' => wp_is_file_mod_allowed( 'trickle' ),
			'requires_php'       => $info->requires_php ?? '',
			'requires_wp'        => $info->requires ?? '',
		);
	}

	/**
	 * Require an idle, writable installation and the current recovery loader.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	private static function preflight(): void {
		CSM_Segments::prune_sources();
		if ( get_option( '_csm_control' ) || get_option( '_csm_active_job' ) || glob( CSM_Support::private_dir() . '/segment-*.source.owner' ) ) {
			throw new RuntimeException( 'Finish and discard retained transfers on this site and its connected controllers before updating Trickle.' ); }
		if ( ! CSM_Plugin::loader_ready() ) {
			throw new RuntimeException( 'Open the remote Trickle dashboard once to refresh its recovery loader before updating.' ); }
		if ( ! wp_is_file_mod_allowed( 'trickle' ) ) {
			throw new RuntimeException( 'WordPress disallows file modifications on the remote site.' ); }
		if ( is_link( WP_PLUGIN_DIR . '/' . dirname( plugin_basename( CSM_FILE ) ) ) || CSM_Files::git_folders( array( 'plugins' => array( basename( dirname( CSM_FILE ) ) ) ) ) ) {
			throw new RuntimeException( 'Trickle is inside a Git working directory. Update that checkout manually.' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( get_filesystem_method( array(), WP_PLUGIN_DIR ) !== 'direct' ) {
			throw new RuntimeException( 'Remote updates require direct filesystem access; update through the remote WordPress dashboard instead.' ); }
	}

	/**
	 * Install the exact newer version advertised by the configured release feed.
	 *
	 * @param string $target Exact version expected in the installed package header.
	 * @param string $from Receiver version observed before starting the update.
	 *
	 * @return array Installed version and update status from the native WordPress upgrader.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	public static function install( string $target, string $from ): array {
		if ( CSM_VERSION !== $from ) {
			throw new RuntimeException( 'The remote Trickle version changed. Refresh versions before updating.' ); }
		if ( ! preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,39}$/D', $target ) || version_compare( $target, CSM_VERSION, '<=' ) ) {
			throw new RuntimeException( 'Remote updates must be upgrades. Update this controlling site first when the remote is newer.' ); }
		self::preflight();
		$info = self::release();
		if ( $info->version !== $target ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'The JSON feed currently publishes Trickle ' . $info->version . ', but the controlling site requires ' . $target . '. Publish the matching release or update both installations manually.' ); }
		if ( ( ! empty( $info->requires_php ) && version_compare( PHP_VERSION, $info->requires_php, '<' ) ) || ( ! empty( $info->requires ) && version_compare( get_bloginfo( 'version' ), $info->requires, '<' ) ) ) {
			throw new RuntimeException( 'The published Trickle release requires a newer PHP or WordPress version on this site.' ); }
		return self::apply_package( $target, $info->download_url );
	}

	/**
	 * Enumerate allowed runtime files, rejecting linked directories and files.
	 *
	 * @param string $root Absolute runtime root; null fingerprints the installed plugin.
	 *
	 * @return array Sorted map of relative runtime filenames to absolute file paths.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	private static function runtime_files( string $root ): array {
		$files = array( 'trickle.php' => $root . '/trickle.php' );
		foreach ( array( 'includes', 'assets', 'vendor' ) as $directory ) {
			$base = $root . '/' . $directory;
			if ( ! is_dir( $base ) || is_link( $base ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Trickle runtime directory is missing or linked: ' . $directory ); }
			$filtered = new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				static function ( $entry ) use ( $root ) {
					if ( CSM_Files::excluded( substr( $entry->getPathname(), strlen( $root ) + 1 ) ) ) {
						return false; }
					if ( $entry->isLink() ) {
						throw new RuntimeException( 'Trickle runtime contains a symbolic link.' ); }
					return true;
				}
			);
			$iterator = new RecursiveIteratorIterator( $filtered );
			foreach ( $iterator as $entry ) {
				$relative = substr( $entry->getPathname(), strlen( $root ) + 1 );
				if ( CSM_Files::excluded( $relative ) ) {
					continue; }
				if ( $entry->isLink() ) {
					throw new RuntimeException( 'Trickle runtime contains a symbolic link.' ); }
				if ( $entry->isFile() ) {
					$files[ $relative ] = $entry->getPathname(); }
			}
		}
		ksort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * Hash the sorted runtime paths and their file digests.
	 *
	 * @param string|null $root Absolute runtime root; null fingerprints the installed plugin.
	 *
	 * @return string Hexadecimal SHA-256 identifying the exact runtime build.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	public static function fingerprint( ?string $root = null ): string {
		$root = rtrim( $root ?? dirname( CSM_FILE ), '/' );
		$hash = hash_init( 'sha256' );
		foreach ( self::runtime_files( $root ) as $relative => $path ) {
			$digest = hash_file( 'sha256', $path );
			if ( false === $digest ) {
				throw new RuntimeException( 'Cannot read the local Trickle build.' ); }
			hash_update( $hash, $relative . "\n" . $digest . "\n" );
		}
		return hash_final( $hash );
	}

	/**
	 * Package a stable local runtime build for authenticated peer installation.
	 *
	 * @param bool $binary Whether to return raw ZIP bytes rather than base64 for JSON compatibility.
	 *
	 * @return array Target version, build, ZIP digest, byte size and binary or base64 package bytes.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	public static function package( bool $binary = false ): array {
		$root = dirname( CSM_FILE );
		$path = CSM_Support::private_dir() . '/trickle-build-' . bin2hex( random_bytes( 8 ) ) . '.zip';
		try {
			$build = self::fingerprint();
			$files = self::runtime_files( $root );
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
					throw new RuntimeException( 'Cannot create the local Trickle package.' ); }
				try {
					foreach ( $files as$relative => $file ) {
						if ( ! $zip->addFile( $file, 'trickle/' . $relative ) ) {
							throw new RuntimeException( 'Cannot package ' . $relative );
						}
					}
				} finally {
					if ( ! $zip->close() ) {
						throw new RuntimeException( 'Cannot finish the local Trickle package.' );
					}
				}
			} else {
				require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
				$zip = new PclZip( $path );
				if ( ! $zip->create( array_values( $files ), PCLZIP_OPT_REMOVE_PATH, $root, PCLZIP_OPT_ADD_PATH, 'trickle' ) ) {
					throw new RuntimeException( 'Cannot create the local Trickle package.' ); }
			}
			if ( filesize( $path ) > 700000 ) {
				throw new RuntimeException( 'This Trickle build is too large for direct installation. Upload its ZIP manually.' );
			}
			if ( ! hash_equals( $build, self::fingerprint() ) ) {
				throw new RuntimeException( 'Local Trickle files changed during packaging. Retry the update.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
			$bytes = file_get_contents( $path );
			return array(
				'target'  => CSM_VERSION,
				'build'   => $build,
				'sha256'  => hash( 'sha256', $bytes ),
				'size'    => strlen( $bytes ),
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Protocol encoding preserves binary bytes; decoded input is validated before use.
				'package' => $binary ? $bytes : base64_encode( $bytes ),
			);
		} finally {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the validated local file during cleanup or recoverable publication.
				unlink( $path );
			}
		}
	}

	/**
	 * Report installed-build capabilities and readiness without requesting a release.
	 *
	 * @return array Native build metadata, readiness and any blocking reason.
	 */
	public static function build_info(): array {
		$reason = '';
		try {
			self::preflight();
		} catch ( Throwable $e ) {
			$reason = $e->getMessage();}
		return array(
			'ready'              => '' === $reason,
			'blocked_reason'     => $reason,
			'installed'          => CSM_VERSION,
			'file_modifications' => wp_is_file_mod_allowed( 'trickle' ),
		) + self::capabilities();
	}

	/**
	 * Bound archive contents and reject protected or non-runtime paths.
	 *
	 * @param string $path Absolute path to the private uploaded ZIP archive.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	private static function validate_archive( string $path ): void {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$zip     = new PclZip( $path );
		$entries = $zip->listContent();
		if ( ! is_array( $entries ) || count( $entries ) > 500 ) {
			throw new RuntimeException( 'Invalid or oversized Trickle archive.' );
		}
		$expanded = 0;
		foreach ( $entries as$entry ) {
			$name = $entry['filename'];
			if ( ! str_starts_with( $name, 'trickle/' ) ) {
				throw new RuntimeException( 'The package contains files outside Trickle.' );
			}
			$relative = rtrim( substr( $name, 8 ), '/' );
			if ( '' === $relative ) {
				continue;
			}
			CSM_Files::safe_relative( $relative );
			if ( CSM_Files::excluded( $relative ) || ( 'trickle.php' !== $relative && ! preg_match( '#^(includes|assets|vendor)(/|$)#', $relative ) ) ) {
				throw new RuntimeException( 'The package contains unrelated or protected files.' );
			}
			$expanded += (int) $entry['size'];
			if ( $expanded > 8 * 1024 * 1024 ) {
				throw new RuntimeException( 'Expanded Trickle package exceeds its size limit.' );
			}
		}
	}

	/**
	 * Require idle update readiness and an unchanged expected receiver build.
	 *
	 * @param array $data Signed package metadata, expected receiver state and any compatibility payload.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	private static function check_build( array $data ): void {
		self::preflight();
		if ( ( $data['from'] ?? null ) !== CSM_VERSION || ! is_string( $data['from_build'] ?? null ) || ! hash_equals( self::fingerprint(), $data['from_build'] ) ) {
			throw new RuntimeException( 'Remote Trickle changed. Refresh and retry.' ); }
		$target = $data['target'] ?? '';
		$build  = $data['build'] ?? '';
		$digest = $data['sha256'] ?? '';
		if ( ! is_string( $target ) || ! preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,39}$/D', $target ) || ! is_string( $build ) || ! preg_match( '/^[a-f0-9]{64}$/D', $build ) || ! is_string( $digest ) || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
			throw new RuntimeException( 'Invalid local Trickle package metadata.' ); }
	}

	/**
	 * Decode the compatibility JSON package after validating receiver state.
	 *
	 * @param array $data Signed package metadata, expected receiver state and any compatibility payload.
	 *
	 * @return array Installed version, update status and verified runtime fingerprint.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	public static function install_build( array $data ): array {
		self::check_build( $data );
		if ( ! is_string( $data['package'] ?? null ) || strlen( $data['package'] ) > 940000 ) {
			throw new RuntimeException( 'Invalid local Trickle package metadata.' ); }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Protocol encoding preserves binary bytes; decoded input is validated before use.
		$bytes = base64_decode( $data['package'], true );
		if ( false === $bytes ) {
			throw new RuntimeException( 'Local Trickle package checksum mismatch.' ); }
		return self::install_archive( $data, $bytes );
	}

	/**
	 * Read a signed binary ZIP without exceeding its declared bounded size.
	 *
	 * @param array    $data Signed package metadata, expected receiver state and any compatibility payload.
	 * @param resource $input Readable binary upload stream; ownership remains with the caller.
	 *
	 * @return array Installed version, update status and verified runtime fingerprint.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	public static function install_zip( array $data, $input ): array {
		self::check_build( $data );
		if ( ! is_int( $data['size'] ?? null ) || $data['size'] < 1 || $data['size'] > 700000 ) {
			throw new RuntimeException( 'Invalid Trickle ZIP size.' ); }
		$bytes = stream_get_contents( $input, $data['size'] + 1 );
		if ( false === $bytes || strlen( $bytes ) !== $data['size'] ) {
			throw new RuntimeException( 'Trickle ZIP is truncated or exceeds its declared size.' ); }
		return self::install_archive( $data, $bytes );
	}

	/**
	 * Verify package bytes and archive boundaries before native installation.
	 *
	 * @param array  $data Signed package metadata, expected receiver state and any compatibility payload.
	 * @param string $bytes Exact compressed ZIP bytes whose SHA-256 must match the signed metadata.
	 *
	 * @return array Installed version, update status and verified runtime fingerprint.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	private static function install_archive( array $data, string $bytes ): array {
		if ( strlen( $bytes ) > 700000 || ! hash_equals( $data['sha256'], hash( 'sha256', $bytes ) ) ) {
			throw new RuntimeException( 'Local Trickle package checksum mismatch.' );
		}
		$path = CSM_Support::private_dir() . '/trickle-upload-' . bin2hex( random_bytes( 8 ) ) . '.zip';
		try {
			CSM_Support::atomic_bytes( $path, $bytes );
			self::validate_archive( $path );
			return self::apply_package( $data['target'], $path, $data['build'] ); } finally {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the validated local file during cleanup or recoverable publication.
				unlink( $path );
			}
			}
	}

	/**
	 * Install a checked package while preserving the real plugin activation list.
	 *
	 * Archive contents and any requested build are checked before publication. The
	 * complete activation list is restored even when the upgrader fails.
	 *
	 * @param string      $target Exact version expected in the installed package header.
	 * @param string      $package HTTPS package URL or validated private ZIP path supplied to WordPress.
	 * @param string|null $build Expected runtime fingerprint, or null for a published-feed update.
	 *
	 * @return array Installed version and update status, plus build identity when requested.
	 *
	 * @throws RuntimeException If the expected version, runtime build, package or installation is invalid.
	 */
	private static function apply_package( string $target, string $package, ?string $build = null ): array {
		$plugin = plugin_basename( CSM_FILE );
		// The recovery loader filters this option during RPCs; preserve the complete real list.
		$isolation = $GLOBALS['_csm_active_plugins_filter'] ?? null;
		if ( defined( 'CSM_ORIGINAL_ACTIVE_PLUGINS' ) && ! $isolation ) {
			throw new RuntimeException( 'Refresh the remote recovery loader before updating Trickle.' ); }
		$active   = defined( 'CSM_ORIGINAL_ACTIVE_PLUGINS' ) ? CSM_ORIGINAL_ACTIVE_PLUGINS : get_option( 'active_plugins', array() );
		$publish  = static function ( $updates ) use ( $plugin, $target, $package ) {
			if ( ! is_object( $updates ) ) {
				$updates = (object) array(
					'response'  => array(),
					'no_update' => array(),
					'checked'   => array(),
				); }
			$updates->response[ $plugin ] = (object) array(
				'slug'        => basename( dirname( CSM_FILE ) ),
				'plugin'      => $plugin,
				'new_version' => $target,
				'package'     => $package,
				'url'         => 'https://github.com/jonschr/trickle',
			);
			return $updates;
		};
		$validate = static function ( $source, $remote_source, $upgrader, $extra ) use ( $plugin, $target, $build ) {
			if ( is_wp_error( $source ) || ( $extra['plugin'] ?? '' ) !== $plugin ) {
				return $source; }
			$main   = trailingslashit( $source ) . basename( CSM_FILE );
			$header = is_file( $main ) ? get_file_data(
				$main,
				array(
					'name'    => 'Plugin Name',
					'version' => 'Version',
					'php'     => 'Requires PHP',
					'wp'      => 'Requires at least',
				)
			) : array();
			if ( basename( untrailingslashit( $source ) ) !== basename( dirname( CSM_FILE ) ) || ( $header['name'] ?? '' ) !== 'Trickle' || ( $header['version'] ?? '' ) !== $target ) {
				return new WP_Error( 'trickle_package', 'The downloaded ZIP does not contain the expected Trickle version and directory.' ); }
			if ( ( ! empty( $header['php'] ) && version_compare( PHP_VERSION, $header['php'], '<' ) ) || ( ! empty( $header['wp'] ) && version_compare( get_bloginfo( 'version' ), $header['wp'], '<' ) ) ) {
				return new WP_Error( 'trickle_requirements', 'The downloaded Trickle package is incompatible with this site.' ); }
			if ( null !== $build ) {
				try {
					if ( ! hash_equals( $build, self::fingerprint( untrailingslashit( $source ) ) ) ) {
						return new WP_Error( 'trickle_build', 'The uploaded package does not match the local Trickle build.' ); }
					foreach ( self::runtime_files( untrailingslashit( $source ) ) as $relative => $path ) {
						if ( str_ends_with( $relative, '.php' ) ) {
							try {
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
								token_get_all( file_get_contents( $path ), TOKEN_PARSE );
							} catch ( ParseError $error ) {
								return new WP_Error( 'trickle_php', $relative . ' has a PHP parse error on PHP ' . PHP_VERSION . ': ' . $error->getMessage() ); }
						}
					}
				} catch ( Throwable $error ) {
					return new WP_Error( 'trickle_build', $error->getMessage() ); }
			}
			return $source;
		};
		add_filter( 'site_transient_update_plugins', $publish, PHP_INT_MAX );
		add_filter( 'upgrader_source_selection', $validate, PHP_INT_MAX, 4 );
		ignore_user_abort( true );
		if ( $isolation ) {
			remove_filter( 'option_active_plugins', $isolation, PHP_INT_MAX ); }
		try {
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade( $plugin );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() ); }
			if ( is_wp_error( $upgrader->skin->result ) ) {
				throw new RuntimeException( $upgrader->skin->result->get_error_message() ); }
			if ( ! $result ) {
				throw new RuntimeException( 'WordPress could not install the Trickle update. Check the remote diagnostics and filesystem permissions.' ); }
			$header = get_file_data( CSM_FILE, array( 'version' => 'Version' ) );
			if ( $header['version'] !== $target ) {
				throw new RuntimeException( 'The installed Trickle version does not match the requested release. Verify the remote installation manually.' ); }
			CSM_Plugin::install_loader();
			return array(
				'installed' => $target,
				'updated'   => true,
			) + ( null !== $build ? array( 'trickle_build' => $build ) : array() );
		} finally {
			update_option( 'active_plugins', $active );
			if ( $isolation ) {
				add_filter( 'option_active_plugins', $isolation, PHP_INT_MAX ); }
			remove_filter( 'site_transient_update_plugins', $publish, PHP_INT_MAX );
			remove_filter( 'upgrader_source_selection', $validate, PHP_INT_MAX );
			if ( function_exists( 'opcache_reset' ) ) {
				opcache_reset(); }
		}
	}
}
