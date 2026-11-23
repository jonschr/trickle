<?php
/**
 * Bounded gzip framing and resumable snapshot packaging.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-csm-timing.php';

/** Small, independently verified gzip frames; saved cursors never depend on a PHP process surviving. */
final class CSM_Package {
	/**
	 * Whether the optional OpenSSL digest provider is usable.
	 *
	 * @var bool|null
	 */
	private static ?bool $openssl = null;

	/**
	 * Compute SHA-256 with an optional accelerated provider and exact fallback.
	 *
	 * @param string $bytes Exact bytes whose SHA-256 digest is required.
	 * @param bool   $binary Whether to return the digest as raw binary bytes.
	 *
	 * @return string Raw 32-byte digest when binary is true; otherwise 64 lowercase hex characters.
	 */
	public static function digest( string $bytes, bool $binary = false ): string {
		$started = hrtime( true );
		$backend = 'checksum.block_native';
		try {
			if ( strlen( $bytes ) >= 4096 ) {
				self::$openssl ??= function_exists( 'openssl_digest' );
				if ( self::$openssl ) {
					try {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A rejected optional provider falls back to the exact native SHA-256 implementation.
						$digest = @openssl_digest( $bytes, 'sha256', $binary ); } catch ( Throwable $error ) {
						$digest = false; }
						if ( is_string( $digest ) && strlen( $digest ) === ( $binary ? 32 : 64 ) ) {
							$backend = 'checksum.block_openssl';
							return $digest; }
						self::$openssl = false;
				}
			}
			return hash( 'sha256', $bytes, $binary );
		} finally {
			CSM_Timing::add( $backend, $started, strlen( $bytes ) ); }
	}

	/**
	 * Extend the stream checksum chain with one record.
	 *
	 * @param string $hash Previous checksum-chain value; empty before the first record.
	 * @param string $record Exact bytes appended to the checksum chain.
	 *
	 * @return string Hexadecimal SHA-256 of the previous chain followed by the record bytes.
	 */
	public static function chain( string $hash, string $record ): string {
		return hash( 'sha256', $hash . $record ); }
	/**
	 * Encode one bounded gzip frame with its length, digest and control flag.
	 *
	 * @param string $part Nonempty uncompressed frame payload, at most 256 KiB.
	 * @param bool   $control Whether to set the frame header control-record bit.
	 *
	 * @return string Binary frame containing a 36-byte header and compressed payload.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function frame( string $part, bool $control = false ): string {
		if ( '' === $part || strlen( $part ) > 262144 ) {
			throw new RuntimeException( 'Invalid frame payload size.' ); }
		CSM_Support::check_source_budget();
		$sample = substr( $part, max( 0, intdiv( strlen( $part ) - 8192, 2 ) ), 8192 );
		$level  = strlen( $sample ) === 8192 && strlen( gzdeflate( $sample, 1 ) ) >= strlen( $sample ) * .98 ? 0 : 3;
		$gzip   = gzencode( $part, $level );
		return pack( 'N', strlen( $gzip ) | ( $control ? 0x80000000 : 0 ) ) . self::digest( $gzip, true ) . $gzip;
	}

	/**
	 * Split bytes into bounded frames and write them to the supplied stream.
	 *
	 * @param resource $fp Writable framed-output stream; ownership remains with the caller.
	 * @param string   $bytes Exact payload bytes to frame or append.
	 */
	public static function put( $fp, string $bytes ): void {
		foreach ( str_split( $bytes, 262144 ) as $part ) {
			CSM_Support::write( $fp, self::frame( $part ) ); }
	}

