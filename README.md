# Trickle 0.1.16

A shared WordPress plugin for selective push/pull migrations, controlled from the local site's **Tools → Trickle** page. Install the same ZIP at both ends.

## Included

- Select plugin folders, themes, and individual database tables.
- Review the exact default URL/directory find-and-replace values, disable individual rules, or add custom literal replacements. A master switch disables all replacements. Serialized string lengths are rebuilt without instantiating objects.
- Compare installed plugin/theme versions at both ends. Update badges use each site's WordPress update information, including vendor updates exposed through WordPress. **Refresh versions** requests fresh information; a missing badge is not proof that the vendor has no newer version.
- Compare media in a year → month → file cascade, skipping matching years and months and transferring only changed or missing files. Cached content hashes are reused after scanning local filesystem metadata. Both sites must run the same current Trickle version.
- Transfer all uploads, files modified since the last successful all/changed media transfer, selected attachments and their generated image sizes, or attachments uploaded in an inclusive UTC date range. The visual picker shows source thumbnails and upload dates newest first, supports click/Shift-click selection, and loads older attachments in pages of 60. Upload-date transfers cover the full attachment library; all/changed modes cover the entire uploads directory.
- Save named remote sites once and select Staging, Production, or another remote before choosing push/pull. The same connection works in both directions; the remote never initiates a connection back to local.
- Reuse the exact settings of the latest 20 transfers, including remote, direction, component selections, explicit replacement rules with enabled flags, and changed-media cutoff. Recent entries keep timestamps and status, not additional backup copies.
- Use the same transfer controller from the dashboard or WP-CLI; agent presets can plan or complete a transfer in one command, while advanced CLI commands support separate staging and apply.
- Send one gzip-compressed database stream and one gzip-compressed file stream per transfer, when those components are selected. On matching current versions, each selected logical stream is retained as an immutable private gzip file on the source, then downloaded continuously for pulls or uploaded in signed windows of at most 16 MiB for pushes by default. Interrupted streams resume at the receiver’s byte offset; **Transfer method → 512 KB chunks** or `--transport=chunks` keeps bounded requests available. The complete snapshot digest is verified before parsing. Both ends need temporary disk space; this trades source storage for network resumability.
- Start a dashboard push/pull with one click: stage privately, verify checksums and apply automatically. Unchanged plugin/theme contents are compared using cached hashes and are not retransmitted. When all selected plugin/theme component digests match, a compact token pins the source inventory instead of paging per-file manifests through the controller. Missing destination folders need no per-file comparison. Other comparisons exchange a single compressed checksum manifest instead of 500-file request pages. Source metadata and destination contents are still checked independently.
- Keep three destination backups, independently restorable from the separate **Backups** page. Completed transfers release their staging data without blocking the next task.

The dashboard prepares both selected source streams before sending. A segmented bar shows measured Database, Plugins, Themes and Media payload sizes, with an elapsed timer, a live remaining-time estimate and a timestamped recent-activity list. Database size is the uncompressed serialized stream; file sizes exclude unchanged payloads and protected paths. The bar uses payload-weighted staging estimates because plugins, themes and media share one compressed file stream. Compressed bytes acknowledged by the receiver drive the transfer ETA; measured replacement-step counts can produce a separate step ETA. Preparation, verification, stalls and health checks show their phase instead of a made-up countdown. Only a successfully applied transfer shows completion. Timers/history survive active-session reloads; ETA recalibrates from fresh observations.

Progress reports the current relative plugin/theme/media path or database table, processed counts, and streamed bytes. The dashboard polls about once per second; quick components may pass between updates. A failure retains its phase, exact component and error, including through automatic rollback. Progress snapshots live outside the webroot and are read without the transfer mutation lock. A hosting timeout can leave only the last reported activity; that does not prove which operation caused the timeout.

Component headers start unchecked, in Database → Plugins → Themes → Media library order. Enabling a component initially opens its settings and selects all transferable items (all files for media). Its separate disclosure arrow can collapse or reopen the settings while the component stays enabled and its selections remain unchanged. The inner select-all control changes individual selections independently: clearing a list leaves the component enabled and open. Disabling the component clears its selections and closes its settings. Refresh and recent settings preserve component enablement, expansion and selected items independently; older presets infer enabled components from their selections.

Self-connections are excluded from the saved-site selector and rejected by the controller before staging or applying. Identity checks normalize hostname case, trailing slashes, protocol differences and default ports while preserving distinct installation paths and nonstandard ports. The authenticated manifest also exposes the canonical site URL and a salted installation fingerprint so aliases can be detected without exposing keys or filesystem paths. Existing recovery records are retained.

## Installation and use

1. Upload `trickle-0.1.16.zip` through Plugins → Add Plugin on both sites and activate it.
   Activation installs `wp-content/mu-plugins/0-trickle-loader.php`. That directory must be writable. The loader isolates signed streaming/recovery requests and authenticated Trickle dashboard requests from ordinary plugins and theme code, so a broken active plugin need not block rollback. Isolation is limited to the actual WordPress entry scripts; unrelated login and admin pages retain their plugins. Dashboard AJAX isolation also requires a valid Trickle nonce. Inventory requests load ordinary plugins so their vendor update filters still work. A failed inventory request does not hide an existing transfer's recovery controls. Other must-use plugins and host firewalls still run. The loader is dormant when Trickle is deactivated. The first WordPress request after a version update refreshes an outdated loader when file modifications are allowed. The dashboard also checks it; CLI-only installations can reactivate Trickle to refresh it immediately. File apply remains blocked until the expected loader is installed.
