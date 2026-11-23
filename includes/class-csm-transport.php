<?php
/**
 * Authenticated peer transport, request scheduling and cooldowns.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-csm-timing.php';
require_once __DIR__ . '/class-csm-network-error.php';
require_once __DIR__ . '/class-csm-busy-error.php';
require_once __DIR__ . '/class-csm-pacing-error.php';

/**
 * Authenticated peer transport, request scheduling and cooldowns.
 */
final class CSM_Transport {
	/**
	 * Whether a peer operation is inside the bounded retry loop.
	 *
	 * @var bool
	 */
	private static bool $retrying = false;
	/**
	 * Monotonic deadline shared by retries for the current operation.
	 *
	 * @var float|null
	 */
	private static ?float $retry_deadline = null;
	/**
	 * Host schedule keys currently owned by this PHP request.
	 *
	 * @var array
	 */
	private static array $active_hosts = array();

	/**
	 * Derive the shared scheduling key for a peer hostname.
	 *
	 * @param string $url WordPress installation URL; remote URLs must use HTTPS.
	 *
	 * @return string Private option key derived from the normalized hostname, without credentials.
	 */
	private static function cooldown_key( string $url ): string {
		return '_csm_cooldown_' . substr( hash( 'sha256', strtolower( rtrim( wp_parse_url( $url, PHP_URL_HOST ), '.' ) ) ), 0, 20 );
	}

	/**
	 * Identify startup and update operations subject to global start spacing.
	 *
	 * @param string $action Peer operation included in the request signature.
	 *
	 * @return bool Whether the peer action belongs to the setup request group.
	 */
	private static function setup_request( string $action ): bool {
		return in_array( $action, array( 'manifest', 'version', 'file_policy', 'prepare', 'component_hashes', 'media_hashes', 'component_manifest', 'media_manifest', 'build_info', 'update_info', 'update_trickle', 'update_trickle_build', 'update_trickle_zip', 'health', 'estimates' ), true );
	}

	/**
	 * Persist schedule timestamps while holding the host scheduler lock.
	 *
	 * @param resource $lock Writable scheduler stream held under an exclusive host lock.
	 * @param array    $state Shared quiet-time and startup-spacing timestamps.
	 *
	 * @throws RuntimeException If the locked schedule cannot be truncated, written or flushed.
	 */
	private static function save_schedule( $lock, array $state ): void {
		$raw = wp_json_encode( $state, JSON_THROW_ON_ERROR );
		rewind( $lock );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write exact bytes through the already locked native descriptor.
		if ( ! ftruncate( $lock, 0 ) || fwrite( $lock, $raw ) !== strlen( $raw ) || ! fflush( $lock ) ) {
			throw new RuntimeException( 'Cannot save remote request spacing.' ); }
	}

