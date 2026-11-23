<?php
/**
 * Shared storage, cancellation, deadlines and migration primitives.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-csm-timing.php';
require_once __DIR__ . '/class-csm-cancelled-error.php';
require_once __DIR__ . '/class-csm-yield.php';

/**
 * Shared storage, cancellation, deadlines and migration primitives.
 */
final class CSM_Support {
	/**
	 * Transfer whose cancellation marker is checked by this request.
	 *
	 * @var string|null
	 */
	private static ?string $work_id = null;
	/**
	 * Validated cancellation marker path for the current transfer.
	 *
	 * @var string|null
	 */
	private static ?string $work_cancel_path = null;
	/**
	 * Whether the current operation may honor cancellation.
	 *
	 * @var bool
	 */
	private static bool $cancellable = true;
	/**
	 * Monotonic deadline for the current bounded work step.
	 *
	 * @var float|null
	 */
	private static ?float $step_deadline = null;

	/**
	 * Open runtime lock handle, when acquired.
	 *
	 * @var resource|false|null
	 */
	private static $runtime_handle = null;
	/**
	 * Whether this request holds the exclusive runtime lock.
	 *
	 * @var bool
	 */
	private static bool $runtime_exclusive = false;

	/** Drain ordinary requests before replacing code; keep them out until verification ends.
	 *
	 * @param bool $exclusive Whether to drain ordinary requests and require exclusive runtime access.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function runtime_lock( bool $exclusive ): bool {
		if ( is_resource( self::$runtime_handle ) && ( self::$runtime_exclusive || ! $exclusive ) ) {
			return true; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$handle = self::$runtime_handle ?? fopen( self::private_dir() . '/runtime.lock', 'c' );
		if ( ! $handle ) {
			throw new RuntimeException( 'Cannot open site runtime lock.' ); }
		if ( ! flock( $handle, ( $exclusive ? LOCK_EX : LOCK_SH ) | LOCK_NB ) ) {
			if ( ! is_resource( self::$runtime_handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $handle ); }
			return false;
		}
		if ( ! is_resource( self::$runtime_handle ) ) {
			register_shutdown_function(
				static function () {
					if ( is_resource( self::$runtime_handle ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
						fclose( self::$runtime_handle );
					} }
			); }
		self::$runtime_handle    = $handle;
		self::$runtime_exclusive = $exclusive;
		return true;
	}

	/**
	 * Bind cancellation checks to the current transfer and cancellation policy.
	 *
	 * @param string    $id Transfer identifier.
	 * @param bool|null $cancellable Whether cancellation is allowed; null retains the current policy.
	 */
	public static function work( string $id, ?bool $cancellable = true ): void {
		self::$work_cancel_path = self::cancel_path( $id );
		if ( self::$work_id !== $id || null !== $cancellable ) {
			self::$cancellable = $cancellable ?? true;
		} self::$work_id = $id;
	}

	/**
	 * Resolve the private cancellation-marker path for a validated transfer identifier.
	 *
	 * @param string $id Transfer identifier.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function cancel_path( string $id ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
			throw new RuntimeException( 'Invalid transfer ID.' ); }
		return self::private_dir() . '/' . $id . '-cancel.json';
	}

	/**
	 * Publish a cancellation marker associated with the current connection owner.
	 *
	 * @param string $id Transfer identifier.
	 */
	public static function cancel( string $id ): void {
		self::atomic_json(
			self::cancel_path( $id ),
			array(
				'created'    => time(),
				'credential' => class_exists( 'CSM_Access' ) ? CSM_Access::owner() : 'local',
			)
		);
	}

	/**
	 * Check whether an applicable cancellation marker exists for this transfer.
	 *
	 * @param string $id Transfer identifier.
	 */
	public static function is_cancelled( string $id ): bool {
		$path = self::cancel_path( $id );
		clearstatcache( true, $path );
		return is_file( $path ); }
	/**
	 * Stop cancellable work when its owned cancellation marker is present.
	 *
	 * @throws CSM_Cancelled_Error If an applicable cancellation marker stops the current work.
	 */
	public static function check_cancel(): void {
		if ( self::$cancellable && self::$work_id ) {
			// The path was validated at work entry; never cache marker existence or contents.
			$path = self::$work_cancel_path;
			clearstatcache( true, $path );
			if ( ! is_file( $path ) ) {
				return; }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
			$marker = json_decode( file_get_contents( $path ), true );
			if ( ! isset( $marker['credential'] ) || ! class_exists( 'CSM_Access' ) || CSM_Access::owner() === $marker['credential'] || ! empty( $GLOBALS['_csm_credential']['master'] ) ) {
				throw new CSM_Cancelled_Error( 'Transfer cancelled.' ); }
		}
	}