2. Open Tools → Trickle on the remote site. Authenticated remote reads and incoming writes are both allowed by default on a fresh installation. Either can be disabled under Connection & access; saved permission choices are preserved.
3. Use **Copy connection code** at the top of the remote Trickle page, then paste both lines into the local site's connection panel. A complete code connects automatically: the visible site URL followed by its private key. Check the URL before pasting. Permission checkboxes save automatically. Treat the code like a password; do not put the key in a URL or support ticket. Older base64 connection codes are still accepted.
4. Select the saved remote site, then push or pull, review source/destination versions, and select components. A single saved remote is selected automatically. With several, the dashboard remembers the selected site. Selecting a child theme also selects its parent.
5. Select **Push** or **Pull**. Trickle stages and verifies the selected streams, then replaces the destination automatically. Git inclusion still requires confirmation.
6. Wait for **Push complete** or **Pull complete**, then **View site**. Completed backups never block another transfer.
7. Open **Backups** to restore any of the three most recent backups on this site or a saved remote destination.

The connection-code input is empty after saving because the credentials are stored on the controlling site. Do not paste the code again to change direction. Use **Recent transfers** to restore earlier settings without starting a transfer. Reused changed-media settings retain their original UTC cutoff; choose a new cutoff or change media mode if you want different behavior. If saved components are no longer in the source inventory, the dashboard asks you to make a new selection rather than silently transferring a subset.

Git protection applies to file transfers in both directions. Pull review detects repositories on the source and destination; push review protects repositories on the destination while permitting deployment from a source working directory. Repositories start excluded; including each specific folder requires an explicit confirmation. Recent-transfer settings never retain that approval. Both sites must advertise the new file-policy capability; file transfers to older receivers stop and ask for an update.

The preview respects `DISALLOW_FILE_MODS` and filesystem permissions. It does not grant FTP access or bypass file ownership restrictions. Every push/pull defaults to the source’s full active plugin list. Uncheck **Use source’s full active plugin list** to preserve destination activation.

## One-step transfers and backups

The dashboard's **Push**/**Pull** button runs staging, verification and replacement as one operation. Safe inventory and transfer steps retry transient connection errors up to three times with backoff. A source that is still building a snapshot returns a retryable busy response; safe steps wait separately from the network-error budget, up to twelve ten-second pauses. Older unclassified source-lock responses remain compatible. Busy work does not block unrelated read-only requests with a host-wide cooldown. Retained-snapshot downloads detect a stalled connection after 30 seconds below one byte per second and resume from saved bytes; healthy streams have no total-duration limit. Retry-After pauses are honored across subsequent requests to the same host. Interrupted transfers keep receiver checkpoints; long cooldowns return control to the UI/CLI rather than blocking a PHP worker. A paused dashboard transfer resumes after its cooldown; CLI network failures retain the session for `resume`. Apply operations are never automatically replayed. A refreshed/reopened dashboard resumes a one-step transfer that was already authorized. A timeout is checked against destination state before recovery; an apply request is never blindly replayed. Failed staging is cancelled when reachable, leaving the destination unchanged. Completed source/staging streams are cleaned up automatically; cleanup errors are logged without hiding a successful migration.

The progress view follows four stage tabs: Prepare, Transfer & verify, Replace and Health. Prepare shows the selected payload; Transfer combines received/pending byte ranges and measured speed in one graph with a shared byte-position axis. Each stage tab shows its observed elapsed time, combining repeated passes; unobserved time while the dashboard was closed is not backfilled. Transfer and verification share one tab and elapsed timer; received ranges are blue and turn purple only after the receiver confirms that stream has passed verification. A separate purple strip shows reported verification counts without mapping file counts to byte ranges. Each stage pairs its tab color with a progress meter. Processing stages use known file/table counts; unknown totals use an indeterminate meter. Current filenames appear only in Recent activity. Verification loops report their known counts without changing validation or transfer behavior. Speed and remaining-time fields stay hidden until measurable. Tabs follow the active operation and ignore reports from prior operations; Replace is selected only when replacement is reported. Earlier tabs remain available for inspection. Elapsed time, retry/error notices and recent activity remain visible across stages. Completion and site/backup links stay within the same progress view.

Replacement failures restore the pre-transfer state automatically. Before mutation, a recovery event is scheduled for WordPress cron. The database-copy phase of a saved-backup restore is journalled too; an interrupted copy is rebuilt before replacing any live tables. On a hard process exit, its isolated recovery request restores an `applying` job after acquiring the site lock and rereading its state. Other plugins' due cron events are left untouched for a later ordinary cron request. Recovery also runs when the dashboard reconnects. A host that disables or does not run WordPress cron needs the dashboard reconnect; recovery cannot run while the host or database is unavailable. Blocked recovery remains recorded and retries rather than silently discarding its plan.

**Backups** keeps the three newest successful destination backups. Each contains complete selected database tables, snapshots of the selected plugin/theme files, and the original media files affected by that transfer. It is not a whole-site snapshot: unselected components, unrelated media, WordPress core and server configuration are outside its scope. Failed staging does not rotate backups. Restore works in any order, repeatedly, using temporary database copies so the originals stay available. Newer code files inside a restored component are removed; protected development/secret files remain excluded. Git folders created after the backup block restoration rather than being overwritten.

