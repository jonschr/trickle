<?php
/**
 * Redacted migration diagnostics and bounded log storage.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/timing.php';

/**
 * Redacted migration diagnostics and bounded log storage.
 */
final class CSM_Log {
	/**
	 * Known credential bytes to redact before persisting diagnostics.
	 *
	 * @var array
	 */
	private static array $secrets = array();

	/**
	 * Register credential bytes that must be redacted from diagnostics.
	 *
	 * @param string $value Credential bytes to redact from subsequent diagnostic output.
	 */
	public static function secret( string $value ): void {
		if ( '' !== $value ) {
			self::$secrets[] = $value; }
	}

	/**
	 * Redact credentials, SQL details and response bodies from diagnostic text.
	 *
	 * @param string $text Diagnostic text that may contain sensitive values.
	 *
	 * @return string Bounded diagnostic text with sensitive values removed.
	 */
	public static function clean( string $text ): string {
		foreach ( array_merge( self::$secrets, array( (string) get_option( '_csm_key', '' ) ) ) as $secret ) {
			if ( '' !== $secret ) {
				$text = str_replace( $secret, '[redacted]', $text ); }
		}
		// Error bodies and SQL values can contain credentials or customer data.
		$text = preg_replace( '/\s*Response:.*$/s', ' [response body omitted]', $text );
		$text = preg_replace( '/Database operation failed:.*$/s', 'Database operation failed; database details omitted.', $text );
		$text = preg_replace( '/\b(Duplicate entry|Data too long for column|Incorrect .+? value|Truncated incorrect .+? value).*$/is', 'Database value error; details omitted.', $text );
		$text = preg_replace( '/\b[a-f0-9]{64}\b/i', '[redacted]', $text );
		$text = preg_replace( '/\b(Bearer\s+)\S+/i', '$1[redacted]', $text );
		$text = preg_replace( '/\b(authorization|cookie|set-cookie|x-csm-[a-z-]+)\s*:[^\r\n]*/i', '$1: [redacted]', $text );
		$text = preg_replace( '/\b(password|secret|token|api[_ -]?key|signature|nonce|authorization|cookie)\b["\']?\s*[:=]\s*("[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '$1=[redacted]', $text );
		$text = preg_replace_callback(
			'#https?://[^\s<>]+#i',
			static function ( $match ) {
				$parts = wp_parse_url( $match[0] );
				return $parts && ! empty( $parts['host'] ) ? ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( ! empty( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '' ) : '[URL omitted]';
			},
			$text
		);
		return wp_check_invalid_utf8( substr( wp_strip_all_tags( $text ), 0, 1200 ), true );
	}

	/**
	 * Append a bounded, redacted event without interrupting migration recovery.
	 *
	 * @param string $level Diagnostic severity label.
	 * @param string $event Event description to redact before writing.
	 * @param array  $context Diagnostic fields restricted to the supported allowlist.
	 */
	public static function write( string $level, string $event, array $context = array() ): void {
		$timing = hrtime( true );
		try {
			$allowed     = array( 'plugins', 'themes', 'media_mode', 'file_force', 'plugins_force', 'themes_force', 'media_force', 'transport', 'operation', 'stream_kind', 'offset', 'length', 'snapshot_size', 'timeout_seconds', 'site', 'transfer_id', 'direction', 'phase', 'component', 'http_status', 'curl_errno', 'ssl_verify_result', 'duration_ms', 'retry_after', 'ca_source', 'error', 'tables', 'files', 'bytes', 'run', 'request_run', 'count', 'total', 'unit', 'progress_age_seconds', 'progress_matches_request', 'runtime' );
			$performance = isset( $context['performance'] ) && is_array( $context['performance'] ) ? CSM_Timing::numeric( $context['performance'] ) : null;
			$context     = array_intersect_key( $context, array_flip( $allowed ) );
			foreach ( $context as $key => $value ) {
				$context[ $key ] = is_string( $value ) ? self::clean( $value ) : ( is_scalar( $value ) || null === $value ? $value : '[omitted]' ); }
			if ( null !== $performance ) {
				$context['performance'] = $performance; }
			$entry = array(
				'time'    => gmdate( 'c' ),
				'level'   => $level,
				'event'   => self::clean( $event ),
				'context' => $context,
			);
			$dir   = CSM_Support::private_dir();
			$lock  = @fopen( $dir . '/diagnostics.lock', 'c' );
			if ( ! $lock ) {
				return; }
			try {
				if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
					return; }
				$path = $dir . '/diagnostics.log';
				clearstatcache( true, $path );
				if ( is_file( $path ) && filesize( $path ) >= 512 * 1024 && ! @rename( $path, $path . '.1' ) ) {
					return; }
				if ( @file_put_contents( $path, CSM_Support::json( $entry ), FILE_APPEND | LOCK_EX ) !== false ) {
					@chmod( $path, 0600 ); }
			} finally {
				fclose( $lock ); }
		} catch ( Throwable $e ) {
			/* Diagnostics must never block a transfer or its recovery. */ } finally {
			CSM_Timing::add( 'diagnostics.write', $timing, 0 ); }
	}

	/**
	 * Build a diagnostics report with sensitive fields recursively redacted.
	 *
	 * @param array $details Diagnostic report data requiring recursive redaction.
	 *
	 * @return array Diagnostics with secret fields and embedded values redacted.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	public static function report( array $details ): array {
		$id = $details['transfer_id'] ?? '';
		if ( ! is_string( $id ) || ( '' !== $id && ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) ) {
			throw new RuntimeException( 'Invalid diagnostic transfer ID.' ); }
		$fields  = array( 'transfer_id', 'direction', 'status', 'source', 'destination', 'transport', 'phase', 'elapsed_seconds', 'error', 'notice', 'remote_version', 'remote_wordpress', 'remote_php' );
		$context = array_intersect_key( $details, array_flip( $fields ) );
		foreach ( $context as $key => $value ) {
			$context[ $key ] = is_string( $value ) ? self::clean( $value ) : ( is_scalar( $value ) || null === $value ? $value : '[omitted]' ); }
		$clean   = static function ( $value ) use ( &$clean ) {
			if ( is_string( $value ) ) {
				return self::clean( $value ); }
			if ( ! is_array( $value ) ) {
				return $value; }
			$result = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && preg_match( '/key|password|secret|token|signature|nonce|cookie|authorization|pairing|http_auth/i', $key ) ) {
					continue; }
				$result[ $key ] = $clean( $item );
			}
			return $result;
		};
		$entries = self::entries( $id ?: null, true );
		return array(
			'schema_version' => 1,
			'copied_at'      => gmdate( 'c' ),
			'site'           => self::clean( site_url() ),
			'environment'    => array(
				'trickle'     => CSM_VERSION,
				'wordpress'   => get_bloginfo( 'version' ),
				'php'         => PHP_VERSION,
				'report_sapi' => PHP_SAPI,
			),
			'transfer'       => $context,
			'progress'       => $clean( '' !== $id ? CSM_Progress::read( $id ) : array() ),
			'entries'        => $clean( $entries ),
			'performance'    => CSM_Timing::report( $entries ),
			'log_scope'      => 'Retained controller logs, including recorded remote responses. Remote server logs are not included.',
		);
	}

	/**
	 * Read bounded diagnostic entries, optionally scoped to one transfer.
	 *
	 * @param string|null $transfer_id Optional transfer filter; null includes all retained transfers.
	 * @param bool        $performance Whether to include numeric performance samples.
	 *
	 * @return array Retained matching diagnostic entries.
	 */
	public static function entries( ?string $transfer_id = null, bool $performance = false ): array {
		$dir     = CSM_Support::private_dir();
		$entries = array();
		foreach ( array( 'diagnostics.log.1', 'diagnostics.log' ) as $name ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				continue; }
			foreach ( file( $dir . '/' . $name, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
				$entry = json_decode( $line, true );
				if ( ! $performance && ( $entry['event'] ?? '' ) === 'Performance sample' ) {
					continue; }
				if ( is_array( $entry ) && ( null === $transfer_id || ( $entry['context']['transfer_id'] ?? '' ) === $transfer_id ) ) {
					$entries[] = $entry; }
			}
		}
		return null === $transfer_id ? array_slice( $entries, -200 ) : $entries;
	}
}
