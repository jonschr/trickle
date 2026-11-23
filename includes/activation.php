<?php
/**
 * Source plugin activation selection, application and recovery.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Source plugin activation selection, application and recovery.
 */
final class CSM_Activation {
	/**
	 * Validate and deduplicate relative plugin entry-point filenames.
	 *
	 * @param array $plugins Relative plugin entry-point filenames.
	 *
	 * @return array Unique relative plugin entry-point filenames.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	private static function validate( $plugins ): array {
		if ( ! is_array( $plugins ) ) {
			throw new RuntimeException( 'Invalid source plugin activation list.' ); }
		foreach ( $plugins as $plugin ) {
			if ( ! is_string( $plugin ) || ! str_ends_with( $plugin, '.php' ) ) {
				throw new RuntimeException( 'Invalid active plugin filename.' ); }
			CSM_Files::safe_relative( $plugin );
		}
		return array_values( array_unique( $plugins ) );
	}

	/**
	 * Read the real active-plugin list outside the migration isolation filter.
	 *
	 * @return array Actual destination active-plugin filenames.
	 */
	public static function current(): array {
		$filter = $GLOBALS['_csm_active_plugins_filter'] ?? null;
		if ( ! $filter && defined( 'CSM_ORIGINAL_ACTIVE_PLUGINS' ) ) {
			return self::validate( CSM_ORIGINAL_ACTIVE_PLUGINS ); }
		if ( $filter ) {
			remove_filter( 'option_active_plugins', $filter, PHP_INT_MAX ); }
		try {
			return self::validate( get_option( 'active_plugins', array() ) ); } finally {
			if ( $filter ) {
				add_filter( 'option_active_plugins', $filter, PHP_INT_MAX ); }
			}
	}

	/**
	 * Validate and default the active-plugin synchronization preference.
	 *
	 * @param array $selection Approved component selection and activation preferences.
	 *
	 * @return array Selection with a validated synchronization preference.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	public static function selection( array $selection ): array {
		if ( isset( $selection['sync_active_plugins'] ) && ! is_bool( $selection['sync_active_plugins'] ) ) {
			throw new RuntimeException( 'Plugin activation setting must be true or false.' ); }
		$selection['sync_active_plugins'] = $selection['sync_active_plugins'] ?? true;
		return $selection;
	}

	/**
	 * Capture the approved source active-plugin list in the destination job.
	 *
	 * @param array &$job Destination transfer record, including recovery state.
	 * @param array $header Verified source stream metadata.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	public static function source( array &$job, array $header ): void {
		if ( empty( $job['selection']['sync_active_plugins'] ) || ! array_key_exists( 'active_plugins', $header ) ) {
			return; }
		$plugins    = self::validate( $header['active_plugins'] );
		$own        = plugin_basename( CSM_FILE );
		$source_own = $header['trickle_plugin'] ?? $own;
		$plugins    = array_values( array_unique( array_map( static fn( $plugin ) => $plugin === $source_own ? $own : $plugin, $plugins ) ) );
		if ( ! in_array( $own, $plugins, true ) ) {
			$plugins[] = $own; }
		if ( isset( $job['source_active_plugins'] ) && $job['source_active_plugins'] !== $plugins ) {
			throw new RuntimeException( 'Source plugin activation changed between streams. Start the transfer again.' ); }
		$job['source_active_plugins'] = $plugins;
	}

	/**
	 * Record destination activation state before changing installed plugins.
	 *
	 * @param array &$job Destination transfer record, including recovery state.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	public static function capture( array &$job ): void {
		if ( empty( $job['selection']['sync_active_plugins'] ) ) {
			return; }
		if ( ! isset( $job['source_active_plugins'] ) ) {
			throw new RuntimeException( 'Source plugin activation is unavailable. Update both sites and start the transfer again.' ); }
		$job['active_plugins_before'] = self::current();
	}

	/**
	 * Apply the source active-plugin list while retaining Trickle itself.
	 *
	 * @param array &$job Destination transfer record, including recovery state.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	public static function apply( array &$job ): void {
		if ( ! isset( $job['active_plugins_before'] ) ) {
			return; }
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_clean_plugins_cache( false );
		$installed = get_plugins();
		$active    = array();
		$missing   = array();
		foreach ( $job['source_active_plugins'] as $plugin ) {
			if ( ! isset( $installed[ $plugin ] ) ) {
				$missing[] = $plugin;
				continue; }
			$active[] = $plugin;
		}
		// Dependency checks must see the complete proposed set, rather than the isolated RPC list.
		$proposed = static fn() => $active;
		add_filter( 'pre_option_active_plugins', $proposed, PHP_INT_MAX );
		try {
			foreach ( $active as $index => $plugin ) {
				CSM_Progress::update(
					array(
						'phase'     => 'Checking plugin activation',
						'component' => $plugin,
						'unit'      => 'plugins',
						'count'     => $index,
						'total'     => count( $active ),
					)
				);
				$valid = validate_plugin_requirements( $plugin );
				if ( is_wp_error( $valid ) ) {
					throw new RuntimeException( 'Cannot activate ' . $plugin . ': ' . wp_strip_all_tags( $valid->get_error_message() ) ); }
			}
		} finally {
			remove_filter( 'pre_option_active_plugins', $proposed, PHP_INT_MAX ); }
		$job['activation_missing'] = $missing;
		CSM_Jobs::save( $job );
		CSM_Progress::update(
			array(
				'phase'     => 'Updating plugin activation',
				'component' => '',
				'unit'      => 'plugins',
				'count'     => 0,
				'total'     => count( $active ),
			),
			true
		);
		self::store( $active );
		foreach ( $missing as $plugin ) {
			CSM_Log::write(
				'info',
				'Source plugin unavailable on destination',
				array(
					'component'   => $plugin,
					'transfer_id' => $job['id'],
				)
			); }
	}

	/**
	 * Restore the destination active-plugin list from its recovery record.
	 *
	 * @param array $job Destination transfer record, including recovery state.
	 */
	public static function restore( array $job ): void {
		if ( isset( $job['active_plugins_before'] ) ) {
			self::store( $job['active_plugins_before'] ); }
	}

	/**
	 * Persist the active-plugin list and refresh the corresponding option cache.
	 *
	 * @param array $plugins Relative plugin entry-point filenames.
	 *
	 * @throws RuntimeException If validation or the protected operation fails.
	 */
	private static function store( array $plugins ): void {
		$plugins = self::validate( $plugins );
		$own     = plugin_basename( CSM_FILE );
		if ( ! in_array( $own, $plugins, true ) ) {
			$plugins[] = $own; }
		$filter = $GLOBALS['_csm_active_plugins_filter'] ?? null;
		if ( $filter ) {
			remove_filter( 'option_active_plugins', $filter, PHP_INT_MAX ); }
		try {
			update_option( 'active_plugins', $plugins );
			if ( get_option( 'active_plugins' ) !== $plugins ) {
				throw new RuntimeException( 'The destination could not save plugin activation.' ); }
		} finally {
			if ( $filter ) {
				add_filter( 'option_active_plugins', $filter, PHP_INT_MAX ); }
		}
	}
}
