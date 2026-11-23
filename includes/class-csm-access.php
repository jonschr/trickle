<?php
/**
 * Scoped connection credentials and migration authorization.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/** Separately revocable, scoped connection keys; the original administrator key remains compatible. */
final class CSM_Access {
	/**
	 * Return the site-wide permissions for remote reads and writes.
	 *
	 * @return array Remote pull and push permission flags.
	 */
	public static function settings(): array {
		return get_option(
			'_csm_settings',
			array(
				'pull' => true,
				'push' => true,
			)
		); }

	/**
	 * Derive the public identifier for a connection key without exposing the key.
	 *
	 * @param string $key Raw connection secret used only to derive its public identifier.
	 *
	 * @return string Twenty-four-character public credential identifier.
	 */
	public static function id( string $key ): string {
		return substr( hash( 'sha256', $key ), 0, 24 ); }

	/**
	 * List scoped keys with credential bytes removed.
	 *
	 * @return array Scoped key metadata without key bytes.
	 */
	public static function list(): array {
		return array_values( array_map( static fn( $token ) => array_intersect_key( $token, array_flip( array( 'id', 'name', 'read', 'write', 'expires' ) ) ), get_option( '_csm_tokens', array() ) ) );
	}

	/**
	 * Issue a revocable key with bounded lifetime and explicit read/write scope.
	 *
	 * @param string $name Human-readable label for the new connection.
	 * @param bool   $read Whether the new connection may read from this site.
	 * @param bool   $write Whether the new connection may modify this site.
	 * @param int    $days Connection lifetime in days, between 1 and 90.
	 *
	 * @return array New pairing code, identifier and redacted key list.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	public static function issue( string $name, bool $read, bool $write, int $days ): array {
		if ( ! $read && ! $write ) {
			throw new RuntimeException( 'A connection needs read or write access.' ); }
		if ( $days < 1 || $days > 90 ) {
			throw new RuntimeException( 'Choose a key lifetime from 1 to 90 days.' ); }
		$tokens = get_option( '_csm_tokens', array() );
		if ( count( $tokens ) >= 20 ) {
			throw new RuntimeException( 'Revoke an unused connection key before creating another (20 maximum).' ); }
		$label         = substr( sanitize_text_field( $name ), 0, 80 );
		$key           = bin2hex( random_bytes( 32 ) );
		$id            = self::id( $key );
		$tokens[ $id ] = array(
			'id'      => $id,
			'key'     => $key,
			'name'    => $label ? $label : 'Connection',
			'read'    => $read,
			'write'   => $write,
			'expires' => time() + $days * DAY_IN_SECONDS,
		);
		update_option( '_csm_tokens', $tokens, false );
		return array(
			'id'      => $id,
			'pairing' => site_url() . "\n" . $key,
			'keys'    => self::list(),
		);
	}

	/**
	 * Revoke one scoped connection key.
	 *
	 * @param string $id Scoped credential identifier.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	public static function revoke( string $id ): void {
		$tokens = get_option( '_csm_tokens', array() );
		if ( ! isset( $tokens[ $id ] ) ) {
			throw new RuntimeException( 'Connection key not found.' ); }
		unset( $tokens[ $id ] );
		update_option( '_csm_tokens', $tokens, false );
	}

	/**
	 * Resolve and validate a scoped key or the administrator key.
	 *
	 * @param string $id Scoped credential identifier.
	 *
	 * @return array Validated credential, including private key and access flags.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	public static function credential( string $id ): array {
		$master = (string) get_option( '_csm_key' );
		if ( $master && ( '' === $id || hash_equals( self::id( $master ), $id ) ) ) {
			return array(
				'id'     => self::id( $master ),
				'key'    => $master,
				'read'   => true,
				'write'  => true,
				'master' => true,
			); }
		$token = get_option( '_csm_tokens', array() )[ $id ] ?? null;
		if ( ! $token || $token['expires'] <= time() ) {
			throw new RuntimeException( 'Connection key expired or was revoked. Ask the remote administrator for a new code.', 403 ); }
		return $token + array( 'master' => false );
	}

	/**
	 * Require the connection scope and site settings needed by an action.
	 *
	 * @param array  $token Resolved connection credential and its access scope.
	 * @param string $action Signed peer operation being authorized.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	public static function authorize( array $token, string $action ): void {
		if ( 'cancel' === $action && ( ! empty( $token['read'] ) || ! empty( $token['write'] ) ) ) {
			$GLOBALS['_csm_credential'] = $token;
			return; }
		if ( in_array( $action, array( 'update_trickle', 'update_trickle_build', 'update_trickle_zip' ), true ) ) {
			if ( empty( $token['write'] ) ) {
				throw new RuntimeException( 'Use an administrator or read/write connection key to update Trickle.', 403 ); }
			$GLOBALS['_csm_credential'] = $token;
			return;
		}
		$reads    = array( 'live_files', 'export', 'manifest', 'media', 'media_hashes', 'file_policy', 'component_hashes', 'component_manifest', 'media_manifest', 'bundle', 'bundle_stream', 'bundle_chunk', 'bundle_discard', 'estimates' );
		$status   = array( 'live_state', 'backups', 'version', 'update_info', 'build_info', 'progress', 'status', 'preview', 'segment_state', 'cancel' );
		$settings = self::settings();
		$read     = ! empty( $settings['pull'] ) && ! empty( $token['read'] );
		$write    = ! empty( $settings['push'] ) && ! empty( $token['write'] );
		if ( in_array( $action, array( 'version', 'update_info', 'build_info' ), true ) && ! empty( $token['write'] ) ) {
			$GLOBALS['_csm_credential'] = $token;
			return; }
		if ( in_array( $action, $reads, true ) ? ! $read : ( in_array( $action, $status, true ) ? ! $read && ! $write : ! $write ) ) {
			throw new RuntimeException( 'This connection does not permit ' . ( in_array( $action, $reads, true ) ? 'remote reads' : 'this operation' ) . '. Check the key scope and remote access settings.', 403 );
		}
		$GLOBALS['_csm_credential'] = $token;
	}

	/**
	 * Return the authenticated connection identifier, or the local owner.
	 *
	 * @return string Authenticated credential identifier, or "local".
	 */
	public static function owner(): string {
		return $GLOBALS['_csm_credential']['id'] ?? 'local'; }

	/**
	 * Reject access to a job owned by a different scoped connection.
	 *
	 * @param array $record Persisted record whose connection ownership must be checked.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	public static function assert_owner( array $record ): void {
		if ( isset( $record['credential'] ) && self::owner() !== $record['credential'] && empty( $GLOBALS['_csm_credential']['master'] ) ) {
			throw new RuntimeException( 'This transfer belongs to another connection key. Use its original key or the site administrator connection.', 403 ); }
	}
}