File backups use a private per-site directory under the PHP user's `~/.trickle-backups/`, outside the public roots, when writable. Otherwise they use existing private temporary storage; that limitation is labelled in the backup list because hosts may clear temporary files. Database backups remain private tables in the destination database. Restoring requires writable files and enough free space to copy the saved database tables. Rotation removes only the expired backup's private files and tables after a successful migration.

CLI: `wp trickle backups --format=json` and `wp trickle restore-backup <id> --yes --format=json`. Add `--remote=Production` to manage a remote push destination. The advanced CLI's explicit stage/apply commands remain available.

## Plugin activation

**Use source’s full active plugin list** is checked by default. Every push/pull copies the entire source active list, even when transferring only a few plugins, themes, media files or database tables. Unselected destination plugins may be activated or deactivated. Uncheck it to preserve the destination list; CLI uses `--keep-destination-plugins`. The setting is saved with recent selections and locked during a transfer. Older recent profiles use the new default when replayed.

Signed source streams include the full activation list. If it changes between streams, staging stops before replacement. Trickle always remains active, including when its directory name differs between installations. Source plugins absent from the destination are skipped and reported in the completion notice and Diagnostics; they are not silently added to the file selection. Native WordPress requirement checks run against the full proposed active list. Activation hooks are not replayed; include the plugin's database settings/tables when needed.

After native plugin upgrades, authenticated requests refresh an outdated recovery loader when file modifications are allowed. The first request under an older loader still reads its captured original activation list, so request isolation cannot make Trickle appear to be the only active plugin. Unauthenticated requests cannot trigger the refresh.

The complete destination active list is saved before replacement. Activation is applied after files/tables and before the frontend health check; health failures and interrupted-apply recovery restore the previous list. Saved backups retain this activation snapshot independently of whether the options table was transferred. Opted-out file transfers leave activation unchanged.

## Matching versions and remote Trickle updates

Both sites must run the exact same Trickle version before preparing, transferring or applying a push or pull, including database-only transfers. The controller checks the remote manifest before prepare/apply, and signed RPC headers carry the controller version for every transfer request. Retained jobs record the version that prepared them; after either installation changes, discard/reprepare instead of applying stale staging. Status, discard and rollback remain available across version differences.

The dashboard shows both Trickle versions above the selection panels. A mismatch disables preparation/apply. For an older remote, **Check remote update** inspects its configured static JSON feed. When that feed publishes the local version, **Update remote Trickle to …** performs only that plugin update after confirmation. It verifies the freshly running remote version afterward and preserves your selections; you still start the migration separately. If the remote is newer, update local first. Downgrades are refused.

On the remote, enable **Allow authenticated Trickle updates** under Connection & access. This permission is separate from incoming migration writes and defaults off. The administrator key or a read/write scoped key is required; read-only keys cannot update code. Existing remotes without the new endpoint need one manual installation of this build first. The feature cannot bootstrap itself into old plugin code.

Remote updates use Plugin Update Checker's configured HTTPS JSON metadata and WordPress's native upgrader. The caller supplies the desired version, not a ZIP URL. The feed and extracted package must match that exact version; package name, root directory and PHP/WordPress requirements are checked before replacing files. All original plugin activation state and connection settings are retained; the recovery-loader activation filter is temporarily lifted around the native upgrader so other plugins remain active throughout. The loader is refreshed after installation. Git checkouts, symlinks, retained transfers/source snapshots, filesystem restrictions and unavailable/mismatched releases block updates. A retry after success is a no-op.

CLI: `wp trickle update-remote --remote=Production --check --format=json` inspects eligibility. `wp trickle update-remote --remote=Production --yes --format=json` updates and verifies the remote; it does not start a transfer. With multiple remotes, specify `--remote` explicitly. A development build that is newer than the published JSON feed cannot be sent through this updater until its matching release is published.

## Plugin updates

Trickle bundles Plugin Update Checker 5.7 and initializes its static JSON checker at plugin load, matching Guest Key. Its feed is `https://raw.githubusercontent.com/jonschr/trickle/master/update.json`; this is a JSON endpoint, not PUC’s GitHub repository/release-discovery mode. It registers in frontend, admin, cron, AJAX, REST and WP-CLI contexts. WordPress gets the normal update notice, version-details dialog and **Check for updates** link. Automatic installation follows WordPress/host settings; the updater does not force-enable it.

`Update URI: https://github.com/jonschr/trickle` identifies the custom update origin and avoids unrelated WordPress.org updates. `CSM_UPDATE_URL` can be defined before the plugin loads to override the feed for testing or another HTTPS endpoint. The default check interval is 12 hours; ordinary dashboard checks and manual checks can run sooner.

To publish a release, keep the plugin header, `CSM_VERSION` and `update.json` version/requirements in sync. Build a ZIP with a single `trickle/` root containing `trickle.php`, `update.json`, `README.md`, `AI-USAGE.md`, `assets/`, `includes/` and `vendor/`; exclude tests, Git metadata and environment files. Commit the release source, create its matching version tag (for example `0.1.7`), publish the release ZIP at the exact `download_url`, then publish the updated JSON on `master`. Make the ZIP available before advertising a newer version in the public feed. The configured feed and versioned ZIP must both be publicly accessible for default updates to work. Existing installations without this integration need one manual ZIP update first.

