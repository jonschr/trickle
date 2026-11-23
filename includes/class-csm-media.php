<?php
/**
 * Media selection, comparison and attachment discovery.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/** Media size/mtime metadata, compared in year → month → file order. */
final class CSM_Media {
	/**
	 * Resolve the private media inventory cache path.
	 */
	private static function path(): string {
		return CSM_Support::private_dir() . '/media-hashes-' . substr( hash( 'sha256', CSM_Files::root( 'uploads' ) ), 0, 16 ) . '.json';
	}

	/**
	 * Encode filesystem comparison metadata into a stable media stamp.
	 *
	 * @param array $stat Filesystem stat fields used to build a media comparison stamp.
	 */
	private static function stamp( array $stat ): string {
		return implode( ':', array_map( static fn( $key ) => $stat[ $key ], array( 'size', 'mtime', 'ctime', 'ino', 'dev', 'mode' ) ) );
	}

	/**
	 * Hash media inventory metadata without reading file contents.
	 *
	 * @param array $items Media metadata records whose inventory digest is calculated.
	 */
	private static function digest( array $items ): string {
		ksort( $items, SORT_STRING );
		return hash( 'sha256', CSM_Support::json( $items ) );
	}

	/**
	 * Build or reuse the media metadata inventory for the selected comparison policy.
	 *
	 * @param bool  $verify Compatibility flag; media comparisons use metadata, not content hashing.
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function build( bool $verify, array $selection = array() ): array {
		$groups = array();
		$stats  = array(
			'scanned_files'  => 0,
			'hashed_files'   => 0,
			'years_reused'   => 0,
			'years_rebuilt'  => 0,
			'months_reused'  => 0,
			'months_rebuilt' => 0,
		);
		CSM_Support::step();
		foreach ( CSM_Files::paths(
			array_intersect_key( $selection, array_flip( array( 'skip_paths', 'git_overrides', 'protect_source_git' ) ) ) + array(
				'plugins' => array(),
				'themes'  => array(),
				'media'   => 'all',
			)
		) as [$relative, $path] ) {
			$tail  = substr( $relative, 8 );
			$parts = explode( '/', $tail );
			$year  = count( $parts ) > 1 ? $parts[0] : '';
			$month = count( $parts ) > 2 ? $parts[1] : '';
			clearstatcache( true, $path );
			$stat = lstat( $path );
			if ( ! $stat || ! is_file( $path ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Cannot inspect media file: ' . $tail ); }
			$metadata                           = array(
				'size'  => $stat['size'],
				'mtime' => $stat['mtime'],
			);
			$groups[ $year ][ $month ][ $tail ] = array(
				'stamp' => self::stamp( $stat ),
				'hash'  => self::digest( $metadata ),
			) + $metadata;
			++$stats['scanned_files'];
			CSM_Progress::update(
				array(
					'phase'     => 'Comparing media metadata',
					'component' => $relative,
					'count'     => $stats['scanned_files'],
					'total'     => null,
					'unit'      => 'files',
				)
			);
		}
		ksort( $groups, SORT_STRING );
		$years = array();
		foreach ( $groups as $year => $months ) {
			ksort( $months, SORT_STRING );
			$result      = array();
			$year_hashes = array();
			foreach ( $months as $month => $files ) {
				ksort( $files, SORT_STRING );
				$hashes = array_column( $files, 'hash' );
				// Include names: equal sizes/timestamps at different paths do not match.
				$hashes                = array_combine( array_keys( $files ), $hashes );
				$hash                  = self::digest( $hashes );
				$result[ $month ]      = array(
					'hash'  => $hash,
					'files' => $files,
				);
				$year_hashes[ $month ] = $hash;
				++$stats['months_rebuilt'];
			}
			$years[ $year ] = array(
				'hash'   => self::digest( $year_hashes ),
				'months' => $result,
			);
			++$stats['years_rebuilt'];
		}
		$index = array(
			'comparison' => 'size_mtime',
			'years'      => $years,
			'token'      => self::digest( $years ),
		);
		CSM_Support::atomic_json( self::path(), $index );
		CSM_Support::atomic_json( CSM_Support::private_dir() . '/media-snapshot-' . $index['token'] . '.json', $index );
		return $index + array( 'stats' => $stats );
	}

	/**
	 * Load a media inventory pinned to its metadata digest.
	 *
	 * @param string $token Inventory snapshot digest pinning this comparison to stable metadata.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function snapshot( string $token ): array {
		$snapshot = preg_match( '/^[a-f0-9]{64}$/D', $token ) ? CSM_Support::private_dir() . '/media-snapshot-' . $token . '.json' : '';
		$path     = $snapshot && is_file( $snapshot ) ? $snapshot : self::path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
		$index = is_file( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) || ! is_array( $index ) || ! is_array( $index['years'] ?? null ) || ( $index['comparison'] ?? '' ) !== 'size_mtime' || ! hash_equals( $index['token'] ?? '', $token ) || ! hash_equals( $token, self::digest( $index['years'] ) ) ) {
			throw new RuntimeException( 'Media metadata inventory changed or expired. Retry the file stream to compare again.' ); }
		return $index;
	}

	/**
	 * Build or page the media metadata inventory requested by a peer.
	 *
	 * @param array $query Validated inventory request with level, token and paging fields.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function inventory( array $query ): array {
		$level = $query['level'] ?? '';
		if ( 'years' === $level ) {
			$id = $query['selection']['comparison_id'] ?? '';
			if ( ! is_string( $id ) || ( '' !== $id && ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) ) {
				throw new RuntimeException( 'Invalid media metadata work ID.' ); }
			$result_path = '' !== $id ? self::path() . '.comparison-' . $id . '.json' : '';
			if ( $result_path && is_file( $result_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
				$saved = json_decode( file_get_contents( $result_path ), true, 512, JSON_THROW_ON_ERROR );
				if ( ( $saved['comparison'] ?? '' ) === 'size_mtime' ) {
					return $saved; }
			}
			$index = self::build( ! empty( $query['verify'] ), $query['selection'] ?? array() );
			$items = array();
			foreach ( $index['years'] as $year => $item ) {
				$items[] = array(
					'name' => (string) $year,
					'hash' => $item['hash'],
				); }
			$result = array(
				'comparison' => 'size_mtime',
				'token'      => $index['token'],
				'items'      => $items,
				'stats'      => $index['stats'],
			);
			if ( $result_path ) {
				CSM_Support::atomic_json( $result_path, $result ); }
			return $result;
		}
		$index = self::snapshot( $query['token'] ?? '' );
		$years = $query['years'] ?? array();
		if ( ! is_array( $years ) || ! array_is_list( $years ) || count( $years ) > 2000 ) {
			throw new RuntimeException( 'Invalid media year selection.' ); }
		foreach ( $years as $year ) {
			if ( ! is_string( $year ) || ! isset( $index['years'][ $year ] ) ) {
				throw new RuntimeException( 'Unknown media year.' ); }
		}
		if ( 'months' === $level ) {
			$items = array();
			foreach ( $years as $year ) {
				foreach ( $index['years'][ $year ]['months'] as $month => $item ) {
					$items[] = array(
						'year'  => $year,
						'month' => (string) $month,
						'hash'  => $item['hash'],
						'files' => count( $item['files'] ),
					); }
			}
			return array( 'items' => $items );
		}
		if ( 'files' !== $level || ! is_string( $query['year'] ?? null ) || ! is_string( $query['month'] ?? null ) ) {
			throw new RuntimeException( 'Invalid media metadata query.' ); }
		$files  = $index['years'][ $query['year'] ]['months'][ $query['month'] ]['files'] ?? null;
		$offset = $query['offset'] ?? 0;
		if ( null === $files || ! is_int( $offset ) || $offset < 0 ) {
			throw new RuntimeException( 'Unknown media month or invalid page.' ); }
		$items = array();
		foreach ( array_slice( $files, $offset, 500, true ) as $path => $file ) {
			$items[] = array(
				'path' => (string) $path,
				'hash' => $file['hash'],
			); }
		return array(
			'items' => $items,
			'more'  => count( $files ) > $offset + 500,
		);
	}

	/**
	 * Expose the checked media inventory manifest for comparison.
	 *
	 * @param array $query Validated inventory request with level, token and paging fields.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function manifest( array $query ): array {
		if ( ! is_string( $query['year'] ?? null ) || ! is_string( $query['month'] ?? null ) ) {
			throw new RuntimeException( 'Invalid media month.' ); }
		$index = self::snapshot( $query['token'] ?? '' );
		$files = $index['years'][ $query['year'] ]['months'][ $query['month'] ]['files'] ?? null;
		if ( ! is_array( $files ) ) {
			throw new RuntimeException( 'Unknown media month.' ); }
		return array(
			'token' => $index['token'],
			'files' => array_map( static fn( $file )=>$file['hash'], $files ),
		);
	}

	/**
	 * Resolve matching source/destination media paths from size and timestamp metadata.
	 *
	 * @param array $peer Saved peer connection, including credentials for trusted internal transport use.
	 * @param bool  $pull Whether the remote is the source rather than the destination.
	 * @param bool  $verify Compatibility flag; media comparisons use metadata, not content hashing.
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 * @throws CSM_Yield If bounded work must resume from its saved checkpoint.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function compare( array $peer, bool $pull, bool $verify, array $selection = array() ): array {
		$id   = $selection['comparison_id'] ?? '';
		$path = preg_match( '/^[a-f0-9]{32}$/D', $id ) ? CSM_Support::private_dir() . '/media-compare-' . $id . '.json' : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
		$work = $path && is_file( $path ) ? json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR ) : null;
		if ( isset( $work['result'] ) ) {
			return $work['result']; }
		$remote      = static function ( $query ) use ( $peer, $selection ) {
			$result = CSM_Transport::request(
				$peer,
				'media_hashes',
				$query + array(
					'selection'    => $selection,
					'progress_run' => CSM_Progress::run(),
				),
				null,
				null,
				15
			);
			if ( ! empty( $result['continue'] ) ) {
				throw new CSM_Yield( 'Remote media metadata checkpointed.' );
			} return $result;
		};
		$local       = static fn( $query )=>self::inventory( $query + array( 'selection' => $selection ) );
		$source      = $pull ? $remote : $local;
		$destination = $pull ? $local : $remote;
		CSM_Support::step();
		if ( ! $work ) {
			$src = $source( array( 'level' => 'years' ) );
			$dst = $destination( array( 'level' => 'years' ) );
			if ( ( $src['comparison'] ?? '' ) !== 'size_mtime' || ( $dst['comparison'] ?? '' ) !== 'size_mtime' ) {
				throw new RuntimeException( 'Update Trickle on both sites before comparing media metadata.' ); }
			$stats     = array(
				'years_compared'      => 0,
				'years_skipped'       => 0,
				'months_compared'     => 0,
				'months_skipped'      => 0,
				'files_compared'      => 0,
				'files_selected'      => 0,
				'source_hashing'      => $src['stats'],
				'destination_hashing' => $dst['stats'],
			);
			$dst_years = array_column( $dst['items'], 'hash', 'name' );
			$different = array();
			foreach ( $src['items'] as $year ) {
				++$stats['years_compared'];
				if ( ( $dst_years[ $year['name'] ] ?? null ) === $year['hash'] ) {
					++$stats['years_skipped'];
				} else {
					$different[] = $year['name']; }
			}
			$src_months = $different ? $source(
				array(
					'level' => 'months',
					'token' => $src['token'],
					'years' => $different,
				)
			) : array( 'items' => array() );
			$present    = array_values( array_filter( $different, static fn( $year )=>isset( $dst_years[ $year ] ) ) );
			$dst_months = $present ? $destination(
				array(
					'level' => 'months',
					'token' => $dst['token'],
					'years' => $present,
				)
			) : array( 'items' => array() );
			$months     = array();
			foreach ( $dst_months['items'] as $month ) {
				$months[ $month['year'] . '/' . $month['month'] ] = $month['hash']; }
			$work = array(
				'src'         => $src['token'],
				'dst'         => $dst['token'],
				'months'      => $src_months['items'],
				'destination' => $months,
				'index'       => 0,
				'buckets'     => array(),
				'paths'       => array(),
				'stats'       => $stats,
			);
			if ( $path ) {
				CSM_Support::atomic_json( $path, $work ); }
			if ( $path && CSM_Support::due() && $work['months'] ) {
				throw new CSM_Yield( 'Media year comparison checkpointed.' ); }
		}
		$read = static function ( array $query, bool $remote ) use ( $peer ): array {
			if ( ! $remote ) {
				return self::manifest( $query )['files']; }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
			$fp = fopen( 'php://temp', 'w+b' );
			try {
				CSM_Transport::request( $peer, 'media_manifest', $query, null, $fp, 15, 32 * 1024 * 1024 );
				rewind( $fp );
				$raw  = gzdecode( stream_get_contents( $fp ), 32 * 1024 * 1024 );
				$data = false === $raw ? null : json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
				if ( ! is_array( $data ) || ( $data['token'] ?? '' ) !== $query['token'] || ! is_array( $data['files'] ?? null ) ) {
					throw new RuntimeException( 'Invalid media metadata manifest.' ); }
				return $data['files'];
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $fp ); }
		};
		CSM_Support::step();
		$month_count = count( $work['months'] );
		while ( $work['index'] < $month_count ) {
			CSM_Support::check_cancel();
			$month  = $work['months'][ $work['index'] ];
			$bucket = $month['year'] . '/' . $month['month'];
			$stats  =&$work['stats'];
			++$stats['months_compared'];
			if ( ( $work['destination'][ $bucket ] ?? null ) === $month['hash'] ) {
				++$stats['months_skipped']; } elseif ( ! isset( $work['destination'][ $bucket ] ) ) {
				$work['buckets'][]        = array( $month['year'], $month['month'] );
				$stats['files_selected'] += $month['files']; } else {
								$query     = array(
									'year'  => $month['year'],
									'month' => $month['month'],
								);
								$src_files = $read( $query + array( 'token' => $work['src'] ), $pull );
								$dst_files = $read( $query + array( 'token' => $work['dst'] ), ! $pull );
								foreach ( $src_files as $file => $hash ) {
									++$stats['files_compared'];
									if ( ( $dst_files[ $file ] ?? null ) !== $hash ) {
										$work['paths'][] = (string) $file;
										++$stats['files_selected']; }
								}
				}
				++$work['index'];
				CSM_Progress::update(
					array(
						'phase'     => 'Comparing media months',
						'component' => $bucket,
						'count'     => $work['index'],
						'total'     => count( $work['months'] ),
						'unit'      => 'months',
					)
				);
			if ( $path ) {
				CSM_Support::atomic_json( $path, $work ); }
			if ( CSM_Support::due() ) {
				if ( $path ) {
					throw new CSM_Yield( 'Media month comparison checkpointed.' ); }
				throw new RuntimeException( 'Media comparison exceeded its work budget. Narrow the selection.' );
			}
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Protocol encoding preserves binary bytes; decoded input is validated before use.
		$delta = base64_encode(
			gzencode(
				CSM_Support::json(
					array(
						'token'   => $work['src'],
						'buckets' => $work['buckets'],
						'paths'   => $work['paths'],
					)
				),
				3
			)
		);
		if ( strlen( $delta ) > 700000 ) {
			throw new RuntimeException( 'Media comparison is too large. Use Force retransfer or narrow the selection.' ); }
		$result = array(
			'delta' => $delta,
			'stats' => $work['stats'],
		);
		if ( $path ) {
			CSM_Support::atomic_json( $path, array( 'result' => $result ) );
		} return $result;
	}

	/**
	 * Decode selected media paths while enforcing selection and path safety.
	 *
	 * @param string $encoded Encoded approved relative media paths.
	 * @param array  $selection Approved database, component and media selection with transfer policies.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function paths( string $encoded, array $selection = array() ): Generator {
		if ( strlen( $encoded ) > 700000 ) {
			throw new RuntimeException( 'Invalid media comparison plan.' ); }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Protocol encoding preserves binary bytes; decoded input is validated before use.
		$compressed = base64_decode( $encoded, true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Malformed bounded gzip input is checked as false and rejected by the following validation.
		$raw  = false === $compressed ? false : @gzdecode( $compressed, 32 * 1024 * 1024 );
		$plan = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $plan ) || ! is_array( $plan['buckets'] ?? null ) || ! array_is_list( $plan['buckets'] ) || ! is_array( $plan['paths'] ?? null ) || ! array_is_list( $plan['paths'] ) ) {
			throw new RuntimeException( 'Invalid media comparison plan.' ); }
		$index    = self::snapshot( $plan['token'] ?? '' );
		$selected = array();
		foreach ( $plan['buckets'] as $bucket ) {
			if ( ! is_array( $bucket ) || count( $bucket ) !== 2 || ! is_string( $bucket[0] ?? null ) || ! is_string( $bucket[1] ?? null ) || ! isset( $index['years'][ $bucket[0] ]['months'][ $bucket[1] ] ) ) {
				throw new RuntimeException( 'Unknown media comparison folder.' ); }
			$selected += $index['years'][ $bucket[0] ]['months'][ $bucket[1] ]['files'];
		}
		foreach ( $plan['paths'] as $path ) {
			if ( ! is_string( $path ) ) {
				throw new RuntimeException( 'Invalid media comparison file.' ); }
			$parts = explode( '/', $path );
			$year  = count( $parts ) > 1 ? $parts[0] : '';
			$month = count( $parts ) > 2 ? $parts[1] : '';
			$file  = $index['years'][ $year ]['months'][ $month ]['files'][ $path ] ?? null;
			if ( ! $file ) {
				throw new RuntimeException( 'Unknown media comparison file.' ); }
			$selected[ $path ] = $file;
		}
		ksort( $selected, SORT_STRING );
		foreach ( $selected as $tail => $file ) {
			$relative = 'uploads/' . $tail;
			$path     = CSM_Files::destination( $relative, $selection + array( 'media' => 'compare' ), ! empty( $selection['protect_source_git'] ) );
			clearstatcache( true, $path );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing or replaced file is handled by the following metadata validation or fallback.
			$stat = @lstat( $path );
			if ( ! $stat || self::stamp( $stat ) !== $file['stamp'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Media changed after comparison: ' . $tail . '. Retry the file stream.' ); }
			yield array( $relative, $path );
		}
	}
}
