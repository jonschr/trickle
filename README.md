# Trickle 0.1.7 — preview

A shared WordPress plugin for selective push/pull migrations, controlled from the local site's **Tools → Trickle** page. Install the same ZIP at both ends.

## Included

- Select plugin folders, themes, and individual database tables.
- Review the exact default URL/directory find-and-replace values, disable individual rules, or add custom literal replacements. A master switch disables all replacements. Serialized string lengths are rebuilt without instantiating objects.
- Compare installed plugin/theme versions at both ends. Update badges use each site's WordPress update information, including vendor updates exposed through WordPress. **Refresh versions** requests fresh information; a missing badge is not proof that the vendor has no newer version.
- Compare media in a year → month → file cascade, skipping matching years and months and transferring only changed or missing files. Cached content hashes are reused after scanning local filesystem metadata. Both sites need 0.1.6+ for this mode; older transfer modes remain available.
- Transfer all uploads, files modified since the last successful all/changed media transfer, selected attachments and their generated image sizes, or attachments uploaded in an inclusive UTC date range. The visual picker shows source thumbnails and upload dates newest first, supports click/Shift-click selection, and loads older attachments in pages of 60. Upload-date transfers cover the full attachment library; all/changed modes cover the entire uploads directory.
- Save named remote sites once and select Staging, Production, or another remote before choosing push/pull. The same connection works in both directions; the remote never initiates a connection back to local.
- Reuse the exact settings of the latest 20 transfers, including remote, direction, component selections, explicit replacement rules with enabled flags, and changed-media cutoff. Recent entries keep timestamps and status, not additional backup copies.
- Use the same transfer controller from the dashboard or WP-CLI; CLI transfers stage by default and apply only when explicitly requested.
- Send one gzip-compressed database stream and one gzip-compressed file stream per transfer, when those components are selected. No complete export archive is built on the source disk.
- Stage privately, verify stream/file checksums, review counts, then explicitly apply.
- Retain destination database tables and replaced/deleted files for rollback until **Discard transfer & backup**.

Progress reports the current relative plugin/theme/media path or database table, processed counts, and streamed bytes. The dashboard polls about once per second; quick components may pass between updates. A failure retains its phase, exact component and error, including through automatic rollback. Progress snapshots live outside the webroot and are read without the transfer mutation lock. A hosting timeout can leave only the last reported activity; that does not prove which operation caused the timeout.

Component headers start unchecked, in Database → Plugins → Themes → Media library order. Enabling a component initially opens its settings and selects all transferable items (all files for media). Its separate disclosure arrow can collapse or reopen the settings while the component stays enabled and its selections remain unchanged. The inner select-all control changes individual selections independently: clearing a list leaves the component enabled and open. Disabling the component clears its selections and closes its settings. Refresh and recent settings preserve component enablement, expansion and selected items independently; older presets infer enabled components from their selections.

Self-connections are excluded from the saved-site selector and rejected by the controller before staging or applying. Identity checks normalize hostname case, trailing slashes, protocol differences and default ports while preserving distinct installation paths and nonstandard ports. The authenticated manifest also exposes the canonical site URL and a salted installation fingerprint so aliases can be detected without exposing keys or filesystem paths. Existing recovery records are retained.

## Installation and use

1. Upload `trickle-0.1.7.zip` through Plugins → Add Plugin on both sites and activate it.
   Activation installs `wp-content/mu-plugins/0-trickle-loader.php`. That directory must be writable. The loader isolates streaming/recovery requests and the Trickle dashboard from ordinary plugins and theme code, so a broken active plugin need not block rollback. Inventory requests load ordinary plugins so their vendor update filters still work. A failed inventory request does not hide an existing transfer's recovery controls. Other must-use plugins and host firewalls still run. The loader is dormant when Trickle is deactivated.
