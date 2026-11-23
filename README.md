# Trickle

Trickle transfers selected database tables, plugins, themes, and media between WordPress sites. Connections are saved for subsequent pushes and pulls. Transfers can run from **Tools → Trickle** or WP-CLI.

## Installation

1. [Download the latest ZIP](https://github.com/jonschr/trickle/releases/latest/download/trickle.zip), install it on both sites, and activate it. Both sites must run the same Trickle version and build.
2. Open **Tools → Trickle** on the remote site and copy its connection code.
3. Add the connection on the controlling site.
4. Choose a direction and preset, adjust the selection, and start the transfer.

Treat connection codes like passwords. Selected items replace their destination counterparts. Plugin activation follows the source by default, with an option to retain destination settings.

## Transfer behavior

- File comparisons use size and modification time. Matching files are skipped; transferred contents retain stream checksums and installed-file verification.
- Changed plugins and themes replace complete components, including removal of obsolete files. Protected destination exclusions are retained.
- Plugins and themes stream into private destination staging. Media streams into adjacent, uniquely named `.part` files in uploads, then renames into place during apply after the existing rollback backup is saved. Media parts contain only media accepted by the existing file policy. Uploads is not a private storage boundary; database and code staging, credentials, and backups stay private. No web-server rewrite rule is required. There is no complete source archive or destination decompression archive. Bounded windows and verified frame-prefix checkpoints support interrupted push and pull requests. Request limits apply to individual steps, not the entire migration.
- Database exports retain their consistent snapshots. Existing snapshot transfers and explicitly selected chunked transport remain supported.
- Interrupted transfers retain checkpoints for resuming. Failed replacements attempt rollback; three destination backups are retained for manual restoration.
- Private component preparation and completed-component counters checkpoint in batches. Live replacements retain their durable journals; the complete recovery plan is persisted and checked before replacement begins. Replaying an unacknowledged batch is safe.
- Database imports use private staging tables before replacement. InnoDB, MyISAM, and MEMORY source tables are supported. Nontransactional source tables require read locks and `LOCK TABLES` permission; writes may wait during export. Related foreign-key tables must be selected together.
- Staged database inserts batch up to 250 rows and 256 KiB, reduced to fit the server packet allowance. Buffered rows and their cursor commit in the same transaction; individual larger rows keep the existing single-row path.
- Ordinary destination web requests pause during replacement and verification. Trickle control and recovery requests remain available.
- Installed-file verification checks changed media and rebuilt components. It verifies migration integrity, not application behavior.

## Network requests

The controlling site's PHP cURL transport sends authenticated HTTPS requests directly to the connected site at `/?trickle=<operation>`. Requests exchange inventories, comparison hashes, database and file exports, and transfer status. There is no external migration service.

The default migration and health-check header is:

```http
User-Agent: Trickle/1.0.0 (+https://github.com/jonschr/trickle)
```

The version follows the installed plugin. A `csm_user_agent` filter can override this value. These are plugin-originated requests, whether initiated through the dashboard or WP-CLI.

All peer requests, including health checks, setup, progress, transfers and retries, share a host-level scheduler on the controlling WordPress installation. Only one request to a host can be active. In Settings → Transfer preferences, two site-wide controls apply to every push, pull and Trickle update started here: startup/update spacing defaults to 0.5 seconds between setup request starts; quiet time after every request defaults to zero. Either can be set to zero to disable it. Startup spacing does not add a recurring delay to file/database windows. Progress polls run at five-second intervals and defer while that host is occupied. Separate PHP workers and connections to different paths on the same host share the budget.

Spacing remains editable while a transfer is retained; its transfer method and selection stay locked. Opening Settings or Diagnostics leaves the retained job paused. Return to Migrate to continue it.

When posts are replaced, Trickle removes stale WordPress ZIP-upgrader cleanup and future-post publication events, then rebuilds publication schedules from the imported posts. Unrelated destination cron jobs remain intact. Recovery and backup restoration reinstate the original post-specific schedules.

Optional configuration overrides: `CSM_REQUEST_INTERVAL` / `csm_request_interval` control quiet time; `CSM_SETUP_REQUEST_INTERVAL` / `csm_setup_request_interval` control startup spacing (0–60 seconds; filters receive the hostname as their second argument). Constants override saved preferences. These control request frequency, not transfer bandwidth. HTTP 429 and 430 responses retain a host-wide cooldown of at least 30 seconds and honor a longer `Retry-After`, including HTTP-date and non-JSON responses. Trickle retains the transfer and stops automatic retries; resume after the cooldown. Other clients and plugins do not share this scheduler.

Direct Trickle build sync negotiates a signed, bounded binary ZIP upload with compatible receivers, avoiding base64 JSON overhead. Older receivers retain the existing JSON format. Readiness can share the version response; installation still requires a write credential, validates the archive and its checksum, and checks the installed build. Rejected or uncertain updates are not blindly uploaded again; a later sync checks the receiver’s current build first.

Fresh destination preparation returns initial stream checkpoints, eliminating separate initial state probes. Compatible pushes verify the signed sender version/build and self-connection protection inside commit. Fresh discovery remains mandatory after an uncertain upload; pulls retain their final source version check.

## Requirements

Single-site WordPress 6.4+, 64-bit PHP 8.1+, cURL, writable private storage, and HTTPS for remote connections. Keep an independent backup before significant changes.

## Documentation

- WP-CLI command help: `wp help trickle`
- [Changelog](changes.md)
- [Releases](https://github.com/jonschr/trickle/releases)

## Development checks

Copied transfer diagnostics include a `performance` section with retained per-request elapsed measurements and process-wide CPU deltas, progress overhead, checksum read volume, checkpoint persistence and local apply phases. Timers are inclusive: nested work and concurrent polling overlap, so their totals must not be added. HTTP time includes remote production, network waits and local receive callbacks; it is not a network-only measurement. Samples use the existing bounded private diagnostic logs and introduce no extra requests. Remote samples remain on the remote site. On threaded runtimes such as FrankenPHP, process CPU can include other requests and must not be interpreted or summed as per-request CPU. PHP CLI does not report web bootstrap time. Run `php tests/performance-timing.php` for an isolated check of counters, progress throttling, exception handling and unavailable diagnostic storage.

Progress retains current counters and the five-second dashboard polling cadence while coalescing cancellation-marker reads to 50 ms. Forced updates check immediately, source deadlines are checked on every update, and file work loops retain their cancellation guards. Repeated cURL progress callbacks use the same interval and check again before accepting a response. `php tests/progress-cancellation.php` covers progress cancellation and deadline behavior; the HTTP download fixture also checks cancellation during a fast response. Component preparation and cursor replay checks are included in the isolated runner; `tests/directory-cycle.php` additionally exercises apply, restore and process interruption on explicitly named disposable sites.

Run `python3 tests/run-offline.py` for isolated checks. `python3 tests/request-pacing-network.py` checks real cURL spacing, cross-worker serialization, poll deferral, cancellation and persistent HTTP 429/430 cooldowns against a temporary loopback server. `python3 tests/http-auth-network.py` checks native Basic Auth, streaming and redirects on loopback. `tests/live-stream-cycle.py` exercises real interrupted push/pull streams only on its named disposable Cove peers; `tests/download-windows.py` exercises the snapshot compatibility transport over temporary local HTTP. Optional core API checks use `--wordpress-core /path/to/wordpress` without loading a site or its database. Integration scripts require their named disposable sites and are not part of this runner.

`tests/database-batches.php` exercises SQL failure, hard-exit recovery, small packet allowances and binary/null row preservation on the named disposable dual-benchmark receiver. `tests/scoped-manifest.php` checks attachment-query avoidance and unchanged dashboard fields on its matching source. Both require explicitly prepared fixtures.

Run `phpcs` with WordPress Coding Standards installed to audit first-party PHP using `.phpcs.xml.dist`. Existing standards findings are not suppressed. Shared design tokens are defined at the beginning of `assets/admin.css`.

Sites behind hosting-provider HTTP Basic Auth can enter the site-protection username and password under **Add a site → Site protection**. Saved credentials stay on the controlling site and are sent only to that connection’s URL; redirects are not followed. **Update connection** preserves saved credentials when both fields are blank, or removes them with the explicit removal checkbox.

File change selection uses size and modification time for plugins, themes and media; it does not read file contents to compare them before transfer. Transferred contents retain stream checksums and installed-file verification. Force retransfer sends matching files as well.

Pushes keep one data request active. While awaiting a receipt, the sending plugin can prepare one additional private replay window; the current window remains available until acknowledged. No helper process or additional receiver worker is required. Small-file checksum acceleration is bounded to 2 MiB; larger files keep streaming checksums.