The updater test uses an intercepted local JSON response and a disposable next-version ZIP on idle QA-C: `python3 tests/updater.py`. It verifies current/older/newer metadata, native update/details hooks, HTTP/JSON failures, scheduled checks and a real WordPress ZIP upgrade. It restores the test plugin header, metadata and activation afterward. It does not publish a release or update a live site.

## AI workflows

[AI-USAGE.md](AI-USAGE.md) documents discovery, planning, execution and recovery for AI operators. `wp trickle inventory --remote=Production --format=json` exposes component IDs, capabilities, local Git-backed code and the guide location without connection secrets.

| Preset | Scope |
| --- | --- |
| `pull-db` | Pull all eligible WordPress database tables, without files or media. |
| `pull-db-missing` | Pull the database and plugin folders absent locally; leave existing plugin files in place. |
| `push-git` — **Push version-controlled code** | Push plugin/theme folders containing Git repositories, including nested repos and worktree markers; no database tables or media. |

```sh
wp trickle presets --format=json
wp trickle run push-git --remote=Staging --format=json
wp trickle run push-git --remote=Staging --yes --format=json
wp --user=admin trickle run pull-db-missing --remote=Production --yes --format=json
```

Without `--yes`, `run` returns a read-only selection plan with URLs, activation changes, Git exclusions and blockers. `--dry-run` always plans. With `--yes`, it stages, verifies, applies and releases the session, retaining the destination backup. Errors return structured JSON and a nonzero exit status, with recovery information when available. Use the intended local administrator's login with `--user` for database pulls including users; do not assume it is `admin`.

**Push version-controlled code** copies current working folder contents, including uncommitted changes and generated assets. An ancestor repository alone does not classify all vendor folders as version controlled. Non-Git parent themes must already exist at the destination. Git metadata, `.gitignore`, `node_modules` and environment files remain excluded; destination Git folders remain protected unless explicitly included for that transfer. `--yes` alone never approves Git replacement.

All presets retain the full source active-plugin-list default, including file-only pushes. Use `--keep-destination-plugins` explicitly to preserve destination activation. Missing source-active plugin files are reported; the database-only preset does not fetch them. Database pulls report missing active themes before starting.

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

`pull` accepts the same flags. Push, pull and preset runs accept `--transport=stream` (default) or `--transport=chunks`; saved recents preserve this choice. Plugin/theme/table flags accept comma-separated IDs or `all`; plugin `all` excludes Trickle. Child-theme selections include their parent. Media flags are `--media=all` (skip matching files by default; add `--force-media` to retransfer them), `--media=changed --since=2026-10-01T00:00:00Z`, `--media=selected --media-ids=12,34`, `--media=compare` (a compatibility alias for `all`; add `--rehash-media` for a full checksum recheck), or `--media=uploaded --uploaded-after=2026-10-01 --uploaded-before=2026-10-31`. Upload dates are UTC and inclusive; provide at least one date boundary. Changed media without `--since` uses the last successful media watermark and fails if there is no initial cutoff. Database find & replace is enabled unless `--skip-replace` is given; that flag disables custom rules too. `wp trickle replacements --remote=Staging --direction=push --format=json` lists the exact default mappings and their IDs. `--skip-default-replacements=root` disables an individual default, and `--replacements=/private/path/rules.json` appends custom rules from a JSON array such as `[{"find":"old text","replace":"new text","enabled":true}]`. An empty replacement deletes matching text. When defaults share a search string they are combined; use the IDs reported by the preview command.

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

Default URL mappings cover HTTP, HTTPS and protocol-relative variants. URL/path rules also match JSON-escaped slashes without re-encoding unrelated JSON formatting. Serialized containers retain valid byte lengths. Existing recent profiles retain their original explicit rules; reselect defaults to add the new variants.

The database section shows the actual source and destination values for WordPress URL, home URL, WordPress directory and content directory. Identical search strings share one row; the home/content mapping retains the legacy precedence when default searches coincide. Each row can be disabled, and custom rows can be added, edited, disabled or removed. To change an automatic mapping, disable that default and add your desired custom mapping.

Rules are literal and case-sensitive, with longer searches taking precedence. They run as a single pass: text inserted by a replacement is not searched again. Conflicting enabled rules with the same search but different replacement values are rejected before staging. Empty enabled searches are rejected; empty replacement values are allowed. Up to 100 rules and 64 KB of combined JSON configuration are accepted.

Only selected database text columns are processed, including nested serialized strings and supported serialized objects. Values with no replacement matches remain byte-for-byte unchanged, including malformed legacy serialized values. Malformed serialized values that require changes still stop the import. Binary columns and file contents are unchanged. Destination identity, Trickle state and other preserved options remain protected independently. Replacement rules are sent only to the receiver when database tables are selected, not to a pull source that only exports data. The replacement list and its enabled flags are captured in recent-transfer settings; replay uses those exact strings. Older recent entries that only contain the original checkbox setting continue to calculate the legacy defaults.

Both sites must run this version for new transfers. Recovery operations remain available for retained older transfers; discard and prepare again to transfer or apply after a version change.

## Cascading media comparison

Choose **All media files**, or use `--media=all` with either `push` or `pull`. Matching media is skipped by default; **Force retransfer** or `--force-media` sends it again. The older `--media=compare` alias and saved comparison selections remain compatible. The controller first compares the year hashes. It requests month hashes only for differing years and individual file hashes only for differing existing months. Entirely missing month folders can be selected without fetching their individual manifests. All selected bytes still travel in the existing single file stream, with per-file and stream checksum verification. Matching folders do not get file comparison requests. Destination-only media is kept; checksum comparison does not mirror deletions or advance the date-based transfer watermark. Forced full transfers and date-based transfers still advance that watermark.