	/**
	 * Start a bounded work deadline, optionally retaining an existing deadline.
	 *
	 * @param float $seconds Maximum duration of the bounded work step, in seconds.
	 * @param bool  $continuing Whether to preserve the current work-step deadline.
	 */
	public static function step( float $seconds = 8, bool $continuing = false ): void {
		if ( ! $continuing || null === self::$step_deadline ) {
			self::$step_deadline = hrtime( true ) / 1e9 + max( .01, (float) apply_filters( 'csm_step_seconds', $seconds ) ); }
	}
	/**
	 * Report whether the current bounded work deadline has expired.
	 */
	public static function due(): bool {
		return null !== self::$step_deadline && hrtime( true ) / 1e9 >= self::$step_deadline; }

	/**
	 * Encode a checkpoint and publish it through atomic file replacement.
	 *
	 * @param string $path Filesystem path checked or operated on by this helper.
	 * @param array  $data Operation-specific request fields, validated by the receiving operation.
	 */
	public static function atomic_json( string $path, array $data ): void {
		self::atomic_bytes( $path, self::json( $data ) );
	}

	/**
	 * Publish exact private file bytes through a checked temporary-file rename.
	 *
	 * @param string $path Filesystem path checked or operated on by this helper.
	 * @param string $raw Exact bytes to publish without text transformations.
	 * @param int    $mode Destination permission bits.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function atomic_bytes( string $path, string $raw, int $mode = 0600 ): void {
		$timing = hrtime( true );
		try {
			$tmp = $path . '.' . bin2hex( random_bytes( 6 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact local bytes under the specified lock; publication and failure handling stay with this operation.
			if ( file_put_contents( $tmp, $raw, LOCK_EX ) !== strlen( $raw ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
				@unlink( $tmp );
				throw new RuntimeException( 'Could not save transfer checkpoint.' ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
			if ( ! chmod( $tmp, $mode ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
				@unlink( $tmp );
				throw new RuntimeException( 'Could not set transfer checkpoint permissions.' ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename is the atomic publication/recovery boundary.
			if ( ! rename( $tmp, $path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
				@unlink( $tmp );
				throw new RuntimeException( 'Could not save transfer checkpoint.' ); }
		} finally {
			CSM_Timing::add( str_ends_with( $path, '-apply.json' ) ? 'filesystem.atomic_cursor' : ( str_ends_with( $path, '/swap.json' ) ? 'filesystem.atomic_journal' : 'filesystem.atomic_other' ), $timing, strlen( $raw ) ); }
	}

	/**
	 * Monotonic deadline for source snapshot production.
	 *
	 * @var float|null
	 */
	private static ?float $source_deadline = null;
	/**
	 * Maximum source production time in seconds.
	 *
	 * @var int
	 */
	private static int $source_seconds = 45;

	/**
	 * Start the source production deadline for the current request.
	 */
	public static function begin_source_budget(): void {
		self::$source_seconds  = max( 1, min( 45, (int) apply_filters( 'csm_source_packaging_seconds', 45 ) ) );
		self::$source_deadline = hrtime( true ) / 1e9 + self::$source_seconds;
	}

	/**
	 * Enforce the source production deadline and optional cancellation check.
	 *
	 * @param bool $check_cancel Whether to probe cancellation while enforcing the source deadline.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function check_source_budget( bool $check_cancel = true ): void {
		if ( $check_cancel ) {
			self::check_cancel(); }
		if ( null !== self::$source_deadline && hrtime( true ) / 1e9 >= self::$source_deadline ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Source packaging exceeded the ' . self::$source_seconds . '-second limit. Select fewer components or a smaller media date range.' );
		}
	}

	/**
	 * Clear the source production deadline after snapshot work finishes.
	 */
	public static function end_source_budget(): void {
		self::$source_deadline = null; }

