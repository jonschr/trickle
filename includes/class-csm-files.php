<?php
/**
 * Safe file inventory, comparison, staging and installation.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Safe file inventory, comparison, staging and installation.
 */
final class CSM_Files {
	/**
	 * Identify relative paths that must never be transferred.
	 *
	 * @param string $relative Content-relative path subject to exclusion and link policies.
	 */
	public static function excluded( string $relative ): bool {
		if ( preg_match( '/^\.trickle-[a-f0-9]{32}-[a-f0-9]{20}\.part$/D', basename( $relative ) ) ) {
			return true; }
		foreach ( explode( '/', strtolower( $relative ) ) as $part ) {
			if ( in_array( $part, array( '.git', '.gitignore', '.gitattributes', '.gitmodules', '.gitkeep', '.github', '.svn', '.hg', '.bzr', 'node_modules', '.idea', '.vscode', '.ds_store', 'thumbs.db', '.ssh', '.aws', '.gnupg', '.docker', '.kube', '.htpasswd', '.my.cnf', '.git-credentials', 'wp-config.php', '.npmrc', '.pypirc', '.netrc', 'auth.json', 'id_rsa', 'id_ed25519', 'id_dsa', 'id_ecdsa' ), true ) || str_starts_with( $part, '.env' ) || preg_match( '/\.env($|[._~-])/', $part ) ) {
				return true; }
		}
		return false;
	}

	/**
	 * Check whether a path lies within any of the selected relative folders.
	 *
	 * @param string $path Filesystem path checked or operated on by this helper.
	 * @param array  $folders Relative directory prefixes used for membership checks.
	 */
	public static function beneath( string $path, array $folders ): bool {
		foreach ( $folders as $folder ) {
			if ( $path === $folder || str_starts_with( $path, rtrim( $folder, '/' ) . '/' ) ) {
				return true; }
		}
		return false;
	}

	/**
	 * Detect Git metadata in a filesystem path or its ancestors.
	 *
	 * @param string $path Filesystem path checked or operated on by this helper.
	 */
	private static function git_ancestor( string $path ): bool {
		while ( true ) {
			if ( file_exists( $path . '/.git' ) || is_link( $path . '/.git' ) ) {
				return true; }
			$parent = dirname( $path );
			if ( $parent === $path ) {
				return false;
			} $path = $parent;
		}
	}

	/**
	 * Inventory selected component folders that require explicit Git-policy approval.
	 *
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 * @param bool  $source Whether the inventory describes source files, allowing approved source links.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function git_folders( array $selection, bool $source = false ): array {
		$result   = array();
		$deadline = hrtime( true ) / 1e9 + 45;
		$scan     = static function ( string $path, string $relative, array $ancestors = array() ) use ( &$scan, &$result, $deadline, $selection, $source ): void {
			if ( hrtime( true ) / 1e9 >= $deadline ) {
				throw new RuntimeException( 'Git folder scan exceeded its 45-second limit. Narrow the selection.' ); }
			CSM_Support::check_source_budget();
			if ( self::excluded( $relative ) || ( ! $source && is_link( $path ) ) ) {
				return; }
			if ( $source && self::source_path( $path, $relative, $selection ) === null ) {
				return; }
			if ( ! is_dir( $path ) ) {
				return; }
			if ( $source && self::git_ancestor( realpath( $path ) ) ) {
				$result[] = $relative;
				return; }
			// A .git file is a worktree/submodule marker, just as a .git directory is a repository marker.
			if ( file_exists( $path . '/.git' ) || is_link( $path . '/.git' ) ) {
				$result[] = $relative; }
			$real = realpath( $path );
			if ( in_array( $real, $ancestors, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Circular source link: ' . $relative ); }
			$ancestors[] = $real;
			foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $file ) {
				if ( $file->isDir() || ( $source && $file->isLink() ) ) {
					$scan( $file->getPathname(), $relative . '/' . $file->getFilename(), $ancestors ); }
			}
		};
		foreach ( array( 'plugins', 'themes' ) as $kind ) {
			foreach ( $selection[ $kind ] ?? array() as $id ) {
				self::safe_relative( $id );
				if ( str_contains( $id, '/' ) ) {
					throw new RuntimeException( 'Invalid component ID.' ); }
				$root = self::root( $kind, $id );
				if ( self::git_ancestor( $root ) ) {
					$result[] = $kind; }
				$scan( $root . '/' . $id, $kind . '/' . $id );
			}
		}
		if ( ( $selection['media'] ?? 'none' ) !== 'none' ) {
			if ( self::git_ancestor( self::root( 'uploads' ) ) ) {
				$result[] = 'uploads';
			} $scan( self::root( 'uploads' ), 'uploads' ); }
		$result = array_values( array_unique( $result ) );
		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Apply exclusion and per-selection skip-path rules.
	 *
	 * @param string $relative Content-relative path subject to exclusion and link policies.
	 * @param array  $selection Approved database, component and media selection with transfer policies.
	 */
	public static function skipped( string $relative, array $selection ): bool {
		return self::excluded( $relative ) || self::beneath( $relative, $selection['skip_paths'] ?? array() );
	}

	/**
	 * Require explicit approval before replacing a destination Git-backed component.
	 *
	 * @param string $relative Content-relative path subject to exclusion and link policies.
	 * @param string $path Filesystem path checked or operated on by this helper.
	 * @param string $root Allowed filesystem root for the component.
	 * @param array  $selection Approved database, component and media selection with transfer policies.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function assert_git_destination( string $relative, string $path, string $root, array $selection ): void {
		$kind = explode( '/', $relative )[0];
		if ( self::git_ancestor( dirname( $root ) ) && ! in_array( $kind, $selection['git_overrides'] ?? array(), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Git working directory protected: ' . $kind . '. Review and explicitly include it before transferring.' ); }
		$walk = is_dir( $path ) ? $path : dirname( $path );
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The ancestor path changes on each step; its current length bounds traversal.
		while ( strlen( $walk ) >= strlen( $root ) ) {
			if ( file_exists( $walk . '/.git' ) || is_link( $walk . '/.git' ) ) {
				$folder = explode( '/', $relative )[0] . ( $walk === $root ? '' : '/' . substr( $walk, strlen( $root ) + 1 ) );
				if ( ! in_array( $folder, $selection['git_overrides'] ?? array(), true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( 'Git working directory protected: ' . $folder . '. Review and explicitly include it before transferring.' ); }
			}
			if ( $walk === $root ) {
				break; }
			$walk = dirname( $walk );
		}
	}

	/**
	 * Check whether this source link belongs to an explicitly approved component.
	 *
	 * @param string $relative Content-relative path subject to exclusion and link policies.
	 * @param array  $selection Approved database, component and media selection with transfer policies.
	 */
	public static function link_approved( string $relative, array $selection ): bool {
		$parts = explode( '/', $relative );
		return in_array( ( $parts[0] ?? '' ) . '/' . ( $parts[1] ?? '' ), $selection['follow_symlinks'] ?? array(), true );
	}