The first comparison builds a private checksum cache on each site. Later comparisons scan file names, sizes, modification/change times, inode, device and mode to invalidate changed entries. Matching cached years reuse their month and file hashes without rebuilding them. Differing years rebuild only changed months, and those months hash only new or changed file contents. Very recent files are conservatively rehashed to avoid trusting coarse timestamp resolution. Files directly inside uploads, files directly inside a year, and non-date folders are also included using the same first/second directory level grouping.

A metadata scan is still required: directory timestamps alone cannot detect edits to existing files. Metadata caching is a practical shortcut, not a guarantee against metadata-preserving filesystem restores or silent corruption. Select **Recalculate all file checksums** or pass `--rehash-media` to bypass the content cache for a full verification. The comparison still descends only through differing folders. Keep media stable during comparison and transfer; a selected source file that changes afterward stops the stream rather than exporting an unverified change. Retrying the file stream runs a fresh comparison. A concurrently replaced inventory also stops safely and can be retried.

Cached hashes and filesystem metadata are stored atomically in a private file outside the webroot, with mode 0600. Year/month requests are batched; individual manifests paginate at 500 files. The compressed changed-file selection is limited to 700 KB and expands to at most 32 MB. If a very large delta exceeds that limit, select **Force retransfer** or use `--media=all --force-media`. Recent settings store the media scope, force and verification flags, not old snapshots or per-file selections, so reusing them compares the sites again. The staged/complete summary reports year skips, month skips and individual comparison counts.

## Development-folder and secret protection

Every transfer, including explicit Git overrides, excludes `.git` directories/files, `.gitignore`, `node_modules`, `.env*`, and filenames containing `.env` followed by a suffix separator or end of name. Exclusions apply at any directory depth, to both export and receive. Apply never removes excluded destination files as obsolete code; rollback does not restore excluded legacy paths. Symlinks remain blocked. Trickle is not a complete secrets scanner: credentials stored under other filenames or in selected database tables still travel with those selections.

Git detection includes `.git` files (worktrees/submodules), nested repositories, and repository markers above the selected component roots. A parent repository protects the entire relevant plugin/theme/uploads root. Pulls protect source and destination repositories; pushes protect destination repositories. Source Git metadata is always excluded even when deploying its code. The destination checks again during staging and apply, so a repository created after review blocks overwrite. Missing source/destination folders and ordinary existing components remain supported.

The dashboard lists Git folders unchecked in a focused review before preparing; checking a folder requires confirmation. CLI defaults skip them too. To explicitly include a specific folder for one transfer, use `--include-git=themes/my-theme` (comma-separated paths). `--yes` alone never grants Git inclusion. Saved recent settings clear all Git approvals; each new transfer must authorize them again.

## Component comparison and destination preview

Plugins/themes use cached SHA-256 hashes with metadata invalidation. Matching folder hashes skip file contents automatically in both streaming and chunked transfers. With matching current builds, mixed selections keep matching folders as compact pinned tokens and request individual manifests only for differing folders. Equal files travel as verified unchanged-file records, not file bytes. The destination verifies them while staging and again before apply. Obsolete non-excluded files inside selected components are still deleted. Metadata-preserving source restores or silent corruption can fool a cache; select **Recalculate plugin and theme checksums** or pass `--rehash-files`. Very large per-file equality plans resend only differing folders; matching folder tokens remain in the plan. Older sources retain the compatible per-file comparison path until updated.

Once all streams are staged, review counts and individual additions/replacements/deletions, with deletions first and pages of 200. Preview data and checkpoints stay private. Preflight checks destination writability, staged checksums, plugin/theme PHP and WordPress headers, PHP syntax, and free private backup storage. PHP failures show an onscreen notice with the plugin/theme name, destination PHP version, failing file and parser line; update or deselect that component before retrying. It hashes destination code in the review and rejects changes made afterward rather than silently overwriting newer development. This cannot eliminate a concurrent write racing the actual apply; pause development and live writes while applying.

Normal frontend traffic receives a temporary maintenance response during apply. Before lifting maintenance, the destination makes a verified-TLS, cache-busting frontend HEAD request. A short-lived secret header bypasses only the maintenance gate for that request, while normal plugin/theme bootstrap still runs. Other visitors remain blocked during the check. An observed HTTP 5xx triggers automatic rollback. A timeout, redirect, authentication challenge, or firewall denial is reported as unverified, rather than treated as proof of broken code. A 2xx is only a basic liveness signal; it does not test forms, integrations, checkout, or every page. PHP/header checks cannot validate plugin database upgrade behavior. Plugin activation follows the source by default. Plugin settings remain separate database data.

## Named connection keys

The administrator code remains compatible and valid until rotated. Named codes require 0.1.8 on the controlling site; legacy controllers do not send the scoped-key identifier. Under **Connection & access → Named connection keys**, create separate read-only or read/write codes for workstations/services with 1–90 day expiry. Global read/write permissions remain the upper bound. Revoke one key without disrupting other named keys; rotating the administrator key also revokes all named keys. New transfer records and source snapshots are bound to their connection key; another scoped key cannot inspect, commit, or delete them. The administrator key retains recovery access after a scoped key expires or is revoked. Legacy job ownership remains compatible.

