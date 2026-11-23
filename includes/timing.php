<?php
defined( 'ABSPATH' ) || exit;

/** In-memory counters; one diagnostic sample per request, never per file. */
final class CSM_Timing {
	private static ?array $request = null;
	private static array $metrics  = array();
	private static bool $shutdown  = false;

	public static function begin( string $id, string $operation ): void {
		if ( $id !== '' && ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
			return; }
		if ( self::$request !== null ) {
			if ( self::$request['id'] === '' && $id !== '' ) {
				self::$request['id'] = $id; }
			return;
		}
		self::$request = array(
			'id'        => $id,
			'operation' => $operation,
			'started'   => microtime( true ),
			'clock'     => hrtime( true ),
			'cpu'       => self::cpu(),
		);
		self::$metrics = array();
		if ( ! self::$shutdown ) {
			register_shutdown_function( array( self::class, 'flush' ) );
			self::$shutdown = true; }
	}

	private static function cpu(): ?float {
		if ( ! function_exists( 'getrusage' ) ) {
			return null; }
		$usage = getrusage();
		return is_array( $usage ) ? 1000 * ( ( $usage['ru_utime.tv_sec'] ?? 0 ) + ( $usage['ru_stime.tv_sec'] ?? 0 ) ) + ( ( $usage['ru_utime.tv_usec'] ?? 0 ) + ( $usage['ru_stime.tv_usec'] ?? 0 ) ) / 1000 : null;
	}

	public static function add( string $name, int $started, int $bytes = 0 ): void {
		if ( self::$request === null ) {
			return; }
		$metric   =&self::$metrics[ $name ];
		$metric ??= array(
			'calls' => 0,
			'ms'    => 0.0,
			'bytes' => 0,
		);
		++$metric['calls'];
		$metric['ms']    += ( hrtime( true ) - $started ) / 1e6;
		$metric['bytes'] += $bytes;
	}

	public static function measure( string $name, callable $work ) {
		$started = hrtime( true );
		try {
			return $work();
		} finally {
			self::add( $name, $started ); }
	}

	public static function flush(): void {
		if ( self::$request === null ) {
			return; }
		$request       = self::$request;
		$metrics       = self::$metrics;
		self::$request = null;
		self::$metrics = array();
		if ( $request['id'] === '' ) {
			return; }
		foreach ( $metrics as &$metric ) {
			$metric['ms'] = round( $metric['ms'], 3 );
		} unset( $metric );
		$cpu    = self::cpu();
		$sample = array(
			'started'        => $request['started'],
			'elapsed_ms'     => round( ( hrtime( true ) - $request['clock'] ) / 1e6, 3 ),
			'process_cpu_ms' => $cpu !== null && $request['cpu'] !== null ? round( max( 0, $cpu - $request['cpu'] ), 3 ) : null,
			'bootstrap_ms'   => isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && PHP_SAPI !== 'cli' ? round( max( 0, 1000 * ( $request['started'] - $_SERVER['REQUEST_TIME_FLOAT'] ) ), 3 ) : null,
			'metrics'        => $metrics,
		);
		// Existing bounded, private, non-blocking diagnostics own storage and redaction.
		CSM_Log::write(
			'info',
			'Performance sample',
			array(
				'transfer_id' => $request['id'],
				'operation'   => $request['operation'],
				'runtime'     => PHP_SAPI,
				'performance' => $sample,
			)
		);
	}

	public static function numeric( array $values ): array {
		$result = array();
		foreach ( $values as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_.]{0,63}$/D', $key ) ) {
				continue; }
			if ( is_array( $value ) ) {
				$result[ $key ] = self::numeric( $value ); } elseif ( $value === null || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
				$result[ $key ] = $value; }
		}
		return $result;
	}

	public static function report( array $entries ): array {
		$operations = array();
		$metrics    = array();
		$samples    = 0;
		foreach ( $entries as $entry ) {
			$sample = $entry['context']['performance'] ?? null;
			if ( ( $entry['event'] ?? '' ) !== 'Performance sample' || ! is_array( $sample ) ) {
				continue; }
			++$samples;
			$name = $entry['context']['operation'];
			$op   =&$operations[ $name ];
			$op ??= array(
				'requests'            => 0,
				'elapsed_ms'          => 0.0,
				'process_cpu_ms'      => 0.0,
				'process_cpu_samples' => 0,
				'bootstrap_ms'        => 0.0,
				'bootstrap_samples'   => 0,
			);
			++$op['requests'];
			$op['elapsed_ms']          += $sample['elapsed_ms'];
			$sample['process_cpu_ms'] ??= $sample['cpu_ms'] ?? null;
			foreach ( array( 'process_cpu', 'bootstrap' ) as $kind ) {
				if ( isset( $sample[ $kind . '_ms' ] ) ) {
					$op[ $kind . '_ms' ] += $sample[ $kind . '_ms' ];
					++$op[ $kind . '_samples' ]; }
			}
			foreach ( $sample['metrics'] as $key => $value ) {
				$metric   =&$metrics[ $key ];
				$metric ??= array(
					'calls' => 0,
					'ms'    => 0.0,
					'bytes' => 0,
				);
				foreach ( array( 'calls', 'ms', 'bytes' ) as $field ) {
					$metric[ $field ] += $value[ $field ]; }
			}
		}
		return array(
			'samples'    => $samples,
			'operations' => $operations,
			'metrics'    => $metrics,
			'scope'      => 'Retained samples from this site only. Timings are inclusive and overlap; do not add nested timers or concurrent requests. HTTP time includes remote production, network waits and local stream callbacks. CPU is a process-wide delta after handler entry; on threaded runtimes it can include other concurrent requests and must not be treated or summed as per-request CPU. Earlier cpu_ms samples have the same process scope. Bootstrap is elapsed time before Trickle starts measuring.',
		);
	}
}