	/**
	 * Run one peer operation under the cross-worker hostname scheduler.
	 *
	 * @param string   $url WordPress installation URL; remote URLs must use HTTPS.
	 * @param string   $action Peer operation included in the request signature.
	 * @param callable $operation One peer request callback to execute under the transport policy.
	 *
	 * @return array Operation result after applying shared quiet time, startup spacing and cooldowns.
	 *
	 * @throws CSM_Pacing_Error If another request, spacing or a cooldown prevents this operation.
	 * @throws RuntimeException If scheduler storage or configured request intervals are invalid.
	 */
	private static function paced( string $url, string $action, callable $operation ): array {
		$key = self::cooldown_key( $url );
		if ( isset( self::$active_hosts[ $key ] ) ) {
			throw new CSM_Pacing_Error( 'Another request to this host is running.' ); }
		$path = CSM_Support::private_dir() . '/request-' . substr( $key, 14 ) . '.lock';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$lock = fopen( $path, 'c+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
		if ( ! $lock || ! chmod( $path, 0600 ) ) {
			if ( $lock ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $lock );
			} throw new RuntimeException( 'Cannot open remote request scheduler.' ); }
		$deadline    = min( microtime( true ) + 45, null === self::$retry_deadline ? INF : microtime( true ) + max( 0, self::$retry_deadline - hrtime( true ) / 1e9 ) );
		$poll        = 'progress' === $action;
		$cancellable = ! in_array( $action, array( 'cancel', 'bundle_discard', 'rollback', 'status', 'progress' ), true );
		try {
			while ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
				if ( $poll || microtime( true ) >= $deadline ) {
					throw new CSM_Pacing_Error( 'Another request to this host is running.' ); }
				if ( $cancellable ) {
					CSM_Support::check_cancel(); }
				usleep( 50000 );
			}
			self::$active_hosts[ $key ] = true;
			// These options may have changed in another PHP worker while this one was waiting.
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( 'notoptions', 'options' ); }
			foreach ( array( $key, $key . '_' . $action ) as $cooldown ) {
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( $cooldown, 'options' ); }
				$remaining = (int) get_option( $cooldown, 0 ) - time();
				if ( $remaining > 0 ) {
					$e              = new CSM_Pacing_Error( 'Remote server requested a pause. Retry in ' . $remaining . ' seconds.' );
					$e->retry_after = $remaining;
					throw $e; }
			}
			$settings = get_option( '_csm_transfer_defaults', array() );
			$interval = (float) apply_filters( 'csm_request_interval', defined( 'CSM_REQUEST_INTERVAL' ) ? CSM_REQUEST_INTERVAL : ( $settings['request_interval'] ?? 0.0 ), wp_parse_url( $url, PHP_URL_HOST ) );
			$setup    = (float) apply_filters( 'csm_setup_request_interval', defined( 'CSM_SETUP_REQUEST_INTERVAL' ) ? CSM_SETUP_REQUEST_INTERVAL : ( $settings['setup_request_interval'] ?? .5 ), wp_parse_url( $url, PHP_URL_HOST ) );
			foreach ( array( $interval, $setup ) as $value ) {
				if ( ! is_finite( $value ) || $value < 0 || $value > 60 ) {
					throw new RuntimeException( 'Request intervals must be between 0 and 60 seconds.' ); }
			}
			rewind( $lock );
			$raw   = stream_get_contents( $lock );
			$state = json_decode( $raw, true );
			// Older schedulers stored only the optional quiet-time timestamp.
			if ( ! is_array( $state ) ) {
				$state = array(
					'quiet' => (float) $raw,
					'setup' => 0,
				); }
			$next = max( $interval > 0 ? ( $state['quiet'] ?? 0 ) : 0, $setup > 0 ? ( $state['setup'] ?? 0 ) : 0 );
			while ( true ) {
				$wait = $next - microtime( true );
				if ( $wait <= 0 ) {
					break;
				}
				if ( $poll || microtime( true ) + $wait >= $deadline ) {
					$e              = new CSM_Pacing_Error( 'Remote request spacing is active.' );
					$e->retry_after = max( 1, (int) ceil( $wait ) );
					throw $e; }
				if ( $cancellable ) {
					CSM_Support::check_cancel(); }
				usleep( (int) ( min( .05, $wait ) * 1000000 ) );
			}
			$state['quiet'] = microtime( true ) + $interval;
			if ( self::setup_request( $action ) ) {
				$state['setup'] = microtime( true ) + $setup; }
			self::save_schedule( $lock, $state );
			try {
				return $operation(); } finally {
				// Full quiet time is optional; setup spacing is measured between starts only.
				$state['quiet'] = microtime( true ) + $interval;
				self::save_schedule( $lock, $state );
				}
		} finally {
			unset( self::$active_hosts[ $key ] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			fclose( $lock ); }
	}

	/**
	 * Persist an HTTP 429/430 host cooldown and stop automatic retries.
	 *
	 * @param string $url WordPress installation URL; remote URLs must use HTTPS.
	 * @param int    $status HTTP status returned by the peer.
	 * @param int    $retry_after Peer-requested pause in seconds.
	 *
	 * @throws CSM_Network_Error If receipt is interrupted or the peer requires a retained retry.
	 */
	private static function throttle( string $url, int $status, int $retry_after ): void {
		if ( ! in_array( $status, array( 429, 430 ), true ) ) {
			return; }
		$e              = new CSM_Network_Error( 'Remote server returned HTTP ' . $status . '. The transfer is retained; wait before resuming.' );
		$e->retry_after = max( 30, $retry_after );
		$e->exhausted   = true;
		update_option( self::cooldown_key( $url ), time() + $e->retry_after, false );
		throw $e;
	}

	/**
	 * Retry eligible safe operations within the shared bounded request deadline.
	 *
	 * @param callable $operation One peer request callback to execute under the transport policy.
	 *
	 * @return array Operation result from the first successful attempt.
	 *
	 * @throws CSM_Network_Error If receipt is interrupted or the peer requires a retained retry.
	 */
	public static function retry( callable $operation ): array {
		if ( self::$retrying || ( defined( 'CSM_STOP_ON_ERROR' ) && CSM_STOP_ON_ERROR ) ) {
			return $operation(); }
		self::$retrying       = true;
		self::$retry_deadline = hrtime( true ) / 1e9 + 50;
		try {
			for ( $attempt = 0, $busy = 0; ; ) {
				try {
					return $operation(); } catch ( CSM_Network_Error $e ) {
					$wait      = $e instanceof CSM_Busy_Error ? max( 1, $e->retry_after ) : max( $e->retry_after, $e->retry_after ? 30 * 2 ** $attempt : 2 ** $attempt );
					$exhausted = $e instanceof CSM_Busy_Error ? $busy++ >= 1 : $attempt++ >= 2;
					if ( $e->exhausted || $exhausted || $wait > 5 || hrtime( true ) / 1e9 + $wait >= self::$retry_deadline ) {
						$e->retry_after = max( $e->retry_after, $wait );
						$e->exhausted   = true;
						throw $e; }
					CSM_Log::write(
						'info',
						'Connection interrupted; retrying safe transfer step',
						array(
							'error'       => $e->getMessage(),
							'duration_ms' => $wait * 1000,
						)
					);
					sleep( $wait );
					}
			}
		} finally {
			self::$retrying       = false;
			self::$retry_deadline = null; }
	}

	/**
	 * Resolve the configured CA bundle for a peer URL.
	 *
	 * @param string $url WordPress installation URL; remote URLs must use HTTPS.
	 *
	 * @return string Configured CA path; an empty string uses the system CA store.
	 */
	public static function ca_bundle( string $url ): string {
		return (string) apply_filters( 'csm_ca_bundle', defined( 'CSM_CA_BUNDLE' ) ? CSM_CA_BUNDLE : ABSPATH . WPINC . '/certificates/ca-bundle.crt', $url );
	}

	/**
	 * Validate a WordPress installation URL without weakening remote TLS.
	 *
	 * @param string $url WordPress installation URL; remote URLs must use HTTPS.
	 *
	 * @return string Validated URL without trailing slashes; plain HTTP is limited to local hosts.
	 *
	 * @throws RuntimeException If the URL contains unsupported components or violates the TLS policy.
	 */
	public static function url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
			throw new RuntimeException( 'Use the WordPress installation URL without credentials, query strings or fragments.' ); }
		$local = in_array( $parts['host'], array( 'localhost', '127.0.0.1', '::1' ), true ) || str_ends_with( $parts['host'], '.localhost' );
		if ( ( $parts['scheme'] ?? '' ) !== 'https' && ! ( ( $parts['scheme'] ?? '' ) === 'http' && $local ) ) {
			throw new RuntimeException( 'HTTPS is required outside localhost.' ); }
		return rtrim( $url, '/' );
	}

	/**
	 * Sign an action, nonce, body digest and encoded parameter bytes.
	 *
	 * @param string $action Peer operation included in the request signature.
	 * @param string $key Raw connection secret used to compute the request HMAC.
	 * @param string $bodyhash Hexadecimal body digest or the protocol stream marker.
	 * @param array  $params Parameters encoded verbatim into the signed request headers.
	 *
	 * @return array Native cURL header lines, including scoped key identifier and HMAC signature.
	 */
	public static function headers( string $action, string $key, string $bodyhash, array $params = array() ): array {
		$time                      = (string) time();
		$nonce                     = bin2hex( random_bytes( 16 ) );
		$params['trickle_version'] = CSM_VERSION;
		if ( 'commit' === $action ) {
			$params['trickle_build']   = CSM_Updates::fingerprint();
			$params['sender_url']      = site_url();
			$params['sender_instance'] = CSM_Controller::fingerprint();
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Protocol encoding preserves binary bytes; decoded input is validated before use.
		$params    = base64_encode( CSM_Support::json( $params ) );
		$signature = hash_hmac( 'sha256', implode( "\n", array( $action, $time, $nonce, $bodyhash, $params ) ), $key );
		return array( 'X-CSM-Key-Id: ' . CSM_Access::id( $key ), 'X-CSM-Time: ' . $time, 'X-CSM-Nonce: ' . $nonce, 'X-CSM-Hash: ' . $bodyhash, 'X-CSM-Params: ' . $params, 'X-CSM-Signature: ' . $signature, 'Expect:' );
	}

	/**
	 * Read bounded authentication-header bytes without changing their signed representation.
	 *
	 * @param string $name Internal server header key.
	 * @return string Exact header bytes, or an empty string when absent.
	 * @throws RuntimeException If the header is not a bounded string.
	 */
	private static function signed_header( string $name ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Type/length validation follows; sanitizing signed bytes would invalidate the HMAC.
		$value = $_SERVER[ $name ] ?? '';
		if ( ! is_string( $value ) || strlen( $value ) > 4096 ) {
			throw new RuntimeException( 'Malformed authentication header.', 403 );
		}
		return $value;
	}

	/**
	 * Verify signed header bytes and action scope without consuming the nonce.
	 *
	 * This early check does not reserve the nonce or verify incoming body bytes.
	 * Final authentication and streamed receipt verification remain mandatory.
	 *
	 * @param string $action Peer operation included in the request signature.
	 *
	 * @return array Authenticated private credential, including its key and access flags.
	 *
	 * @throws RuntimeException If the signed time, HMAC or scoped connection authorization is invalid.
	 */
	public static function signed_credential( string $action ): array {
		$credential = CSM_Access::credential( self::signed_header( 'HTTP_X_CSM_KEY_ID' ) );
		$key        = $credential['key'];
		$time       = self::signed_header( 'HTTP_X_CSM_TIME' );
		$nonce      = self::signed_header( 'HTTP_X_CSM_NONCE' );
		$hash       = self::signed_header( 'HTTP_X_CSM_HASH' );
		$params     = self::signed_header( 'HTTP_X_CSM_PARAMS' );
		$signature  = self::signed_header( 'HTTP_X_CSM_SIGNATURE' );
		if ( ! $key || ! ctype_digit( $time ) || abs( time() - (int) $time ) > 300 || ! preg_match( '/^[a-f0-9]{32}$/D', $nonce ) ) {
			throw new RuntimeException( 'Authentication failed. Check the connection key and both server clocks.', 403 );
		}
		$expected = hash_hmac( 'sha256', implode( "\n", array( $action, $time, $nonce, $hash, $params ) ), $key );
		if ( ! hash_equals( $expected, $signature ) ) {
			throw new RuntimeException( 'Authentication failed.', 403 ); }
		CSM_Access::authorize( $credential, $action );
		return $credential;
	}

	/**
	 * Consume a signed nonce and validate the action-specific request envelope.
	 *
	 * Stream payload checksums are verified by their receiving handlers. Do not
	 * sanitize signed header bytes before HMAC verification or consume stream bodies here.
	 *
	 * @param string $action Peer operation included in the request signature.
	 *
	 * @return array Decoded checked JSON body, or signed parameters for a streamed upload.
	 *
	 * @throws RuntimeException If the nonce is reused or the action-specific signed envelope fails validation.
	 */
	public static function authenticate( string $action ): array {
		self::signed_credential( $action );
		$nonce     = self::signed_header( 'HTTP_X_CSM_NONCE' );
		$hash      = self::signed_header( 'HTTP_X_CSM_HASH' );
		$params    = self::signed_header( 'HTTP_X_CSM_PARAMS' );
		$nonce_key = '_csm_nonce_' . $nonce;
		if ( ! add_option( $nonce_key, time(), '', false ) ) {
			throw new RuntimeException( 'Request was already used.', 403 ); }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Protocol encoding preserves binary bytes; decoded input is validated before use.
		$decoded = base64_decode( $params, true );
		if ( false === $decoded ) {
			throw new RuntimeException( 'Invalid signed request parameters.', 403 );
		}
		try {
			$params = json_decode( $decoded, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new RuntimeException( 'Invalid signed request parameters.', 403 );
		}
		if ( ! is_array( $params ) ) {
			throw new RuntimeException( 'Signed parameters must be a JSON object or array.', 403 );
		}
		if ( in_array( $action, array( 'live_files', 'live_receive', 'live_state', 'prepare', 'bundle', 'bundle_chunk', 'bundle_stream', 'component_manifest', 'media_manifest', 'export', 'receive', 'stream_data', 'segment_state', 'segment_data', 'commit' ), true ) ) {
			CSM_Updates::assert_version( $params['trickle_version'] ?? null ); }
		if ( 'commit' === $action ) {
			CSM_Updates::assert_build(
				array(
					'remote_build_update' => 1,
					'trickle_build'       => $params['trickle_build'] ?? null,
				)
			);
			CSM_Controller::assert_remote(
				array(
					'url'                  => $params['sender_url'] ?? '',
					'instance_fingerprint' => $params['sender_instance'] ?? '',
				)
			);
		}
		if ( 'update_trickle_zip' === $action ) {
			if ( ! is_string( $params['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $params['sha256'] ) || ! hash_equals( $params['sha256'], $hash ) || ! is_int( $params['size'] ?? null ) || $params['size'] < 1 || $params['size'] > 700000 ) {
				throw new RuntimeException( 'Invalid signed Trickle ZIP metadata.', 403 ); }
			return $params;
		}
		if ( in_array( $action, array( 'live_receive', 'receive', 'stream_data', 'segment_data' ), true ) ) {
			if ( 'stream' !== $hash ) {
				throw new RuntimeException( 'Expected a stream upload.' ); }
			return $params;
		}
		$body = file_get_contents( 'php://input', false, null, 0, 1048577 );
		if ( ! is_string( $body ) || strlen( $body ) > 1048576 || ! hash_equals( hash( 'sha256', $body ), $hash ) ) {
			throw new RuntimeException( 'Request body checksum mismatch.', 403 ); }
		try {
			$data = json_decode( $body, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new RuntimeException( 'Invalid request JSON.', 400 );
		}
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Request body must be a JSON object or array.', 400 );
		}
		return $data;
	}

	/**
	 * Validate optional Basic Auth and register its secrets for log redaction.
	 *
	 * @param array $auth Optional Basic Auth username/password pair.
	 *
	 * @return array Native cURL Basic Auth options, or an empty array when unused.
	 *
	 * @throws RuntimeException If the username or password violates the bounded Basic Auth format.
	 */
	public static function http_auth( array $auth ): array {
		if ( ! $auth ) {
			return array(); }
		$user     = $auth['username'] ?? null;
		$password = $auth['password'] ?? null;
		if ( ! is_string( $user ) || ! is_string( $password ) || '' === $user || '' === $password || strlen( $user ) > 255 || strlen( $password ) > 4096 || str_contains( $user, ':' ) || preg_match( '/[\x00-\x1f\x7f]/', $user . $password ) ) {
			throw new RuntimeException( 'Enter a valid HTTP Basic Auth username and password.' );
		}
		CSM_Log::secret( $password );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Register the HTTP Basic credential encoding for redaction; it is never logged.
		CSM_Log::secret( base64_encode( $user . ':' . $password ) );
		return array(
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
			CURLOPT_USERNAME => $user,
			CURLOPT_PASSWORD => $password,
		);
	}

	/**
	 * Run a bounded health probe through the shared host scheduler.
	 *
	 * @param string $url WordPress installation URL; remote URLs must use HTTPS.
	 * @param string $probe Optional non-secret probe marker sent with the health request.
	 * @param string $transfer_id Optional transfer identifier associated with the health probe.
	 * @param array  $http_auth Optional Basic Auth username/password pair.
	 *
	 * @return array Probe status and sanitized connection diagnostics.
	 */
	public static function health( string $url, string $probe = '', string $transfer_id = '', array $http_auth = array() ): array {
		return self::paced( self::url( $url ), 'health', static fn()=>self::probe( $url, $probe, $transfer_id, $http_auth ) );
	}

	/**
	 * Perform one health request with native TLS and optional Basic Auth.
	 *
	 * @param string $url WordPress installation URL; remote URLs must use HTTPS.
	 * @param string $probe Optional non-secret probe marker sent with the health request.
	 * @param string $transfer_id Optional transfer identifier associated with the health probe.
	 * @param array  $http_auth Optional Basic Auth username/password pair.
	 *
	 * @return array Probe status and sanitized connection diagnostics.
	 */
	private static function probe( string $url, string $probe, string $transfer_id, array $http_auth ): array {
		$url = add_query_arg( 'trickle_health', bin2hex( random_bytes( 8 ) ), self::url( $url ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
		$ch          = curl_init( $url );
		$ca          = self::ca_bundle( $url );
		$retry_after = 0;
		$options     = array(
			CURLOPT_NOBODY         => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HEADERFUNCTION => static function ( $curl, $line ) use ( &$retry_after ) {
				if ( preg_match( '#^HTTP/\\S+\\s+\\d+#', $line ) ) {
					$retry_after = 0; }
				if ( stripos( $line, 'Retry-After:' ) === 0 ) {
					$value       = trim( substr( $line, 12 ) );
					if ( ctype_digit( $value ) ) {
						$retry_after = (int) $value;
					} else {
						$date = strtotime( $value );
						$retry_after = max( 0, ( $date ? $date : time() ) - time() );
					}
				}
				return strlen( $line );
			},
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_HTTPHEADER     => array( 'Cache-Control: no-cache', 'User-Agent: ' . (string) apply_filters( 'csm_user_agent', 'Trickle/' . CSM_VERSION . ' (+https://github.com/jonschr/trickle)' ) ),
		);
		$options    += self::http_auth( $http_auth );
		if ( $ca ) {
			$options[ CURLOPT_CAINFO ] = $ca; }
		if ( '' !== $probe ) {
			$options[ CURLOPT_HTTPHEADER ][] = 'X-Trickle-Health: ' . $probe; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
		curl_setopt_array( $ch, $options );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
		$ok = curl_exec( $ch );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
		$error   = CSM_Log::clean( curl_error( $ch ) );
		$context = array(
			'operation'         => 'health',
			'transfer_id'       => $transfer_id ? $transfer_id : null,
			'site'              => $url,
			'http_status'       => $status,
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
			'curl_errno'        => curl_errno( $ch ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
			'ssl_verify_result' => curl_getinfo( $ch, CURLINFO_SSL_VERIFYRESULT ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
			'duration_ms'       => (int) round( curl_getinfo( $ch, CURLINFO_TOTAL_TIME ) * 1000 ),
			'ca_source'         => ABSPATH . WPINC . '/certificates/ca-bundle.crt' === $ca ? 'WordPress CA bundle' : ( $ca ? 'Custom CA bundle (' . basename( $ca ) . ')' : 'System CA bundle' ),
		);
		if ( '' !== $error ) {
			$context['error'] = $error; }
		CSM_Log::write( false === $ok || $status >= 500 ? 'error' : 'info', 'Destination health check completed', $context );
		self::throttle( $url, $status, $retry_after );
		return array(
			'status'   => $status,
			'verified' => false !== $ok && $status >= 200 && $status < 300,
			'error'    => $error,
		);
	}

	/**
	 * Send a signed JSON or streamed request with the native pacing policy.
	 *
	 * @param array                  $peer Peer URL, scoped connection key and optional Basic Auth configuration.
	 * @param string                 $action Peer operation included in the request signature.
	 * @param array                  $data JSON payload fields or signed binary-upload metadata.
	 * @param Generator|null         $upload Optional generator of outgoing binary chunks; null sends JSON.
	 * @param resource|callable|null $download Writable stream or chunk callback; null receives a JSON response.
	 * @param int                    $timeout Request time limit in seconds; zero selects the action default.
	 * @param int                    $download_limit Maximum received bytes; zero leaves the stream unbounded by this setting.
	 * @param Generator|null         $idle Generator for bounded local preparation during successful upload response waits.
	 *
	 * @return array Peer response data or bounded-download continuation metadata.
	 */
	public static function request( array $peer, string $action, array $data = array(), ?Generator $upload = null, $download = null, int $timeout = 0, int $download_limit = 0, ?Generator $idle = null ): array {
		if ( ! $timeout ) {
			$timeout = ( $upload || $download ) ? 50 : ( 'bundle' === $action ? 55 : 45 ); }
		$request = static fn()=>self::paced( self::url( $peer['url'] ), $action, static fn()=>self::send( $peer, $action, $data, $upload, $download, $timeout, $download_limit, $idle ) );
		return ! $upload && ! $download && in_array( $action, array( 'manifest', 'version', 'file_policy', 'component_hashes', 'media_hashes', 'status', 'segment_state' ), true ) ? self::retry( $request ) : $request();
	}

	/**
	 * Perform one signed peer request without following redirects.
	 *
	 * @param array                  $peer Peer URL, scoped connection key and optional Basic Auth configuration.
	 * @param string                 $action Peer operation included in the request signature.
	 * @param array                  $data JSON payload fields or signed binary-upload metadata.
	 * @param Generator|null         $upload Optional generator of outgoing binary chunks; null sends JSON.
	 * @param resource|callable|null $download Writable stream or chunk callback; null receives a JSON response.
	 * @param int                    $timeout Request time limit in seconds; zero selects the action default.
	 * @param int                    $download_limit Maximum received bytes; zero leaves the stream unbounded by this setting.
	 * @param Generator|null         $idle Generator for bounded local preparation during successful upload response waits.
	 *
	 * @return array Decoded peer response data or bounded-download continuation metadata.
	 *
	 * @throws RuntimeException If URL, credentials, request metadata or the native transport are invalid.
	 */
	private static function send( array $peer, string $action, array $data, ?Generator $upload, $download, int $timeout, int $download_limit, ?Generator $idle ): array {
		if ( null !== self::$retry_deadline ) {
			$left = (int) ceil( self::$retry_deadline - hrtime( true ) / 1e9 );
			if ( $left < 1 ) {
				$error            = new CSM_Network_Error( 'Transfer step reached its request limit. Resume from its saved checkpoint.' );
				$error->exhausted = true;
				throw $error;
			} $timeout = min( $timeout, $left ); }
		if ( ! extension_loaded( 'curl' ) ) {
			throw new RuntimeException( 'The PHP cURL extension is required.' ); }
		CSM_Log::secret( $peer['key'] );
		$url                = add_query_arg( 'trickle', $action, self::url( $peer['url'] ) );
		$cooldown           = self::cooldown_key( $peer['url'] );
		$operation_cooldown = $cooldown . '_' . $action;
		$remaining          = max( (int) get_option( $cooldown, 0 ), (int) get_option( $operation_cooldown, 0 ) ) - time();
		if ( $remaining > 0 ) {
			$e              = new CSM_Network_Error( 'Remote server requested a pause. Retry in ' . $remaining . ' seconds.' );
			$e->retry_after = $remaining;
			throw $e; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
		$ch                            = curl_init( $url );
		$response                      = '';
		$status                        = 0;
		$bytes                         = 0;
		$retry_after                   = 0;
		$download_offset               = $download && ! is_callable( $download ) ? ftell( $download ) : 0;
		$body                          = $upload ? '' : CSM_Support::json( $data );
		$headers                       = self::headers( $action, $peer['key'], $upload ? ( 'update_trickle_zip' === $action ? $data['sha256'] : 'stream' ) : hash( 'sha256', $body ), $upload ? $data : array() );
		$headers[]                     = 'Content-Type: ' . ( $upload ? ( 'update_trickle_zip' === $action ? 'application/zip' : 'application/octet-stream' ) : 'application/json' );
		$headers[]                     = 'Accept: ' . ( $download ? 'application/octet-stream' : 'application/json' );
		$headers[]                     = 'Cache-Control: no-store';
		$headers[]                     = 'User-Agent: ' . (string) apply_filters( 'csm_user_agent', 'Trickle/' . CSM_VERSION . ' (+https://github.com/jonschr/trickle)' );
		$options                       = array(
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_CONNECTTIMEOUT => min( 10, $timeout ),
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_HEADERFUNCTION => static function ( $curl, $line ) use ( &$status, &$retry_after ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) {
					$status      = (int) $m[1];
					$retry_after = 0; }
				if ( stripos( $line, 'Retry-After:' ) === 0 ) {
					$value       = trim( substr( $line, 12 ) );
					if ( ctype_digit( $value ) ) {
						$retry_after = (int) $value;
					} else {
						$date = strtotime( $value );
						$retry_after = max( 0, ( $date ? $date : time() ) - time() );
					}
				}
				return strlen( $line );
			},
			CURLOPT_WRITEFUNCTION  => static function ( $curl, $chunk ) use ( $action, $download, $download_limit, $download_offset, &$response, &$bytes, &$status ) {
				if ( $download && 200 === $status ) {
					if ( $download_limit && $bytes + strlen( $chunk ) > $download_limit ) {
						throw new RuntimeException( 'Downloaded stream exceeds its declared size limit.' ); }
					if ( is_callable( $download ) ) {
						CSM_Timing::measure( 'stream.receive', static fn()=>$download( $chunk ) );
					} else {
						CSM_Support::write( $download, $chunk );
					} $bytes += strlen( $chunk );
					if ( ! is_callable( $download ) && ! in_array( $action, array( 'component_manifest', 'media_manifest' ), true ) ) {
						CSM_Progress::update(
							array(
								'bytes' => $download_offset + $bytes,
								'count' => $download_offset + $bytes,
							)
						); }
				} elseif ( strlen( $response ) < 1048576 ) {
					$response .= $chunk; }
				return strlen( $chunk );
			},
		);
		$options                      += self::http_auth( $peer['http_auth'] ?? array() );
		$options[ CURLOPT_NOPROGRESS ] = false;
		$cancel_checked                = 0.0;
		$cancellable                   = ! in_array( $action, array( 'cancel', 'bundle_discard', 'rollback', 'status', 'progress' ), true );
		$options[ defined( 'CURLOPT_XFERINFOFUNCTION' ) ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION ] = static function () use ( $cancellable, &$cancel_checked ) {
			// cURL may call repeatedly between data callbacks, which retain their own work-loop guards.
			if ( $cancellable ) {
				$now = hrtime( true ) / 1e9;
				if ( $now - $cancel_checked >= .05 ) {
					CSM_Support::check_cancel();
					$cancel_checked = $now; }
			}
			return 0;
		};
		// A retained snapshot should respond immediately; a stalled body can safely resume.
		if ( $download && 'bundle_stream' === $action ) {
			$options[ CURLOPT_LOW_SPEED_LIMIT ] = 1;
			$options[ CURLOPT_LOW_SPEED_TIME ]  = 30; }
		$ca = self::ca_bundle( $peer['url'] );
		if ( $ca ) {
			$options[ CURLOPT_CAINFO ] = $ca; }
		$context = array(
			'operation'       => $action,
			'stream_kind'     => $data['kind'] ?? null,
			'offset'          => $data['offset'] ?? null,
			'length'          => $data['length'] ?? null,
			'snapshot_size'   => $data['bundle']['size'] ?? null,
			'timeout_seconds' => $timeout,
			'site'            => $peer['url'],
			'transfer_id'     => $data['id'] ?? null,
			'ca_source'       => ABSPATH . WPINC . '/certificates/ca-bundle.crt' === $ca ? 'WordPress CA bundle' : ( $ca ? 'Custom CA bundle (' . basename( $ca ) . ')' : 'System CA bundle' ),
		);
		if ( 'media_manifest' === $action ) {
			$context['component'] = ( $data['year'] ?? '' ) . '/' . ( $data['month'] ?? '' ); }
		if ( ! in_array( $action, array( 'progress', 'status' ), true ) ) {
			CSM_Log::write( 'info', 'Outgoing request started', $context ); }
		$upload_error = null;
		$upload_done  = false;
		if ( $upload ) {
			$buffer                    = '';
			$initialized               = false;
			$options[ CURLOPT_UPLOAD ] = true;
			// Uploads are serial; HTTP/1.1 avoids the extra upload delay measured on Flywheel.
			$options[ CURLOPT_HTTP_VERSION ] = CURL_HTTP_VERSION_1_1;
			if ( 'stream_data' === $action && isset( $data['length'] ) ) {
				$options[ CURLOPT_INFILESIZE ] = $data['length']; }
			if ( 'update_trickle_zip' === $action ) {
				$options[ CURLOPT_INFILESIZE ] = $data['size']; }
			$options[ CURLOPT_CUSTOMREQUEST ] = 'POST';
			$options[ CURLOPT_READFUNCTION ]  = static function ( $curl, $fp, $length ) use ( $upload, &$buffer, &$initialized, &$upload_error, &$upload_done ) {
				$started = hrtime( true );
				$out     = '';
				try {
					// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The buffer changes on each step; its current length controls exact frame boundaries.
					while ( strlen( $buffer ) < $length ) {
						if ( ! $initialized ) {
							$upload->rewind();
							$initialized = true; }
						if ( ! $upload->valid() ) {
							break; }
						$buffer .= $upload->current();
						$upload->next();
					}
					$out         = substr( $buffer, 0, $length );
					$buffer      = substr( $buffer, strlen( $out ) );
					$upload_done = '' === $out;
					return $out;
				} catch ( Throwable $e ) {
					$upload_error = $e;
					return CURL_READFUNC_ABORT; } finally {
					CSM_Timing::add( 'stream.produce', $started, strlen( $out ) ); }
			};
		} else {
			$options[ CURLOPT_POST ]       = true;
			$options[ CURLOPT_POSTFIELDS ] = $body;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
		curl_setopt_array( $ch, $options );
		$timing       = hrtime( true );
		$idle_started = false;
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
			$ok = $idle && $upload && function_exists( 'curl_multi_init' ) ? self::send_with_idle( $ch, $idle, $upload_done, $idle_started ) : curl_exec( $ch ); } finally {
			CSM_Timing::add( 'http.' . $action, $timing, $bytes ); }
			if ( $cancellable ) {
				CSM_Support::check_cancel(); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
			$error = curl_error( $ch );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
			$status                 = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$context               += array(
				'http_status'       => $status,
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
				'curl_errno'        => curl_errno( $ch ),
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
				'ssl_verify_result' => curl_getinfo( $ch, CURLINFO_SSL_VERIFYRESULT ),
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
				'duration_ms'       => (int) round( curl_getinfo( $ch, CURLINFO_TOTAL_TIME ) * 1000 ),
			);
			$context['performance'] = array(
				'http' => array(
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
					'dns_ms'        => 1000 * curl_getinfo( $ch, CURLINFO_NAMELOOKUP_TIME ),
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
					'connect_ms'    => 1000 * curl_getinfo( $ch, CURLINFO_CONNECT_TIME ),
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
					'tls_ms'        => 1000 * curl_getinfo( $ch, CURLINFO_APPCONNECT_TIME ),
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
					'ready_ms'      => 1000 * curl_getinfo( $ch, CURLINFO_PRETRANSFER_TIME ),
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
					'first_byte_ms' => 1000 * curl_getinfo( $ch, CURLINFO_STARTTRANSFER_TIME ),
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
					'total_ms'      => 1000 * curl_getinfo( $ch, CURLINFO_TOTAL_TIME ),
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
					'upload_bytes'  => (int) curl_getinfo( $ch, CURLINFO_SIZE_UPLOAD ),
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
					'http_version'  => curl_getinfo( $ch, CURLINFO_HTTP_VERSION ),
				),
			);
			if ( defined( 'CURLINFO_POSTTRANSFER_TIME_T' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
				$context['performance']['http']['upload_end_ms'] = curl_getinfo( $ch, CURLINFO_POSTTRANSFER_TIME_T ) / 1000; }
			if ( $retry_after ) {
				$context['retry_after'] = $retry_after; }
			do_action( 'csm_request_complete', $action, $context, $bytes );
			unset( $options, $ch );
			$parsed = json_decode( $response, true );
			// Hosts can reject an upload before its body completes; retain their throttle response.
			self::throttle( $peer['url'], $status, max( $retry_after, (int) ( $parsed['retry_after'] ?? 0 ) ) );
			if ( 'bundle' === $action && ( false === $ok || in_array( $status, array( 502, 503, 504 ), true ) ) && ! empty( $data['id'] ) ) {
				// Last observed work is evidence of progress, not proof that this file caused the error.
				try {
					$last = self::request( $peer, 'progress', array( 'id' => $data['id'] ), null, null, 5 );
					CSM_Log::write(
						'error',
						'Last observed source activity after packaging failure',
						$context +
						array_intersect_key( $last, array_flip( array( 'run', 'phase', 'component', 'count', 'total', 'unit' ) ) ) +
						array(
							'request_run'              => $data['progress_run'] ?? null,
							'progress_matches_request' => isset( $data['progress_run'] ) && ( $last['run'] ?? null ) === $data['progress_run'],
							'progress_age_seconds'     => isset( $last['updated'] ) ? max(
								0,
								round( microtime( true ) - $last['updated'], 1 )
							) : null,
						)
					);
				} catch ( Throwable $probe ) {
					CSM_Log::write( 'error', 'Source activity unavailable after packaging failure', $context + array( 'error' => $probe->getMessage() ) ); }
			}
			if ( $upload_error ) {
				CSM_Log::write( 'error', 'Source stream failed', $context + array( 'error' => $upload_error->getMessage() ) );
				throw $upload_error; }
			// A bounded download that made progress is a normal checkpoint, not a failed migration.
			if ( false === $ok && $download && in_array( $action, array( 'bundle_stream', 'live_files' ), true ) && 200 === $status && CURLE_OPERATION_TIMEDOUT === $context['curl_errno'] && $bytes > 0 ) {
				CSM_Log::write( 'info', 'Stream window checkpointed', $context + array( 'bytes' => $bytes ) );
				return array(
					'bytes'    => $bytes,
					'continue' => true,
				);
			}
			if ( false === $ok ) {
				CSM_Log::write( 'error', 'Outgoing connection failed', $context + array( 'error' => $error ) );
				$class = in_array( $context['curl_errno'], array( 7, 18, 28, 35, 52, 55, 56, 92 ), true ) ? CSM_Network_Error::class : RuntimeException::class;
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new $class( 'Connection failed: ' . $error . ( ( 60 === $context['curl_errno'] ) ? '. The sending site could not verify the remote certificate chain. Check its CA bundle; TLS verification is enabled. See Diagnostics below.' : '' ) );
			}
			if ( $download && 200 === $status ) {
				CSM_Log::write( 'info', 'Stream downloaded', $context + array( 'bytes' => $bytes ) );
				return array( 'bytes' => $bytes ); }
			$parsed = json_decode( $response, true );
			if ( 200 !== $status || ! is_array( $parsed ) || empty( $parsed['ok'] ) ) {
				CSM_Log::write( 'error', 'Remote request rejected', $context + array( 'error' => $parsed['error'] ?? 'Unexpected or non-JSON response; response body omitted.' ) );
				$message = 401 === $status ? 'The remote site requires HTTP Basic Auth. Check its site-protection username and password in Update connection.' : ( $parsed['error'] ?? ( 'Endpoint returned HTTP ' . $status . '. Check firewall rules, redirects and upload/time limits.' ) );
				// Older sources returned an unclassified lock error while a disconnected build kept running.
				$busy = 429 !== $status && ( ! empty( $parsed['busy'] ) || ( 'bundle' === $action && in_array( $message, array( 'Another transfer is running on this site.', 'Source stream is still being built. Retry preparation shortly.' ), true ) ) );
				if ( $busy || ! empty( $parsed['retryable'] ) || in_array( $status, array( 429, 502, 503, 504 ), true ) ) {
					$e              = $busy ? new CSM_Busy_Error( $message ) : new CSM_Network_Error( $message );
					$e->retry_after = max( $e->retry_after, $retry_after, (int) ( $parsed['retry_after'] ?? 0 ), 429 === $status ? 30 : 0 );
					if ( $retry_after > 0 || ( ! $busy && $e->retry_after > 0 ) ) {
						update_option( 429 === $status ? $cooldown : $operation_cooldown, time() + $e->retry_after, false ); }
					throw $e;
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( $message );
			}
			if ( ! in_array( $action, array( 'progress', 'status' ), true ) ) {
				CSM_Log::write( 'info', 'Outgoing request completed', $context ); }
			// Preserve work already started: the next request reuses this bounded window instead of producing it twice.
			if ( $idle_started ) {
				CSM_Support::check_cancel();
				$work = hrtime( true );
				try {
					while ( $idle->valid() ) {
								$idle->next(); }
				} catch ( Throwable $error ) {
						CSM_Log::write( 'info', 'Source look-ahead deferred', array( 'error' => $error->getMessage() ) ); } finally {
					CSM_Timing::add( 'stream.lookahead', $work ); }
						CSM_Support::check_cancel();
			}
			return $parsed['data'] ?? array();
	}

	/**
	 * Wait for one upload response while advancing bounded local look-ahead.
	 *
	 * Look-ahead prepares local bytes only; it does not open another peer connection.
	 * A speculative failure must not replace a successful upload response.
	 *
	 * @param CurlHandle $ch Native cURL handle for the one upload connection.
	 * @param Generator  $idle Generator for bounded local preparation during successful upload response waits.
	 * @param bool       &$uploaded Updated by the upload callback once the request body has been supplied.
	 * @param bool       &$started Whether the speculative generator has already been rewound.
	 *
	 * @return bool Whether the native cURL transfer completed successfully.
	 *
	 * @throws RuntimeException If the native cURL multi connection cannot advance.
	 */
	private static function send_with_idle( $ch, Generator $idle, bool &$uploaded, bool &$started ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_init -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
		$multi = curl_multi_init();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
		curl_multi_add_handle( $multi, $ch );
		$done        = false;
		$uploaded_at = null;
		try {
			do {
				do {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_exec -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
					$result = curl_multi_exec( $multi, $running );
				} while ( CURLM_CALL_MULTI_PERFORM === $result );
				if ( CURLM_OK !== $result ) {
					throw new RuntimeException( 'Cannot advance upload connection.' ); }
				if ( ! $running ) {
					break; }
				if ( $uploaded && null === $uploaded_at ) {
					$uploaded_at = hrtime( true ); }
				// Avoid speculative work for immediate replies; never delay socket processing by more than one frame.
				if ( null !== $uploaded_at && ! $done && hrtime( true ) - $uploaded_at >= 100000000 ) {
					CSM_Support::check_cancel();
					$work = hrtime( true );
					try {
						if ( ! $started ) {
							$idle->rewind();
							$started = true;
						} else {
							$idle->next(); }
						$done = ! $idle->valid();
					} catch ( Throwable $error ) {
						$done = true;
						CSM_Log::write( 'info', 'Source look-ahead deferred', array( 'error' => $error->getMessage() ) );
					} finally {
						CSM_Timing::add( 'stream.lookahead', $work ); }
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_select -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
				if ( curl_multi_select( $multi, .01 ) === -1 ) {
					usleep( 1000 ); }
			} while ( $running );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_info_read -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
			$info = curl_multi_info_read( $multi );
			return $info && CURLE_OK === $info['result'];
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
			curl_multi_remove_handle( $multi, $ch );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_close -- Native cURL preserves streaming callbacks, cancellation and shared request pacing with verified TLS.
			curl_multi_close( $multi ); }
	}
}
