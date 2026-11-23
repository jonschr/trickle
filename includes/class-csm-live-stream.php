<?php
/**
 * Resumable framed file streaming and destination receipts.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/** Bounded replay windows, with at most one unpublished source look-ahead window. */
final class CSM_Live_Stream {
	public const WINDOW_BYTES = 32 * 1024 * 1024;
	/**
	 * Destination job record shared with the receiving request.
	 *
	 * @var array
	 */
	private array $job;
	/**
	 * Unconsumed incoming frame bytes.
	 *
	 * @var string
	 */
	private string $buffer = '';
	/**
	 * Whether this file stream has consumed its opening record.
	 *
	 * @var bool
	 */
	private bool $started = false;
	/**
	 * Most recent complete frame checkpoint eligible for persistence.
	 *
	 * @var array|null
	 */
	private ?array $checkpoint = null;
	/**
	 * Running frame-chain checksum.
	 *
	 * @var string
	 */
	private string $chain;
	/**
	 * Logical file bytes acknowledged by the stream.
	 *
	 * @var int
	 */
	private int $bytes;
	/**
	 * Current replay window sequence number.
	 *
	 * @var int
	 */
	private int $sequence;
	/**
	 * Current byte offset in the replay window.
	 *
	 * @var int
	 */
	private int $position = 0;
	/**
	 * Window offset at the start of this request.
	 *
	 * @var int
	 */
	private int $initial_position = 0;
	/**
	 * Bytes received during this request.
	 *
	 * @var int
	 */
	private int $received = 0;
	/**
	 * Digest binding the stream to its approved selection.
	 *
	 * @var string
	 */
	private string $selection;
	/**
	 * Negotiated frame-chain checksum format.
	 *
	 * @var int
	 */
	private int $chain_format = 1;
	/**
	 * Open staged-file handle and its validated receipt state.
	 *
	 * @var array
	 */
	private array $file_output = array();

