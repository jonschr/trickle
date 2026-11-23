<?php
/**
 * Bounded stream windows, replay checkpoints and receipts.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/** Immutable gzip snapshots with resumable streaming, bounded chunks and durable byte offsets. */
final class CSM_Segments {
	public const SIZE          = 524288;
	public const UPLOAD_SIZE   = 16 * 1024 * 1024;
	public const DOWNLOAD_SIZE = 64 * 1024 * 1024;

	/**
	 * Resolve private snapshot storage for a validated transfer and stream kind.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 * @param string $kind Selected stream kind: database or files.
	 * @param string $suffix Internal storage suffix appended to the validated basename.
	 *
	 * @return string Absolute private stream path including the requested internal suffix.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	private static function path( string $id, string $kind, string $suffix ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $id ) || ! in_array( $kind, array( 'files', 'database' ), true ) ) {
			throw new RuntimeException( 'Invalid segmented transfer.' ); }
		return CSM_Support::private_dir() . '/segment-' . $id . '-' . $kind . $suffix;
	}

	/**
	 * Create or refresh source ownership under its nonblocking stream lock.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 *
	 * @throws CSM_Busy_Error If another request owns the source stream lock.
	 */
	public static function lease( string $id ): void {
		$path = self::path( $id, 'files', '.source.owner' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$lock = fopen( self::path( $id, 'files', '.source.lock' ), 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( $lock ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $lock );
			} throw new CSM_Busy_Error( 'Source preparation is busy.' ); }
		try {
			$identity = self::path( $id, 'files', '.comparison.owner' );
			if ( is_file( $identity ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
				CSM_Access::assert_owner( json_decode( file_get_contents( $identity ), true, 32, JSON_THROW_ON_ERROR ) ); }
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
				CSM_Access::assert_owner( json_decode( file_get_contents( $path ), true, 32, JSON_THROW_ON_ERROR ) );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- A best-effort activity heartbeat must not interrupt checkpoint publication or recovery. Retain source timestamps or the recovery heartbeat on local storage.
				@touch( $path ); } else {
				CSM_Support::atomic_json(
					$path,
					array(
						'credential' => CSM_Access::owner(),
						'created'    => time(),
					)
				); }
		} finally {
			flock( $lock, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $lock ); }
	}