	/**
	 * Resolve an approved source path without allowing link escapes or protected targets.
	 *
	 * @param string $path Filesystem path checked or operated on by this helper.
	 * @param string $relative Content-relative path subject to exclusion and link policies.
	 * @param array  $selection Approved database, component and media selection with transfer policies.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function source_path( string $path, string $relative, array $selection ): ?string {
		if ( ! self::link_approved( $relative, $selection ) ) {
			if ( is_link( $path ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Source symlink needs approval: ' . $relative ); }
			return $path;
		}
		[$kind, $id] = explode( '/', $relative, 3 );
		if ( ! in_array( $kind, array( 'plugins', 'themes' ), true ) ) {
			throw new RuntimeException( 'Only plugin and theme source links can be followed.' ); }
		clearstatcache( true, $path );
		$resolved = realpath( $path );
		if ( false === $resolved ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Broken or circular source link: ' . $relative ); }
		if ( self::excluded( $resolved ) ) {
			if ( count( explode( '/', $relative ) ) === 2 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Source component resolves into an excluded location: ' . $relative );
			} return null; }
		$base = realpath( self::root( $kind, $id ) . '/' . $id );
		if ( ! $base || ( $resolved !== $base && ! str_starts_with( $resolved, $base . '/' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Source link leaves the approved component: ' . $relative ); }
		if ( ! is_file( $resolved ) && ! is_dir( $resolved ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Unsupported source file: ' . $relative ); }
		return $resolved;
	}

	/**
	 * Explain why a component cannot be transferred under the current path and time limits.
	 *
	 * @param string $path Filesystem path checked or operated on by this helper.
	 * @param string $relative Content-relative path subject to exclusion and link policies.
	 * @param float  $deadline Absolute monotonic traversal deadline, or null to use the current budget.
	 */
	public static function transfer_block_reason( string $path, string $relative, float $deadline ): string {
		if ( self::excluded( $relative ) ) {
			return ''; }
		$component = str_starts_with( $relative, 'plugins/' ) ? 'plugin' : 'theme';
		if ( is_link( $path ) ) {
			return "This $component contains symlinks."; }
		if ( hrtime( true ) / 1e9 >= $deadline ) {
			return 'Symlink check timed out. Refresh to retry.'; }
		if ( ! is_readable( $path ) ) {
			return 'Cannot check unreadable files for symlinks.'; }
		if ( ! is_dir( $path ) ) {
			return ''; }
		try {
			foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $entry ) {
				$reason = self::transfer_block_reason( $entry->getPathname(), $relative . '/' . $entry->getFilename(), $deadline );
				if ( '' !== $reason ) {
					return $reason; }
			}
		} catch ( UnexpectedValueException $error ) {
			return 'Cannot check unreadable folders for symlinks.'; }
		return '';
	}

	/**
	 * Read plugin, theme and optional media inventory with the requested refresh policy.
	 *
	 * @param bool $refresh Whether to bypass or refresh reusable inventory state.
	 * @param bool $media Whether to include media metadata in the inventory.
	 */
	public static function inventory( bool $refresh = false, bool $media = true ): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( $refresh ) {
			wp_update_plugins();
			wp_update_themes(); }
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );
		$active_plugins = defined( 'CSM_ORIGINAL_ACTIVE_PLUGINS' ) ? CSM_ORIGINAL_ACTIVE_PLUGINS : get_option( 'active_plugins', array() );
		$active_themes  = defined( 'CSM_ORIGINAL_TEMPLATE' ) ? array( CSM_ORIGINAL_TEMPLATE, CSM_ORIGINAL_STYLESHEET ) : array( get_template(), get_stylesheet() );
		$plugins        = array();
		$deadline       = hrtime( true ) / 1e9 + 5;
		foreach ( get_plugins() as $file => $data ) {
			$id = str_contains( $file, '/' ) ? explode( '/', $file )[0] : $file;
			if ( isset( $plugins[ $id ] ) ) {
				continue; }
			$icons          = ( $plugin_updates->response[ $file ] ?? $plugin_updates->no_update[ $file ] ?? null )->icons ?? array();
			$plugins[ $id ] = array(
				'id'        => $id,
				'name'      => $data['Name'],
				'version'   => $data['Version'],
				'update'    => $plugin_updates->response[ $file ]->new_version ?? null,
				'icon'      => esc_url_raw( $icons['svg'] ?? $icons['2x'] ?? $icons['1x'] ?? '', array( 'http', 'https' ) ),
				'active'    => in_array( $file, $active_plugins, true ),
				'protected' => basename( dirname( CSM_FILE ) ) === $id,
			);
			$reason         = $plugins[ $id ]['protected'] ? '' : self::transfer_block_reason( WP_PLUGIN_DIR . '/' . $id, 'plugins/' . $id, $deadline );
			if ( '' !== $reason ) {
				$plugins[ $id ]['protected']        = true;
				$plugins[ $id ]['protected_reason'] = $reason;
				$plugins[ $id ]['symlinks']         = str_contains( $reason, 'contains symlinks' ); }
		}
		$themes = array();
		foreach ( wp_get_themes() as $id => $theme ) {
			$screenshot = $theme->get_screenshot();
			$themes[]   = array(
				'id'      => $id,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'update'  => $theme_updates->response[ $id ]['new_version'] ?? null,
				'icon'    => esc_url_raw( $screenshot ? $screenshot : '', array( 'http', 'https' ) ),
				'parent'  => $theme->get( 'Template' ),
				'active'  => in_array( $id, $active_themes, true ),
			);
			$reason     = self::transfer_block_reason( $theme->get_theme_root() . '/' . $id, 'themes/' . $id, $deadline );
			if ( '' !== $reason ) {
				$themes[ array_key_last( $themes ) ] += array(
					'protected'        => true,
					'protected_reason' => $reason,
					'symlinks'         => str_contains( $reason, 'contains symlinks' ),
				); }
		}
		return array(
			'plugins' => array_values( $plugins ),
			'themes'  => $themes,
		) + ( $media ? self::media() : array() );
	}

	/**
	 * Read one bounded page of attachment metadata for the media selector.
	 *
	 * @param int   $page One-based media-selector page.
	 * @param array $ids Optional attachment identifiers limiting the media page.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function media( int $page = 1, array $ids = array() ): array {
		if ( $page < 1 || $page > 100000 || count( $ids ) > 10000 ) {
			throw new RuntimeException( 'Invalid media page or attachment selection.' ); }
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new RuntimeException( 'Invalid attachment ID.' ); }
		}
		$args = array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 60,
			'offset'      => ( $page - 1 ) * 60,
			'orderby'     => 'date ID',
			'order'       => 'DESC',
		);
		if ( $ids ) {
			$args['post__in']    = $ids;
			$args['numberposts'] = count( $ids );
			$args['offset']      = 0; }
		$media = array();
		foreach ( get_posts( $args ) as $post ) {
			$image    = wp_attachment_is_image( $post->ID ) ? wp_get_attachment_image_src( $post->ID, 'thumbnail' ) : false;
			$uploaded = '0000-00-00 00:00:00' !== $post->post_date_gmt ? str_replace( ' ', 'T', $post->post_date_gmt ) . 'Z' : null;
			$media[]  = array(
				'id'       => $post->ID,
				'name'     => $post->post_title,
				'file'     => get_post_meta( $post->ID, '_wp_attached_file', true ),
				'date'     => $post->post_modified_gmt,
				'uploaded' => $uploaded,
				'preview'  => $image ? esc_url_raw( $image[0], array( 'http', 'https' ) ) : '',
				'mime'     => get_post_mime_type( $post ),
			);
		}
		$total = (int) wp_count_posts( 'attachment' )->inherit;
		return array(
			'media'       => $media,
			'media_total' => $total,
			'media_page'  => $page,
			'media_more'  => ! $ids && $page * 60 < $total,
		);
	}

	/**
	 * Validate and normalize the approved file-transfer selection.
	 *
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function selection( array $selection ): array {
		$links = $selection['follow_symlinks'] ?? array();
		if ( ! is_array( $links ) || ! array_is_list( $links ) || count( $links ) > 500 ) {
			throw new RuntimeException( 'Invalid source symlink approvals.' ); }
		foreach ( $links as $component ) {
			if ( ! is_string( $component ) || ! preg_match( '#^(plugins|themes)/[^/]+$#D', $component ) ) {
				throw new RuntimeException( 'Invalid source symlink approval.' ); }
			[$kind, $id] = explode( '/', $component );
			self::safe_relative( $id );
			if ( ! in_array( $id, $selection[ $kind ] ?? array(), true ) || ( 'plugins' === $kind && basename( dirname( CSM_FILE ) ) === $id ) ) {
				throw new RuntimeException( 'Symlink approval is outside the selected components.' ); }
		}
		$inventory = self::inventory( false, false );
		foreach ( array( 'plugins', 'themes' ) as $kind ) {
			$allowed            = array_column( $inventory[ $kind ], null, 'id' );
			$selection[ $kind ] = array_values( array_unique( $selection[ $kind ] ?? array() ) );
			foreach ( $selection[ $kind ] as $id ) {
				if ( ! isset( $allowed[ $id ] ) || ( ! empty( $allowed[ $id ]['protected'] ) && ! ( self::link_approved( $kind . '/' . $id, $selection ) && ! empty( $allowed[ $id ]['symlinks'] ) ) ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( ( $allowed[ $id ]['protected_reason'] ?? 'Unknown or protected ' . $kind . ' selection.' ) . ' (' . $id . ')' ); }
			}
		}
		return self::media_selection( $selection );
	}

	/**
	 * Check whether the current media selection requires destination comparison.
	 *
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 */
	public static function compare_media( array $selection ): bool {
		return in_array( $selection['media'] ?? 'none', array( 'all', 'compare' ), true ) && empty( $selection['media_force'] );
	}

	/**
	 * Resolve media-selection rules into their normalized representation.
	 *
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function media_selection( array $selection ): array {
		$mode = $selection['media'] ?? 'none';
		if ( ! in_array( $mode, array( 'none', 'all', 'changed', 'selected', 'uploaded', 'compare' ), true ) ) {
			throw new RuntimeException( 'Unknown media selection.' ); }
		$selection['media'] = $mode;
		foreach ( array( 'file_force', 'plugins_force', 'themes_force' ) as $flag ) {
			if ( isset( $selection[ $flag ] ) && ! is_bool( $selection[ $flag ] ) ) {
				throw new RuntimeException( 'Force file retransfer must be true or false.' ); }
		}
		if ( isset( $selection['media_force'] ) && ! is_bool( $selection['media_force'] ) ) {
			throw new RuntimeException( 'Force media retransfer must be true or false.' ); }
		$selection['media_force'] = in_array( $mode, array( 'all', 'compare' ), true ) && ! empty( $selection['media_force'] );
		unset( $selection['media_verify'], $selection['file_verify'] );
		$selection['since'] = max( 0, (int) ( $selection['since'] ?? 0 ) );
		if ( 'changed' === $mode && ! $selection['since'] ) {
			throw new RuntimeException( 'Choose a starting date or complete an initial media transfer first.' ); }
		$selection['media_ids'] = array_values( array_unique( array_map( 'intval', $selection['media_ids'] ?? array() ) ) );
		foreach ( array( 'media_after', 'media_before' ) as $field ) {
			$date = $selection[ $field ] ?? '';
			if ( ! is_string( $date ) ) {
				throw new RuntimeException( 'Upload dates must use YYYY-MM-DD.' ); }
			if ( '' !== $date ) {
				$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
				if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
					throw new RuntimeException( 'Upload dates must use valid YYYY-MM-DD dates.' ); }
			}
			$selection[ $field ] = $date;
		}
		if ( 'uploaded' === $mode ) {
			if ( '' === $selection['media_after'] && '' === $selection['media_before'] ) {
				throw new RuntimeException( 'Choose at least one upload-date boundary.' ); }
			if ( $selection['media_after'] && $selection['media_before'] && $selection['media_after'] > $selection['media_before'] ) {
				throw new RuntimeException( 'The upload end date must be on or after the start date.' ); }
		}
		return $selection;
	}

	/**
	 * Yield attachment identifiers matching the approved media filters.
	 *
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 */
	private static function attachment_ids( array $selection ): Generator {
		if ( 'selected' === $selection['media'] ) {
			yield from $selection['media_ids'];
			return; }
		$query = array(
			'column'    => 'post_date_gmt',
			'inclusive' => true,
		);
		if ( $selection['media_after'] ) {
			$query['after'] = $selection['media_after'] . ' 00:00:00'; }
		if ( $selection['media_before'] ) {
			$query['before'] = $selection['media_before'] . ' 23:59:59'; }
		$offset = 0;
		do {
			$ids = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- Fetch only attachment IDs in bounded 250-row pages, not an unbounded content query.
					'numberposts' => 250,
					'offset'      => $offset,
					'fields'      => 'ids',
					'date_query'  => array( $query ),
					'orderby'     => 'ID',
					'order'       => 'ASC',
				)
			);
			yield from $ids;
			$offset += count( $ids );
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- Each fetched page replaces this batch; its size determines whether another page is needed.
		} while ( count( $ids ) === 250 );
	}

	/**
	 * Resolve the WordPress content root for a component kind.
	 *
	 * @param string $kind Component or stream kind selected by the caller.
	 * @param string $component Plugin or theme directory identifier, when applicable.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function root( string $kind, string $component = '' ): string {
		if ( 'plugins' === $kind ) {
			return WP_PLUGIN_DIR; }
		if ( 'themes' === $kind ) {
			return $component && wp_get_theme( $component )->exists() ? wp_get_theme( $component )->get_theme_root() : WP_CONTENT_DIR . '/themes'; }
		$uploads = wp_upload_dir( null, false );
		if ( $uploads['error'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( $uploads['error'] ); }
		return $uploads['basedir'];
	}

	/**
	 * Reject absolute, traversing or otherwise unsafe relative file paths.
	 *
	 * @param string $path Filesystem path checked or operated on by this helper.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function safe_relative( string $path ): string {
		if ( ! $path || str_contains( $path, "\0" ) || str_contains( $path, '\\' ) || str_starts_with( $path, '/' ) || preg_match( '#(^|/)\.\.?(/|$)|:#', $path ) ) {
			throw new RuntimeException( 'Unsafe file path.' );
		}
		return $path;
	}

	/**
	 * Resolve a checked destination path while enforcing exclusions and Git policy.
	 *
	 * @param string $relative Content-relative path subject to exclusion and link policies.
	 * @param array  $selection Approved database, component and media selection with transfer policies.
	 * @param bool   $check_git Whether destination Git policy must also be checked.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function destination( string $relative, array $selection, bool $check_git = true ): string {
		self::safe_relative( $relative );
		if ( self::skipped( $relative, $selection ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Excluded file or protected development folder: ' . $relative ); }
		[$kind, $tail] = array_pad( explode( '/', $relative, 2 ), 2, '' );
		if ( ! in_array( $kind, array( 'plugins', 'themes', 'uploads' ), true ) || ! $tail ) {
			throw new RuntimeException( 'Unsupported file location.' ); }
		$first = explode( '/', $tail )[0];
		if ( 'uploads' === $kind ) {
			if ( ( $selection['media'] ?? 'none' ) === 'none' || preg_match( '/\.(php\d*|phtml|phar|cgi|pl|sh|htaccess|user\.ini)$/i', $tail ) || str_starts_with( basename( $tail ), '.' ) ) {
				throw new RuntimeException( 'Executable or unselected media file blocked.' );
			}
		} elseif ( ! in_array( $first, $selection[ $kind ] ?? array(), true ) || ( 'plugins' === $kind && basename( dirname( CSM_FILE ) ) === $first ) ) {
			throw new RuntimeException( 'File outside the selected components.' );
		}
		$root = self::root( $kind, $first );
		$path = $root . '/' . $tail;
		$walk = $path;
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The ancestor path changes on each step; its current length bounds traversal.
		while ( strlen( $walk ) >= strlen( $root ) ) {
			if ( is_link( $walk ) ) {
				throw new RuntimeException( 'Symbolic links are not transferable.' ); }
			if ( $walk === $root ) {
				break; }
			$walk = dirname( $walk );
		}
		if ( $check_git ) {
			self::assert_git_destination( $relative, $path, $root, $selection ); }
		return $path;
	}

	/**
	 * Require safe writable destination storage before applying a file replacement.
	 *
	 * @param string $target Destination filesystem path.
	 * @param string $relative Content-relative path subject to exclusion and link policies.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function assert_writable( string $target, string $relative ): void {
		$ancestor = dirname( $target );
		while ( ! file_exists( $ancestor ) ) {
			$ancestor = dirname( $ancestor ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Check the actual local publication permissions before starting a recoverable write.
		if ( ! is_writable( $ancestor ) || ( file_exists( $target ) && ( ! is_file( $target ) || ! is_writable( $target ) || ! is_writable( dirname( $target ) ) ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Destination is not writable: ' . $relative );
		}
	}

	/**
	 * Yield selected source entries with bounded traversal and checked symlink handling.
	 *
	 * @param string     $path Filesystem path checked or operated on by this helper.
	 * @param string     $relative Content-relative path subject to exclusion and link policies.
	 * @param array      $selection Approved database, component and media selection with transfer policies.
	 * @param float|null $deadline Absolute monotonic traversal deadline, or null to use the current budget.
	 * @param bool       $source Whether the inventory describes source files, allowing approved source links.
	 * @param array      $ancestors Already visited source directory identities used to detect link cycles.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function scan( string $path, string $relative = '', array $selection = array(), ?float $deadline = null, bool $source = false, array $ancestors = array() ): Generator {
		$deadline ??= hrtime( true ) / 1e9 + 45;
		if ( hrtime( true ) / 1e9 >= $deadline ) {
			throw new RuntimeException( 'File traversal exceeded its 45-second limit. Narrow the selection.' ); }
		CSM_Support::check_source_budget();
		if ( self::skipped( $relative, $selection ) ) {
			return; }
		if ( ! empty( $selection['protect_source_git'] ) && is_dir( $path ) && ( file_exists( $path . '/.git' ) || is_link( $path . '/.git' ) ) && ! in_array( $relative, $selection['git_overrides'] ?? array(), true ) ) {
			return; }
		if ( $source ) {
			if ( self::source_path( $path, $relative, $selection ) === null ) {
				return; }
		} elseif ( is_link( $path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Symbolic links are not transferable: ' . basename( $path ) ); }
		if ( is_dir( $path ) ) {
			$real = realpath( $path );
			if ( in_array( $real, $ancestors, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Circular source link: ' . $relative ); }
			$ancestors[] = $real;
		}
		if ( is_file( $path ) ) {
			yield $path;
			return; }
		if ( ! is_dir( $path ) ) {
			return; }
		foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $file ) {
			yield from self::scan( $file->getPathname(), $relative . '/' . $file->getFilename(), $selection, $deadline, $source, $ancestors ); }
	}

	/**
	 * Advance a durable filesystem traversal cursor over selected roots.
	 *
	 * @param array &$cursor Durable traversal, read or checksum cursor, updated in place.
	 * @param array $roots Selected root paths to traverse.
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 * @param bool  $source Whether the inventory describes source files, allowing approved source links.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function walk( array &$cursor, array $roots, array $selection, bool $source = false ): Generator {
		$cursor += array(
			'pending' => array_reverse( $roots ),
			'active'  => null,
		);
		while ( $cursor['pending'] || $cursor['active'] ) {
			if ( CSM_Support::due() ) {
				return; }
			CSM_Support::check_source_budget();
			if ( $cursor['active'] ) {
				$active = &$cursor['active'];
				if ( $active['index'] >= count( $active['names'] ) ) {
					$cursor['active'] = null;
					continue; }
				$name      = $active['names'][ $active['index']++ ];
				$path      = $active['path'] . '/' . $name;
				$relative  = $active['relative'] . '/' . $name;
				$ancestors = $active['ancestors'] ?? array();
				unset( $active );
			} else {
				$next              = array_pop( $cursor['pending'] );
				[$path, $relative] = $next;
				$ancestors         = $next[2] ?? array(); }
			if ( self::skipped( $relative, $selection ) ) {
				continue; }
			$resolved = $path;
			if ( $source ) {
				$resolved = self::source_path( $path, $relative, $selection );
				if ( null === $resolved ) {
					continue; }
			} elseif ( is_link( $path ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Symbolic links are not transferable: ' . basename( $path ) ); }
			if ( is_file( $path ) ) {
				yield array( $relative, $resolved );
				continue; }
			if ( ! is_dir( $path ) ) {
				continue; }
			if ( ! empty( $selection['protect_source_git'] ) && ( file_exists( $path . '/.git' ) || is_link( $path . '/.git' ) ) && ! in_array( $relative, $selection['git_overrides'] ?? array(), true ) ) {
				continue; }
			// Queue subdirectories without restarting the current directory after a checkpoint.
			if ( $cursor['active'] ) {
				$cursor['pending'][] = array( $path, $relative, $ancestors );
				continue; }
			$real = realpath( $path );
			if ( in_array( $real, $ancestors, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Circular source link: ' . $relative ); }
			$ancestors[] = $real;
			$names       = scandir( $path );
			if ( false === $names ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Cannot list directory: ' . $relative ); }
			$cursor['active'] = array(
				'path'      => $path,
				'relative'  => $relative,
				'names'     => array_values( array_diff( $names, array( '.', '..' ) ) ),
				'index'     => 0,
				'ancestors' => $ancestors,
			);
		}
	}

	/**
	 * Yield the next bounded set of selected file paths from its saved cursor.
	 *
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 * @param array &$cursor Durable traversal, read or checksum cursor, updated in place.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function paths_step( array $selection, array &$cursor ): Generator {
		$roots            = array();
		$cursor['walk'] ??= array();
		foreach ( array( 'plugins', 'themes' ) as $kind ) {
			foreach ( $selection[ $kind ] as $id ) {
				if ( self::skipped( $kind . '/' . $id, $selection ) ) {
					continue; }
				$path = self::root( $kind, $id ) . '/' . self::safe_relative( $id );
				if ( ! file_exists( $path ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( 'Selected source component is missing: ' . $id ); }
				$roots[] = array( $path, $kind . '/' . $id );
			}
		}
		$ordinary = in_array( $selection['media'], array( 'all', 'changed' ), true );
		if ( $ordinary ) {
			$roots[] = array( self::root( 'uploads' ), 'uploads' ); }
		foreach ( self::walk( $cursor['walk'], $roots, $selection, true ) as [$relative, $path] ) {
			if ( str_starts_with( $relative, 'uploads/' ) ) {
				if ( 'changed' === $selection['media'] && filemtime( $path ) < $selection['since'] ) {
					continue; }
				if ( preg_match( '#(^|/)(cache|backups?|ai1wm-backups|wpvividbackups|\.git)(/|$)#i', $relative ) ) {
					continue; }
				try {
					self::destination( $relative, $selection, ! empty( $selection['protect_source_git'] ) );
				} catch ( RuntimeException $error ) {
					continue; }
			}
			yield array( $relative, $path );
		}
		if ( $cursor['walk']['pending'] || $cursor['walk']['active'] ) {
			return; }
		if ( ! $ordinary && 'none' !== $selection['media'] ) {
			$media            = $selection;
			$media['plugins'] = array();
			$media['themes']  = array();
			$index            = 0; foreach ( self::paths( $media ) as $entry ) {
				if ( $index++ < ( $cursor['media_index'] ?? 0 ) ) {
					continue; }
				$cursor['media_index'] = $index;
				yield $entry;
			}
		}
	}

	/**
	 * Yield all file paths in the normalized component and media selection.
	 *
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function paths( array $selection ): Generator {
		foreach ( array( 'plugins', 'themes' ) as $kind ) {
			foreach ( $selection[ $kind ] as $id ) {
				if ( self::skipped( $kind . '/' . $id, $selection ) ) {
					continue; }
				CSM_Progress::update(
					array(
						'phase'     => 'Reading source component',
						'component' => $kind . '/' . $id,
					)
				);
				$root  = self::root( $kind, $id );
				$count = 0;
				if ( ! file_exists( $root . '/' . $id ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( 'Selected source component is missing: ' . $id ); }
				foreach ( self::scan( $root . '/' . $id, $kind . '/' . $id, $selection, null, true ) as $file ) {
					$relative = $kind . '/' . substr( $file, strlen( $root ) + 1 );
					self::safe_relative( $relative );
					$resolved = self::source_path( $file, $relative, $selection );
					if ( null !== $resolved ) {
						yield array( $relative, $resolved );
						++$count; }
				}
				if ( ! $count ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( 'Selected source component has no files: ' . $id ); }
			}
		}
		$root = self::root( 'uploads' );
		if ( 'compare' === $selection['media'] ) {
			foreach ( CSM_Media::paths( $selection['media_delta'] ?? '', $selection ) as $entry ) {
				if ( ! self::skipped( $entry[0], $selection ) ) {
					yield $entry;
				}
			} return; }
		if ( in_array( $selection['media'], array( 'selected', 'uploaded' ), true ) ) {
			$seen = array();
			foreach ( self::attachment_ids( $selection ) as $id ) {
				if ( get_post_type( $id ) !== 'attachment' ) {
					throw new RuntimeException( 'Unknown attachment.' ); }
				$file     = get_post_meta( $id, '_wp_attached_file', true );
				$metadata = wp_get_attachment_metadata( $id );
				$paths    = array( $file );
				foreach ( $metadata['sizes'] ?? array() as $size ) {
					$paths[] = dirname( $file ) . '/' . $size['file']; }
				if ( ! empty( $metadata['original_image'] ) ) {
					$paths[] = dirname( $file ) . '/' . $metadata['original_image']; }
				foreach ( $paths as $tail ) {
					if ( ! $tail || isset( $seen[ $tail ] ) ) {
						continue; }
					$relative = 'uploads/' . ltrim( preg_replace( '#^\./#', '', $tail ), '/' );
					if ( self::skipped( $relative, $selection ) ) {
						continue; }
					$path = self::destination( $relative, $selection, ! empty( $selection['protect_source_git'] ) );
					if ( ! is_file( $path ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
						throw new RuntimeException( 'Missing attachment file: ' . $tail ); }
					$seen[ $tail ] = true;
					yield array( $relative, $path );
				}
			}
		} elseif ( in_array( $selection['media'], array( 'all', 'changed' ), true ) ) {
			foreach ( self::scan( $root, 'uploads', $selection ) as $file ) {
				$relative = 'uploads/' . substr( $file, strlen( $root ) + 1 );
				if ( 'changed' === $selection['media'] && filemtime( $file ) < $selection['since'] ) {
					continue; }
				// Exclude executables, hidden server configuration and common backup/cache directories.
				if ( preg_match( '#(^|/)(cache|backups?|ai1wm-backups|wpvividbackups|\.git)(/|$)#i', $relative ) ) {
					continue; }
				try {
					self::destination( $relative, $selection, ! empty( $selection['protect_source_git'] ) );
				} catch ( RuntimeException $e ) {
					continue; }
				yield array( $relative, $file );
			}
		}
	}

	/**
	 * Read a file's comparison metadata without hashing its contents.
	 *
	 * @param string $path Filesystem path checked or operated on by this helper.
	 */
	private static function file_stamp( string $path ): ?array {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing or replaced file is handled by the following metadata validation or fallback.
		$stat = @stat( $path );
		return $stat && is_file( $path ) ? array_intersect_key( $stat, array_flip( array( 'size', 'mtime', 'ctime', 'ino', 'dev', 'mode' ) ) ) : null;
	}

	/**
	 * Build the stream record for a file skipped by size and timestamp comparison.
	 *
	 * @param string $target Destination filesystem path.
	 * @param array  $record Recorded file, stream or migration metadata.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function unchanged_record( string $target, array $record ): array {
		$stamp = self::file_stamp( $target );
		if ( ! is_int( $record['size'] ?? null ) || $record['size'] < 0 || ! is_int( $record['mtime'] ?? null ) || ! $stamp || $stamp['size'] !== $record['size'] || $stamp['mtime'] !== $record['mtime'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
			throw new RuntimeException( 'Unchanged destination metadata changed: ' . $record['path'] );
		}
		return array(
			'path'             => $record['path'],
			'size'             => $record['size'],
			'mtime'            => $record['mtime'],
			'unchanged'        => true,
			'comparison_stamp' => $stamp,
		);
	}

	/** After receipt, establish the local recovery baseline for files skipped by size/mtime.
	 *
	 * @param string $target Destination filesystem path.
	 * @param array  &$file Recorded file metadata and expected content digest.
	 */
	public static function verify_unchanged( string $target, array &$file ): bool {
		if ( isset( $file['sha256'] ) ) {
			return CSM_Support::verify_file( $target, $file['sha256'], $file['verification_receipt'] ); }
		$stamp = self::file_stamp( $target );
		if ( ! $stamp || ( $file['comparison_stamp'] ?? null ) !== $stamp ) {
			return false; }
		$hash = CSM_Support::hash_file( $target );
		if ( self::file_stamp( $target ) !== $stamp ) {
			return false; }
		$file['sha256'] = $hash;
		unset( $file['comparison_stamp'] );
		if ( max( $stamp['mtime'], $stamp['ctime'] ) < time() - 2 ) {
			$file['verification_receipt'] = array(
				'stamp' => $stamp,
				'hash'  => $hash,
			); }
		return true;
	}

	/**
	 * Yield file records for the selection while accumulating component byte counts.
	 *
	 * @param array      $selection Approved database, component and media selection with transfer policies.
	 * @param array|null &$component_sizes Optional per-component logical byte counters, updated in place.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function stream( array $selection, ?array &$component_sizes = null ): Generator {
		$selection       = self::selection( $selection );
		$component_sizes = array(
			'plugins' => 0,
			'themes'  => 0,
			'media'   => 0,
		);
		$started         = time();
		$count           = 0;
		$hash            = hash_init( 'sha256' );
		$same            = CSM_Components::unchanged( $selection );
		$parents         = array();
		foreach ( $selection['themes'] as $theme ) {
			$parents[ $theme ] = wp_get_theme( $theme )->get( 'Template' ); }
		yield CSM_Support::json(
			array(
				'type'           => 'header',
				'version'        => 1,
				'kind'           => 'files',
				'started'        => $started,
				'theme_parents'  => $parents,
				'active_plugins' => CSM_Activation::current(),
				'trickle_plugin' => plugin_basename( CSM_FILE ),
			)
		);
		foreach ( self::paths( $selection ) as $entry ) {
			[$relative, $path] = $entry;
			if ( isset( $same[ $relative ] ) ) {
				if ( ! CSM_Components::verify( $path, $same[ $relative ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( 'Source file changed after comparison: ' . $relative . '. Retry preparation.' ); }
				$line = CSM_Support::json(
					array(
						'type'          => 'file-same',
						'path'          => $relative,
						'metadata_only' => true,
						'size'          => filesize( $path ),
						'mtime'         => filemtime( $path ),
					)
				);
				hash_update( $hash, $line );
				yield $line;
				++$count;
				continue;
			}
			CSM_Progress::update(
				array(
					'phase'     => 'Exporting file',
					'component' => $relative,
					'count'     => $count,
					'total'     => null,
					'unit'      => 'files',
					'bytes'     => 0,
				)
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
			$fp = fopen( $path, 'rb' );
			if ( ! $fp ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Cannot read file: ' . $relative ); }
			try {
				$stat = fstat( $fp );
				$size = $stat['size'];
				$component_sizes[ str_starts_with( $relative, 'uploads/' ) ? 'media' : explode( '/', $relative )[0] ] += $size;
				$line = CSM_Support::json(
					array(
						'type'  => 'file',
						'path'  => $relative,
						'size'  => $size,
						'mtime' => $stat['mtime'],
						'mode'  => ( $stat['mode'] & 0111 ) ? 0755 : 0644,
					)
				);
				hash_update( $hash, $line );
				yield $line;
				$filehash  = hash_init( 'sha256' );
				$remaining = $size;
				while ( $remaining > 0 ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
					$bytes = fread( $fp, min( 65536, $remaining ) );
					if ( false === $bytes || '' === $bytes ) {
						throw new RuntimeException( 'File changed or became unreadable during transfer.' ); }
					$remaining -= strlen( $bytes );
					hash_update( $filehash, $bytes );
					yield $bytes;
					CSM_Progress::update( array( 'bytes' => $size - $remaining ) );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
				if ( fread( $fp, 1 ) !== '' || fstat( $fp )['mtime'] !== $stat['mtime'] ) {
					throw new RuntimeException( 'File changed during transfer: ' . $relative ); }
				$checksum = hash_final( $filehash );
				if ( isset( $entry[2] ) && ! hash_equals( $entry[2], $checksum ) ) {
					throw new RuntimeException( 'Media contents changed after comparison: ' . $relative ); }
				$line = CSM_Support::json(
					array(
						'type'   => 'file-end',
						'sha256' => $checksum,
					)
				);
				hash_update( $hash, $line );
				yield $line;
				++$count;
				CSM_Progress::update( array( 'count' => $count ) );
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $fp ); }
		}
		yield CSM_Support::json(
			array(
				'type'    => 'end',
				'files'   => $count,
				'started' => $started,
				'sha256'  => hash_final( $hash ),
			)
		);
	}

	/**
	 * Yield bounded file records and retain traversal, read and checksum cursors.
	 *
	 * @param array $selection Approved database, component and media selection with transfer policies.
	 * @param array &$cursor Durable traversal, read or checksum cursor, updated in place.
	 * @param array &$sizes Per-component logical byte counters, updated in place.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function stream_step( array $selection, array &$cursor, array &$sizes ): Generator {
		$cursor += array(
			'header' => false,
			'index'  => 0,
			'offset' => 0,
			'hash'   => '',
			'done'   => false,
		);
		if ( $cursor['done'] ) {
			return; }
		if ( empty( $cursor['planned'] ) ) {
			$cursor['plan']     ??= array();
			$cursor['planning'] ??= array();
			foreach ( self::paths_step( $selection, $cursor['planning'] ) as $entry ) {
				if ( isset( $cursor['plan'][ $entry[0] ] ) ) {
					continue; }
				$stat                        = stat( $entry[1] );
				$cursor['plan'][ $entry[0] ] = array( $entry[1], array( $stat['size'], $stat['mtime'], $stat['ctime'], $stat['ino'] ), $entry[2] ?? null );
				if ( CSM_Support::due() ) {
					return; }
			}
			if ( $cursor['planning']['walk']['pending'] || $cursor['planning']['walk']['active'] ) {
				return; }
			unset( $cursor['planning'] );
			$cursor['planned'] = true;
			$cursor['plan']    = array_map( static fn( $relative, $entry )=>array( $relative, ...$entry ), array_keys( $cursor['plan'] ), array_values( $cursor['plan'] ) );
		}
		$cursor['component'] = null;
		if ( ! $cursor['header'] ) {
			$parents = array();
			foreach ( $selection['themes'] as $theme ) {
				$parents[ $theme ] = wp_get_theme( $theme )->get( 'Template' ); }
			$cursor['started'] ??= time();
			$cursor['header']    = true;
			yield CSM_Support::json(
				array(
					'type'           => 'header',
					'version'        => 2,
					'kind'           => 'files',
					'started'        => $cursor['started'],
					'theme_parents'  => $parents,
					'active_plugins' => CSM_Activation::current(),
					'trickle_plugin' => plugin_basename( CSM_FILE ),
				)
			);
		}
		$same       = CSM_Components::unchanged( $selection );
		$plan_count = count( $cursor['plan'] );
		for ( $index = $cursor['index']; $index < $plan_count; $index++ ) {
			[$relative, $path, $planned_stamp, $expected_hash] = $cursor['plan'][ $index ];

			CSM_Support::check_cancel();

			$cursor['component'] = str_starts_with( $relative, 'uploads/' ) ? 'media' : explode( '/', $relative )[0];
			clearstatcache( true, $path );
			$stat = stat( $path );
			if ( array( $stat['size'], $stat['mtime'], $stat['ctime'], $stat['ino'] ) !== $planned_stamp ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Source file changed after planning: ' . $relative ); }
			if ( ! $cursor['offset'] ) {
				$cursor['stamp'] = array( $stat['size'], $stat['mtime'], $stat['ctime'], $stat['ino'] );
				if ( isset( $same[ $relative ] ) ) {
					if ( ! CSM_Components::verify( $path, $same[ $relative ] ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
						throw new RuntimeException( 'Source file changed after comparison: ' . $relative ); }
					$line           = CSM_Support::json(
						array(
							'type'          => 'file-same',
							'path'          => $relative,
							'metadata_only' => true,
							'size'          => $stat['size'],
							'mtime'         => $stat['mtime'],
						)
					);
					$cursor['hash'] = CSM_Package::chain( $cursor['hash'], $line );
					++$cursor['index'];
					yield $line;
					continue;
				}
				$line           = CSM_Support::json(
					array(
						'type'  => 'file',
						'path'  => $relative,
						'size'  => $stat['size'],
						'mtime' => $stat['mtime'],
						'mode'  => ( $stat['mode'] & 0111 ) ? 0755 : 0644,
					)
				);
				$cursor['hash'] = CSM_Package::chain( $cursor['hash'], $line );
				// offset=-1 means the header is durable, including for empty files.
				$cursor['offset'] = -1;
				yield $line;
			}
			if ( array( $stat['size'], $stat['mtime'], $stat['ctime'], $stat['ino'] ) !== $cursor['stamp'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Source file changed during packaging: ' . $relative ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
			$fp       = fopen( $path, 'rb' );
			$offset   = max( 0, $cursor['offset'] );
			$filehash = 0 === $offset ? hash_init( 'sha256' ) : ( isset( $cursor['file_hash'] ) ? CSM_Support::restore_hash( $cursor['file_hash'] ) : null );
			if ( ! $fp || fseek( $fp, $offset ) !== 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Cannot resume source file: ' . $relative ); }
			try {
				$buffer = null;
				if ( 0 === $offset && $stat['size'] <= 2097152 && function_exists( 'openssl_digest' ) ) {
					$buffer = '';
					// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The buffer changes on each step; its current length controls exact frame boundaries.
					while ( strlen( $buffer ) < $stat['size'] ) {
						CSM_Support::check_source_budget();
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
						$part = fread( $fp, min( 262144, $stat['size'] - strlen( $buffer ) ) );
						if ( false === $part || '' === $part ) {
							throw new RuntimeException( 'Source file is truncated: ' . $relative ); }
						$buffer .= $part;
					}
					$cursor['digest'] = CSM_Package::digest( $buffer );
					$filehash         = null;
				}
				while ( $offset < $stat['size'] ) {
					CSM_Support::check_cancel();
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
					$bytes = null !== $buffer ? substr( $buffer, $offset, 262144 ) : fread( $fp, min( 262144, $stat['size'] - $offset ) );
					if ( false === $bytes || '' === $bytes ) {
						throw new RuntimeException( 'Source file is truncated: ' . $relative ); }
					$offset          += strlen( $bytes );
					$cursor['offset'] = $offset;
					if ( null !== $filehash ) {
						hash_update( $filehash, $bytes );
						$cursor['file_hash'] = CSM_Support::save_hash( $filehash ); }
					$component            = str_starts_with( $relative, 'uploads/' ) ? 'media' : explode( '/', $relative )[0];
					$sizes[ $component ] += strlen( $bytes );
					CSM_Progress::update(
						array(
							'phase'     => 'Packaging file',
							'component' => $relative,
							'count'     => $index,
							'bytes'     => $offset,
						)
					);
					if ( null !== $filehash && $offset === $stat['size'] ) {
						$cursor['digest'] = hash_final( $filehash );
						$filehash         = null; }
					yield $bytes;
				}
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $fp ); }
			clearstatcache( true, $path );
			$after = stat( $path );
			if ( array( $after['size'], $after['mtime'], $after['ctime'], $after['ino'] ) !== $cursor['stamp'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Source file changed during packaging: ' . $relative ); }
			$digest = $cursor['digest'] ?? ( null !== $filehash ? hash_final( $filehash ) : ( $expected_hash ?? CSM_Support::hash_file( $path ) ) );
			unset( $cursor['digest'], $cursor['file_hash'] );
			if ( null !== $expected_hash && ! hash_equals( $expected_hash, $digest ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Media changed after comparison: ' . $relative ); }
			$line           = CSM_Support::json(
				array(
					'type'   => 'file-end',
					'sha256' => $digest,
				)
			);
			$cursor['hash'] = CSM_Package::chain( $cursor['hash'], $line );
			++$cursor['index'];
			$cursor['offset'] = 0;
			yield $line;
		}
		$cursor['component'] = null;
		$cursor['done']      = true;
		yield CSM_Support::json(
			array(
				'type'    => 'end',
				'files'   => $cursor['index'],
				'started' => $cursor['started'],
				'sha256'  => $cursor['hash'],
			)
		);
	}

	/**
	 * Flush and close the retained staged-file output handle.
	 *
	 * @param array &$output Retained staged-file handle and its validated output state.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function close_stage_output( array &$output ): void {
		$handle = $output['handle'] ?? null;
		$output = array();
		if ( ! is_resource( $handle ) ) {
			return; }
		$started = hrtime( true );
		try {
			if ( ! fflush( $handle ) ) {
				throw new RuntimeException( 'Cannot flush staged file.' ); }
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
			$closed = fclose( $handle );
			CSM_Timing::add( 'stream.stage_flush', $started ); }
		if ( ! $closed ) {
			throw new RuntimeException( 'Cannot close staged file.' ); }
	}

	/**
	 * Advance checked file staging from complete records or verified incremental frames.
	 *
	 * @param resource   $fp Caller-owned stream handle positioned at the next record or write offset.
	 * @param array      &$job Destination job and its durable staging or recovery state.
	 * @param bool       $continuing Whether to preserve the current work-step deadline.
	 * @param bool       $incremental Whether input is a complete verified frame rather than the full stream.
	 * @param array|null &$output Retained staged-file handle and its validated output state.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function stage_step( $fp, array &$job, bool $continuing = false, bool $incremental = false, ?array &$output = null ): bool {
		$state = $job['imports']['files'] ?? null;
		if ( ! $state ) {
			$header = CSM_Support::line( $fp );
			if ( ( $header['type'] ?? '' ) !== 'header' || ( $header['version'] ?? 0 ) !== 2 || ( $header['kind'] ?? '' ) !== 'files' ) {
				throw new RuntimeException( 'Invalid file stream.' ); }
			CSM_Activation::source( $job, $header );
			foreach ( $header['theme_parents'] ?? array() as $parent ) {
				if ( $parent && ( ! in_array( $parent, $job['selection']['themes'], true ) || self::skipped( 'themes/' . $parent, $job['selection'] ) ) && ! wp_get_theme( $parent )->exists() ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( 'Include the required parent theme: ' . $parent ); }
			}
			$state        = array(
				'header'     => $header,
				'offset'     => ftell( $fp ),
				'hash'       => '',
				'seen'       => array(),
				'components' => array(),
				'current'    => null,
			);
			$job['files'] = array();
		}
		$dir = CSM_Support::private_dir() . '/' . $job['id'];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private staging with explicit permissions; no FTP backend may replace it.
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700 ) ) {
			throw new RuntimeException( 'Cannot create transfer staging directory.' ); }
		if ( fseek( $fp, $state['offset'] ) !== 0 ) {
			throw new RuntimeException( 'Cannot resume file import.' ); }
		if ( ! $incremental ) {
			CSM_Support::step( 8, $continuing ); }
		$available = $incremental ? fstat( $fp )['size'] : null;
		do {
			CSM_Support::check_cancel();
			if ( $incremental && ftell( $fp ) === $available ) {
				$state['offset']         = 0;
				$job['imports']['files'] = $state;
				return false; }
			if ( ! $state['current'] ) {
				$record = CSM_Support::line( $fp );
				$type   = $record['type'] ?? '';
				if ( 'end' === $type ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
					if ( count( $job['files'] ) !== $record['files'] || ! hash_equals( $state['hash'], $record['sha256'] ) || fread( $fp, 1 ) !== '' ) {
						throw new RuntimeException( 'Incomplete file stream or checksum failure.' ); }
					foreach ( array( 'plugins', 'themes' ) as $kind ) {
						foreach ( $job['selection'][ $kind ] as $component ) {
							if ( ! self::skipped( $kind . '/' . $component, $job['selection'] ) && empty( $state['components'][ $kind . '/' . $component ] ) ) {
								throw new RuntimeException( 'Selected component was omitted from the file stream.' ); }
						}
					}
					$job['media_watermark'] = $state['header']['started'];
					unset( $job['imports']['files'] );
					return true;
				}
				if ( ! in_array( $type, array( 'file', 'file-same' ), true ) || ! is_string( $record['path'] ?? null ) || isset( $state['seen'][ $record['path'] ] ) ) {
					throw new RuntimeException( 'Invalid or duplicate file header.' ); }
				$target                           = self::destination( $record['path'], $job['selection'] );
				$state['seen'][ $record['path'] ] = true;
				$parts                            = explode( '/', $record['path'] );
				$state['components'][ $parts[0] . '/' . $parts[1] ] = true;
				$state['hash']                                      = CSM_Package::chain( $state['hash'], CSM_Support::json( $record ) );
				if ( 'file-same' === $type ) {
					$receipt = null;
					if ( ( $record['metadata_only'] ?? false ) === true ) {
						$job['files'][] = self::unchanged_record( $target, $record ); } else {
						if ( ! is_string( $record['sha256'] ?? null ) || ! CSM_Support::verify_file( $target, $record['sha256'], $receipt ) ) {
							// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
							throw new RuntimeException( 'Unchanged destination file failed verification: ' . $record['path'] ); }
						$job['files'][] = array(
							'path'                 => $record['path'],
							'sha256'               => $record['sha256'],
							'size'                 => filesize( $target ),
							'unchanged'            => true,
							'verification_receipt' => $receipt,
						);
						}
				} else {
					if ( ! is_int( $record['size'] ?? null ) || $record['size'] < 0 ) {
						throw new RuntimeException( 'Invalid file size.' ); }
					$stage = str_starts_with( $record['path'], 'uploads/' ) ? self::media_stage( $job, $record['path'] ) : $dir . '/' . count( $job['files'] );
					if ( in_array( $parts[0], array( 'plugins', 'themes' ), true ) ) {
						$component                              = $parts[0] . '/' . $parts[1];
						$job['component_staging'][ $component ] = $dir . '/components/' . $component;
						$stage                                  = $dir . '/components/' . $record['path'];
						if ( ! is_dir( dirname( $stage ) ) && ! wp_mkdir_p( dirname( $stage ) ) ) {
							throw new RuntimeException( 'Cannot create component staging directory.' ); }
					}
					$state['current'] = array(
						'record'  => $record,
						'written' => 0,
						'stage'   => $stage,
						'hash'    => CSM_Support::save_hash( hash_init( 'sha256' ) ),
					);
				}
			}
			$current = $state['current'];
			if ( $current ) {
				$open_started = hrtime( true );
				$stat         = null;
				if ( str_starts_with( $current['record']['path'], 'uploads/' ) ) {
					self::destination( $current['record']['path'], $job['selection'] );
					clearstatcache( true, $current['stage'] );
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing or replaced file is handled by the following metadata validation or fallback.
					$stat = @lstat( $current['stage'] );
					if ( is_link( $current['stage'] ) || ( $stat['nlink'] ?? 1 ) > 1 ) {
						throw new RuntimeException( 'Unsafe media part.' ); }
				}
				$record = $current['record'];
				$reused = isset( $output['handle'] ) && is_resource( $output['handle'] );
				if ( $reused ) {
					$out = $output['handle'];
					if ( null === $stat ) {
						clearstatcache( true, $current['stage'] );
						$stat = lstat( $current['stage'] ); }
					if ( ( $output['path'] ?? '' ) !== $current['stage'] || ! $stat || array( $stat['dev'], $stat['ino'] ) !== $output['identity'] || 1 !== $stat['nlink'] || ftell( $out ) !== $current['written'] ) {
						throw new RuntimeException( 'Staged file changed between frames.' ); }
				} else {
					if ( $current['written'] > 0 && ( ! is_file( $current['stage'] ) || filesize( $current['stage'] ) < $current['written'] ) ) {
						throw new RuntimeException( 'Staged file checkpoint is missing or truncated.' ); }
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
					$out = fopen( $current['stage'], 'c+b' );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
				if ( $out && str_starts_with( $record['path'], 'uploads/' ) && ( fileperms( $current['stage'] ) & 0777 ) !== 0600 && ! chmod( $current['stage'], 0600 ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
					fclose( $out );
					throw new RuntimeException( 'Cannot set media part permissions.' ); }
				if ( ! $reused ) {
					$opened      = $out ? fstat( $out ) : null;
					$staged_size = $opened['size'] ?? -1;
					// Normal frames append at the checkpoint; truncate only unacknowledged replay bytes.
					if ( ! $out || $staged_size < $current['written'] || ( $staged_size > $current['written'] && ! ftruncate( $out, $current['written'] ) ) || fseek( $out, $current['written'] ) !== 0 ) {
						if ( $out ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
							fclose( $out );
						} throw new RuntimeException( 'Cannot resume staged file.' ); }
					if ( null !== $output ) {
						$output = array(
							'handle'   => $out,
							'path'     => $current['stage'],
							'identity' => array( $opened['dev'], $opened['ino'] ),
						); }
				}
				CSM_Timing::add( $reused ? 'stream.stage_reuse' : 'stream.stage_open', $open_started );
				$write_started = hrtime( true );
				$hash          = isset( $current['hash'] ) ? CSM_Support::restore_hash( $current['hash'] ) : null;
				try {
					do {
						$remaining = $record['size'] - $current['written'];
						if ( $incremental ) {
							$remaining = min( $remaining, $available - ftell( $fp ) ); }
						if ( $remaining <= 0 ) {
							break; }
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
						$bytes = fread( $fp, min( 262144, $remaining ) );
						if ( false === $bytes || '' === $bytes ) {
							throw new RuntimeException( 'Truncated file stream.' ); }
						CSM_Support::write( $out, $bytes );
						$current['written'] += strlen( $bytes );
						if ( null !== $hash ) {
							hash_update( $hash, $bytes ); }
						CSM_Progress::update(
							array(
								'phase'     => 'Staging file',
								'component' => $record['path'],
								'count'     => count( $job['files'] ),
								'bytes'     => $current['written'],
							)
						);
					} while ( $incremental || ! CSM_Support::due() );
					if ( null !== $hash ) {
						$current['hash'] = CSM_Support::save_hash( $hash ); }
					if ( null === $output && ! fflush( $out ) ) {
						throw new RuntimeException( 'Cannot flush staged file.' ); }
				} finally {
					if ( null === $output ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
						fclose( $out );
					} CSM_Timing::add( 'stream.stage_write', $write_started ); }
				$state['current'] = $current;
				if ( $current['written'] === $record['size'] && ( ! $incremental || ftell( $fp ) < $available ) ) {
					if ( null !== $output ) {
						self::close_stage_output( $output ); }
					$end = CSM_Support::line( $fp );
					if ( ( $end['type'] ?? '' ) !== 'file-end' || ! hash_equals( null !== $hash ? hash_final( $hash ) : CSM_Support::hash_file( $current['stage'] ), $end['sha256'] ?? '' ) ) {
						throw new RuntimeException( 'File checksum mismatch.' ); }
					$state['hash']    = CSM_Package::chain( $state['hash'], CSM_Support::json( $end ) );
					$job['files'][]   = array(
						'path'       => $record['path'],
						'mtime'      => (int) $record['mtime'],
						'size'       => $record['size'],
						'mode'       => ! str_starts_with( $record['path'], 'uploads/' ) && ( ( $record['mode'] ?? 0644 ) & 0111 ) ? 0755 : 0644,
						'stage'      => $current['stage'],
						'sha256'     => $end['sha256'],
						'media_part' => str_starts_with( $record['path'], 'uploads/' ) && self::media_part_path( $job, $record['path'] ) === $current['stage'],
					);
					$state['current'] = null;
				}
			}
			$state['offset'] = ftell( $fp );
		} while ( $incremental || ! CSM_Support::due() );
		$job['imports']['files'] = $state;
		CSM_Jobs::save( $job );
		return false;
	}

	/**
	 * Stage a complete file-record stream using the bounded staging machinery.
	 *
	 * @param resource $fp Caller-owned stream handle positioned at the next record or write offset.
	 * @param array    &$job Destination job and its durable staging or recovery state.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function stage( $fp, array &$job ): void {
		$header = CSM_Support::line( $fp );
		if ( ( $header['type'] ?? '' ) !== 'header' || ( $header['version'] ?? 0 ) !== 1 || ( $header['kind'] ?? '' ) !== 'files' ) {
			throw new RuntimeException( 'Invalid file stream.' ); }
		CSM_Activation::source( $job, $header );
		foreach ( $header['theme_parents'] ?? array() as $parent ) {
			if ( $parent && ( ! in_array( $parent, $job['selection']['themes'], true ) || self::skipped( 'themes/' . $parent, $job['selection'] ) ) && ! wp_get_theme( $parent )->exists() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'Include the required parent theme: ' . $parent ); }
		}
		$dir = CSM_Support::private_dir() . '/' . $job['id'];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private staging with explicit permissions; no FTP backend may replace it.
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700 ) ) {
			throw new RuntimeException( 'Cannot create transfer staging directory.' ); }
		$files      = array();
		$seen       = array();
		$components = array();
		$hash       = hash_init( 'sha256' );
		while ( true ) {
			$record = CSM_Support::line( $fp );
			if ( ( $record['type'] ?? '' ) === 'end' ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
				if ( count( $files ) !== $record['files'] || ! hash_equals( hash_final( $hash ), $record['sha256'] ) || fread( $fp, 1 ) !== '' ) {
					throw new RuntimeException( 'Incomplete file stream or checksum failure.' ); }
				foreach ( array( 'plugins', 'themes' ) as $kind ) {
					foreach ( $job['selection'][ $kind ] as $component ) {
						if ( ! self::skipped( $kind . '/' . $component, $job['selection'] ) && empty( $components[ $kind . '/' . $component ] ) ) {
							throw new RuntimeException( 'Selected component was omitted from the file stream.' ); }
					}
				}
				// Legacy receivers also retain their recovery baseline only after the complete payload arrives.
				foreach ( $files as &$file ) {
					if ( ! empty( $file['unchanged'] ) && ! isset( $file['sha256'] ) && ! self::verify_unchanged( self::destination( $file['path'], $job['selection'] ), $file ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
						throw new RuntimeException( 'Unchanged destination changed after receipt: ' . $file['path'] ); }
				}
				unset( $file );
				$job['files']           = $files;
				$job['media_watermark'] = $header['started'];
				return;
			}
			if ( ( $record['type'] ?? '' ) === 'file-same' ) {
				$path   = $record['path'] ?? '';
				$target = self::destination( $path, $job['selection'] );
				if ( isset( $seen[ $path ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( 'Duplicate unchanged file: ' . $path ); }
				if ( ( $record['metadata_only'] ?? false ) === true ) {
					$file = self::unchanged_record( $target, $record ); } else {
					if ( ! is_string( $record['sha256'] ?? null ) || ! is_file( $target ) || ! hash_equals( $record['sha256'], CSM_Support::hash_file( $target ) ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
						throw new RuntimeException( 'Unchanged destination file failed verification: ' . $path ); }
					$file = array(
						'path'      => $path,
						'sha256'    => $record['sha256'],
						'size'      => filesize( $target ),
						'unchanged' => true,
					);
					}
					$parts                                     = explode( '/', $path );
					$components[ $parts[0] . '/' . $parts[1] ] = true;
					$seen[ $path ]                             = true;
					hash_update( $hash, CSM_Support::json( $record ) );
					$files[] = $file;
					continue;
			}
			if ( ( $record['type'] ?? '' ) !== 'file' || ! is_int( $record['size'] ) || $record['size'] < 0 || isset( $seen[ $record['path'] ] ) ) {
				throw new RuntimeException( 'Invalid file header.' ); }
			self::destination( $record['path'], $job['selection'] );
			CSM_Progress::update(
				array(
					'phase'     => 'Receiving and verifying file',
					'component' => $record['path'],
					'count'     => count( $files ),
					'total'     => null,
					'unit'      => 'files',
					'bytes'     => 0,
				)
			);
			$parts                                     = explode( '/', $record['path'] );
			$components[ $parts[0] . '/' . $parts[1] ] = true;
			$seen[ $record['path'] ]                   = true;
			hash_update( $hash, CSM_Support::json( $record ) );
			$index  = count( $files );
			$staged = str_starts_with( $record['path'], 'uploads/' ) ? self::media_stage( $job, $record['path'] ) : $dir . '/' . $index;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handles retain offsets and locks across bounded streaming steps.
			$out = fopen( $staged, 'xb' );
			if ( ! $out ) {
				throw new RuntimeException( 'Cannot stage incoming file.' ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
			if ( str_starts_with( $record['path'], 'uploads/' ) && ! chmod( $staged, 0600 ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $out );
				throw new RuntimeException( 'Cannot set media part permissions.' ); }
			$remaining = $record['size'];
			$filehash  = hash_init( 'sha256' );
			try {
				while ( $remaining > 0 ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read a bounded block from the current native stream offset.
					$bytes = fread( $fp, min( 65536, $remaining ) );
					if ( false === $bytes || '' === $bytes ) {
						throw new RuntimeException( 'Truncated file stream.' ); }
					CSM_Support::write( $out, $bytes );
					hash_update( $filehash, $bytes );
					$remaining -= strlen( $bytes );
					CSM_Progress::update( array( 'bytes' => $record['size'] - $remaining ) );
				}
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
				fclose( $out ); }
			$end = CSM_Support::line( $fp );
			if ( ( $end['type'] ?? '' ) !== 'file-end' || ! hash_equals( hash_final( $filehash ), $end['sha256'] ) ) {
				throw new RuntimeException( 'File checksum mismatch.' ); }
			hash_update( $hash, CSM_Support::json( $end ) );
			$files[] = array(
				'path'       => $record['path'],
				'mtime'      => (int) $record['mtime'],
				'size'       => $record['size'],
				'mode'       => ! str_starts_with( $record['path'], 'uploads/' ) && ( ( $record['mode'] ?? 0644 ) & 0111 ) ? 0755 : 0644,
				'stage'      => $staged,
				'sha256'     => $end['sha256'],
				'media_part' => str_starts_with( $record['path'], 'uploads/' ),
			);
			CSM_Progress::update( array( 'count' => count( $files ) ) );
		}
	}

	/**
	 * Derive the destination-adjacent private publication filename for one media file.
	 *
	 * @param array  $job Destination job and its durable staging or recovery state.
	 * @param string $relative Content-relative path subject to exclusion and link policies.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function media_part_path( array $job, string $relative ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $job['id'] ) || ! str_starts_with( $relative, 'uploads/' ) ) {
			throw new RuntimeException( 'Invalid media part.' ); }
		$target = self::destination( $relative, $job['selection'] );
		return dirname( $target ) . '/.trickle-' . $job['id'] . '-' . substr( hash( 'sha256', $relative ), 0, 20 ) . '.part';
	}

	/**
	 * Register a protected media staging path and its cleanup ownership record.
	 *
	 * @param array  $job Destination job and its durable staging or recovery state.
	 * @param string $relative Content-relative path subject to exclusion and link policies.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function media_stage( array $job, string $relative ): string {
		if ( ! wp_is_file_mod_allowed( 'trickle' ) ) {
			throw new RuntimeException( 'WordPress configuration disallows media file modifications.' ); }
		$path = self::media_part_path( $job, $relative );
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing or replaced file is handled by the following metadata validation or fallback.
		if ( is_link( $path ) || ( @lstat( $path )['nlink'] ?? 1 ) > 1 || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			throw new RuntimeException( 'Unsafe media part.' ); }
		if ( ! is_dir( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
			throw new RuntimeException( 'Cannot create media destination directory.' ); }
		// Register before creation so a crash before the next job checkpoint cannot orphan public-directory parts.
		$registry = CSM_Support::private_dir() . '/' . $job['id'] . '/media-parts';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private staging with explicit permissions; no FTP backend may replace it.
		if ( ! is_dir( $registry ) && ! mkdir( $registry, 0700 ) ) {
			throw new RuntimeException( 'Cannot create media part cleanup registry.' ); }
		CSM_Support::atomic_bytes( $registry . '/' . hash( 'sha256', $relative ) . '.json', CSM_Support::json( $relative ) );
		return $path;
	}

	/**
	 * Publish a checked media part and remove its registered temporary-file ownership.
	 *
	 * @param array  $job Destination job and its durable staging or recovery state.
	 * @param array  $file Recorded file metadata and expected content digest.
	 * @param string $target Destination filesystem path.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function publish_media( array $job, array $file, string $target ): void {
		$path = self::media_part_path( $job, $file['path'] );
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing or replaced file is handled by the following metadata validation or fallback.
		if ( $path !== $file['stage'] || self::destination( $file['path'], $job['selection'] ) !== $target || is_link( $path ) || ( @lstat( $path )['nlink'] ?? 1 ) > 1 ) {
			throw new RuntimeException( 'Media destination changed during receipt.' ); }
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.rename_rename -- Retain source timestamps or the recovery heartbeat on local storage. Same-filesystem rename is the atomic publication/recovery boundary.
			if ( ! touch( $path, $file['mtime'] ) || ! rename( $path, $target ) ) {
				throw new RuntimeException( 'Cannot publish media part.' ); }
		} else {
			// Publication may have succeeded just before its apply cursor was persisted.
			$receipt = null;
			if ( ! CSM_Support::verify_file( $target, $file['sha256'], $receipt ) ) {
				throw new RuntimeException( 'Published media is missing or changed.' ); }
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
		if ( ! chmod( $target, $file['mode'] ) ) {
			throw new RuntimeException( 'Cannot set media permissions.' ); }
	}

	/**
	 * Remove registered temporary media parts without deleting installed destination files.
	 *
	 * @param array $job Destination job and its durable staging or recovery state.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	public static function cleanup_media_parts( array $job ): void {
		$registry = CSM_Support::private_dir() . '/' . $job['id'] . '/media-parts';
		foreach ( (array) glob( $registry . '/*.json' ) as $record ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local file or input-stream bytes; no outbound HTTP request is made.
			$relative = json_decode( file_get_contents( $record ), true, 512, JSON_THROW_ON_ERROR );
			if ( ! is_string( $relative ) ) {
				throw new RuntimeException( 'Invalid media part cleanup record.' ); }
			$path = self::media_part_path( $job, $relative );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete the validated local file during cleanup or recoverable publication.
			if ( is_link( $path ) || ( file_exists( $path ) && ( ! is_file( $path ) || ! unlink( $path ) ) ) ) {
				throw new RuntimeException( 'Cannot remove media part.' ); }
		}
	}
}