Read/write keys also permit Trickle updates when the separate site-wide update permission is enabled.

`wp trickle code --name=Workstation --days=30 --output=/private/new.code` issues a scoped read key; add `--write` to permit incoming writes too. `wp trickle keys --format=json` lists metadata only; `wp trickle keys --revoke=<id>` revokes one immediately. Named keys never override site-wide access settings. Finish retained transfers or use the administrator code for recovery after revocation/expiry.

## Transfer protocol

All remote requests are HTTP POSTs to the WordPress installation URL with a dedicated `trickle` query parameter. These are lightweight plugin endpoints, not REST or admin-ajax handlers. The local dashboard uses normal authenticated admin AJAX to invoke its controller.

| Action | Request | Response | Permission |
| --- | --- | --- | --- |
| `bundle` / `bundle_stream` / `bundle_chunk` / `bundle_discard` | Frozen source stream and bounded byte requests | Private checkpoint metadata or bytes | Remote reads |
| `segment_state` / `stream_data` / `segment_data` | Source digest, offset and signed segment digest | Durable offset or staged summary | Status: access; data: incoming writes |
| `file_policy` / `component_hashes` / `component_manifest` | Selected paths and pinned inventory token | Git folders, component digests and one compressed file manifest | Remote reads |
| `preview` | Transfer ID and page offset | Verified destination-change preview | Remote access plus transfer ownership |
| `version` / `update_info` | Minimal version/update status | Identity, capabilities or configured release eligibility | Remote access or separate update authority |
| `update_trickle` | Exact target and expected installed version | Native upgrade result | Separate updates permission plus administrator/read-write key |
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

Control requests carry a SHA-256 body digest and an HMAC-SHA256 signature. `X-CSM-Time`, `X-CSM-Nonce`, `X-CSM-Hash`, `X-CSM-Params`, and `X-CSM-Signature` authenticate requests. Timestamps have a five-minute window; nonces are single-use. Stream uploads authenticate their transfer ID/kind in signed headers and rely on verified TLS plus final stream/file digests for body integrity. Connection secrets never appear in request URLs. Redirects are not followed. The default user agent is `Trickle/<version> (WordPress site migration)`; health probes use `Trickle/<version> health check`. Invoking the plugin through an AI operator does not change these identities. Keep migration tests on these defaults; identify the agent only for its own direct HTTP requests outside the plugin. The `csm_user_agent` filter remains available for explicit integration requirements. The `csm_request_complete` action exposes sanitized endpoint/status/timing counters without credentials or bodies. The signed parameters include the controlling Trickle version; transfer actions reject a mismatch.

Database streams contain schema records and base64-encoded row values. They are logical data streams, not SQL files to execute wholesale. The receiver validates schemas and constructs its own inserts. The source uses one InnoDB repeatable-read snapshot, reading bounded batches with primary-key pagination where available. Tables without primary keys use offset pagination. Ordinary serialized strings, arrays, objects and references are handled without instantiating objects; unsupported custom serialization that requires replacement stops the import. Binary columns are not searched/replaced.

File streams contain path/size/mtime headers, raw file bytes, per-file SHA-256 trailers, and a final manifest digest. No archive-extraction library or shell command is required. Only selected plugin/theme paths and permitted uploads are accepted; symlinks, traversal, executable uploads, and replacing Trickle itself are blocked.

On matching current versions, the controller builds or retrieves a frozen source stream, requests the destination checkpoint, and downloads the remaining bytes continuously for pulls or uploads up to 16 MiB per request for pushes. Explicit chunk mode moves one signed/checksummed 512 KB segment per controller step. A resumed source request must match the pinned snapshot size and digest. Streamed uploads append only at the current destination offset; interrupted downloads keep partial bytes. Complete stream digests, per-file digests and destination checks still run before apply. The receiver verifies the complete compressed digest before parsing/staging. The browser and CLI loop over steps; retrying after reload uses the same snapshot and actual destination offset. Source streams and incoming bytes remain until discard. Building a source snapshot and parsing its completed stream still run in foreground PHP requests; they are not bounded workers. The continuous export/receive format remains implemented, but new mixed-version transfers are refused. There is no requirement that the remote server can resolve or reach the local hostname.

Database apply uses one multi-table `RENAME TABLE` operation to exchange staged and destination tables. File apply backs up original destination files and saves a complete checksummed recovery plan outside the webroot before any file replacement. It then uses per-file replacements and removes obsolete files inside selected plugin/theme components. The large transfer record is not rewritten per file; interrupted transfers can restore from the saved plan, including files not yet changed. Whole-site file application is not atomic: use a maintenance window for busy sites. Database and filesystem snapshots are not synchronized with each other.

Destination `siteurl`, `home`, upload configuration, privacy flag, cron schedule, and Trickle keys, saved connections, recent settings and control state are preserved, including preserving absent optional settings. When the logged-in local controller imports user data, its administrator login/password are retained, its session is refreshed, and it remains an administrator. Remote token-only writes do not preserve an interactive remote administrator account. Other imported users/settings and integration credentials are replaced by the selected source data.

## Requirements and limits