	/**
	 * Build or reuse an immutable snapshot pinned to its owner and selection.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 * @param string $kind Selected stream kind: database or files.
	 * @param array  $selection Approved source component selection.
	 *
	 * @return array Preparation progress or complete snapshot size, digest and ownership metadata.
	 *
	 * @throws CSM_Busy_Error If another request owns the source stream lock.
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 * @throws Throwable If snapshot production fails; the original error is rethrown after cleanup.
	 */
	public static function bundle( string $id, string $kind, array $selection ): array {
		$path = self::path( $id, $kind, '.source' );
		if ( is_dir( $path . '.live' ) ) {
			throw new RuntimeException( 'This transfer already uses live streaming.' );
		} $meta = $path . '.json';
		$digest = hash( 'sha256', CSM_Support::json( $selection ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$lock = fopen( $path . '.lock', 'c' );
		if ( ! $lock ) {
			throw new RuntimeException( 'Cannot open source snapshot lock.' ); }
		if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $lock );
			throw new CSM_Busy_Error( 'Source stream is still being built. Retry preparation shortly.' ); }
		try {
			$identity = self::path( $id, 'files', '.comparison.owner' );
			if ( is_file( $identity ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
				CSM_Access::assert_owner( json_decode( file_get_contents( $identity ), true, 32, JSON_THROW_ON_ERROR ) ); }
			if ( is_file( $meta ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
				$info = json_decode( file_get_contents( $meta ), true, 32, JSON_THROW_ON_ERROR );
				CSM_Access::assert_owner( $info );
				if ( ! hash_equals( $info['selection'], $digest ) ) {
					throw new RuntimeException( 'This stream is pinned to a different selection. Discard and prepare again.' ); }
				if ( is_file( $path ) && filesize( $path ) === $info['size'] ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- A best-effort activity heartbeat must not interrupt checkpoint publication or recovery. Retain source timestamps or the recovery heartbeat on local storage.
					@touch( $path . '.owner' );
					return $info; }
				throw new RuntimeException( 'Source checkpoint is missing. Discard and prepare again.' );
			}
			if ( is_file( $path . '.owner' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
				$owner = json_decode( file_get_contents( $path . '.owner' ), true, 32, JSON_THROW_ON_ERROR );
				CSM_Access::assert_owner( $owner );
				if ( isset( $owner['selection'] ) && ! hash_equals( $owner['selection'], $digest ) ) {
					throw new RuntimeException( 'This package is pinned to a different selection.' ); }
			}
			CSM_Support::atomic_json(
				$path . '.owner',
				array(
					'credential' => CSM_Access::owner(),
					'selection'  => $digest,
					'touched'    => time(),
				)
			);
			ignore_user_abort( true );
			CSM_Support::begin_source_budget();
			$previous_limit = (int) ini_get( 'max_execution_time' );
			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts may forbid runtime changes; bounded work and resumable checkpoints remain mandatory.
				@set_time_limit( 50 ); }
			try {
				$selection = CSM_Files::selection( $selection );
				CSM_Database::validate( $selection['tables'] ?? array() );
				$built = CSM_Package::build( $path, $kind, $selection );
				if ( ! empty( $built['continue'] ) ) {
					return $built; }
				$info = $built + array(
					'download_windows' => 1,
					'credential'       => CSM_Access::owner(),
					'selection'        => $digest,
					'created'          => time(),
				);
				CSM_Support::atomic_json( $meta, $info );
				return $info;
			} catch ( Throwable $error ) {
				if ( 'database' === $kind ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
					@unlink( $path . '.part' ); }
				throw $error;
			} finally {
				CSM_Support::end_source_budget();
				if ( function_exists( 'set_time_limit' ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts may forbid runtime changes; bounded work and resumable checkpoints remain mandatory.
					@set_time_limit( $previous_limit ); }
			}
		} finally {
			flock( $lock, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $lock ); }
	}

	/**
	 * Read one bounded byte chunk from an owned completed snapshot.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 * @param string $kind Selected stream kind: database or files.
	 * @param int    $offset Absolute byte offset within the immutable snapshot.
	 *
	 * @return string Nonempty snapshot bytes, up to the configured chunk size.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function chunk( string $id, string $kind, int $offset ): string {
		$path = self::path( $id, $kind, '.source' );
		if ( $offset < 0 || ! is_file( $path . '.json' ) || ! is_file( $path ) || $offset >= filesize( $path ) ) {
			throw new RuntimeException( 'Invalid source checkpoint offset.' ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
		CSM_Access::assert_owner( json_decode( file_get_contents( $path . '.json' ), true, 32, JSON_THROW_ON_ERROR ) );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- A best-effort activity heartbeat must not interrupt checkpoint publication or recovery. Retain source timestamps or the recovery heartbeat on local storage.
		@touch( $path . '.owner' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$fp = fopen( $path, 'rb' );
		if ( ! $fp || fseek( $fp, $offset ) !== 0 ) {
			throw new RuntimeException( 'Could not read source checkpoint.' ); }
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
			$bytes = fread( $fp, self::SIZE );
			if ( false === $bytes || '' === $bytes ) {
				throw new RuntimeException( 'Source checkpoint is unreadable.' );
			} return $bytes; } finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $fp ); }
	}

	/**
	 * Stream a checked byte range from an owned immutable snapshot.
	 *
	 * @param string   $id Transfer identifier, a 32-character hexadecimal string.
	 * @param string   $kind Selected stream kind: database or files.
	 * @param int      $offset Absolute byte offset within the immutable snapshot.
	 * @param array    $bundle Snapshot size, SHA-256 and negotiated transport metadata.
	 * @param int|null $length Requested byte count, or null to stream the remaining snapshot.
	 *
	 * @return Generator Generator yielding raw snapshot bytes from the requested offset.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function source( string $id, string $kind, int $offset, array $bundle, ?int $length = null ): Generator {
		self::validate_bundle( $bundle );
		$path = self::path( $id, $kind, '.source' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
		$meta = is_file( $path . '.json' ) ? json_decode( file_get_contents( $path . '.json' ), true, 32, JSON_THROW_ON_ERROR ) : array();
		CSM_Access::assert_owner( $meta );
		if ( $offset < 0 || $offset > $bundle['size'] || ( $meta['size'] ?? null ) !== $bundle['size'] || ! hash_equals( $meta['sha256'] ?? '', $bundle['sha256'] ) || ! is_file( $path ) || filesize( $path ) !== $bundle['size'] ) {
			throw new RuntimeException( 'Source snapshot or offset does not match.' ); }
		$length ??= $bundle['size'] - $offset;
		if ( $length < 0 || $length > $bundle['size'] - $offset ) {
			throw new RuntimeException( 'Invalid source window length.' ); }
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- A best-effort activity heartbeat must not interrupt checkpoint publication or recovery. Retain source timestamps or the recovery heartbeat on local storage.
		@touch( $path . '.owner' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$fp = fopen( $path, 'rb' );
		if ( ! $fp || fseek( $fp, $offset ) !== 0 ) {
			throw new RuntimeException( 'Cannot open source snapshot.' ); }
		try {
			while ( $length > 0 && ! feof( $fp ) ) {
				CSM_Support::check_cancel();
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
				$bytes = fread( $fp, min( self::SIZE, $length ) );
				if ( false === $bytes ) {
					throw new RuntimeException( 'Cannot read source snapshot.' ); }
				if ( '' !== $bytes ) {
					$length -= strlen( $bytes );
					yield $bytes; }
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $fp ); }
	}

	/**
	 * Download a bounded snapshot window and retain its durable byte offset.
	 *
	 * @param array  $peer Peer URL, scoped connection key and optional Basic Auth configuration.
	 * @param array  $job Destination job with its persisted staging and receipt state.
	 * @param string $kind Selected stream kind: database or files.
	 * @param array  $bundle Snapshot size, SHA-256 and negotiated transport metadata.
	 *
	 * @return array Job summary with continuation and checkpoint fields.
	 *
	 * @throws CSM_Network_Error If receipt is interrupted or the peer requires a retained retry.
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function pull( array $peer, array $job, string $kind, array $bundle ): array {
		CSM_Support::step();
		$state = self::state( $job, $kind, $bundle );
		$path  = self::path( $job['id'], $kind, '.incoming' );
		if ( $state['offset'] < $bundle['size'] ) {
			CSM_Support::atomic_json( $path . '.json', $bundle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
			$fp = fopen( $path, 'c+b' );
			if ( ! $fp || fseek( $fp, $state['offset'] ) !== 0 ) {
				throw new RuntimeException( 'Cannot resume destination stream.' ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
			chmod( $path, 0600 );
			// Older sources ignore length; time windows still checkpoint their partial downloads.
			$length = ! empty( $bundle['download_windows'] ) ? min( self::DOWNLOAD_SIZE, $bundle['size'] - $state['offset'] ) : $bundle['size'] - $state['offset'];
			$data   = array(
				'id'     => $job['id'],
				'kind'   => $kind,
				'offset' => $state['offset'],
				'bundle' => $bundle,
			);
			if ( ! empty( $bundle['download_windows'] ) ) {
				$data['length'] = $length; }
			try {
				$download = CSM_Transport::request( $peer, 'bundle_stream', $data, null, $fp, 20, $length ); } finally {
				fflush( $fp );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $fp ); }
				clearstatcache( true, $path );
				if ( filesize( $path ) !== $state['offset'] + $length && empty( $download['continue'] ) ) {
					throw new CSM_Network_Error( 'Connection failed: Incomplete snapshot window. Resume from the saved offset.' ); }
		}
		clearstatcache( true, $path );
		$next = is_file( $path ) ? filesize( $path ) : 0;
		if ( $next < $bundle['size'] ) {
			return self::receive( $job, $kind, $bundle, $next, '', hash( 'sha256', '' ), true, true ); }
		return self::receive( $job, $kind, $bundle, $next, '', hash( 'sha256', '' ), false, true );
	}

	/**
	 * Append a streamed upload only at the expected snapshot offset.
	 *
	 * @param array    $job Destination job with its persisted staging and receipt state.
	 * @param string   $kind Selected stream kind: database or files.
	 * @param array    $bundle Snapshot size, SHA-256 and negotiated transport metadata.
	 * @param int      $offset Absolute byte offset within the immutable snapshot.
	 * @param resource $input Readable incoming HTTP body stream; ownership remains with the caller.
	 * @param int|null $length Expected upload byte count, or null for the remaining snapshot.
	 *
	 * @return array Job summary after retaining or processing the uploaded bytes.
	 *
	 * @throws CSM_Network_Error If receipt is interrupted or the peer requires a retained retry.
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function receive_stream( array $job, string $kind, array $bundle, int $offset, $input, ?int $length = null ): array {
		CSM_Support::step();
		$state = self::state( $job, $kind, $bundle );
		$path  = self::path( $job['id'], $kind, '.incoming' );
		if ( $offset !== $state['offset'] ) {
			throw new RuntimeException( 'Destination offset changed. Refresh its checkpoint before resuming.' ); }
		if ( null !== $length && ( $length < 0 || $length > self::UPLOAD_SIZE || $length > $bundle['size'] - $offset || ( 0 === $length && $offset !== $bundle['size'] ) ) ) {
			throw new RuntimeException( 'Invalid upload window length.' ); }
		$end = $offset + ( $length ?? ( $bundle['size'] - $offset ) );
		CSM_Support::atomic_json( $path . '.json', $bundle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$fp = fopen( $path, 'c+b' );
		if ( ! $fp || fseek( $fp, $offset ) !== 0 ) {
			throw new RuntimeException( 'Cannot resume destination stream.' ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
		chmod( $path, 0600 );
		try {
			while ( ! feof( $input ) ) {
				CSM_Support::check_cancel();
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
				$bytes = fread( $input, self::SIZE );
				if ( false === $bytes || ( '' === $bytes && ! feof( $input ) ) ) {
					throw new CSM_Network_Error( 'Connection failed: Incoming stream interrupted.' ); }
				if ( $offset + strlen( $bytes ) > $end ) {
					throw new RuntimeException( 'Snapshot stream exceeds its declared size.' ); }
				CSM_Support::write( $fp, $bytes );
				$offset += strlen( $bytes );
				fflush( $fp );
				CSM_Progress::update(
					array(
						'phase'     => 'Receiving resumable stream',
						'component' => $kind,
						'bytes'     => $offset,
						'count'     => $offset,
						'total'     => $bundle['size'],
						'unit'      => 'bytes',
					)
				);
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $fp ); }
		if ( $offset !== $end ) {
			throw new CSM_Network_Error( 'Connection failed: Incoming stream interrupted. Resume from the saved offset.' ); }
		return self::receive( $job, $kind, $bundle, $offset, '', hash( 'sha256', '' ), false, true );
	}

	/**
	 * Require a positive integer snapshot size and a hexadecimal SHA-256 digest.
	 *
	 * @param array $bundle Snapshot size, SHA-256 and negotiated transport metadata.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	private static function validate_bundle( array $bundle ): void {
		if ( ! is_int( $bundle['size'] ?? null ) || $bundle['size'] < 1 || ! is_string( $bundle['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $bundle['sha256'] ) ) {
			throw new RuntimeException( 'Invalid source checkpoint metadata.' ); }
	}

	/**
	 * Check the receiving job and bind its saved offset to one snapshot digest.
	 *
	 * @param array  $job Destination job with its persisted staging and receipt state.
	 * @param string $kind Selected stream kind: database or files.
	 * @param array  $bundle Snapshot size, SHA-256 and negotiated transport metadata.
	 *
	 * @return array Current offset, total size and SHA-256 of the selected snapshot.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function state( array $job, string $kind, array $bundle ): array {
		CSM_Updates::assert_job( $job );
		self::validate_bundle( $bundle );
		$path = self::path( $job['id'], $kind, '.incoming' );
		if ( 'staging' !== $job['status'] || ! in_array( $kind, CSM_Plugin::kinds( $job['selection'] ), true ) ) {
			throw new RuntimeException( 'Transfer is not awaiting this stream.' ); }
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Missing local input is checked immediately and rejected or treated as an absent cache. Read exact local file or input-stream bytes; no outbound HTTP request is made.
		$raw = @file_get_contents( $path . '.json' );
		if ( false !== $raw ) {
			$old = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
			if ( $old['size'] !== $bundle['size'] || ! hash_equals( $old['sha256'], $bundle['sha256'] ) ) {
				throw new RuntimeException( 'Source stream changed. Discard and prepare again.' ); }
		}
		$offset = is_file( $path ) ? filesize( $path ) : 0;
		if ( $offset > $bundle['size'] ) {
			throw new RuntimeException( 'Destination checkpoint is damaged.' ); }
		return array(
			'offset' => $offset,
			'size'   => $bundle['size'],
			'sha256' => $bundle['sha256'],
		);
	}

	/**
	 * Verify a chunk or replay, then advance resumable snapshot processing.
	 *
	 * @param array  $job Destination job with its persisted staging and receipt state.
	 * @param string $kind Selected stream kind: database or files.
	 * @param array  $bundle Snapshot size, SHA-256 and negotiated transport metadata.
	 * @param int    $offset Absolute byte offset within the immutable snapshot.
	 * @param string $bytes Exact payload bytes to frame or append.
	 * @param string $checksum Hexadecimal SHA-256 of this chunk, including an empty continuation chunk.
	 * @param bool   $defer Whether to checkpoint bytes without unpacking or staging them yet.
	 * @param bool   $continuing Whether to retain the current bounded-work deadline.
	 *
	 * @return array Job summary with continuation and checkpoint fields.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function receive( array $job, string $kind, array $bundle, int $offset, string $bytes, string $checksum, bool $defer = false, bool $continuing = false ): array {
		CSM_Support::step( 8, $continuing );
		$state = self::state( $job, $kind, $bundle );
		$path  = self::path( $job['id'], $kind, '.incoming' );
		if ( strlen( $bytes ) > self::SIZE || ! hash_equals( hash( 'sha256', $bytes ), $checksum ) || $offset < 0 || $offset > $state['offset'] || $offset + strlen( $bytes ) > $bundle['size'] ) {
			throw new RuntimeException( 'Segment checksum or offset mismatch.' ); }
		if ( isset( $job['received'][ $kind ] ) ) {
			return CSM_Plugin::summary( $job ) + array(
				'continue'   => false,
				'checkpoint' => $state,
			); }
		if ( $offset < $state['offset'] ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- A best-effort activity heartbeat must not interrupt checkpoint publication or recovery. Retain source timestamps or the recovery heartbeat on local storage.
			@touch( $path . '.owner' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
			$fp = fopen( $path, 'rb' );
			fseek( $fp, $offset );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
			$old = fread( $fp, strlen( $bytes ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $fp );
			if ( $offset + strlen( $bytes ) > $state['offset'] || ! hash_equals( $old, $bytes ) ) {
				throw new RuntimeException( 'Conflicting retry at an acknowledged offset.' ); }
		} elseif ( '' !== $bytes ) {
			CSM_Support::atomic_json( $path . '.json', $bundle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
			$fp = fopen( $path, 'c+b' );
			if ( ! $fp || fseek( $fp, $offset ) !== 0 ) {
				throw new RuntimeException( 'Cannot write destination checkpoint.' ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
			chmod( $path, 0600 );
			try {
				CSM_Support::write( $fp, $bytes );
				fflush( $fp ); } finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $fp ); }
		}
		clearstatcache( true, $path );
		$next = is_file( $path ) ? filesize( $path ) : 0;
		CSM_Progress::update(
			array(
				'phase'     => 'Receiving resumable stream',
				'component' => $kind,
				'bytes'     => $next,
				'count'     => $next,
				'total'     => $bundle['size'],
				'unit'      => 'bytes',
			)
		);
		if ( $next === $bundle['size'] && ! $defer && ! CSM_Support::due() ) {
			if ( ( $bundle['format'] ?? 1 ) === 2 ) {
				if ( CSM_Package::unpack( $path, $bundle, true ) && ! CSM_Support::due() ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
					$fp = fopen( $path . '.raw', 'rb' );
					try {
						$job = CSM_Plugin::receive_step( $job, $kind, $fp, true ); } finally {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
						fclose( $fp ); }
				}
			} else {
				if ( ! hash_equals( $bundle['sha256'], CSM_Support::hash_file( $path ) ) ) {
					throw new RuntimeException( 'Completed stream checksum mismatch. Discard and prepare again.' ); }
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- A best-effort activity heartbeat must not interrupt checkpoint publication or recovery. Retain source timestamps or the recovery heartbeat on local storage.
				@touch( $path . '.owner' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
				$fp = fopen( $path, 'rb' );
				try {
					$job = CSM_Plugin::receive( $job, $kind, $fp, CSM_Progress::run() ); } finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
					fclose( $fp ); }
			}
		}
		return CSM_Plugin::summary( $job ) + array(
			'continue'   => empty( $job['received'][ $kind ] ),
			'checkpoint' => array(
				'offset' => $next,
				'size'   => $bundle['size'],
			),
		);
	}

	/**
	 * Read the current incoming snapshot size without loading the full payload.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 * @param string $kind Selected stream kind: database or files.
	 *
	 * @return int Bytes present in private incoming storage, or zero before receipt.
	 */
	public static function received_bytes( string $id, string $kind ): int {
		$path = self::path( $id, $kind, '.incoming' );
		clearstatcache( true, $path );
		return is_file( $path ) ? filesize( $path ) : 0;
	}

	/**
	 * Require ownership of any existing job or source progress record.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 */
	public static function assert_progress_owner( string $id ): void {
		$job = get_option( '_csm_job_' . $id );
		if ( $job ) {
			CSM_Access::assert_owner( $job );
			return; }
		foreach ( array( 'files', 'database' ) as $kind ) {
			foreach ( array( '.source.owner', '.comparison.owner' ) as $suffix ) {
				$path = self::path( $id, $kind, $suffix );
				if ( is_file( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
					CSM_Access::assert_owner( json_decode( file_get_contents( $path ), true, 32, JSON_THROW_ON_ERROR ) );
					return; }
			}
		}
	}

	/**
	 * Check existing transfer ownership before publishing a cancellation marker.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 */
	public static function cancel( string $id ): void {
		$found = false;
		$job   = get_option( '_csm_job_' . $id );
		if ( $job ) {
			CSM_Access::assert_owner( $job );
			$found = true; }
		foreach ( array( 'database', 'files' ) as $kind ) {
			foreach ( array( '.source.owner', '.comparison.owner' ) as $suffix ) {
				$path = self::path( $id, $kind, $suffix );
				if ( is_file( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
					CSM_Access::assert_owner( json_decode( file_get_contents( $path ), true, 32, JSON_THROW_ON_ERROR ) );
					$found = true; }
			}
		}
		CSM_Support::cancel( $id );
	}

	/**
	 * Prune expired source/comparison storage while respecting active locks.
	 */
	public static function prune_sources(): void {
		CSM_Jobs::prune();
		foreach ( (array) glob( CSM_Support::private_dir() . '/segment-*.comparison.owner' ) as $marker ) {
			if ( filemtime( $marker ) < time() - DAY_IN_SECONDS ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
				@unlink( $marker ); }
		}
		foreach ( (array) glob( CSM_Support::private_dir() . '/media-compare-*.json' ) as $cache ) {
			if ( filemtime( $cache ) < time() - DAY_IN_SECONDS ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
				@unlink( $cache ); }
		}
		foreach ( (array) glob( CSM_Support::private_dir() . '/*.comparison-*.json' ) as $cache ) {
			if ( filemtime( $cache ) < time() - DAY_IN_SECONDS ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
				@unlink( $cache ); }
		}
		foreach ( (array) glob( CSM_Support::private_dir() . '/*.build-*' ) as $cache ) {
			if ( filemtime( $cache ) < time() - DAY_IN_SECONDS ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
				@unlink( $cache ); }
		}
		foreach ( (array) glob( CSM_Support::private_dir() . '/component-folder-*.json' ) as $cache ) {
			if ( filemtime( $cache ) < time() - 30 * DAY_IN_SECONDS ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
				@unlink( $cache ); }
		}
		foreach ( array_merge( (array) glob( CSM_Support::private_dir() . '/component-snapshot-*.json' ), (array) glob( CSM_Support::private_dir() . '/media-snapshot-*.json' ) ) as $cache ) {
			if ( filemtime( $cache ) < time() - 7 * DAY_IN_SECONDS ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
				@unlink( $cache ); }
		}
		foreach ( (array) glob( CSM_Support::private_dir() . '/segment-*.source.owner' ) as $owner ) {
			if ( filemtime( $owner ) > time() - DAY_IN_SECONDS || ! preg_match( '/segment-([a-f0-9]{32})-(database|files)\.source\.owner$/D', $owner, $m ) ) {
				continue; }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
			$lock = fopen( substr( $owner, 0, -6 ) . '.lock', 'c' );
			if ( ! $lock ) {
				continue; }
			if ( flock( $lock, LOCK_EX | LOCK_NB ) ) {
				try {
					clearstatcache( true, $owner );
					if ( is_file( $owner ) && filemtime( $owner ) <= time() - DAY_IN_SECONDS ) {
						foreach ( array( '', '.json', '.part', '.owner', '.build', '.live' ) as $suffix ) {
							CSM_Support::remove_tree( substr( $owner, 0, -6 ) . $suffix ); }
						CSM_Log::write( 'info', 'Expired source package removed', array( 'transfer_id' => $m[1] ) );
					}
				} finally {
					flock( $lock, LOCK_UN ); }
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $lock );
		}
	}

	/**
	 * List retained source preparations visible to the current connection.
	 *
	 * @return array Source identifiers, age metadata, byte counts and discard eligibility.
	 *
	 * @throws RuntimeException If an ownership check fails for a reason other than access denial.
	 */
	public static function retained_sources(): array {
		$sources = array();
		foreach ( (array) glob( CSM_Support::private_dir() . '/segment-*.source.owner' ) as $path ) {
			if ( ! preg_match( '/segment-([a-f0-9]{32})-(database|files)\.source\.owner$/D', $path, $m ) ) {
				continue; }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
			$owner = json_decode( file_get_contents( $path ), true, 32, JSON_THROW_ON_ERROR );
			try {
				CSM_Access::assert_owner( $owner );
			} catch ( RuntimeException $error ) {
				if ( $error->getCode() === 403 ) {
					continue;
				} throw $error; }
			$id                         = $m[1];
			$base                       = substr( $path, 0, -6 );
			$sources[ $id ]           ??= array(
				'id'       => $id,
				'created'  => $owner['created'] ?? $owner['touched'] ?? filemtime( $path ),
				'kinds'    => array(),
				'bytes'    => 0,
				'snapshot' => false,
			);
			$sources[ $id ]['kinds'][]  = $m[2];
			$sources[ $id ]['snapshot'] = $sources[ $id ]['snapshot'] || isset( $owner['selection'] );
			$sources[ $id ]['bytes']   += is_file( $base ) ? filesize( $base ) : ( is_file( $base . '.part' ) ? filesize( $base . '.part' ) : 0 );
			foreach ( array( '/window', '/window.part' ) as $suffix ) {
				if ( is_file( $base . '.live' . $suffix ) ) {
					$sources[ $id ]['bytes'] += filesize( $base . '.live' . $suffix ); }
			}
			$sources[ $id ]['can_discard'] = ( get_option( '_csm_control' )['id'] ?? '' ) !== $id && get_option( '_csm_active_job' ) !== $id && ! get_option( '_csm_job_' . $id );
		}
		return array_values( $sources );
	}

	/**
	 * Cancel and clean an idle owned preparation while retaining comparison ownership.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function discard_preparation( string $id ): void {
		$source = array_column( self::retained_sources(), null, 'id' )[ $id ] ?? null;
		if ( ! $source ) {
			throw new RuntimeException( 'Source preparation not found. Refresh its status.' ); }
		if ( ! $source['can_discard'] ) {
			throw new RuntimeException( 'Use this transfer’s recovery controls before discarding its source preparation.' ); }
		$owner = null;
		foreach ( array( 'database', 'files' ) as $kind ) {
			$path = self::path( $id, $kind, '.source.owner' );
			if ( ! is_file( $path ) ) {
				continue; }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
			$record = json_decode( file_get_contents( $path ), true, 32, JSON_THROW_ON_ERROR );
			CSM_Access::assert_owner( $record );
			if ( null !== $owner && $owner !== $record['credential'] ) {
				throw new RuntimeException( 'Inconsistent source ownership. Recovery state has been kept.' ); }
			$owner = $record['credential'];
		}
		$previous = $GLOBALS['_csm_credential'] ?? null;
		try {
			// A site administrator's cancellation must also stop the original remote credential's worker.
			$GLOBALS['_csm_credential'] = array_replace( $previous ?? array(), array( 'id' => $owner ) );
			self::cancel( $id );
			self::cleanup_source( $id );
			CSM_Support::atomic_json(
				self::path( $id, 'files', '.comparison.owner' ),
				array(
					'credential' => $owner,
					'created'    => time(),
				)
			);
		} finally {
			if ( null === $previous ) {
				unset( $GLOBALS['_csm_credential'] );
			} else {
				$GLOBALS['_csm_credential'] = $previous; }
		}
	}

	/**
	 * Release read-only source leases without losing progress/cancel ownership.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 *
	 * @return bool True when released; false when locks, jobs or snapshot state still prevent release.
	 */
	public static function release_comparison( string $id ): bool {
		$locks     = array();
		$ownership = null;
		try {
			foreach ( array( 'database', 'files' ) as $kind ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
				$fp = fopen( self::path( $id, $kind, '.source.lock' ), 'c' );
				if ( ! $fp ) {
					return false; }
				if ( ! flock( $fp, LOCK_EX | LOCK_NB ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
					fclose( $fp );
					return false; }
				$locks[] = $fp;
			}
			if ( get_option( '_csm_job_' . $id ) || ( get_option( '_csm_control' )['id'] ?? '' ) === $id ) {
				return false; }
			foreach ( array( 'database', 'files' ) as $kind ) {
				$path = self::path( $id, $kind, '.source' );
				foreach ( array( '', '.json', '.part', '.build', '.live' ) as $suffix ) {
					if ( file_exists( $path . $suffix ) ) {
						return false; }
				}
				if ( is_file( $path . '.owner' ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
					$owner = json_decode( file_get_contents( $path . '.owner' ), true, 32, JSON_THROW_ON_ERROR );
					CSM_Access::assert_owner( $owner );
					if ( isset( $owner['selection'] ) ) {
						return false; }
					$ownership = $owner;
				}
			}
			$identity = self::path( $id, 'files', '.comparison.owner' );
			if ( is_file( $identity ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
				CSM_Access::assert_owner( json_decode( file_get_contents( $identity ), true, 32, JSON_THROW_ON_ERROR ) ); }
			if ( $ownership ) {
				CSM_Support::atomic_json( $identity, $ownership ); }
			// Keep progress/cancel ownership without counting read-only comparisons as retained snapshots.
			foreach ( array( 'database', 'files' ) as $kind ) {
				$path = self::path( $id, $kind, '.source.owner' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the validated local file during cleanup or recoverable publication.
				if ( is_file( $path ) && ! unlink( $path ) ) {
					return false; }
			}
			return true;
		} finally {
			foreach ( $locks as $fp ) {
				flock( $fp, LOCK_UN );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $fp ); }
		}
	}

	/**
	 * Remove owned source snapshots and replay windows under their stream locks.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 *
	 * @throws CSM_Busy_Error If another request owns the source stream lock.
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function cleanup_source( string $id ): void {
		foreach ( array( 'database', 'files' ) as $kind ) {
			$path = self::path( $id, $kind, '.source' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
			$lock = fopen( $path . '.lock', 'c' );
			if ( ! $lock ) {
				throw new RuntimeException( 'Cannot open source snapshot lock.' ); }
			if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $lock );
				throw new CSM_Busy_Error( 'Source stream is still being built. Retry cleanup shortly.' ); }
			try {
				if ( is_file( $path . '.owner' ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
					$owner = json_decode( file_get_contents( $path . '.owner' ), true, 32, JSON_THROW_ON_ERROR );
					CSM_Access::assert_owner( $owner ); } elseif ( is_file( $path . '.json' ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
					CSM_Access::assert_owner( json_decode( file_get_contents( $path . '.json' ), true, 32, JSON_THROW_ON_ERROR ) ); }
					foreach ( array( '', '.json', '.part', '.owner', '.build', '.live' ) as $suffix ) {
						CSM_Support::remove_tree( $path . $suffix ); }
			} finally {
				flock( $lock, LOCK_UN );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $lock ); }
		}
	}

	/**
	 * Remove owned source storage and destination snapshot intermediates.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 */
	public static function cleanup( string $id ): void {
		self::cleanup_source( $id );
		foreach ( array( 'database', 'files' ) as $kind ) {
			foreach ( array( '.incoming', '.incoming.json', '.incoming.json.tmp', '.incoming.raw', '.incoming.unpack' ) as $suffix ) {
				CSM_Support::remove_tree( self::path( $id, $kind, $suffix ) ); }
		}
	}
}