2. Open Tools → Trickle on the remote site. Reads are allowed by default; incoming remote writes are disabled by default. Enable incoming writes if you want to push into this site.
3. Use **Copy connection code** at the top of the remote Trickle page, then paste both lines into the local site's connection panel. A complete code connects automatically: the visible site URL followed by its private key. Check the URL before pasting. Permission checkboxes save automatically. Treat the code like a password; do not put the key in a URL or support ticket. Older base64 connection codes are still accepted.
4. Select the saved remote site, then push or pull, review source/destination versions, and select components. A single saved remote is selected automatically. With several, the dashboard remembers the selected site. Selecting a child theme also selects its parent.
5. Select **Prepare push** or **Prepare pull**. This reads the source and prepares temporary destination tables/files without replacing live content. The transfer page shows which stream is running, not a precise byte percentage.
6. Review the verified table, row and file counts and destination URL, then **Push** or **Pull**. The dashboard shows **Pushing…** / **Pulling…** while applying and **Push complete** / **Pull complete** only after success.
7. Keep the rollback backup until satisfied, then discard it. Only one pending/retained transfer is allowed per destination and per controlling site. The retained transfer stays pinned to its original remote until discarded. Adding another connection does not move its recovery controls.

The connection-code input is empty after saving because the credentials are stored on the controlling site. Do not paste the code again to change direction. Use **Recent transfers** to restore earlier settings without starting a transfer. Reused changed-media settings retain their original UTC cutoff; choose a new cutoff or change media mode if you want different behavior. If saved components are no longer in the source inventory, the dashboard asks you to make a new selection rather than silently transferring a subset.

The preview respects `DISALLOW_FILE_MODS` and filesystem permissions. It does not grant FTP access or bypass file ownership restrictions. New plugins are not automatically activated; destination activation state is preserved.

## Plugin updates

Trickle bundles Plugin Update Checker 5.7 and initializes its static JSON checker at plugin load, matching Guest Key. Its feed is `https://raw.githubusercontent.com/jonschr/trickle/master/update.json`; this is a JSON endpoint, not PUC’s GitHub repository/release-discovery mode. It registers in frontend, admin, cron, AJAX, REST and WP-CLI contexts. WordPress gets the normal update notice, version-details dialog and **Check for updates** link. Automatic installation follows WordPress/host settings; the updater does not force-enable it.

`Update URI: https://github.com/jonschr/trickle` identifies the custom update origin and avoids unrelated WordPress.org updates. `CSM_UPDATE_URL` can be defined before the plugin loads to override the feed for testing or another HTTPS endpoint. The default check interval is 12 hours; ordinary dashboard checks and manual checks can run sooner.

To publish a release, keep the plugin header, `CSM_VERSION` and `update.json` version/requirements in sync. Build a ZIP with a single `trickle/` root containing `trickle.php`, `update.json`, `README.md`, `assets/`, `includes/` and `vendor/`; exclude tests, Git metadata and environment files. Commit the release source, create its matching version tag (for example `0.1.7`), publish the release ZIP at the exact `download_url`, then publish the updated JSON on `master`. Make the ZIP available before advertising a newer version in the public feed. The configured feed and versioned ZIP must both be publicly accessible for default updates to work. Existing installations without this integration need one manual ZIP update first.

The updater test uses an intercepted local JSON response and a disposable next-version ZIP on idle QA-C: `python3 tests/updater.py`. It verifies current/older/newer metadata, native update/details hooks, HTTP/JSON failures, scheduled checks and a real WordPress ZIP upgrade. It restores the test plugin header, metadata and activation afterward. It does not publish a release or update a live site.

## WP-CLI

The plugin registers `wp trickle` when active. List saved remotes with no credential output:

```sh
wp trickle sites --format=json
wp trickle recent --format=json
```

On the remote, write a connection code to a new private file; on the controlling site, import it once. `--code-file=-` reads standard input instead. Avoid putting private keys in command arguments or shell history. `code` without `--output` intentionally prints the private code.