	/**
	 * Build or resume a private framed snapshot for the approved selection.
	 *
	 * File snapshots may yield a durable continuation. A database snapshot is rebuilt
	 * within one consistent source budget rather than mixing transactions across requests.
	 *
	 * @param string $path Absolute private snapshot path.
	 * @param string $kind Selected stream kind: database or files.
	 * @param array  $selection Approved source component selection.
	 *
	 * @return array Continuation progress or completed format, size, SHA-256 and component metadata.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function build( string $path, string $kind, array $selection ): array {
		$checkpoint = $path . '.build';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
		$state = is_file( $checkpoint ) ? json_decode( file_get_contents( $checkpoint ), true, 512, JSON_THROW_ON_ERROR ) : array(
			'offset'         => 0,
			'cursor'         => array(),
			'components'     => array(
				'database' => 0,
				'plugins'  => 0,
				'themes'   => 0,
				'media'    => 0,
			),
			'range_tracking' => true,
			'ranges'         => array(),
		);
		if ( ! empty( $state['complete'] ) && is_file( $path ) && filesize( $path ) === $state['offset'] ) {
			return array(
				'format'           => 2,
				'size'             => $state['offset'],
				'sha256'           => CSM_Support::hash_file( $path ),
				'components'       => $state['components'],
				'component_ranges' => ! empty( $state['range_tracking'] ) ? $state['ranges'] : null,
			); }
		if ( $state['offset'] > 0 && ( ! is_file( $path . '.part' ) || filesize( $path . '.part' ) < $state['offset'] ) ) {
			throw new RuntimeException( 'Source package checkpoint is missing or truncated.' ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$fp = fopen( $path . '.part', 'c+b' );
		if ( ! $fp || ! ftruncate( $fp, $state['offset'] ) || fseek( $fp, $state['offset'] ) !== 0 ) {
			throw new RuntimeException( 'Cannot resume source packaging.' ); }
		CSM_Support::step();
		try {
			if ( 'database' === $kind ) {
				// Keep one consistent snapshot, with read locks for nontransactional tables. Its hard budget fails safely rather than mixing snapshots from different requests.
				$state = array(
					'offset'         => 0,
					'cursor'         => array(),
					'components'     => array(
						'database' => 0,
						'plugins'  => 0,
						'themes'   => 0,
						'media'    => 0,
					),
					'range_tracking' => true,
					'ranges'         => array(),
				);
				ftruncate( $fp, 0 );
				rewind( $fp );
				$buffer = '';
				foreach ( CSM_Database::stream( $selection, true ) as $bytes ) {
					$buffer                          .= $bytes;
					$state['components']['database'] += strlen( $bytes );
					// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The buffer changes on each step; its current length controls exact frame boundaries.
					while ( strlen( $buffer ) >= 262144 ) {
						self::put( $fp, substr( $buffer, 0, 262144 ) );
						$buffer = substr( $buffer, 262144 ); }
				}
				if ( '' !== $buffer ) {
					self::put( $fp, $buffer ); }
				$done = true;
			} else {
				$buffer    = '';
				$component = null;
				$flush     = static function ( bool $all ) use ( $fp, &$buffer, &$component, &$state ): void {
					// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The buffer changes on each step; its current length controls exact frame boundaries.
					while ( strlen( $buffer ) >= 262144 || ( $all && '' !== $buffer ) ) {
						$part   = substr( $buffer, 0, 262144 );
						$buffer = substr( $buffer, strlen( $part ) );
						$start  = ftell( $fp );
						self::put( $fp, $part );
						if ( ! empty( $state['range_tracking'] ) && in_array( $component, array( 'plugins', 'themes', 'media' ), true ) ) {
							$state['ranges'][ $component ]      ??= array(
								'start' => $start,
								'end'   => $start,
							);
							$state['ranges'][ $component ]['end'] = ftell( $fp );
						}
					}
				};
				$generator = CSM_Files::stream_step( $selection, $state['cursor'], $state['components'] );
				foreach ( $generator as $bytes ) {
					$next = $state['cursor']['component'] ?? null;
					if ( $next !== $component ) {
						$flush( true );
						$component = $next; }
					$buffer .= $bytes;
					$flush( false );
					// A checkpoint only acknowledges generator units whose entire output is durable.
					if ( CSM_Support::due() ) {
						$flush( true );
						fflush( $fp );
						$state['offset'] = ftell( $fp );
						CSM_Support::atomic_json( $checkpoint, $state );
						return array(
							'continue'         => true,
							'packaged'         => $state['offset'],
							'components'       => $state['components'],
							'component_ranges' => ! empty( $state['range_tracking'] ) ? $state['ranges'] : null,
						);
					}
				}
				$flush( true );
				$done = ! empty( $state['cursor']['done'] );
				if ( ! $done ) {
					fflush( $fp );
					$state['offset'] = ftell( $fp );
					CSM_Support::atomic_json( $checkpoint, $state );
					return array(
						'continue'         => true,
						'packaged'         => $state['offset'],
						'components'       => $state['components'],
						'component_ranges' => ! empty( $state['range_tracking'] ) ? $state['ranges'] : null,
					); }
			}
			fflush( $fp );
			$state['offset']   = ftell( $fp );
			$state['complete'] = true;
			CSM_Support::atomic_json( $checkpoint, $state );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $fp ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename is the atomic publication/recovery boundary.
		if ( $done && ! rename( $path . '.part', $path ) ) {
			throw new RuntimeException( 'Cannot publish source snapshot.' ); }
		return array(
			'format'           => 2,
			'size'             => $state['offset'],
			'sha256'           => CSM_Support::hash_file( $path ),
			'components'       => $state['components'],
			'component_ranges' => ! empty( $state['range_tracking'] ) ? $state['ranges'] : null,
		);
	}

	/**
	 * Verify and decompress snapshot frames using durable input/output offsets.
	 *
	 * @param string $path Absolute private snapshot path.
	 * @param array  $bundle Snapshot size, SHA-256 and negotiated transport metadata.
	 * @param bool   $continuing Whether to retain the current bounded-work deadline.
	 *
	 * @return bool True after all frames and the whole snapshot checksum verify; false to resume later.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function unpack( string $path, array $bundle, bool $continuing = false ): bool {
		CSM_Support::step( 8, $continuing );
		$checkpoint = $path . '.unpack';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
		$state = is_file( $checkpoint ) ? json_decode( file_get_contents( $checkpoint ), true, 32, JSON_THROW_ON_ERROR ) : array(
			'in'  => 0,
			'out' => 0,
		);
		if ( $state['out'] > 0 && ( ! is_file( $path . '.raw' ) || filesize( $path . '.raw' ) < $state['out'] ) ) {
			throw new RuntimeException( 'Verified frame checkpoint is missing or truncated.' ); }
		if ( $state['in'] === $bundle['size'] ) {
			if ( ! empty( $state['verified'] ) ) {
				return true; }
			if ( CSM_Support::due() ) {
				return false; }
			CSM_Progress::update(
				array(
					'phase'     => 'Verifying snapshot checksum',
					'component' => '',
					'count'     => 0,
					'total'     => $bundle['size'],
					'unit'      => 'bytes',
					'bytes'     => 0,
				),
				true
			);
			if ( ! hash_equals(
				$bundle['sha256'],
				CSM_Support::hash_file(
					$path,
					static fn( $bytes )=>CSM_Progress::update(
						array(
							'count' => $bytes,
							'bytes' => $bytes,
						)
					)
				)
			) ) {
				throw new RuntimeException( 'Completed snapshot checksum mismatch.' ); }
			$state['verified'] = true;
			CSM_Support::atomic_json( $checkpoint, $state );
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$in = fopen( $path, 'rb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$out = fopen( $path . '.raw', 'c+b' );
		if ( ! $in || ! $out || fseek( $in, $state['in'] ) !== 0 || ! ftruncate( $out, $state['out'] ) || fseek( $out, $state['out'] ) !== 0 ) {
			throw new RuntimeException( 'Cannot resume frame verification.' ); }
		try {
			while ( $state['in'] < $bundle['size'] ) {
				CSM_Support::check_cancel();
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
				$header = fread( $in, 36 );
				if ( strlen( $header ) !== 36 ) {
					throw new RuntimeException( 'Truncated frame header.' ); }
				$length = unpack( 'N', substr( $header, 0, 4 ) )[1];
				if ( $length < 1 || $length > 300000 || $state['in'] + 36 + $length > $bundle['size'] ) {
					throw new RuntimeException( 'Invalid frame length.' ); }
				$gzip = '';
				// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The buffer changes on each step; its current length controls exact frame boundaries.
				while ( strlen( $gzip ) < $length ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
					$part = fread( $in, $length - strlen( $gzip ) );
					if ( false === $part || '' === $part ) {
						throw new RuntimeException( 'Truncated frame.' );
					} $gzip .= $part; }
				if ( ! hash_equals( substr( $header, 4 ), self::digest( $gzip, true ) ) ) {
					throw new RuntimeException( 'Frame checksum mismatch.' ); }
				$raw = gzdecode( $gzip, 262144 );
				if ( false === $raw ) {
					throw new RuntimeException( 'Invalid compressed frame.' ); }
				CSM_Support::write( $out, $raw );
				$state = array(
					'in'  => ftell( $in ),
					'out' => ftell( $out ),
				);
				CSM_Progress::update(
					array(
						'phase'     => 'Verifying snapshot frames',
						'component' => '',
						'count'     => $state['in'],
						'total'     => $bundle['size'],
						'unit'      => 'bytes',
					)
				);
				if ( CSM_Support::due() ) {
					break; }
			}
			fflush( $out );
			CSM_Support::atomic_json( $checkpoint, $state );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $in );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $out ); }
		return $state['in'] === $bundle['size'] && ! CSM_Support::due() && self::unpack( $path, $bundle, true );
	}
}
