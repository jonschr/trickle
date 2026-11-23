<?php
/**
 * WP-CLI commands for selective migrations and recovery.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/** Manage saved sites and selective streamed transfers. */
final class CSM_CLI {
	/**
	 * Sanitized context attached to CLI failure output.
	 *
	 * @var array
	 */
	private static array $failure_context = array();

	/**
	 * Run a CLI operation with the site's HTTPS context and structured failure reporting.
	 *
	 * @param callable $operation Command callback whose result or failure is reported by the CLI guard.
	 * @param array    $assoc Named WP-CLI options.
	 */
	private static function guard( callable $operation, array $assoc = array() ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve and restore the exact server HTTPS value around the CLI operation.
		$https            = $_SERVER['HTTPS'] ?? null;
		$_SERVER['HTTPS'] = wp_parse_url( get_option( 'siteurl' ), PHP_URL_SCHEME ) === 'https' ? 'on' : 'off';
		try {
			return $operation(); } catch ( Throwable $e ) {
			if ( ( $assoc['format'] ?? '' ) === 'json' ) {
				$session = CSM_Controller::session();
				WP_CLI::line(
					wp_json_encode(
						array(
							'ok'             => false,
							'schema_version' => 1,
							'code'           => 'trickle_error',
							'error'          => $e->getMessage(),
						) + self::$failure_context + array( 'transfer_id' => $session['id'] ?? null ),
						JSON_UNESCAPED_SLASHES
					)
				);
				WP_CLI::halt( 1 );
			}
			WP_CLI::error( $e->getMessage() );
			} finally {
				if ( null === $https ) {
						unset( $_SERVER['HTTPS'] );
				} else {
					$_SERVER['HTTPS'] = $https; }
			}
	}