- Single-site WordPress 6.4+, 64-bit PHP 8.1+, cURL and zlib.
- InnoDB tables without foreign-key constraints. Multisite, views, non-InnoDB tables and foreign-key schemas are not supported in this preview.
- Writable private system temporary storage **outside the WordPress public directory**, enough disk space for staging plus rollback copies, and permission to create/rename/drop database tables.
- Permission to install the recovery loader in `wp-content/mu-plugins`; file application is blocked if the expected loader is missing. Must-use plugin failures and core/bootstrap failures are outside its recovery coverage.
- Selected destination plugin/theme/upload paths must be writable; shell access is not required. WordPress core, wp-config.php, MU plugins, server configuration, and external storage providers are not transferred.
- A 32 MB decoded JSON record limit applies, including base64 overhead for an individual database row. The limit stops large unsupported rows rather than truncating them.
- Selected database tables replace entire destination tables. This is not an orders/content merge. Moving new attachments normally also requires their posts/postmeta data; selecting media files alone does not create attachment records.
- Date-based changed media uses source filesystem modification times, not content hashes; the separate comparison mode uses cached checksums. Selected-attachment and upload-date transfers do not advance the global all/changed media watermark. Deletions are not mirrored. After rollback, the earlier watermark is restored.
- On matching current versions, a failed network transfer resumes from the destination byte checkpoint. Duplicate acknowledged segments are verified without appending twice; partial writes can resume from their actual byte offset. Source generation and final decompression/staging are still foreground operations and can require a retry after hosting timeouts. This is not a durable background worker. After a plugin-version change, discard and prepare again; status, discard and rollback remain available.
- Keep the dashboard open while staging. Closing it stops the controller loop; reopening resumes authorized one-step transfers from checkpointed network bytes. Long source generation, final staging and apply can still hit PHP-FPM/proxy limits. Apply ignores ordinary client disconnects but cannot survive a killed PHP process. A temporary MU-loader maintenance response protects normal frontend visitors during apply; it expires after 15 minutes if the process dies. Recovery/admin access stays available. A killed apply can leave partial file changes until scheduled recovery or the next dashboard reconnect restores them.
- Restoring a backup overwrites later changes to its included items. These local transfer backups do not replace independent offsite backups. Successful migrations rotate the oldest backup after three retained backups.
- Source table row counts in the selection UI are estimates from database metadata; the completed transfer reports the actual exported row count.

## SiteDistrict compatibility

The installed SiteDistrict 3.2.2 exporter accepts its data requests only when the caller has a recognized SiteDistrict server IP **and** a token or administrator session. It is a source-side exporter for SiteDistrict's separate importer; its code does not reveal the host's WAF configuration.

Trickle uses its own signed authentication rather than SiteDistrict's fixed caller-IP list. That does **not** bypass a host firewall. Ask the host to permit the dedicated `?trickle=…` actions, the `X-CSM-*` headers, long streamed responses, and streamed/chunked POST uploads. Default pushes use 16 MiB requests with a declared Content-Length; a host with a smaller request-body limit may still require the 512 KB option. The snapshot transport has completed a full SiteDistrict pull, recovered from a real SSL EOF by receiving only the remaining bytes, and passed independent file, database and activation checks. Fresh incremental pulls also passed, including cached year-level media skips. These results cover one SiteDistrict configuration; test other hosted sites independently before production use.

Verified HTTPS is required except for HTTP localhost development URLs. Trickle defaults to WordPress's public CA bundle. `CSM_CA_BUNDLE` overrides it, or `csm_ca_bundle` receives the bundle path and destination URL. Scope a development CA override to local destinations; using a local-only CA for public hosts causes issuer-certificate verification failures. Certificate and hostname verification remain enabled. Never ship local test configuration to the hosted site.

## Media picker compatibility

Install the same current version on both sites for thumbnail URLs, upload dates, full-library browsing and upload-date transfer mode. Previews load directly from the source with lazy loading and no referrer. Older-source inventory can still be inspected, but transfers require matching versions. Missing or protected thumbnails use a file placeholder. File selection alone does not import attachment database records.

## Diagnostics

Open the **Diagnostics** tab in Tools → Trickle Migrate to refresh or download the latest 200 events. Administrator capability and the dashboard nonce protect log access; there is no public remote-log endpoint. Logs record operation, destination, elapsed request time, HTTP status, cURL error, TLS verification result, chosen CA source, and component failure context. Response bodies, request payloads, connection codes and database values are omitted; known keys, authentication headers, URL credentials/query strings and common credential fields are redacted.

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

The suite covers signed TLS requests, both transfer directions, real PHP cURL streaming, WordPress admin AJAX control, full database import including users, URL/path/serialized replacements, binary values, staging isolation, obsolete-file removal, rollback, authentication/replay checks, changed media, truncated streams, traversal rejection, recovery access during an ordinary plugin fatal, custom vendor update filters, and key rotation across rollback. Earlier preview versions were also tested with a full production SiteDistrict pull and a plugin-only push. Current streaming/checkpoint behavior has local signed-TLS interruption coverage and verified live pulls from SiteDistrict and Flywheel. Seven consecutive follow-up pulls completed after the recovery fixes, including a fresh ~1 GB media pull with automatic EOF/busy recovery. A separate single-plugin pull completed with verified files and frontend health. Testing excluded one inactive PHP-incompatible theme. Concurrent production writes and a broader host matrix remain untested.

The cascading-media integration checks run on idle QA-A/QA-C only: `python3 tests/media-cascade.py`. They temporarily redirect uploads into isolated fixture directories, exercise signed TLS push/pull, cache reuse, hierarchical skips, deltas, pagination, source changes, invalid plans and rollback, then remove their fixtures and restore access settings.

## Verification and remaining work