```sh
wp trickle code --output=/private/path/remote.code
wp trickle connect --code-file=/private/path/remote.code --name=Staging
```

Stage a selective transfer, review the reported destination and counts, then apply explicitly. `--remote` accepts a saved ID, exact URL, or unique name. It is required when more than one remote is saved.

```sh
wp trickle push --remote=Staging --plugins=classic-editor --themes=my-theme --tables=wp_options --format=json
wp trickle status --format=json
wp trickle apply --yes --format=json
```

`pull` accepts the same flags. Plugin/theme/table flags accept comma-separated IDs or `all`; plugin `all` excludes Trickle. Child-theme selections include their parent. Media flags are `--media=all`, `--media=changed --since=2026-10-01T00:00:00Z`, `--media=selected --media-ids=12,34`, `--media=compare` (optionally `--rehash-media` for a full content recheck), or `--media=uploaded --uploaded-after=2026-10-01 --uploaded-before=2026-10-31`. Upload dates are UTC and inclusive; provide at least one date boundary. Changed media without `--since` uses the last successful media watermark and fails if there is no initial cutoff. Database find & replace is enabled unless `--skip-replace` is given; that flag disables custom rules too. `wp trickle replacements --remote=Staging --direction=push --format=json` lists the exact default mappings and their IDs. `--skip-default-replacements=root` disables an individual default, and `--replacements=/private/path/rules.json` appends custom rules from a JSON array such as `[{"find":"old text","replace":"new text","enabled":true}]`. An empty replacement deletes matching text. When defaults share a search string they are combined; use the IDs reported by the preview command.

To stage and apply in one invocation, explicitly include `--apply`. Application prompts for the destination unless `--yes` is also supplied. `--yes` alone does not apply a staged transfer. Use WordPress's global `--user=<administrator>` when importing users and you want that local administrator preserved, as in the dashboard.

Reuse a recent transfer's exact settings with its matching direction; component flags and a new remote cannot be combined with `--recent`:

```sh
wp trickle pull --recent=<recent-pull-id> --format=json
wp trickle resume --format=json
wp trickle rollback --yes --format=json
wp trickle discard --yes --format=json
```

`resume` retries only incomplete staging streams. `rollback` restores the current destination backup, overwriting later changes. `discard` permanently removes current staging/backup data while keeping the recent settings. These commands never silently discard another retained transfer. CLI operations run in the foreground; a killed process leaves the same recovery state as an interrupted dashboard request. WP-CLI must be able to bootstrap WordPress; its usual `--skip-plugins=<broken-plugin>` flag can exclude an unrelated broken plugin while leaving Trickle active.

## Database find & replace

The database section shows the actual source and destination values for WordPress URL, home URL, WordPress directory and content directory. Identical search strings share one row; the home/content mapping retains the legacy precedence when default searches coincide. Each row can be disabled, and custom rows can be added, edited, disabled or removed. To change an automatic mapping, disable that default and add your desired custom mapping.

Rules are literal and case-sensitive, with longer searches taking precedence. They run as a single pass: text inserted by a replacement is not searched again. Conflicting enabled rules with the same search but different replacement values are rejected before staging. Empty enabled searches are rejected; empty replacement values are allowed. Up to 100 rules and 64 KB of combined JSON configuration are accepted.

Only selected database text columns are processed, including nested serialized strings and supported serialized objects. Values with no replacement matches remain byte-for-byte unchanged, including malformed legacy serialized values. Malformed serialized values that require changes still stop the import. Binary columns and file contents are unchanged. Destination identity, Trickle state and other preserved options remain protected independently. Replacement rules are sent only to the receiver when database tables are selected, not to a pull source that only exports data. The replacement list and its enabled flags are captured in recent-transfer settings; replay uses those exact strings. Older recent entries that only contain the original checkbox setting continue to calculate the legacy defaults.

Both sites should run this version to preview all filesystem mappings. A database push with explicit rules checks destination support and stops if the receiver would ignore them. Existing legacy transfers and their recovery data remain supported.