	/**
	 * Close any retained staged-file handle without acknowledging unfinished work.
	 */
	public function __destruct() {
		// An abandoned request publishes no checkpoint; its trailing bytes are replayed on retry.
		if ( isset( $this->file_output['handle'] ) && is_resource( $this->file_output['handle'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $this->file_output['handle'] ); }
	}

	/**
	 * Bind a receiving writer to a staging job and its approved selection digest.
	 *
	 * @param array  &$job Destination job with its persisted staging and receipt state.
	 * @param string $selection SHA-256 binding the window to its approved selection.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public function __construct( array &$job, string $selection ) {
		CSM_Updates::assert_job( $job );
		if ( 'staging' !== $job['status'] || ! in_array( 'files', CSM_Plugin::kinds( $job['selection'] ), true ) ) {
			throw new RuntimeException( 'Transfer is not awaiting files.' ); }
		$this->job      =&$job;
		$this->sequence = $job['live_files']['seq'] ?? 0;
		$partial        = $job['live_partial'] ?? array();
		$format         = $partial['chain_format'] ?? 1;
		if ( ! in_array( $format, array( 1, 2 ), true ) ) {
			throw new RuntimeException( 'Invalid stream checksum format.' ); }
		$this->chain_format     = $format;
		$this->chain            = $partial['chain'] ?? $job['live_files']['chain'] ?? '';
		$this->bytes            = $partial['bytes'] ?? $job['live_files']['bytes'] ?? 0;
		$this->initial_position = $partial['offset'] ?? 0;
		$this->position         = $this->initial_position;
		$this->started          = $this->position > 0;
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $selection ) ) {
			throw new RuntimeException( 'Invalid stream selection digest.' ); }
		$this->selection = $selection;
		if ( ( isset( $partial['selection'] ) && $partial['selection'] !== $this->selection ) || ( isset( $job['live_files']['selection'] ) && $job['live_files']['selection'] !== $this->selection ) ) {
			throw new RuntimeException( 'File selection changed during streaming.' ); }
	}

	/**
	 * Resolve the private source stream path for a validated transfer identifier.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 *
	 * @return string Absolute private stream basename without replay/checkpoint suffixes.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	private static function base( string $id ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
			throw new RuntimeException( 'Invalid file stream ID.' ); }
		return CSM_Support::private_dir() . '/segment-' . $id . '-files.source';
	}

	/**
	 * Prepare the next replay window without acknowledging the current one.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 * @param array  $selection Approved source component selection.
	 * @param int    $seq Requested zero-based replay window sequence.
	 *
	 * @return Generator Generator yielding framed bytes for speculative local production only.
	 */
	public static function lookahead( string $id, array $selection, int $seq ): Generator {
		$dir  = self::base( $id ) . '.live';
		$path = $dir . '/next.json';
		if ( ! is_file( $path ) ) {
			return; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
		$state = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		CSM_Access::assert_owner( $state );
		if ( $seq + 1 !== $state['seq'] || ! empty( $state['cursor']['done'] ) || hash( 'sha256', CSM_Support::json( $selection ) ) !== $state['selection'] ) {
			return; }
		$ahead = $dir . '/ahead';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
		if ( is_file( $ahead . '/state.json' ) && json_decode( file_get_contents( $ahead . '/state.json' ), true, 512, JSON_THROW_ON_ERROR ) !== $state ) {
			CSM_Support::remove_tree( $ahead ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private staging with explicit permissions; no FTP backend may replace it.
		if ( ! is_dir( $ahead ) && ! mkdir( $ahead, 0700 ) ) {
			return; }
		CSM_Support::atomic_json( $ahead . '/state.json', $state );
		yield from self::source( $id, $selection, $seq + 1, 0, true );
	}

	/**
	 * Produce or replay one file window from its saved sequence and byte offset.
	 *
	 * The source acknowledges the prior window only when the receiver requests the
	 * following sequence. Speculative preparation never removes the current replay.
	 *
	 * @param string $id Transfer identifier, a 32-character hexadecimal string.
	 * @param array  $selection Approved source component selection.
	 * @param int    $seq Requested zero-based replay window sequence.
	 * @param int    $offset Byte offset within the requested replay window.
	 * @param bool   $lookahead Whether to use isolated speculative storage for the next window.
	 *
	 * @return Generator Generator yielding framed control and file bytes for the requested window.
	 *
	 * @throws CSM_Busy_Error If another request owns the source stream lock.
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function source( string $id, array $selection, int $seq, int $offset = 0, bool $lookahead = false ): Generator {
		if ( $seq < 0 || $offset < 0 ) {
			throw new RuntimeException( 'Invalid file stream sequence.' ); }
		$base   = self::base( $id );
		$dir    = $base . '.live' . ( $lookahead ? '/ahead' : '' );
		$digest = hash( 'sha256', CSM_Support::json( $selection ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$lock = fopen( $base . '.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( $lock ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $lock );
			} throw new CSM_Busy_Error( 'Source stream is busy.' ); }
		try {
			foreach ( array( $base . '.owner', substr( $base, 0, -7 ) . '.comparison.owner' ) as $owner ) {
				if ( is_file( $owner ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
					$identity = json_decode( file_get_contents( $owner ), true, 512, JSON_THROW_ON_ERROR );
					CSM_Access::assert_owner( $identity );
					if ( isset( $identity['selection'] ) && $identity['selection'] !== $digest ) {
						throw new RuntimeException( 'This stream is pinned to another selection.' ); }
				}
			}
			if ( is_file( $base . '.json' ) || is_file( $base . '.build' ) ) {
				throw new RuntimeException( 'This transfer already uses a snapshot.' ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private staging with explicit permissions; no FTP backend may replace it.
			if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700 ) ) {
				throw new RuntimeException( 'Cannot create stream checkpoint storage.' ); }
			$state_path = $dir . '/state.json';
			$next_path  = $dir . '/next.json';
			$window     = $dir . '/window';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
			$state = is_file( $state_path ) ? json_decode( file_get_contents( $state_path ), true, 512, JSON_THROW_ON_ERROR ) : null;
			if ( null === $state ) {
				if ( 0 !== $seq ) {
					throw new RuntimeException( 'Source stream checkpoint is missing.' ); }
				$normalized = CSM_Files::selection( $selection );
				$state      = array(
					'credential'   => CSM_Access::owner(),
					'selection'    => $digest,
					'export'       => $normalized,
					'seq'          => 0,
					'chain'        => '',
					'bytes'        => 0,
					'cursor'       => array( 'started' => time() ),
					'components'   => array(
						'database' => 0,
						'plugins'  => 0,
						'themes'   => 0,
						'media'    => 0,
					),
					'chain_format' => 2,
				);
				CSM_Support::atomic_json( $state_path, $state );
			}
			CSM_Access::assert_owner( $state );
			if ( $state['selection'] !== $digest ) {
				throw new RuntimeException( 'This stream is pinned to another selection.' ); }
			CSM_Support::atomic_json(
				$base . '.owner',
				array(
					'credential' => $state['credential'],
					'selection'  => $digest,
					'touched'    => time(),
				)
			);
			if ( $seq === $state['seq'] + 1 && is_file( $next_path ) && is_file( $window ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
				$next = json_decode( file_get_contents( $next_path ), true, 512, JSON_THROW_ON_ERROR );
				if ( $next['seq'] !== $seq || $next['selection'] !== $digest ) {
					throw new RuntimeException( 'Invalid source acknowledgement.' ); }
				CSM_Support::atomic_json( $state_path, $next );
				$state = $next;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the validated local file during cleanup or recoverable publication.
				unlink( $next_path );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the validated local file during cleanup or recoverable publication.
				unlink( $window );
			}
			if ( is_file( $next_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
				$next = json_decode( file_get_contents( $next_path ), true, 512, JSON_THROW_ON_ERROR );
				if ( $next['seq'] === $state['seq'] ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the validated local file during cleanup or recoverable publication.
					unlink( $next_path );
					if ( is_file( $window ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the validated local file during cleanup or recoverable publication.
						unlink( $window ); }
				}
			}
			if ( $seq !== $state['seq'] || ! empty( $state['cursor']['done'] ) ) {
				throw new RuntimeException( 'Source stream sequence changed. Refresh the destination checkpoint.' ); }
			$ahead = $dir . '/ahead';
			if ( ! $lookahead && ! is_file( $next_path ) && is_file( $ahead . '/window' ) && is_file( $ahead . '/next.json' ) && is_file( $ahead . '/state.json' )
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
				&& json_decode( file_get_contents( $ahead . '/state.json' ), true, 512, JSON_THROW_ON_ERROR ) === $state ) {
				// Publish the checkpoint last; a crash between these moves safely regenerates this window.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename is the atomic publication/recovery boundary.
				if ( ! rename( $ahead . '/window', $window ) || ! rename( $ahead . '/next.json', $next_path ) ) {
					throw new RuntimeException( 'Cannot publish prepared stream window.' ); }
				CSM_Timing::add( 'stream.lookahead_reuse', hrtime( true ), filesize( $window ) );
				CSM_Support::remove_tree( $ahead );
			}
			$format = $state['chain_format'] ?? 1;
			if ( ! in_array( $format, array( 1, 2 ), true ) ) {
				throw new RuntimeException( 'Invalid source checksum format.' ); }
			if ( is_file( $window ) && is_file( $next_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
				$fp = fopen( $window, 'rb' );
				if ( ! $fp || $offset > filesize( $window ) || fseek( $fp, $offset ) !== 0 ) {
					if ( $fp ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
						fclose( $fp );
					} throw new RuntimeException( 'Invalid replay offset.' ); }
				try {
					while ( ! feof( $fp ) ) {
						CSM_Support::check_cancel();
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
						$part = fread( $fp, 262144 );
						if ( false === $part ) {
							throw new RuntimeException( 'Cannot replay stream window.' );
						} if ( '' !== $part ) {
							yield $part; }
					}
				} finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
					fclose( $fp ); }
				return;
			}
			// An interrupted producer never publishes its partial cursor or partial replay file.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an obsolete or failed temporary artifact must not mask the primary result. Delete the validated local file during cleanup or recoverable publication.
			@unlink( $next_path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
			$fp = fopen( $window . '.part', 'wb' );
			if ( ! $fp ) {
				throw new RuntimeException( 'Cannot retain stream replay window.' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
			} chmod( $window . '.part', 0600 );
			ignore_user_abort( true );
			CSM_Support::begin_source_budget();
			CSM_Support::step();
			try {
				$header = CSM_Package::frame(
					CSM_Support::json(
						array(
							'type'      => 'window',
							'id'        => $id,
							'seq'       => $seq,
							'selection' => $digest,
							'previous'  => $state['chain'],
						) + ( 2 === $format ? array( 'chain_format' => 2 ) : array() )
					),
					true
				);
				CSM_Support::write( $fp, $header );
				if ( 0 === $offset ) {
					yield $header;
				} elseif ( $offset < strlen( $header ) ) {
					throw new RuntimeException( 'Invalid replay offset.' ); }
				$raw  = 0;
				$wire = 0;
				foreach ( CSM_Files::stream_step( $state['export'], $state['cursor'], $state['components'] ) as $unit ) {
					$frame = CSM_Package::frame( $unit );
					// The header binds length, control bit and the payload's SHA-256; do not hash its bytes twice.
					$chain_started  = hrtime( true );
					$state['chain'] = CSM_Package::chain( $state['chain'], 2 === $format ? substr( $frame, 0, 36 ) : CSM_Package::digest( $frame ) );
					CSM_Timing::add( 'stream.frame_chain', $chain_started, 2 === $format ? 36 : strlen( $frame ) );
					$state['bytes'] += strlen( $frame );
					$raw            += strlen( $unit );
					$wire           += strlen( $frame );
					$start           = ftell( $fp );
					CSM_Support::write( $fp, $frame );
					if ( $start < $offset && ftell( $fp ) > $offset ) {
						throw new RuntimeException( 'Invalid replay offset.' ); }
					if ( $start >= $offset ) {
						yield $frame; }
					if ( ftell( $fp ) > $offset && ( $wire >= self::WINDOW_BYTES || $raw >= self::WINDOW_BYTES || CSM_Support::due() ) ) {
						break; }
				}
				++$state['seq'];
				$end = CSM_Package::frame(
					CSM_Support::json(
						array(
							'type'       => 'checkpoint',
							'seq'        => $seq,
							'chain'      => $state['chain'],
							'bytes'      => $state['bytes'],
							'done'       => ! empty( $state['cursor']['done'] ),
							'components' => $state['components'],
						)
					),
					true
				);
				CSM_Support::write( $fp, $end );
				fflush( $fp );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename is the atomic publication/recovery boundary.
				if ( ! rename( $window . '.part', $window ) ) {
					throw new RuntimeException( 'Cannot publish stream replay window.' ); }
				CSM_Support::atomic_json( $next_path, $state );
				// Never let the receiver acknowledge bytes before the replay and cursor are durable.
				yield $end;
			} catch ( Throwable $error ) {
				CSM_Support::end_source_budget();
				CSM_Log::write(
					'error',
					'Live source stream failed',
					array(
						'transfer_id' => $id,
						'error'       => $error->getMessage(),
					)
				);
				yield CSM_Package::frame(
					CSM_Support::json(
						array(
							'type'  => 'error',
							'error' => CSM_Log::clean( $error->getMessage() ),
						)
					),
					true
				);
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $fp );
				CSM_Support::end_source_budget(); }
		} finally {
			flock( $lock, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $lock ); }
	}

	/**
	 * Accept split HTTP chunks and stage only complete, verified file frames.
	 *
	 * @param string $data Exact incoming HTTP chunk bytes; framing may split across calls.
	 *
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public function write( string $data ): void {
		if ( null !== $this->checkpoint && '' !== $data ) {
			throw new RuntimeException( 'Unexpected bytes after stream checkpoint.' ); }
		$this->received += strlen( $data );
		if ( $this->received > 40 * 1024 * 1024 ) {
			throw new RuntimeException( 'Stream window exceeds its size limit.' ); }
		$this->buffer .= $data;
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The buffer changes on each step; its current length controls exact frame boundaries.
		while ( strlen( $this->buffer ) >= 36 ) {
			CSM_Support::check_cancel();
			$field   = unpack( 'N', substr( $this->buffer, 0, 4 ) )[1];
			$control = (bool) ( $field & 0x80000000 );
			$length  = $field & 0x7fffffff;
			if ( $length < 1 || $length > 300000 ) {
				throw new RuntimeException( 'Invalid stream frame length.' ); }
			if ( strlen( $this->buffer ) < 36 + $length ) {
				break; }
			$frame        = substr( $this->buffer, 0, 36 + $length );
			$gzip         = substr( $frame, 36 );
			$this->buffer = substr( $this->buffer, strlen( $frame ) );
			if ( ! hash_equals( substr( $frame, 4, 32 ), CSM_Package::digest( $gzip, true ) ) ) {
				throw new RuntimeException( 'Stream frame checksum failed.' ); }
			$unit = gzdecode( $gzip, 262144 );
			if ( false === $unit || '' === $unit ) {
				throw new RuntimeException( 'Invalid compressed stream frame.' ); }
			if ( ! $this->started ) {
				$header = json_decode( $unit, true, 32, JSON_THROW_ON_ERROR );
				if ( ! $control || ( $header['type'] ?? '' ) !== 'window' || ( $header['id'] ?? '' ) !== $this->job['id'] || ( $header['seq'] ?? null ) !== $this->sequence || ( $header['selection'] ?? '' ) !== $this->selection || ( $header['previous'] ?? '' ) !== $this->chain ) {
					throw new RuntimeException( 'Stream window does not match the destination checkpoint.' ); }
				$format = $header['chain_format'] ?? 1;
				if ( ! in_array( $format, array( 1, 2 ), true ) ) {
					throw new RuntimeException( 'Unsupported stream checksum format.' ); }
				$this->chain_format = $format;
				$this->started      = true;
				$this->position    += strlen( $frame );
				continue;
			}
			if ( $control ) {
				$record = json_decode( $unit, true, 32, JSON_THROW_ON_ERROR );
				if ( ( $record['type'] ?? '' ) === 'error' ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( 'Source stream failed: ' . ( $record['error'] ?? 'Unknown source error.' ) ); }
				if ( ( $record['type'] ?? '' ) !== 'checkpoint' ) {
					throw new RuntimeException( 'Unexpected stream control record.' ); }
				if ( ( $record['seq'] ?? null ) !== $this->sequence || ( $record['chain'] ?? '' ) !== $this->chain || ( $record['bytes'] ?? null ) !== $this->bytes || ! is_bool( $record['done'] ?? null ) || ! empty( $this->job['import_done']['files'] ) !== $record['done'] || ! is_array( $record['components'] ?? null ) ) {
					throw new RuntimeException( 'Stream checkpoint or completion manifest failed verification.' ); }
				if ( '' !== $this->buffer ) {
					throw new RuntimeException( 'Unexpected bytes after stream checkpoint.' ); }
				$this->checkpoint = $record;
				return;
			}
			if ( ! empty( $this->job['import_done']['files'] ) ) {
				throw new RuntimeException( 'Unexpected data after file manifest.' ); }
			$chain_started = hrtime( true );
			$this->chain   = CSM_Package::chain( $this->chain, 2 === $this->chain_format ? substr( $frame, 0, 36 ) : CSM_Package::digest( $frame ) );
			$this->bytes  += strlen( $frame );
			CSM_Timing::add( 'stream.frame_chain', $chain_started, 2 === $this->chain_format ? 36 : strlen( $frame ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
			$fp = fopen( 'php://temp', 'w+b' );
			try {
				CSM_Support::write( $fp, $unit );
				rewind( $fp );
				if ( isset( $this->job['imports']['files'] ) ) {
					$this->job['imports']['files']['offset'] = 0; }
				if ( CSM_Files::stage_step( $fp, $this->job, true, true, $this->file_output ) ) {
					$this->job['import_done']['files'] = true; }
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $fp ); }
			$this->position += strlen( $frame );
			CSM_Progress::update( array( 'bytes' => $this->bytes ) );
		}
	}

	/**
	 * Persist the next sequence only after a complete window checkpoint verifies.
	 *
	 * @throws CSM_Network_Error If receipt is interrupted or the peer requires a retained retry.
	 */
	public function finish(): void {
		CSM_Files::close_stage_output( $this->file_output );
		if ( ! $this->started || null === $this->checkpoint || '' !== $this->buffer ) {
			throw new CSM_Network_Error( 'Incomplete stream window. Resume its saved checkpoint.' ); }
		$this->job['live_files'] = array(
			'seq'        => $this->sequence + 1,
			'chain'      => $this->chain,
			'bytes'      => $this->bytes,
			'selection'  => $this->selection,
			'done'       => $this->checkpoint['done'],
			'components' => $this->checkpoint['components'],
		);
		unset( $this->job['live_partial'] );
		CSM_Jobs::save( $this->job );
	}

	/**
	 * Flush staged file output and persist the last complete frame boundary.
	 *
	 * Only completed verified frames advance the checkpoint. Trailing unverified
	 * bytes remain unacknowledged and are replayed by the next request.
	 *
	 * @return bool True if a new partial checkpoint was saved; false if no safe progress was available.
	 */
	public function save_partial(): bool {
		CSM_Files::close_stage_output( $this->file_output );
		if ( ! $this->started || null !== $this->checkpoint || $this->position <= $this->initial_position ) {
			return false; }
		$this->job['live_partial'] = array(
			'offset'       => $this->position,
			'chain'        => $this->chain,
			'bytes'        => $this->bytes,
			'selection'    => $this->selection,
			'chain_format' => $this->chain_format,
		);
		CSM_Jobs::save( $this->job );
		return true;
	}

	/**
	 * Return the owned destination window sequence and any safe partial offset.
	 *
	 * @param array $job Destination job with its persisted staging and receipt state.
	 *
	 * @return array Sequence, checksum chain, byte counts, completion state and optional partial offset.
	 */
	public static function state( array $job ): array {
		CSM_Updates::assert_job( $job );
		CSM_Access::assert_owner( $job );
		return array_replace(
			$job['live_files'] ?? array(
				'seq'   => 0,
				'chain' => '',
				'bytes' => 0,
				'done'  => false,
			),
			array_intersect_key( $job['live_partial'] ?? array(), array_flip( array( 'offset', 'bytes' ) ) )
		);
	}

	/**
	 * Receive one source file window, retaining safe progress after interruptions.
	 *
	 * @param array $peer Peer URL, scoped connection key and optional Basic Auth configuration.
	 * @param array $job Destination job with its persisted staging and receipt state.
	 * @param array $selection Approved source component selection.
	 *
	 * @return array Destination summary with its current file-stream checkpoint.
	 *
	 * @throws CSM_Network_Error If receipt is interrupted or the peer requires a retained retry.
	 */
	public static function pull( array $peer, array $job, array $selection ): array {
		if ( empty( $job['live_files']['done'] ) ) {
			$writer = new self( $job, hash( 'sha256', CSM_Support::json( $selection ) ) );
			try {
				CSM_Transport::request(
					$peer,
					'live_files',
					array(
						'id'           => $job['id'],
						'seq'          => $job['live_files']['seq'] ?? 0,
						'offset'       => $job['live_partial']['offset'] ?? 0,
						'selection'    => $selection,
						'progress_run' => CSM_Progress::run(),
					),
					null,
					array( $writer, 'write' ),
					30,
					40 * 1024 * 1024
				);
				$writer->finish();
			} catch ( CSM_Network_Error $error ) {
				if ( ! $writer->save_partial() ) {
					throw $error; }
			}
		}
		return self::summary( $job );
	}

	/**
	 * Receive an uploaded file window at the expected sequence and offset.
	 *
	 * @param array    $job Destination job with its persisted staging and receipt state.
	 * @param string   $selection SHA-256 binding the window to its approved selection.
	 * @param int      $seq Requested zero-based replay window sequence.
	 * @param resource $input Readable incoming HTTP body stream; ownership remains with the caller.
	 * @param int      $offset Byte offset within the requested replay window.
	 *
	 * @return array Destination summary with a complete or safely retained partial checkpoint.
	 *
	 * @throws CSM_Network_Error If receipt is interrupted or the peer requires a retained retry.
	 * @throws RuntimeException If stream metadata, bytes or protected storage fail validation.
	 */
	public static function receive( array $job, string $selection, int $seq, $input, int $offset = 0 ): array {
		if ( ( $job['live_files']['seq'] ?? 0 ) !== $seq || ( $job['live_partial']['offset'] ?? 0 ) !== $offset ) {
			throw new CSM_Network_Error( 'Destination stream checkpoint changed. Refresh and resume.' ); }
		if ( ! empty( $job['live_files']['done'] ) ) {
			if ( ( $job['live_files']['selection'] ?? '' ) !== $selection ) {
				throw new RuntimeException( 'File selection changed during streaming.' ); }
			return self::summary( $job );
		}
		$writer = new self( $job, $selection );
		try {
			while ( ! feof( $input ) ) {
				CSM_Support::check_cancel();
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
				$data = fread( $input, 262144 );
				if ( false === $data || ( '' === $data && ! feof( $input ) ) ) {
					throw new CSM_Network_Error( 'Incoming stream was interrupted.' );
				} $writer->write( $data ); }
			$writer->finish();
		} catch ( CSM_Network_Error $error ) {
			if ( ! $writer->save_partial() ) {
				throw $error; }
		}
		return self::summary( $job );
	}

	/**
	 * Advance completed file staging and expose its resumable receipt state.
	 *
	 * @param array $job Destination job with its persisted staging and receipt state.
	 *
	 * @return array Job summary with continue, checkpoint and live_files fields.
	 */
	public static function summary( array $job ): array {
		CSM_Support::step();
		if ( ! empty( $job['live_files']['done'] ) ) {
			$job = CSM_Plugin::receive_step( $job, 'files', null, true ); }
		return CSM_Plugin::summary( $job ) + array(
			'continue'   => empty( $job['received']['files'] ),
			'checkpoint' => array(
				'offset' => $job['live_partial']['bytes'] ?? $job['live_files']['bytes'] ?? 0,
				'size'   => ! empty( $job['live_files']['done'] ) ? $job['live_files']['bytes'] : null,
			),
			'live_files' => self::state( $job ),
		);
	}
}