`python3 tests/media-all.py` verifies default all-media skipping, forced retransfers, exclusions, saved settings, CLI compatibility, delta application and rollback using isolated QA uploads and pulls only.

`python3 tests/source-recovery.py` checks real source-lock contention, older busy responses, bounded waits, no blind apply replay, and the first authenticated request after an older loader. It performs no migration apply or push and preserves existing backups.

`python3 tests/database.py` checks compound-primary-key pagination and keyless tables against real MariaDB on idle QA-A. `python3 tests/pull-stall.py` checks a real stalled TLS response and byte-offset resume on idle QA-A/QA-C, without pushing or applying; it preserves existing backups. Automation can set `TRICKLE_TEST_USER_AGENT` for its identifying request header.

`python3 tests/activation.py` covers full active-list synchronization on partial push/pull and database-only transfers, explicit UI/API and CLI opt-outs, recent settings, independent backup restores, gapped WordPress option arrays, missing plugins, installation-directory mapping, unsafe metadata, changing source activation, runtime failure and hard-exit recovery.

`python3 tests/backups.py` covers sequential pulls, rotation, independent/repeated restores, unchanged code, later files, generated columns, media, options/credential preservation, scoped access, hard process exits, cron isolation and remote push backups. `node tests/workflow.js` exercises one-click completion, retries, resumed staging and safe failed-apply recovery.

Run `python3 tests/file-policy.py`, `python3 tests/reliability.py`, and `python3 tests/access.py` on idle QA-A/QA-C, never concurrently with another mutation suite. They create isolated fixtures, restore settings/activation state and remove fixtures afterward. They cover source/destination/nested/ancestor Git markers, media comparison overrides, exclusion preservation, explicit approval reset, checkpoint reload/retries/corruption, incremental apply/rollback, stale previews, invalid PHP, compatibility requirements, maintenance responses, runtime-fatal automatic rollback, database checkpoints, key scopes/ownership/expiry/revocation. Existing media-cascade, file-plan, replacement, diagnostic, updater and component-selection suites remain relevant. Tests are excluded from installable ZIPs.

Remaining follow-up: durable background source generation/staging, adaptive bounded-transfer sizes and broader remote performance benchmarks, broader hosting/large-database/concurrent-write testing, and independent security review. Filesystem apply remains per-file; database tables are replaced, not merged. This preview does not provide multisite, external-media storage, core/config/MU transfers, or offsite disaster-recovery backups.

Version/update regression: `python3 tests/remote-updates.py` uses only idle QA-A/QA-C with a temporary intercepted JSON feed and native ZIP package. It verifies mismatches before staging, receiver checks, changed versions after staging, recovery availability, permission/key scope, release failures, wrong packages, filesystem/Git guards, real native upgrades, activation state throughout the upgrade, fresh-version verification and a subsequent pull. Fixtures and settings are restored. `node tests/versions.js` covers compatibility and update-control states.

PHP notice checks: `cove wp trickle-qa-a eval-file /absolute/path/to/tests/compatibility.php` uses private staged plugin/theme fixtures without applying migrations. `node tests/compatibility.js` verifies visible, safely escaped notices.

Default streamed pushes use signed upload windows of at most 16 MiB with a declared Content-Length, retaining the snapshot and byte offsets across requests. The complete snapshot checksum still gates staging and replacement. Pull downloads remain continuous. This avoids sending a full large snapshot in one POST to hosts with request-body limits.

The dashboard’s **Automatic (recommended)** option and CLI default `--transport=stream` use continuous pulls and 16 MiB push windows. `cove wp trickle-qa-c eval-file /absolute/path/to/tests/upload-windows.php` checks interrupted windows, signed uploads and final digest verification without applying a migration. `tests/user-agent.php` captures actual API/health headers on QA-C without overriding them.

Normal-identity SiteDistrict validation: a ~949 MB forced media upload reproduced HTTP 413 with the previous single-POST method. The bounded method transferred all 9,878 media files with no request errors; all 15,325 code files matched. Forty-three checked database tables matched the pinned snapshot exactly. Changes in two Action Scheduler tables were traced to logged background execution, recurrence and cleanup after apply; they were recorded as runtime activity rather than exact row matches.

Progress UI checks: `node tests/progress.js` covers timing, stage transitions, prior-stage inspection and saved state; `node tests/progress-chart.js` checks chart interaction and reduced motion. No site mutations are performed.

Offline security regressions: `python3 tests/security.py` checks loader route/authentication boundaries and rejects staging inside the public document root, including installations in subdirectories. These checks do not contact or modify a site.
Run `php tests/security-core.php /path/to/wordpress <mode>` for each of `admin`, `ajax`, `anonymous`, `invalid`, and `bad-nonce` to exercise real WordPress cookie, session, capability and nonce APIs with in-memory fixtures. This loads core files only, without wp-config, a database connection or network requests.

Dashboard workflow: editable database/missing-plugin/Git-code presets, searchable component lists, destination-specific action labels, and collapsible setup during monitoring. Follow live can be paused by inspecting an earlier stage. Recent activity groups routine file updates while retaining errors/retries and supports copying error details. Completion summarizes changed/unchanged files (including media comparison skips), protected folders, elapsed time, homepage health and the destination backup, with a direct link to that backup.

`php tests/loader-refresh.php` verifies automatic upgrade-time loader refresh, no repeated rewrites, failure handling and file-modification policy without loading WordPress or changing a site.