## Cascading media comparison

Choose **Only changed or missing files (compare sites)**, or use `--media=compare` with either `push` or `pull`. The controller first compares the year hashes. It requests month hashes only for differing years and individual file hashes only for differing existing months. Entirely missing month folders can be selected without fetching their individual manifests. All selected bytes still travel in the existing single file stream, with per-file and stream checksum verification. Matching folders do not get file comparison requests. Destination-only media is kept; this mode does not mirror deletions or advance the date-based transfer watermark.

The first comparison builds a private checksum cache on each site. Later comparisons scan file names, sizes, modification/change times, inode, device and mode to invalidate changed entries. Matching cached years reuse their month and file hashes without rebuilding them. Differing years rebuild only changed months, and those months hash only new or changed file contents. Very recent files are conservatively rehashed to avoid trusting coarse timestamp resolution. Files directly inside uploads, files directly inside a year, and non-date folders are also included using the same first/second directory level grouping.

A metadata scan is still required: directory timestamps alone cannot detect edits to existing files. Metadata caching is a practical shortcut, not a guarantee against metadata-preserving filesystem restores or silent corruption. Select **Recalculate all file checksums** or pass `--rehash-media` to bypass the content cache for a full verification. The comparison still descends only through differing folders. Keep media stable during comparison and transfer; a selected source file that changes afterward stops the stream rather than exporting an unverified change. Retrying the file stream runs a fresh comparison. A concurrently replaced inventory also stops safely and can be retried.

Cached hashes and filesystem metadata are stored atomically in a private file outside the webroot, with mode 0600. Year/month requests are batched; individual manifests paginate at 500 files. The compressed changed-file selection is limited to 700 KB and expands to at most 32 MB. If a very large delta exceeds that limit, use the full media transfer. Recent settings store the comparison mode and verification flag, not old snapshots or per-file selections, so reusing them compares the sites again. The staged/complete summary reports year skips, month skips and individual comparison counts.

## Transfer protocol

All remote requests are HTTP POSTs to the WordPress installation URL with a dedicated `trickle` query parameter. These are lightweight plugin endpoints, not REST or admin-ajax handlers. The local dashboard uses normal authenticated admin AJAX to invoke its controller.

| Action | Request | Response | Permission |
| --- | --- | --- | --- |
| `manifest` | Small JSON body | Version, component and table inventory | Remote reads |
| `media` | JSON page or attachment IDs | Read-only attachment previews and upload dates | Remote reads |
| `media_hashes` | Year, selected month, or paginated file query | Cached media checksums; no application data changes | Remote reads |
| `export` | JSON selection and stream kind | One continuous gzip body | Remote reads |
| `prepare` | JSON selection | Destination transfer ID | Incoming writes |
| `receive` | Continuous gzip body, signed ID/kind headers | Validated staging summary | Incoming writes |
| `status` | JSON transfer ID | Staging/apply status | Incoming writes |
| `progress` | JSON transfer ID | Read-only live phase, component, counts and retained failure | Remote reads or incoming writes |
| `commit` | JSON transfer ID | Apply result | Incoming writes |
| `rollback` | JSON transfer ID | Restore result | Incoming writes |
| `discard` | JSON transfer ID | Cleanup result | Incoming writes |

Control requests carry a SHA-256 body digest and an HMAC-SHA256 signature. `X-CSM-Time`, `X-CSM-Nonce`, `X-CSM-Hash`, `X-CSM-Params`, and `X-CSM-Signature` authenticate requests. Timestamps have a five-minute window; nonces are single-use. Stream uploads authenticate their transfer ID/kind in signed headers and rely on verified TLS plus final stream/file digests for body integrity. Connection secrets never appear in request URLs. Redirects are not followed. The user agent is `Trickle/0.1.7 (WordPress site migration)`.