	/**
	 * Compute a file SHA-256 with bounded reads and optional progress reporting.
	 *
	 * @param string        $path Filesystem path checked or operated on by this helper.
	 * @param callable|null $progress Optional callback receiving the cumulative bytes hashed.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function hash_file( string $path, ?callable $progress = null ): string {
		$timing = hrtime( true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$fp = fopen( $path, 'rb' );
		if ( ! $fp ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Cannot read checksum file: ' . basename( $path ) ); }
		$seconds  = max( 1, min( 15, (int) apply_filters( 'csm_hash_seconds', 15 ) ) );
		$deadline = hrtime( true ) / 1e9 + $seconds;
		$hash     = hash_init( 'sha256' );
		$read     = 0;
		// Keep large files streaming; small files can use the existing accelerated SHA-256 provider.
		$buffer = function_exists( 'openssl_digest' ) && class_exists( 'CSM_Package', false ) && fstat( $fp )['size'] <= 2097152 ? '' : null;
		try {
			while ( ! feof( $fp ) ) {
				self::check_source_budget();
				if ( hrtime( true ) / 1e9 >= $deadline ) {
					throw new RuntimeException( 'Checksum exceeded the ' . $seconds . '-second limit: ' . basename( $path ) ); }
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
				$bytes = fread( $fp, 262144 );
				if ( false === $bytes || ( '' === $bytes && ! feof( $fp ) ) ) {
					throw new RuntimeException( 'Checksum read failed: ' . basename( $path ) ); }
				if ( null !== $buffer && strlen( $buffer ) + strlen( $bytes ) <= 2097152 ) {
					$buffer .= $bytes; } else {
					if ( null !== $buffer ) {
						hash_update( $hash, $buffer );
						$buffer = null; }
					hash_update( $hash, $bytes );
					}
					$read += strlen( $bytes );
					if ( $progress ) {
						$progress( $read ); }
			}
			return null !== $buffer ? CSM_Package::digest( $buffer ) : hash_final( $hash );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $fp );
			CSM_Timing::add( 'checksum.read', $timing, $read ); }
	}

	/** Hash contexts stay in private checkpoints, never in requests or peer responses.
	 *
	 * @param HashContext $hash Live hash context stored only in protected local checkpoint data.
	 */
	public static function save_hash( HashContext $hash ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Protocol encoding preserves binary bytes; decoded input is validated before use. Only a local HashContext is serialized into a protected, bounded recovery checkpoint.
		return base64_encode( serialize( $hash ) ); }

	/**
	 * Restore a bounded private hash checkpoint with an explicit allowed class.
	 *
	 * @param string $saved Bounded private serialized checksum checkpoint.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function restore_hash( string $saved ): HashContext {
		if ( strlen( $saved ) > 2048 ) {
			throw new RuntimeException( 'Invalid checksum checkpoint.' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Protocol encoding preserves binary bytes; decoded input is validated before use.
		$raw = base64_decode( $saved, true );
		if ( false === $raw ) {
			throw new RuntimeException( 'Invalid checksum checkpoint.' ); }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Private checkpoint is bounded to 2048 bytes; only HashContext is allowed and its type is checked below.
		$hash = unserialize( $raw, array( 'allowed_classes' => array( HashContext::class ) ) );
		if ( ! $hash instanceof HashContext ) {
			throw new RuntimeException( 'Invalid checksum checkpoint.' ); }
		return $hash;
	}

	/**
	 * Verify a file digest or reuse a receipt only when its stable metadata matches.
	 *
	 * @param string     $path Filesystem path checked or operated on by this helper.
	 * @param string     $expected Expected hexadecimal SHA-256 digest.
	 * @param array|null &$receipt Previously verified file stamp/digest receipt; refreshed when safe to reuse.
	 */
	public static function verify_file( string $path, string $expected, ?array &$receipt ): bool {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing or replaced file is handled by the following metadata validation or fallback.
		$stat = @stat( $path );
		if ( ! $stat || ! is_file( $path ) ) {
			return false; }
		$stamp = array_intersect_key( $stat, array_flip( array( 'size', 'mtime', 'ctime', 'ino', 'dev', 'mode' ) ) );
		if ( ( $receipt['stamp'] ?? null ) === $stamp && ( $receipt['hash'] ?? null ) === $expected ) {
			return true; }
		$stable = max( $stat['mtime'], $stat['ctime'] ) < time() - 2;
		$actual = self::hash_file( $path );
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing or replaced file is handled by the following metadata validation or fallback.
		$after = @stat( $path );
		if ( ! $after || array_intersect_key( $after, array_flip( array_keys( $stamp ) ) ) !== $stamp || ! hash_equals( $expected, $actual ) ) {
			return false; }
		// Only reuse a check made after timestamps settled; same-second writes must be rehashed.
		$receipt = $stable ? array(
			'stamp' => $stamp,
			'hash'  => $actual,
		) : null;
		return true;
	}