	/**
	 * Render structured command output in the requested supported format.
	 *
	 * @param array $data Operation-specific request fields, validated by the receiving operation.
	 * @param array $assoc Named WP-CLI options.
	 * @param array $fields Explicit table output columns; empty selects the command defaults.
	 */
	private static function output( array $data, array $assoc, array $fields = array() ): void {
		if ( ! empty( $data['activation_missing'] ) ) {
			WP_CLI::warning( 'Source plugins unavailable for activation: ' . implode( ', ', $data['activation_missing'] ) ); }
		unset( $data['state'], $data['nonce'] );
		if ( ( $assoc['format'] ?? '' ) === 'json' ) {
			WP_CLI::line( wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) );
			return; }
		$rows = array_is_list( $data ) ? $data : array( $data );
		\WP_CLI\Utils\format_items( 'table', $rows, $fields ? $fields : array( 'id', 'status', 'tables', 'rows', 'files', 'bytes', 'destination' ) );
	}

	/**
	 * List saved remote sites, without their private keys.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function sites( $args, $assoc ): void {
		self::guard( static fn() => self::output( CSM_Controller::state()['connections'], $assoc, array( 'id', 'name', 'url' ) ) );
	}

	/** List agent workflow presets.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function presets( $args, $assoc ): void {
		self::guard( static fn() => self::output( array_values( CSM_Presets::catalog() ), $assoc, array( 'id', 'name', 'direction', 'description' ) ), $assoc );
	}

	/** Read inventories and capabilities without staging a transfer.
	 *
	 * ## OPTIONS
	 *
	 * [--remote=<site>]
	 * : Include this saved remote; omit for local inventory only.
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function inventory( $args, $assoc ): void {
		self::guard(
			static function () use ( $assoc ) {
				$inventory = empty( $assoc['remote'] ) ? array( 'local' => CSM_Plugin::manifest() ) : CSM_Controller::inventory( CSM_Controller::peer( $assoc['remote'] ) );
				unset( $inventory['state'], $inventory['history'] );
				$inventory['version_controlled'] = CSM_Presets::version_controlled();
				if ( ( $assoc['format'] ?? '' ) === 'json' ) {
					self::output( $inventory, $assoc );
					return; }
				self::output(
					array_map(
						static fn( $site ) => array(
							'url'            => $site['url'],
							'plugin_version' => $site['plugin_version'],
							'plugins'        => count( $site['plugins'] ),
							'themes'         => count( $site['themes'] ),
							'tables'         => count( $site['tables'] ),
						),
						array_values( array_intersect_key( $inventory, array_flip( array( 'local', 'remote' ) ) ) )
					),
					$assoc,
					array( 'url', 'plugin_version', 'plugins', 'themes', 'tables' )
				);
			},
			$assoc
		);
	}

	/** Plan or run a complete preset workflow, retaining its destination backup.
	 *
	 * ## OPTIONS
	 *
	 * <preset>
	 * : pull-db, pull-db-missing, or push-git.
	 *
	 * [--remote=<site>]
	 * : Saved remote ID, URL or unique name; required when several are saved.
	 *
	 * [--dry-run]
	 * : Return a read-only selection plan, even with --yes.
	 *
	 * [--yes]
	 * : Run the plan through verification and replacement; otherwise only plan.
	 *
	 * [--keep-destination-plugins]
	 * : Preserve destination activation instead of copying the full source list.
	 *
	 * [--follow-symlinks=<paths>]
	 * : Follow approved source components, e.g. plugins/custom,themes/child. Destination links stay protected.
	 *
	 * [--include-git=<paths>]
	 * : Explicit per-transfer Git overrides; --yes never grants these.
	 *
	 * [--transport=<mode>]
	 * : stream (default): continuous pulls and 16 MiB push windows. chunks uses 512 KB requests. Both resume.
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function run( $args, $assoc ): void {
		self::guard(
			static function () use ( $args, $assoc ) {
				$preset = CSM_Presets::catalog()[ $args[0] ] ?? null;
				if ( ! $preset ) {
					throw new RuntimeException( 'Unknown preset. Use wp trickle presets.' ); }
				self::transfer( $preset['direction'], $assoc + array( 'preset' => $args[0] ), true );
			},
			$assoc
		);
	}

	/** Read a page of verified changes for the current staged transfer.
	 *
	 * ## OPTIONS
	 *
	 * [--offset=<offset>]
	 * : Start at this file-change offset; pages contain at most 200 items.
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function preview( $args, $assoc ): void {
		self::guard(
			static function () use ( $assoc ) {
				$session = self::current();
				$result  = CSM_Controller::execute(
					'preview',
					array(
						'id'     => $session['id'],
						'offset' => (int) ( $assoc['offset'] ?? 0 ),
					)
				);
				if ( ( $assoc['format'] ?? '' ) === 'json' ) {
					self::output( $result, $assoc ); } else {
								self::output( $result['items'], $assoc, array( 'action', 'path', 'before', 'after' ) ); }
			},
			$assoc
		);
	}

	/**
	 * Save a remote site's code once for both push and pull.
	 *
	 * ## OPTIONS
	 * --code-file=<path>
	 * : File containing the URL and key; use - to read standard input.
	 *
	 * [--name=<name>]
	 * : Connection label, such as Staging or Production.
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function connect( $args, $assoc ): void {
		self::guard(
			static function () use ( $assoc ) {
				$path = $assoc['code-file'];
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Missing local input is checked immediately and rejected or treated as an absent cache. Read exact local file or input-stream bytes; no outbound HTTP request is made.
				$code = '-' === $path ? stream_get_contents( STDIN ) : @file_get_contents( $path );
				if ( false === $code ) {
					throw new RuntimeException( 'Cannot read the connection code file.' ); }
				$result = CSM_Controller::execute(
					'connect',
					array(
						'pairing' => $code,
						'name'    => $assoc['name'] ?? '',
					)
				);
				$peer   = CSM_Controller::peer( $result['saved'] );
				self::output( array_intersect_key( $peer, array_flip( array( 'id', 'name', 'url' ) ) ), $assoc, array( 'id', 'name', 'url' ) );
			}
		);
	}

	/**
	 * Install this site's exact Trickle build on a connected remote site.
	 *
	 * @subcommand update-remote
	 *
	 * ## OPTIONS
	 *
	 * [--remote=<site>]
	 * : Saved site ID, exact URL, or unique name.
	 *
	 * [--check]
	 * : Only check installation readiness and permissions.
	 *
	 * [--yes]
	 * : Confirm the plugin update without an interactive prompt.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function update_remote( $args, $assoc ): void {
		self::guard(
			static function () use ( $assoc ) {
				if ( empty( $assoc['remote'] ) && count( CSM_Controller::peers() ) !== 1 ) {
					throw new RuntimeException( 'Specify --remote before checking or updating a remote Trickle installation.' ); }
				$peer = CSM_Controller::peer( $assoc['remote'] ?? '' );
				if ( isset( $assoc['check'] ) ) {
					$result = CSM_Controller::execute( 'remote_update_info', array( 'site' => $peer['id'] ) ); } else {
								WP_CLI::confirm( 'Update Trickle on ' . $peer['url'] . ' with this site’s installed Trickle build (' . CSM_VERSION . ')?', $assoc );
								$result = CSM_Controller::execute( 'update_remote', array( 'site' => $peer['id'] ) ); }
					self::output( $result, $assoc, isset( $assoc['check'] ) ? ( isset( $result['target_build'] ) ? array( 'installed', 'target', 'can_update_to_target', 'reason' ) : array( 'installed', 'target', 'release', 'can_update_to_target', 'reason' ) ) : array( 'installed', 'updated' ) );
			}
		);
	}

	/**
	 * Print this site's private connection code, or write it to a new private file.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Issue a separately revocable read key with this name, instead of the administrator key.
	 *
	 * [--write]
	 * : Also grant incoming writes to the named key; global site settings still apply.
	 *
	 * [--days=<days>]
	 * : Named-key lifetime from 1 to 90 days. Default: 30.
	 *
	 * [--output=<path>]
	 * : Create a new file with permissions 0600 instead of printing the secret.
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function code( $args, $assoc ): void {
		self::guard(
			static function () use ( $assoc ) {
				$code = isset( $assoc['name'] ) ? CSM_Access::issue( $assoc['name'], true, isset( $assoc['write'] ), (int) ( $assoc['days'] ?? 30 ) )['pairing'] : CSM_Plugin::pairing();
				if ( empty( $assoc['output'] ) ) {
					WP_CLI::line( $code );
					return; }
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Open failure is checked immediately; warnings must not corrupt the command or diagnostic response. Native handles retain offsets and locks across bounded streaming steps.
				$fp = @fopen( $assoc['output'], 'xb' );
				if ( ! $fp ) {
					throw new RuntimeException( 'Cannot create the code file; choose a new writable path.' ); }
				try {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply exact local permission bits required by staging and publication.
					if ( ! chmod( $assoc['output'], 0600 ) ) {
						throw new RuntimeException( 'Cannot make the code file private.' ); }
					CSM_Support::write( $fp, $code . "\n" );
				} finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native handle owned by this stream or lock.
					fclose( $fp ); }
				WP_CLI::line( 'Connection code written to a private file.' );
			}
		);
	}

	/** List named connection keys without their secrets.
	 *
	 * ## OPTIONS
	 *
	 * [--revoke=<id>]
	 * : Immediately revoke this key.
	 *
	 * [--format=<format>]
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function keys( $args, $assoc ): void {
		self::guard(
			static function () use ( $assoc ) {
				if ( isset( $assoc['revoke'] ) ) {
					CSM_Access::revoke( $assoc['revoke'] ); }
				self::output( CSM_Access::list(), $assoc, array( 'id', 'name', 'read', 'write', 'expires' ) );
			}
		);
	}

	/**
	 * List recent transfers and their exact selections.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json; json includes all selections and timestamps.
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function recent( $args, $assoc ): void {
		self::guard(
			static function () use ( $assoc ) {
				$rows = CSM_Controller::state()['recents'];
				if ( ( $assoc['format'] ?? '' ) !== 'json' ) {
					$rows = array_map(
						static fn( $r ) => array(
							'id'        => $r['id'],
							'date'      => gmdate( 'c', $r['started_at'] ),
							'direction' => $r['direction'],
							'remote'    => $r['remote_name'],
							'status'    => $r['status'],
						),
						$rows
					);
				}
				self::output( $rows, $assoc, array( 'id', 'date', 'direction', 'remote', 'status' ) );
			}
		);
	}

	/** List retained destination backups.
	 *
	 * ## OPTIONS
	 * [--remote=<site>]
	 * : Saved remote destination; omit for this site.
	 * [--format=<format>]
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function backups( $args, $assoc ): void {
		self::guard( static fn() => self::output( CSM_Controller::execute( 'backups', array( 'site' => $assoc['remote'] ?? '' ) )['backups'], $assoc, array( 'id', 'created', 'source' ) ) );
	}

	/** Restore a retained destination backup.
	 *
	 * @subcommand restore-backup
	 *
	 * ## OPTIONS
	 * <id>
	 * : Backup ID from trickle backups.
	 * [--remote=<site>]
	 * : Saved remote destination; omit for this site.
	 * [--yes]
	 * : Confirm replacement of the included destination items.
	 * [--format=<format>]
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function restore_backup( $args, $assoc ): void {
		self::guard(
			static function () use ( $args, $assoc ) {
				$site = $assoc['remote'] ?? '';
				$url  = '' === $site ? site_url() : CSM_Controller::peer( $site )['url'];
				WP_CLI::confirm( 'Restore backup ' . $args[0] . ' on ' . $url . '? Later changes to its included items will be overwritten.', $assoc );
				do {
					$result = CSM_Controller::execute(
						'restore_backup',
						array(
							'site' => $site,
							'id'   => $args[0],
						)
					);
				} while ( ! empty( $result['continue'] ) );
				self::output( $result, $assoc );
			}
		);
	}

	/**
	 * Show the exact automatic find/replace defaults for a remote and direction.
	 *
	 * ## OPTIONS
	 *
	 * [--remote=<site>]
	 * : Saved site ID, exact URL, or unique connection name.
	 *
	 * [--direction=<direction>]
	 * : push or pull; default pull.
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function replacements( $args, $assoc ): void {
		self::guard(
			static function () use ( $assoc ) {
				if ( empty( $assoc['remote'] ) && count( CSM_Controller::peers() ) !== 1 ) {
					throw new RuntimeException( 'Specify --remote when several sites are saved.' ); }
				$peer      = CSM_Controller::peer( $assoc['remote'] ?? '' );
				$direction = $assoc['direction'] ?? 'pull';
				if ( ! in_array( $direction, array( 'push', 'pull' ), true ) ) {
					throw new RuntimeException( 'Choose push or pull.' ); }
				$inventory = CSM_Controller::execute( 'manifest', array( 'site' => $peer['id'] ) );
				if ( ! $inventory['replacements'] ) {
					throw new RuntimeException( 'Update Trickle on both sites to preview replacements.' ); }
				self::output( $inventory['replacements'][ $direction ], $assoc, array( 'id', 'label', 'find', 'replace', 'enabled' ) );
			}
		);
	}

	/**
	 * Stage a push to a saved remote; applying requires --apply or a separate apply command.
	 *
	 * ## OPTIONS
	 *
	 * [--remote=<site>]
	 * : Saved site ID, exact URL, or unique connection name; required when several sites are saved.
	 *
	 * [--plugins=<ids>]
	 * : Comma-separated plugin folder IDs, or all.
	 *
	 * [--themes=<ids>]
	 * : Comma-separated theme IDs, or all. Required parent themes are included.
	 *
	 * [--tables=<names>]
	 * : Comma-separated source table names, or all.
	 *
	 * [--media=<mode>]
	 * : none, all, changed, uploaded, selected, or compare (alias for all). Default: none. All skips matching files.
	 *
	 * [--force-media]
	 * : Retransfer matching media files too; requires --media=all.
	 *
	 * [--media-ids=<ids>]
	 * : Comma-separated attachment IDs for selected media.
	 *
	 * [--uploaded-after=<date>]
	 * : Inclusive upload start date in UTC, YYYY-MM-DD, for --media=uploaded.
	 *
	 * [--uploaded-before=<date>]
	 * : Inclusive upload end date in UTC, YYYY-MM-DD, for --media=uploaded.
	 *
	 * [--since=<time>]
	 * : UTC ISO date/time or Unix timestamp for changed media; otherwise use the last media watermark.
	 *
	 * [--follow-symlinks=<paths>]
	 * : Follow approved source components, e.g. plugins/custom,themes/child. Destination links stay protected.
	 *
	 * [--include-git=<paths>]
	 * : Explicitly include these comma-separated Git folders for this transfer only.
	 *
	 * [--keep-destination-plugins]
	 * : Preserve the destination active plugin list instead of copying the source list.
	 *
	 * [--skip-replace]
	 * : Disable all database replacements, including custom rules.
	 *
	 * [--skip-default-replacements=<ids>]
	 * : Disable comma-separated default rule IDs shown by trickle replacements.
	 *
	 * [--replacements=<path>]
	 * : JSON file of custom find/replace rules to append to the defaults.
	 *
	 * [--recent=<id>]
	 * : Reuse one recent push's exact site and selection instead of component flags.
	 *
	 * [--apply]
	 * : Apply after staging and verification.
	 *
	 * [--yes]
	 * : Confirm application without an interactive prompt.
	 *
	 * [--transport=<mode>]
	 * : stream (default): continuous pulls and 16 MiB push windows. chunks uses 512 KB requests. Both resume.
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function push( $args, $assoc ): void {
		self::guard( static fn() => self::transfer( 'push', $assoc ) ); }

	/**
	 * Stage a pull from a saved remote. Options are identical to push.
	 *
	 * ## OPTIONS
	 *
	 * [--remote=<site>]
	 * : Saved site ID, exact URL, or unique connection name; required when several sites are saved.
	 *
	 * [--plugins=<ids>]
	 * : Comma-separated plugin folder IDs, or all.
	 *
	 * [--themes=<ids>]
	 * : Comma-separated theme IDs, or all. Required parent themes are included.
	 *
	 * [--tables=<names>]
	 * : Comma-separated source table names, or all.
	 *
	 * [--media=<mode>]
	 * : none, all, changed, uploaded, selected, or compare (alias for all). Default: none. All skips matching files.
	 *
	 * [--force-media]
	 * : Retransfer matching media files too; requires --media=all.
	 *
	 * [--media-ids=<ids>]
	 * : Comma-separated attachment IDs for selected media.
	 *
	 * [--uploaded-after=<date>]
	 * : Inclusive upload start date in UTC, YYYY-MM-DD, for --media=uploaded.
	 *
	 * [--uploaded-before=<date>]
	 * : Inclusive upload end date in UTC, YYYY-MM-DD, for --media=uploaded.
	 *
	 * [--since=<time>]
	 * : UTC ISO date/time or Unix timestamp for changed media; otherwise use the last media watermark.
	 *
	 * [--follow-symlinks=<paths>]
	 * : Follow approved source components, e.g. plugins/custom,themes/child. Destination links stay protected.
	 *
	 * [--include-git=<paths>]
	 * : Explicitly include these comma-separated Git folders for this transfer only.
	 *
	 * [--keep-destination-plugins]
	 * : Preserve the destination active plugin list instead of copying the source list.
	 *
	 * [--skip-replace]
	 * : Disable all database replacements, including custom rules.
	 *
	 * [--skip-default-replacements=<ids>]
	 * : Disable comma-separated default rule IDs shown by trickle replacements.
	 *
	 * [--replacements=<path>]
	 * : JSON file of custom find/replace rules to append to the defaults.
	 *
	 * [--recent=<id>]
	 * : Reuse one recent pull's exact site and selection instead of component flags.
	 *
	 * [--apply]
	 * : Apply after staging and verification.
	 *
	 * [--yes]
	 * : Confirm application without an interactive prompt.
	 *
	 * [--transport=<mode>]
	 * : stream (default): continuous pulls and 16 MiB push windows. chunks uses 512 KB requests. Both resume.
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function pull( $args, $assoc ): void {
		self::guard( static fn() => self::transfer( 'pull', $assoc ) ); }

	/**
	 * Prepare and execute an authorized CLI push or pull selection.
	 *
	 * @param string $direction Migration direction: push or pull.
	 * @param array  $assoc Named WP-CLI options.
	 * @param bool   $automated Whether a higher-level preset workflow owns execution and cleanup.
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 * @throws Throwable If underlying migration work fails and its original error is rethrown.
	 */
	private static function transfer( string $direction, array $assoc, bool $automated = false ): void {
		if ( ! $automated && get_option( '_csm_control' ) ) {
			throw new RuntimeException( 'A transfer is retained. Use status, resume, apply, rollback or discard before starting another.' ); }
		if ( ! empty( $assoc['recent'] ) ) {
			$recent = CSM_Controller::recent( $assoc['recent'] );
			if ( $recent['direction'] !== $direction ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( 'This recent transfer uses the other direction; use trickle ' . $recent['direction'] . '.' ); }
			if ( array_intersect( array_keys( $assoc ), array( 'remote', 'plugins', 'themes', 'tables', 'media', 'transport', 'force-media', 'rehash-files', 'rehash-media', 'media-ids', 'uploaded-after', 'uploaded-before', 'since', 'keep-destination-plugins', 'skip-replace', 'skip-default-replacements', 'replacements' ) ) ) {
				throw new RuntimeException( 'Use --recent alone to reuse exact settings, or specify a new remote and selection.' ); }
			$peer      = CSM_Controller::peer( $recent['remote_id'] );
			$selection = $recent['selection'];
		} else {
			$peers = CSM_Controller::peers();
			if ( empty( $assoc['remote'] ) && count( $peers ) !== 1 ) {
				throw new RuntimeException( 'Specify --remote using a saved site ID, URL, or unique name.' ); }
			$peer      = CSM_Controller::peer( $assoc['remote'] ?? array_key_first( $peers ) );
			$inventory = CSM_Controller::inventory( $peer );
			if ( $automated ) {
				$assoc += CSM_Presets::resolve( $assoc['preset'], $inventory ); }
			$source = $inventory[ 'push' === $direction ? 'local' : 'remote' ];
			if ( ( $assoc['media'] ?? '' ) === 'uploaded' && empty( $source['media_dates'] ) ) {
				throw new RuntimeException( 'Update Trickle on the source site before transferring by upload date.' ); }
			$selection = array(
				'transport'           => $assoc['transport'] ?? 'stream',
				'sync_active_plugins' => ! isset( $assoc['keep-destination-plugins'] ),
				'media'               => $assoc['media'] ?? ( isset( $assoc['media-ids'] ) ? 'selected' : 'none' ),
				'replace'             => ! isset( $assoc['skip-replace'] ),
				'since'               => 0,
				'media_ids'           => array(),
				'media_force'         => isset( $assoc['force-media'] ),
				'media_after'         => $assoc['uploaded-after'] ?? '',
				'media_before'        => $assoc['uploaded-before'] ?? '',
			);
			if ( $selection['media_force'] && ! in_array( $selection['media'], array( 'all', 'compare' ), true ) ) {
				throw new RuntimeException( '--force-media requires --media=all.' ); }
			foreach ( array( 'plugins', 'themes', 'tables' ) as $kind ) {
				$selection[ $kind ] = ( $assoc[ $kind ] ?? '' ) === 'all' ? array_column( array_filter( $source[ $kind ], static fn( $item ) => empty( $item['protected'] ) || ( ! empty( $item['symlinks'] ) && in_array( $kind . '/' . $item['id'], self::csv( $assoc['follow-symlinks'] ?? '' ), true ) ) ), 'id' ) : self::csv( $assoc[ $kind ] ?? '' );
			}
			if ( $inventory['replacements'] ) {
				$selection['replacements'] = $inventory['replacements'][ $direction ];
				$skipped                   = self::csv( $assoc['skip-default-replacements'] ?? '' );
				$unknown                   = array_diff( $skipped, array_column( $selection['replacements'], 'id' ) );
				if ( $unknown ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
					throw new RuntimeException( 'Unknown default replacement IDs: ' . implode( ', ', $unknown ) . '. Use trickle replacements to list the available IDs.' ); }
				foreach ( $selection['replacements'] as &$rule ) {
					$rule['enabled'] = ! in_array( $rule['id'], $skipped, true ); }
				unset( $rule );
				if ( isset( $assoc['replacements'] ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Missing local input is checked immediately and rejected or treated as an absent cache. Read exact local file or input-stream bytes; no outbound HTTP request is made.
					$file = @file_get_contents( $assoc['replacements'] );
					if ( false === $file ) {
						throw new RuntimeException( 'Cannot read the custom replacement JSON file.' ); }
					$custom = json_decode( $file, true, 64, JSON_THROW_ON_ERROR );
					if ( ! is_array( $custom ) || ! array_is_list( $custom ) ) {
						throw new RuntimeException( 'The custom replacement file must contain a JSON array of rules.' ); }
					foreach ( $custom as $rule ) {
						if ( ! is_array( $rule ) ) {
							throw new RuntimeException( 'Each custom replacement must be an object with find and replace text.' ); }
						$rule['kind']                = 'custom';
						$selection['replacements'][] = $rule;
					}
				}
			} elseif ( isset( $assoc['replacements'] ) || isset( $assoc['skip-default-replacements'] ) ) {
				throw new RuntimeException( 'Update Trickle on both sites before configuring replacement rules.' ); }
			foreach ( $source['themes'] as $theme ) {
				if ( ( ! $automated || 'push-git' !== $assoc['preset'] ) && in_array( $theme['id'], $selection['themes'], true ) && $theme['parent'] ) {
					$selection['themes'][] = $theme['parent']; }
			}
			$selection['themes'] = array_values( array_unique( $selection['themes'] ) );
			foreach ( self::csv( $assoc['media-ids'] ?? '' ) as $id ) {
				if ( ! ctype_digit( $id ) || (int) $id < 1 ) {
					throw new RuntimeException( 'Media IDs must be positive attachment IDs.' ); }
				$selection['media_ids'][] = (int) $id;
			}
			if ( isset( $assoc['since'] ) ) {
				$stamp = ctype_digit( $assoc['since'] ) ? (int) $assoc['since'] : strtotime( $assoc['since'] . ' UTC' );
				if ( ! $stamp ) {
					throw new RuntimeException( 'Use a valid UTC date/time or Unix timestamp for --since.' ); }
				$selection['since'] = $stamp;
			} elseif ( 'changed' === $selection['media'] ) {
				$selection['since'] = $inventory['history'][ $direction . ':' . $peer['url'] ] ?? 0; }
		}
		if ( ! in_array( $selection['transport'] ?? 'stream', array( 'stream', 'chunks' ), true ) ) {
			throw new RuntimeException( 'Choose --transport=stream or --transport=chunks.' ); }
		$selection['follow_symlinks'] = self::csv( $assoc['follow-symlinks'] ?? '' );
		if ( $automated ) {
			$plan = CSM_Presets::plan( $assoc['preset'], $peer, $inventory, $selection, self::csv( $assoc['include-git'] ?? '' ) );
			if ( isset( $assoc['dry-run'] ) || ! isset( $assoc['yes'] ) ) {
				self::output( $plan, $assoc, array( 'preset', 'direction', 'source', 'destination', 'can_run' ) );
				return;
			}
			if ( ! $plan['can_run'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is data; JSON/CLI and context-escaped UI handlers own output encoding.
				throw new RuntimeException( implode( ' ', array_column( $plan['blockers'], 'message' ) ) ); }
			$previous = CSM_Controller::session();
			if ( $previous ) {
				CSM_Controller::execute( 'finish', array( 'id' => $previous['id'] ) ); }
			$assoc['apply'] = true;
		}
		$summary = CSM_Controller::execute(
			'prepare',
			array(
				'site'          => $peer['id'],
				'direction'     => $direction,
				'selection'     => $selection,
				'git_confirmed' => self::csv( $assoc['include-git'] ?? '' ),
			)
		);
		$session = CSM_Controller::session();
		if ( $automated ) {
			self::$failure_context = array(
				'transfer_id' => $session['id'],
				'source'      => $session['source'],
				'destination' => $session['destination'],
			); }
		try {
			foreach ( CSM_Plugin::kinds( $selection ) as $kind ) {
				do {
					$summary = CSM_Controller::execute(
						'transfer',
						array(
							'id'   => $summary['id'],
							'kind' => $kind,
						)
					);
				} while ( ! empty( $summary['continue'] ) ); }
			if ( isset( $assoc['apply'] ) ) {
				WP_CLI::confirm( 'Apply the verified transfer to ' . $session['destination'] . '? Selected destination tables and files will be replaced.', $assoc );
				do {
					$summary = CSM_Controller::execute( 'commit', array( 'id' => $session['id'] ) );
				} while ( ! empty( $summary['continue'] ) );
			}
		} catch ( Throwable $e ) {
			if ( ! $automated ) {
				throw $e; }
			if ( $e instanceof CSM_Network_Error ) {
				self::$failure_context += array(
					'recovery'    => 'retained',
					'retry_after' => $e->retry_after,
				);
				throw $e; }
			try {
				$recovery                          = CSM_Controller::execute(
					'recover',
					array(
						'id'     => $session['id'],
						'cancel' => true,
					)
				);
				self::$failure_context['recovery'] = $recovery['status'];
				if ( in_array( $recovery['status'], array( 'applied', 'restored' ), true ) ) {
					CSM_Controller::execute( 'finish', array( 'id' => $session['id'] ) ); }
				if ( 'applied' !== $recovery['status'] ) {
					throw $e; }
				$summary = $recovery;
			} catch ( Throwable $recovery_error ) {
				self::$failure_context['recovery'] = self::$failure_context['recovery'] ?? 'unavailable';
				throw $e;
			}
		}
		if ( $automated ) {
			$finished = CSM_Controller::session() ? CSM_Controller::execute( 'finish', array( 'id' => $session['id'] ) ) : array();
			$summary += array(
				'ok'             => true,
				'schema_version' => 1,
				'action'         => 'transfer',
				'completed'      => true,
				'preset'         => $assoc['preset'],
				'backup_id'      => $summary['id'],
			);
			if ( ! empty( $finished['cleanup_warning'] ) ) {
				$summary['cleanup_warning'] = $finished['cleanup_warning']; }
			self::$failure_context = array();
		}
		unset( $summary['nonce'] );
		self::output(
			$summary + array(
				'source'      => $session['source'],
				'destination' => $session['destination'],
				'direction'   => $direction,
			),
			$assoc
		);
	}

	/**
	 * Normalize a comma-separated CLI selection into distinct nonempty values.
	 *
	 * @param string $value Value to encode or transform.
	 */
	private static function csv( string $value ): array {
		return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( $item ) => '' !== $item ) ) ); }

	/**
	 * Require a retained controller transfer before continuing CLI work.
	 *
	 * @throws RuntimeException If the operation fails validation or cannot complete safely.
	 */
	private static function current(): array {
		$session = CSM_Controller::session();
		if ( ! $session ) {
			throw new RuntimeException( 'No transfer is retained.' ); }
		return $session;
	}

	/**
	 * Show the retained transfer and its source/destination.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function status( $args, $assoc ): void {
		self::guard(
			static function () use ( $assoc ) {
				$session = self::current();
				$summary = CSM_Controller::execute( 'status', array( 'id' => $session['id'] ) );
				self::output(
					$summary + array(
						'source'      => $session['source'],
						'destination' => $session['destination'],
					),
					$assoc
				);
			}
		);
	}

	/**
	 * Retry incomplete streams of the retained transfer without applying.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function resume( $args, $assoc ): void {
		self::guard(
			static function () use ( $assoc ) {
				$session = self::current();
				$summary = CSM_Controller::execute( 'status', array( 'id' => $session['id'] ) );
				if ( 'staging' !== $summary['status'] ) {
					throw new RuntimeException( 'Only a staging transfer can resume its streams.' ); }
				foreach ( $summary['pending'] as $kind ) {
					do {
								$summary = CSM_Controller::execute(
									'transfer',
									array(
										'id'   => $session['id'],
										'kind' => $kind,
									)
								);
					} while ( ! empty( $summary['continue'] ) ); }
				self::output(
					$summary + array(
						'source'      => $session['source'],
						'destination' => $session['destination'],
					),
					$assoc
				);
			}
		);
	}

	/**
	 * Apply the retained, verified transfer.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 *
	 * [--format=<format>]
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function apply( $args, $assoc ): void {
		self::guard( static fn() => self::finish( 'commit', 'Apply the verified transfer to', $assoc ) ); }

	/**
	 * Restore the destination backup, overwriting changes since application.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 *
	 * [--format=<format>]
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function rollback( $args, $assoc ): void {
		self::guard( static fn() => self::finish( 'rollback', 'Restore the pre-transfer backup on', $assoc ) ); }

	/**
	 * Delete retained staging data and rollback backup. Applied changes remain.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 *
	 * [--format=<format>]
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function discard( $args, $assoc ): void {
		self::guard( static fn() => self::finish( 'discard', 'Permanently delete the retained staging data and backup for', $assoc ) ); }

	/** Request cancellation without waiting for the current worker lock.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 *
	 * [--format=<format>]
	 *
	 * @param array $args Positional WP-CLI arguments.
	 * @param array $assoc Named WP-CLI options.
	 */
	public function cancel( $args, $assoc ): void {
		self::guard(
			static function () use ( $assoc ) {
				$session = self::current();
				WP_CLI::confirm( 'Cancel the transfer to ' . $session['destination'] . '?', $assoc );
				CSM_Controller::execute( 'cancel', array( 'id' => $session['id'] ) );
				$result = CSM_Controller::retry_cancel( $session['id'] );
				$status = $result['status'] ?? ( CSM_Controller::session() ? 'cancelling' : 'cancelled' );
				self::output(
					array(
						'id'          => $session['id'],
						'status'      => $status,
						'destination' => $session['destination'],
					),
					$assoc,
					array( 'id', 'status', 'destination' )
				);
			},
			$assoc
		);
	}

	/**
	 * Confirm and execute a terminal action on the retained CLI transfer.
	 *
	 * @param string $action Supported controller, peer or retained-transfer operation.
	 * @param string $question Confirmation text shown before a destructive retained-transfer action.
	 * @param array  $assoc Named WP-CLI options.
	 */
	private static function finish( string $action, string $question, array $assoc ): void {
		$session = self::current();
		CSM_Controller::execute( 'status', array( 'id' => $session['id'] ) );
		WP_CLI::confirm( $question . ' ' . $session['destination'] . '?', $assoc );
		do {
			$result = CSM_Controller::execute( $action, array( 'id' => $session['id'] ) );
		} while ( ! empty( $result['continue'] ) );
		unset( $result['nonce'] );
		self::output(
			( $result ? $result : array(
				'id'     => $session['id'],
				'status' => 'discarded',
				'tables' => 0,
				'rows'   => 0,
				'files'  => 0,
				'bytes'  => 0,
			) ) + array(
				'source'      => $session['source'],
				'destination' => $session['destination'],
			),
			$assoc
		);
	}
}