Database streams contain schema records and base64-encoded row values. They are logical data streams, not SQL files to execute wholesale. The receiver validates schemas and constructs its own inserts. The source uses one InnoDB repeatable-read snapshot, reading bounded batches with primary-key pagination where available. Tables without primary keys use offset pagination. Ordinary serialized strings, arrays, objects and references are handled without instantiating objects; unsupported custom serialization that requires replacement stops the import. Binary columns are not searched/replaced.

File streams contain path/size/mtime headers, raw file bytes, per-file SHA-256 trailers, and a final manifest digest. No archive-extraction library or shell command is required. Only selected plugin/theme paths and permitted uploads are accepted; symlinks, traversal, executable uploads, and replacing Trickle itself are blocked.

On pull, local cURL writes the compressed stream into private temporary storage before parsing it into staging. On push, cURL reads the source generator directly and sends a streaming POST to the remote receiver. Hosting proxies may buffer incoming uploads before PHP sees them. There is no requirement that the remote server can resolve or reach the local hostname.

Database apply uses one multi-table `RENAME TABLE` operation to exchange staged and destination tables. File apply backs up original destination files and saves a complete checksummed recovery plan outside the webroot before any file replacement. It then uses per-file replacements and removes obsolete files inside selected plugin/theme components. The large transfer record is not rewritten per file; interrupted transfers can restore from the saved plan, including files not yet changed. Whole-site file application is not atomic: use a maintenance window for busy sites. Database and filesystem snapshots are not synchronized with each other.

Destination `siteurl`, `home`, upload configuration, privacy flag, active plugin list, cron schedule, and Trickle keys, saved connections, recent settings and control state are preserved, including preserving absent optional settings. When the logged-in local controller imports user data, its administrator login/password are retained, its session is refreshed, and it remains an administrator. Remote token-only writes do not preserve an interactive remote administrator account. Other imported users/settings and integration credentials are replaced by the selected source data.

## Requirements and limits

- Single-site WordPress 6.4+, 64-bit PHP 8.1+, cURL and zlib.
- InnoDB tables without foreign-key constraints. Multisite, views, non-InnoDB tables and foreign-key schemas are not supported in this preview.
- Writable private system temporary storage **outside the WordPress public directory**, enough disk space for staging plus rollback copies, and permission to create/rename/drop database tables.
- Permission to install the recovery loader in `wp-content/mu-plugins`; file application is blocked if the expected loader is missing. Must-use plugin failures and core/bootstrap failures are outside its recovery coverage.
- Selected destination plugin/theme/upload paths must be writable; shell access is not required. WordPress core, wp-config.php, MU plugins, server configuration, and external storage providers are not transferred.
- A 32 MB decoded JSON record limit applies, including base64 overhead for an individual database row. The limit stops large unsupported rows rather than truncating them.
- Selected database tables replace entire destination tables. This is not an orders/content merge. Moving new attachments normally also requires their posts/postmeta data; selecting media files alone does not create attachment records.
- Date-based changed media uses source filesystem modification times, not content hashes; the separate comparison mode uses cached checksums. Selected-attachment and upload-date transfers do not advance the global all/changed media watermark. Deletions are not mirrored. After rollback, the earlier watermark is restored.
- A failed stream is retried from its beginning. An incomplete stream cannot be applied. Retry status survives a page reload; this is not byte-range resume or a durable background worker.
- Keep the dashboard open while staging. Long requests can still be interrupted by browser disconnects, PHP-FPM limits, proxies, firewalls or hosting timeouts. A killed apply can leave partial file changes; the current session offers destination restore.
- Rollback overwrites later destination changes. It is not a replacement for independent backups. Discard permanently removes the transfer's rollback copies; there is no automatic backup rotation in this version.
- Source table row counts in the selection UI are estimates from database metadata; the completed transfer reports the actual exported row count.

## SiteDistrict compatibility

The installed SiteDistrict 3.2.2 exporter accepts its data requests only when the caller has a recognized SiteDistrict server IP **and** a token or administrator session. It is a source-side exporter for SiteDistrict's separate importer; its code does not reveal the host's WAF configuration.

