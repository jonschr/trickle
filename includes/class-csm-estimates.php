<?php
/**
 * Selection size estimates and migration work counters.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/** Cached allocation estimates: metadata only, never read or hash file contents. */
final class CSM_Estimates {
	/**
	 * Read or advance the bounded component and media size-estimate inventory.
	 *
	 * @param bool $advance Whether to advance the resumable estimate cursor during this read.
	 * @param bool $refresh Whether to bypass or refresh reusable inventory state.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function read( bool $advance = false, bool $refresh = false ): array {
		$scope = hash( 'sha256', CSM_Support::json( array( CSM_Files::root( 'plugins' ), CSM_Files::root( 'themes' ), CSM_Files::root( 'uploads' ) ) ) );
		$path  = CSM_Support::private_dir() . '/size-estimates-' . substr( $scope, 0, 16 ) . '.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
		$lock = fopen( $path . '.lock', 'c' );
		if ( ! $lock ) {
			throw new RuntimeException( 'Cannot open size estimate cache.' ); }
		$locked = flock( $lock, LOCK_EX | LOCK_NB );
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
			$state = is_file( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
			if ( ! is_array( $state ) || ( $state['version'] ?? 0 ) !== 2 || ! isset( $state['queue'], $state['bytes'], $state['items'], $state['complete'], $state['updated'] ) ) {
				$state = null; }
			if ( $locked && ( ! $state || $refresh || ( $state['complete'] && $state['updated'] < time() - 300 ) ) ) {
				$previous = ! empty( $state['complete'] ) ? array_intersect_key( $state, array_flip( array( 'updated', 'complete', 'bytes', 'items', 'unreadable' ) ) ) : ( $state['previous'] ?? null );
				$queue    = array();
				foreach ( array( 'plugins', 'themes' ) as $kind ) {
					$root = CSM_Files::root( $kind );
					if ( ! is_dir( $root ) ) {
						continue; }
					foreach ( new FilesystemIterator( $root ) as $entry ) {
						if ( $entry->isLink() || CSM_Files::excluded( $entry->getFilename() ) || ( 'plugins' === $kind && $entry->getFilename() === basename( dirname( CSM_FILE ) ) ) ) {
							continue; }
						$queue[] = array( $entry->getPathname(), $kind, $entry->getFilename(), $kind . '/' . $entry->getFilename() );
					}
				}
				$queue[] = array( CSM_Files::root( 'uploads' ), 'media', '', 'uploads' );
				$state   = array(
					'version'    => 2,
					'updated'    => time(),
					'complete'   => false,
					'bytes'      => array(
						'database' => 0,
						'plugins'  => 0,
						'themes'   => 0,
						'media'    => 0,
					),
					'items'      => array(
						'plugins' => array(),
						'themes'  => array(),
						'media'   => array(),
					),
					'queue'      => $queue,
					'active'     => null,
					'unreadable' => 0,
				);
				if ( $previous ) {
					$state['previous'] = $previous; }
			}
			if ( $locked && $advance && ! $state['complete'] ) {
				$deadline = hrtime( true ) / 1e9 + 1;
				$limit    = 20000;
				$count    = 0;
				do {
					if ( ! $state['active'] ) {
						$next = array_pop( $state['queue'] );
						if ( ! $next ) {
							$state['complete'] = true;
							break; }
						[$file, $kind, $component, $relative] = $next;
						if ( ! file_exists( $file ) || is_link( $file ) || self::excluded( $relative, $kind ) ) {
							continue; }
						if ( is_file( $file ) ) {
							self::add( $state, $kind, $component, filesize( $file ), $relative );
							continue; }
						$state['active'] = array(
							'entry'  => $next,
							'offset' => 0,
						);
					}
					[$dir, $kind, $component, $relative] = $state['active']['entry'];
					try {
						$iterator = new DirectoryIterator( $dir );
						$iterator->seek( $state['active']['offset'] ); } catch ( Throwable $e ) {
						++$state['unreadable'];
						$state['active'] = null;
						continue; }
						while ( $iterator->valid() ) {
							$entry  = $iterator->current();
							$offset = $iterator->key() + 1;
							if ( ! $entry->isDot() && ! $entry->isLink() && ! self::excluded( $relative . '/' . $entry->getFilename(), $kind ) ) {
								if ( $entry->isDir() ) {
									$state['queue'][] = array( $entry->getPathname(), $kind, $component, $relative . '/' . $entry->getFilename() ); } elseif ( $entry->isFile() ) {
																self::add( $state, $kind, $component, $entry->getSize(), $relative . '/' . $entry->getFilename() ); }
							}
							$state['active']['offset'] = $offset;
							$iterator->next();
							++$count;
							if ( $count >= $limit || hrtime( true ) / 1e9 >= $deadline ) {
								break; }
						}
						if ( ! $iterator->valid() ) {
							$state['active'] = null; }
				} while ( $count < $limit && hrtime( true ) / 1e9 < $deadline );
				if ( $state['complete'] ) {
					unset( $state['previous'] ); }
				$state['updated'] = time();
				CSM_Support::atomic_json( $path, $state );
			}
			$result = array_intersect_key( $state ?? array(), array_flip( array( 'updated', 'complete', 'bytes', 'items', 'unreadable' ) ) );
			if ( empty( $state['complete'] ) && ! empty( $state['previous'] ) ) {
				$result = $state['previous'] + array( 'refreshing' => true ); }
			$result['bytes']['database'] = array_sum( array_column( CSM_Database::inventory(), 'bytes' ) );
			return $result;
		} finally {
			if ( $locked ) {
				flock( $lock, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			} fclose( $lock ); }
	}

	/**
	 * Check whether a filesystem entry is excluded from the estimate for its kind.
	 *
	 * @param string $path Filesystem path checked or operated on by this helper.
	 * @param string $kind Component or stream kind selected by the caller.
	 */
	private static function excluded( string $path, string $kind ): bool {
		if ( CSM_Files::excluded( $path ) ) {
			return true; }
		return 'media' === $kind && ( preg_match( '#(^|/)(cache|backups?|ai1wm-backups|wpvividbackups)(/|$)#i', $path ) || str_starts_with( basename( $path ), '.' ) || preg_match( '/\.(php\d*|phtml|phar|cgi|pl|sh|htaccess|user\.ini)$/i', $path ) );
	}

	/**
	 * Accumulate a selected entry's bytes in component and aggregate estimate counters.
	 *
	 * @param array  &$state Resumable work state; updated in place when passed by reference.
	 * @param string $kind Component or stream kind selected by the caller.
	 * @param string $component Plugin or theme directory identifier, when applicable.
	 * @param int    $bytes Logical byte payload or count for this operation.
	 * @param string $relative Content-relative path subject to exclusion and link policies.
	 */
	private static function add( array &$state, string $kind, string $component, int $bytes, string $relative ): void {
		$state['bytes'][ $kind ] += $bytes;
		if ( 'media' === $kind ) {
			$parts     = explode( '/', $relative, 3 );
			$component = isset( $parts[2] ) && preg_match( '/^[0-9]{4}$/D', $parts[1] ) ? $parts[1] : '_other';
		}
		if ( '' !== $component ) {
			$state['items'][ $kind ][ $component ] = ( $state['items'][ $kind ][ $component ] ?? 0 ) + $bytes; }
	}
}