	/**
	 * Yield compressed bytes from an incoming generator without buffering the full stream.
	 *
	 * @param Generator $input Generator of uncompressed input bytes.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function gzip( Generator $input ): Generator {
		$context = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => 3 ) );
		if ( ! $context ) {
			throw new RuntimeException( 'Cannot initialize stream compression.' ); }
		foreach ( $input as $bytes ) {
			$compressed = deflate_add( $context, $bytes, ZLIB_NO_FLUSH );
			if ( false === $compressed ) {
				throw new RuntimeException( 'Stream compression failed.' ); }
			if ( '' !== $compressed ) {
				yield $compressed; }
		}
		yield deflate_add( $context, '', ZLIB_FINISH );
	}

	/**
	 * Attach a gzip-reading filter to the caller-owned input stream.
	 *
	 * @param resource $fp Caller-owned stream handle positioned at the next record or write offset.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function decompress( $fp ): void {
		if ( ! stream_filter_append( $fp, 'zlib.inflate', STREAM_FILTER_READ, array( 'window' => 31 ) ) ) {
			throw new RuntimeException( 'Cannot decompress incoming stream.' ); }
	}

	/**
	 * Encode one newline-terminated JSON record with exceptions on encoding errors.
	 *
	 * @param mixed $value Value to encode or transform.
	 */
	public static function json( $value ): string {
		return wp_json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * Read and decode one bounded newline-terminated JSON stream record.
	 *
	 * @param resource $fp Caller-owned stream handle positioned at the next record or write offset.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function line( $fp ): array {
		$line = fgets( $fp, 32 * 1024 * 1024 );
		if ( false === $line || ! str_ends_with( $line, "\n" ) ) {
			throw new RuntimeException( 'Incomplete stream or record exceeds the 32 MB record limit.' );
		}
		$value = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) ) {
			throw new RuntimeException( 'Invalid stream record.' ); }
		return $value;
	}

	/**
	 * Write all supplied bytes, handling short writes and storage failure.
	 *
	 * @param resource $fp Caller-owned stream handle positioned at the next record or write offset.
	 * @param string   $bytes Exact bytes to write or encode.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function write( $fp, string $bytes ): void {
		$offset = 0;
		$length = strlen( $bytes );
		while ( $offset < $length ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write exact bytes through the already locked native descriptor.
			$written = fwrite( $fp, substr( $bytes, $offset ) );
			if ( ! $written ) {
				throw new RuntimeException( 'Could not write stream; check disk space.' ); }
			$offset += $written;
		}
	}

	/**
	 * Validate and quote a bounded SQL table or column identifier.
	 *
	 * @param string $name SQL identifier to validate and quote.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function ident( string $name ): string {
		if ( ! preg_match( '/^[a-zA-Z0-9_$-]{1,64}$/D', $name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Unsupported SQL identifier: ' . $name );
		}
		return '`' . $name . '`';
	}

	/**
	 * Execute a migration statement and raise an exception on database failure.
	 *
	 * @param string $sql Migration SQL whose identifiers and values are checked by the caller.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function query( string $sql ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration query primitive: callers validate identifiers and prepare/encode values; schema writes cannot be cached.
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Database operation failed: ' . $wpdb->last_error ); }
		return $result;
	}

	/**
	 * Resolve and secure site-specific staging storage outside public document roots.
	 *
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function private_dir(): string {
		$base = rtrim( sys_get_temp_dir(), '/\\' );
		$path = $base . '/trickle-' . substr( hash_hmac( 'sha256', ABSPATH, wp_salt( 'auth' ) ), 0, 24 );
		clearstatcache( true, $path );
		if ( ! is_dir( $path ) ) {
			// Another request may create the directory between the check and mkdir.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Concurrent directory creation is followed by explicit directory, symlink and permission checks. Create private staging with explicit permissions; no FTP backend may replace it.
			@mkdir( $path, 0700, true );
			clearstatcache( true, $path );
			if ( ! is_dir( $path ) ) {
				throw new RuntimeException( 'Cannot create private staging storage.' ); }
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Check the actual local publication permissions before starting a recoverable write.
		if ( is_link( $path ) || ! is_writable( $path ) ) {
			throw new RuntimeException( 'Unsafe staging directory.' ); }
		// Hash and cancellation loops revisit this directory; do not rewrite its metadata each time.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
		if ( ( fileperms( $path ) & 07777 ) !== 0700 && ! chmod( $path, 0700 ) ) {
			throw new RuntimeException( 'Cannot secure staging directory.' ); }
		if ( self::public_path( $path ) ) {
			throw new RuntimeException( 'Staging storage must be outside the public web directory.' ); }
		return $path;
	}

	/**
	 * Detect paths exposed beneath a public root, including symlinked ancestors.
	 *
	 * @param string $path Filesystem path checked or operated on by this helper.
	 */
	public static function public_path( string $path ): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate a server filesystem root with realpath; unslash only after WordPress adds its slashes.
		$document_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
		if ( is_string( $document_root ) && '' !== $document_root ) {
			// The MU loader runs before wp_magic_quotes(); later requests need the original path restored.
			if ( function_exists( 'did_action' ) && did_action( 'sanitize_comment_cookies' ) ) {
				$document_root = wp_unslash( $document_root );
			}
			$document_root = realpath( $document_root );
		} else {
			$document_root = false;
		}
		$roots = array_filter( array( realpath( ABSPATH ), $document_root ) );
		// Inspect ancestors too: a symlink beneath a public directory can expose an outside target.
		while ( true ) {
			$real = realpath( $path );
			foreach ( $roots as $root ) {
				if ( $real && str_starts_with( $real . '/', rtrim( $root, '/' ) . '/' ) ) {
					return true; }
			}
			$parent = dirname( $path );
			if ( $parent === $path ) {
				return false; }
			$path = $parent;
		}
	}

	/**
	 * Load a complete job through the checkpoint storage layer.
	 *
	 * @param string $id Transfer identifier.
	 */
	public static function job( string $id ): array {
		return CSM_Jobs::get( $id ); }

	/**
	 * Choose protected persistent backup storage with private staging as fallback.
	 */
	public static function backup_dir(): string {
		$home = getenv( 'HOME' );
		if ( ! $home ) {
			$home = function_exists( 'posix_getpwuid' ) ? ( posix_getpwuid( posix_geteuid() )['dir'] ?? '' ) : '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Check the actual local publication permissions before starting a recoverable write.
		if ( $home && is_dir( $home ) && is_writable( $home ) ) {
			$path = rtrim( $home, '/\\' ) . '/.trickle-backups/' . substr( hash_hmac( 'sha256', ABSPATH, wp_salt( 'auth' ) ), 0, 24 );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Concurrent directory creation is followed by explicit directory, symlink and permission checks. Create private staging with explicit permissions; no FTP backend may replace it.
			if ( is_dir( $path ) || @mkdir( $path, 0700, true ) ) {
				$real = realpath( $path );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Check the actual local publication permissions before starting a recoverable write.
				if ( $real && ! is_link( $path ) && is_writable( $path ) && ! self::public_path( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
					chmod( $path, 0700 );
					return $path; }
			}
		}
		return self::private_dir();
	}

	/**
	 * Delegate transactional cursor persistence to the job storage layer.
	 *
	 * @param array $job Destination job and its durable staging or recovery state.
	 */
	public static function save_transactional( array $job ): void {
		CSM_Jobs::save_transactional( $job ); }
	/**
	 * Delegate complete job persistence to the job storage layer.
	 *
	 * @param array $job Destination job and its durable staging or recovery state.
	 */
	public static function save( array $job ): void {
		CSM_Jobs::save( $job ); }

	/**
	 * Acquire the nonblocking site mutation lock and retain its handle for this request.
	 *
	 * @throws CSM_Busy_Error If another request holds the required runtime or mutation lock.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function lock(): string {
		$id = bin2hex( random_bytes( 16 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$fp = fopen( self::private_dir() . '/transfer.lock', 'c' );
		if ( ! $fp ) {
			throw new RuntimeException( 'Cannot open transfer lock.' ); }
		if ( ! flock( $fp, LOCK_EX | LOCK_NB ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $fp );
			throw new CSM_Busy_Error( 'Another transfer is running on this site.' ); }
		// Keep the OS lock alive for the duration of this PHP request, even across a fatal error.
		$GLOBALS['_csm_lock'] = $fp;
		return $id;
	}

	/**
	 * Release this request's site mutation lock when held.
	 */
	public static function unlock(): void {
		if ( isset( $GLOBALS['_csm_lock'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $GLOBALS['_csm_lock'] );
			unset( $GLOBALS['_csm_lock'] ); }
	}

	/**
	 * Remove an internal storage tree without following symbolic links.
	 *
	 * @param string $path Filesystem path checked or operated on by this helper.
	 */
	public static function remove_tree( string $path ): void {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return; }
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( new FilesystemIterator( $path ) as $item ) {
				self::remove_tree( $item->getPathname() ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the checked local recovery tree without following symbolic links.
			rmdir( $path );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the validated local file during cleanup or recoverable publication.
			unlink( $path ); }
	}

	/**
	 * Configure bounded long-running work where the host permits runtime changes.
	 */
	public static function long_request(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts may forbid runtime changes; bounded work and resumable checkpoints remain mandatory.
			@set_time_limit( 50 ); }
		global $wpdb;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ) {
			$previous = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed session-control SQL has no request values and must run on the current connection.
			$wpdb->query( 'SET SESSION innodb_lock_wait_timeout=10' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed session-control SQL has no request values and must run on the current connection.
			$wpdb->query( 'SET SESSION lock_wait_timeout=10' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fixed session-control SQL has no request values and must run on the current connection.
			$wpdb->query( stripos( $wpdb->db_server_info(), 'MariaDB' ) !== false ? 'SET SESSION max_statement_time=10' : 'SET SESSION max_execution_time=10000' );
			$wpdb->suppress_errors( $previous );
		}
		if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) {
			session_write_close(); }
	}

	/**
	 * Apply replacement pairs while preserving valid serialized string lengths.
	 *
	 * @param string $text Input text that may contain serialized values.
	 * @param array  $pairs Ordered search-to-replacement string pairs.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function replace( string $text, array $pairs ): string {
		if ( ! $pairs ) {
			return $text; }
		// Match JSON slash escapes without decoding/re-encoding unrelated JSON formatting.
		$escaped = array();
		foreach ( $pairs as $find => $replacement ) {
			if ( str_contains( $find, '/' ) && ! str_contains( $find, '\\/' ) ) {
				$escaped[ str_replace( '/', '\\/', $find ) ] = str_replace( '/', '\\/', $replacement ); }
		}
		$pairs   += $escaped;
		$replaced = strtr( $text, $pairs );
		// Leave unrelated values byte-for-byte intact, including malformed legacy serialization.
		if ( $replaced === $text ) {
			return $text; }
		// Parse serialized strings without instantiating PHP objects or executing __wakeup().
		if ( is_serialized( $text ) ) {
			$offset = 0;
			$result = self::serialized( $text, $offset, $pairs );
			if ( strlen( $text ) !== $offset ) {
				throw new RuntimeException( 'Unexpected trailing serialized data.' ); }
			return $result;
		}
		return $replaced;
	}

	/**
	 * Walk serialized values recursively and rewrite string lengths after replacements.
	 *
	 * @param string $s Serialized input value being parsed.
	 * @param int    &$p Current byte cursor in the serialized input, advanced in place.
	 * @param array  $pairs Ordered search-to-replacement string pairs.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function serialized( string $s, int &$p, array $pairs ): string {
		$tail = substr( $s, $p );
		if ( preg_match( '/^s:(\d+):"/', $tail, $m ) ) {
			$start  = $p + strlen( $m[0] );
			$length = (int) $m[1];
			if ( substr( $s, $start + $length, 2 ) !== '";' ) {
				throw new RuntimeException( 'Invalid serialized string.' ); }
			$value = substr( $s, $start, $length );
			$p     = $start + $length + 2;
			$value = self::replace( $value, $pairs );
			return 's:' . strlen( $value ) . ':"' . $value . '";';
		}
		if ( preg_match( '/^(a:(\d+):\{|O:\d+:"[^"]*":(\d+):\{)/', $tail, $m ) ) {
			$header = $m[1];
			$count  = (int) ( '' !== $m[2] ? $m[2] : $m[3] );
			$p     += strlen( $header );
			$output = $header;
			for ( $i = 0; $i < $count * 2; $i++ ) {
				$output .= self::serialized( $s, $p, $pairs ); }
			if ( ( $s[ $p++ ] ?? '' ) !== '}' ) {
				throw new RuntimeException( 'Invalid serialized container.' ); }
			return $output . '}';
		}
		if ( preg_match( '/^(N;|[bidrR]:[^;]*;|E:\d+:"[^"]*";)/', $tail, $m ) ) {
			$p += strlen( $m[0] );
			return $m[0];
		}
		throw new RuntimeException( 'Unsupported serialized value; transfer stopped rather than corrupting it.' );
	}
}