Trickle uses its own signed authentication rather than SiteDistrict's fixed caller-IP list. That does **not** bypass a host firewall. Ask the host to permit the dedicated `?trickle=…` actions, the `X-CSM-*` headers, long streamed responses, and streamed/chunked POST uploads. A large push can still hit upload limits even though it is streamed. Test this on a disposable SiteDistrict staging site before any client/production use. No SiteDistrict firewall compatibility or production-scale performance has been established locally.

Verified HTTPS is required except for HTTP localhost development URLs. Trickle defaults to WordPress's public CA bundle. `CSM_CA_BUNDLE` overrides it, or `csm_ca_bundle` receives the bundle path and destination URL. Scope a development CA override to local destinations; using a local-only CA for public hosts causes issuer-certificate verification failures. Certificate and hostname verification remain enabled. Never ship local test configuration to the hosted site.

## Media picker compatibility

Install 0.1.3 or newer on both sites for thumbnail URLs, upload dates, full-library browsing and upload-date transfer mode. Previews load directly from the source with lazy loading and no referrer. Older sources remain available for existing transfer modes; their missing preview/date data is labelled, and upload-date mode is disabled. Missing or protected thumbnails use a file placeholder. File selection alone does not import attachment database records.

## Diagnostics

Open **Diagnostics** at the bottom of Tools → Trickle Migrate to refresh or download the latest 200 events. Administrator capability and the dashboard nonce protect log access; there is no public remote-log endpoint. Logs record operation, destination, elapsed request time, HTTP status, cURL error, TLS verification result, chosen CA source, and component failure context. Response bodies, request payloads, connection codes and database values are omitted; known keys, authentication headers, URL credentials/query strings and common credential fields are redacted.

Two private log files rotate at 512 KB each outside the public directory. Logging is best effort and must not block transfer or recovery if storage is unavailable or contended. Successful progress/status polls are omitted to avoid noise. Logging begins when this version is installed; installing it on both sites provides both sides' diagnostics.

## Local test environment

Two disposable Cove sites were created:

- `https://cove-stream-local.localhost:8453`
- `https://cove-stream-remote.localhost:8453`

Both have three real image attachments with generated sizes, test content, a parent/child theme pair, a custom plugin, and a 613-row InnoDB table containing nested serialized values, serialized objects and binary data. Local also has Classic Editor, Contact Form 7 and Query Monitor. Remote has an older Classic Editor and Twenty Twenty-Five to exercise real plugin/theme update badges.

`tests/seed.php` is restricted to the QA pair described below. Its separate MU plugin supplies local CA trust and suppresses outbound email. These test files and configuration are excluded from the installable ZIP.

The destructive suites run on isolated clones: `trickle-qa-b.localhost` (controller/local), `trickle-qa-a.localhost` (remote), and `trickle-qa-c.localhost` (alternate remote). The original two sites and any retained backups there are left alone. `tests/seed.php` is restricted to QA A/B. Prepare the named clones with the plugin and fixtures before running:

```sh
python3 tests/integration.py
python3 tests/cli.py
```

The suite covers signed TLS requests, both transfer directions, real PHP cURL streaming, WordPress admin AJAX control, full database import including users, URL/path/serialized replacements, binary values, staging isolation, obsolete-file removal, rollback, authentication/replay checks, changed media, truncated streams, traversal rejection, recovery access during an ordinary plugin fatal, custom vendor update filters, and key rotation across rollback. Real SiteDistrict WAF/proxy behavior, large production datasets, and concurrent production writes remain untested.

The cascading-media integration checks run on idle QA-A/QA-C only: `python3 tests/media-cascade.py`. They temporarily redirect uploads into isolated fixture directories, exercise signed TLS push/pull, cache reuse, hierarchical skips, deltas, pagination, source changes, invalid plans and rollback, then remove their fixtures and restore access settings.
