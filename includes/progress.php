<?php
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/timing.php';

final class CSM_Progress {
	private static ?array $current       = null;
	private static float $written        = 0;
	private static float $cancel_checked = 0;

	private static function path( string $id ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
			throw new RuntimeException( 'Invalid transfer ID.' ); }
		return CSM_Support::private_dir() . '/' . $id . '-progress.json';
	}

	public static function run(): ?string {
		return self::$current['run'] ?? null; }

	public static function read( string $id ): array {
		$timing = hrtime( true );
		try {
			$path = self::path( $id );
			return is_file( $path ) ? ( json_decode( file_get_contents( $path ), true ) ?: array() ) : array();
		} finally {
			CSM_Timing::add( 'progress.read', $timing, 0 ); }
	}

	public static function start( string $id, string $phase, ?string $run = null ): void {
		CSM_Timing::begin( $id, 'worker' );
		CSM_Support::work( $id, null );
		$run ??= self::$current && self::$current['id'] === $id && self::$current['state'] === 'running' ? self::$current['run'] : bin2hex( random_bytes( 8 ) );
		if ( ! preg_match( '/^[a-f0-9]{16}$/D', $run ) ) {
			throw new RuntimeException( 'Invalid progress operation.' ); }
		$events        = self::read( $id )['events'] ?? array();
		self::$current = array(
			'id'        => $id,
			'run'       => $run,
			'started'   => microtime( true ),
			'state'     => 'running',
			'phase'     => '',
			'component' => '',
			'count'     => 0,
			'total'     => null,
			'unit'      => '',
			'bytes'     => 0,
			'events'    => $events,
		);
		self::update( array( 'phase' => $phase ), true );
		register_shutdown_function(
			static function () use ( $id ) {
				$error = error_get_last();
				if ( self::$current && self::$current['id'] === $id && self::$current['state'] === 'running' && $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					self::fail( 'The PHP process stopped unexpectedly. Check the server’s PHP log.' );
				}
			}
		);
	}

	public static function update( array $detail, bool $force = false ): void {
		$timing = hrtime( true );
		try {
			if ( ( $detail['state'] ?? '' ) !== 'failed' ) {
				// Work loops also check cancellation; progress-only loops stay responsive within 50 ms.
				$now          = hrtime( true ) / 1e9;
				$check_cancel = $force || $now - self::$cancel_checked >= .05;
				CSM_Support::check_source_budget( $check_cancel );
				if ( $check_cancel ) {
					self::$cancel_checked = $now; }
			}
			if ( ! self::$current ) {
				return; }
			$changed = false;
			foreach ( array( 'phase', 'component', 'state' ) as $key ) {
				if ( isset( $detail[ $key ] ) && $detail[ $key ] !== self::$current[ $key ] ) {
					$changed = true; }
			}
			self::$current = array_replace( self::$current, $detail, array( 'updated' => microtime( true ) ) );
			if ( $changed || ( ! self::$current['events'] && $force ) ) {
				$event = array_intersect_key( self::$current, array_flip( array( 'updated', 'phase', 'component', 'state', 'failure' ) ) );
				self::activity( $event );
			}
			if ( ! $force && microtime( true ) - self::$written < .25 ) {
				return; }
			// A separate atomic file stays readable while the transfer owns the mutation lock or swaps wp_options.
			$path = self::path( self::$current['id'] );
			$temp = $path . '.' . bin2hex( random_bytes( 4 ) );
			// Reporting must not interrupt an apply or prevent rollback when storage is full.
			$write_timing = hrtime( true );
			$json         = CSM_Support::json( self::$current );
			if ( @file_put_contents( $temp, $json, LOCK_EX ) === false ) {
				@unlink( $temp );
				return; }
			@chmod( $temp, 0600 );
			if ( ! @rename( $temp, $path ) ) {
				@unlink( $temp );
				return; }
			CSM_Timing::add( $force ? 'progress.write_forced' : 'progress.write', $write_timing, strlen( $json ) );
			self::$written = microtime( true );
		} finally {
			CSM_Timing::add( 'progress.update', $timing, 0 ); }
	}

	private static function important( array $event ): bool {
		return ! empty( $event['error'] ) || ! empty( $event['failure'] ) || in_array( $event['state'] ?? '', array( 'retry', 'failed', 'resumed' ), true );
	}

	private static function activity( array $event ): void {
		if ( ! self::important( $event ) ) {
			$component = $event['component'] ?? '';
			if ( ( $event['state'] ?? '' ) === 'complete' ) {
				$component = ''; } elseif ( preg_match( '#^(plugins|themes)/[^/]+#', $component, $match ) ) {
				$component = $match[0]; } elseif ( str_starts_with( $component, 'uploads/' ) ) {
					$component = preg_match( '#^uploads/[0-9]{4}(?:/|$)#', $component ) ? substr( $component, 0, 12 ) : 'uploads'; } elseif ( str_contains( strtolower( $event['phase'] ), 'media' ) && preg_match( '#^[0-9]{4}(?:/|$)#', $component ) ) {
					$component = 'uploads/' . substr( $component, 0, 4 ); }
					$event['component'] = $component;
					$event['phase']     = preg_replace( '/\bfile$/', 'files', $event['phase'] );
					foreach ( self::$current['events'] as $previous ) {
						if ( ! self::important( $previous ) && $previous['phase'] === $event['phase'] && ( $previous['component'] ?? '' ) === $component && ( $previous['state'] ?? '' ) === ( $event['state'] ?? '' ) ) {
							return; }
					}
		}
		$event['id']               = substr( hash( 'sha256', CSM_Support::json( $event ) ), 0, 20 );
		self::$current['events'][] = $event;
		$events                    = self::$current['events'];
		$routine                   = array_slice( array_filter( $events, static fn( $item ) => ! self::important( $item ) ), -64, null, true );
		$important                 = array_slice( array_filter( $events, array( self::class, 'important' ) ), -16, null, true );
		self::$current['events']   = array_values( array_intersect_key( $events, $routine + $important ) );
	}

	public static function fail( string $error ): void {
		if ( ! self::$current || isset( self::$current['failure'] ) ) {
			return; }
		CSM_Log::write(
			'error',
			'Transfer component failed',
			array(
				'transfer_id' => self::$current['id'],
				'phase'       => self::$current['phase'],
				'component'   => self::$current['component'],
				'run'         => self::$current['run'],
				'count'       => self::$current['count'],
				'total'       => self::$current['total'],
				'unit'        => self::$current['unit'],
				'error'       => $error,
			)
		);
		self::update(
			array(
				'state'   => 'failed',
				'failure' => self::$current['failure'] ?? array(
					'phase'     => self::$current['phase'],
					'component' => self::$current['component'],
					'error'     => $error,
				),
			),
			true
		);
	}

	public static function finish( string $phase ): void {
		if ( ! self::$current ) {
			return; }
		self::update(
			array(
				'state' => isset( self::$current['failure'] ) ? 'failed' : 'complete',
				'phase' => $phase,
			),
			true
		);
	}

	public static function remove( string $id ): void {
		$path = self::path( $id );
		if ( is_file( $path ) ) {
			unlink( $path ); }
	}
}
