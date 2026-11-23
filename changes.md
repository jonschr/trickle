# Changes

## Unreleased

- Reuse one request-local receiving file handle across live frames, flushing and closing before checkpoint persistence and at file completion. Preserve bounded replay, path checks and installed verification.
- Accept concurrently created private staging directories while retaining path and permission checks, preventing a WordPress startup failure after hosting restores or restarts.
- Receive media into destination-adjacent `.part` files and rename them during apply, avoiding a second media copy. Retain private code staging, rollback backups, stream and installed checksums, replay-safe publication, and registered cleanup of interrupted parts.
- Include recorded performance samples in diagnostic downloads, with an optional retained-transfer filter.
- Keep transport cancellation working on PHP 8.1 through its older cURL progress callback option.
- Prepare one following file window during successful push response waits, retaining the current replay until acknowledged. Keep uploads serial and fall back when cURL multi is unavailable.
- Accelerate bounded small-file source and verification SHA-256 through the existing optional provider; retain the streaming path for larger files and exact checksum fallback.
- Compare plugins, themes and media by file size and modification time, including cold inventories. Remove pre-transfer content hashing and rehash preferences.
- Keep stream checksums, private database/code staging, recovery and installed-file verification. Establish skipped component recovery checksums after receipt.

## 0.1.36 — 2026-11-21
- Cache the validated cancellation-marker path within a request while checking marker existence and ownership at every existing cancellation boundary.
- Reuse confirmed stream receipts; persist upload uncertainty before sending and rediscover receiver state after a lost reply. Use serial HTTP/1.1 uploads and retain numeric upload-phase timings.
- Invalidate installed and removed component files individually instead of resetting the whole shared OPcache. Keep bounded recovery journals and the global fallback for hosts without selective invalidation.
- Allow database INSERT batches up to 1 MiB, still bounded by the server packet limit and the existing 250-row durable transaction/checkpoint.
- Chain verified frame headers instead of hashing compressed payloads twice. Preserve old replay formats and partial checkpoints, frame and per-file SHA-256, installed checks and rollback. Use optional OpenSSL SHA-256 for large one-shot blocks with native hashing as fallback.
- Keep newly created replacement-file descriptors open while their files are sealed, avoiding an unseal/reseal permission cycle during copying. Preserve bounded copy/resume, durable checkpoints, installed verification and rollback; use native stream copying with the existing 256 KiB work boundaries.
- Separate filesystem timing counters for actual cross-device rename probes, atomic cursor/journal publications and install setup/publication.

Validation: 41 formal medium-file pushes plus six database-only comparisons, independently checked and natively restored; rejected candidates remain documented. Final medium CLI median 44.21s versus 54.79s initially, with host/network variability. Two full native-dashboard pushes finished in 126s and 119s (original Trickle 152s; recorded MigrateDB 113s); each copied the same 1,175 files / 964,388,859 bytes, skipped 376 matches and passed all 1,551 selected file hashes and nine content-table comparisons. Matched three-file pushes finished in 7s/8s (original Trickle 11s; recorded MigrateDB 10s), with all 249 selected files, 235 parent-theme files and nine unchanged tables checked. 88 isolated and nine real cURL checks passed. Legacy replay, partial resume and checksum-provider fallback passed on PHP 8.1/8.4; real SQL fixtures covered packet clipping, oversized/binary/numeric/NULL rows, rollback and process-exit recovery. No additional remote concurrency, longer time budgets, larger upload envelopes or weaker verification/recovery. A full pull rerun completed in 45s (earlier Trickle 49s; recorded MigrateDB 60s), with all 1,552 fixture hashes and nine content tables independently verified. Large pushes remain slower than the recorded MigrateDB baseline.

## 0.1.35 — 2026-11-21
- Decode staged database values as byte strings before inserting them. Prevent MySQL numeric columns from interpreting hex literals as integers, preserving IDs, zeroes, signed and unsigned limits, decimals, binary data and NULL in both bounded and legacy imports.
- Recognize authenticated migration requests by the configured endpoint URL, including hosts that keep WordPress core outside the public front controller. Retain signature verification and exclude login, admin, REST and unrelated routes from migration isolation.

Validation: 83 isolated checks and nine real cURL checks passed. Real SQL batching, interrupted-import recovery and legacy import preserved numeric limits, decimals, binary bytes and NULL. Flywheel MySQL 8.4.10-google reproduced the old coercion and validated byte decoding. A full native-dashboard push completed in 152s; a separate forced pull verified all 1,552 fixture file hashes and nine normalized content-table hashes. A prior dashboard fetch interruption rolled back; a route-only run exposed the numeric bug before release. Flywheel page-cache flush was needed separately to refresh old HTML; automatic host page-cache purging is not included.

## 0.1.34 — 2026-11-21
- Scope temporary 503 retry delays to their affected endpoint, so an unavailable progress poll does not delay successful transfer cleanup. Keep HTTP 429 cooldowns host-wide.
- Allow hosts that disable PHP disk-space queries or request-time-limit changes. Keep capacity checks where available and retain write-failure handling on all hosts.
- Support optional HTTP Basic Auth credentials per saved connection, including authenticated JSON and streaming transfers. Keep passwords out of dashboard state and diagnostics; preserve saved credentials during connection updates and allow explicit removal.
- Avoid repeated staging-directory permission writes when permissions are already correct. Continue checking path safety and repairing changed permissions; remove a consecutive duplicate cancellation probe during traversal.
- Include the fresh Git-folder policy scan in preparation manifests, eliminating a separate request. Omit unused attachment browsing data from these manifests while preserving the dashboard inventory and standalone review flow.
- Batch decoded rows into bounded INSERT statements in private database staging. Respect the server packet allowance, preserve existing transaction/cursor checkpoints, and flush partial batches before acknowledging progress. Keep per-row validation, replacements, binary/null handling and oversized single-row behavior.
- Known issue: the approximately 1 GB Flywheel push benchmark failed during bounded apply with repeated HTTP 503 responses. Dashboard recovery then reported a missing theme recovery journal. Large Flywheel pushes need a follow-up fix; tested pulls and selected-plugin pushes completed.

## 0.1.33 — 2026-11-21
- Batch full-job persistence during private component preparation and journaled directory replacements. Persist the immutable recovery plan once before mutation, and batch already-installed component counters instead of writing a cursor for every file. Keep individual media install checkpoints, directory journals, checksums and rollback.
- Coalesce progress and repeated cURL callback cancellation-marker reads to 50 ms while checking forced updates immediately, responses before acknowledgement, and source deadlines on every progress update. Remove a duplicate cancellation check in checksum loops without reducing their check frequency.
- Add per-request performance samples to private diagnostics: local apply phases, PHP checks, checksum bytes, checkpoint persistence, progress updates/writes/polls, logging, HTTP calls and live-stream receive callbacks. Include explicitly process-wide CPU deltas and pre-handler elapsed time when available, with explicit overlap and retention limits. Keep transfer behavior and polling cadence unchanged.
- Stream selected file frames while reading source files, decode directly into private destination staging, and retain only the current replay window. Eliminate the complete file snapshot and decompressed intermediate archive for negotiated streaming transfers.
- Resume at verified frame boundaries after timeouts, lost replies and interrupted producers. Persist incremental SHA-256 contexts privately so large files do not reread their prefixes. Keep per-file and manifest checks, staged-file validation, final installed-file verification, exclusions and rollback.
- Reuse still-valid on-disk verification receipts when directly staged components are prepared for installation. Keep fresh checks after copies and final installation.
- Avoid recompressing incompressible file frames while preserving gzip framing, checksums and compression for text.
- Stage imported plugin and theme files directly into their private replacement trees; retain unchanged files, exclusions, complete backups and rollback.
- Skip background checksum comparisons for components absent from the destination.
- Download large pull snapshots in byte/time windows with durable offsets. A time window that receives data continues normally; stalls, broken connections and cancellation retain their failure handling. Separate large downloads from source packaging requests, and retain full-snapshot verification before staging.
- Remove the connection-code button outline, align its spacing and invert its colors on hover while preserving keyboard focus.

Validation: 75 isolated checks passed, including core authorization/session fixtures. Real HTTP tests covered more than 1 GiB of bounded downloads, interruptions, stalls, truncation and cancellation. Disposable WordPress tests passed apply, two backup restores, corruption-triggered rollback and hard-exit recovery. Matched local pulls took 101.95 and 103.58 seconds versus a 153.26-second baseline. A subsequent staging pull verified 20,473 files and 54 tables in 153.54 seconds including preparation and cleanup, versus 202.33 seconds before persistence/progress optimization. Site database rows changed between hosted runs; timings are observations, not a bandwidth guarantee. PHP 8.1/default PHP and JavaScript syntax, archive integrity and packaged runtime equality passed.

## 0.1.32 — 2026-11-20
- Replace component inclusion, matching visibility and hash checkboxes with accessible toggles; show matching plugins and themes by default.
- Align compact database comparisons with plugin/theme rows, separate table engine into its own column, and color source size cells from green through yellow to red using source-relative byte sizes. Keep destination cells white.
- Keep comparison columns in the same local-first, remote-second order as the site cards for both pushes and pulls. Add larger Carbon sort arrows, single-line database headings and a scroll fade beneath sticky headers.
- Improve preset padding, summary spacing and edge-to-edge comparison fills while preserving text alignment. Remove gray sort-header hover backgrounds and replace summary focus outlines with a keyboard title indicator.
- Use locally bundled IBM Carbon calendars for media upload dates, preserving ISO/UTC date values, typed entry, independent bounds and transfer locking.
- Show selected database, plugin, theme and media sizes only in section summaries and the combined total. Preserve pending states for incomplete estimates and filtered media.
- Keep saved remotes visible and selected when their URL or stored identity matches this installation; retain operation-level checks against self-transfers.

Validation: all 71 isolated checks passed, including WordPress core authorization/session fixtures and the OPcache regression. The Carbon picker passed 13 real-browser checks at desktop and narrow widths. Seven live WordPress markup/asset checks and PHP/JavaScript syntax checks passed. Rendered fixtures verified table ordering, row density, source-only colors, summary totals and responsive layouts. Release ZIP integrity and runtime file/hash equality passed. No live migration was run.

## 0.1.31 — 2026-11-20
- Prevent false obsolete-PHP-file failures after whole-directory replacement by invalidating the path's OPcache entry before verifying removal. Keep failures for actual leftover files.
- Edit saved connection labels inline and remove saved connections with confirmation. Preserve URLs, credentials, migration history and backups; block management during retained transfers or recovery.
- Preserve inline editing and focus across inventory refreshes, and reject stale connection metadata after a label change.
- Animate the direction arrow on hover and push/pull changes, with reduced-motion support and no gray hover surface.
- Align version-conflict notice typography with the shared scale and replace its brown accent with a neutral border.
- Use the shared blue with white text for ordinary full-width feedback banners, aligning messages with the content column.

Validation: 71 isolated checks passed, including the OPcache regression, connection-management checks and WordPress core authorization/session fixtures. The OPcache regression also passed on PHP 8.4; it reproduced the original obsolete-file error before the fix. Packaged PHP/JavaScript syntax, ZIP integrity and exact runtime file/hash checks passed. The historical hosted OPcache setting remains unconfirmed; no hosted migration was retried.

## 0.1.30 — 2026-11-19
- Introduce the Clarity transfer workspace with locally served fonts, a white shared header and a compact design-token scale in `assets/admin.css`. Use `#3858e9` for every blue accent.
- Choose and switch horizontal presets, then customize transfer categories through checkboxes and expandable summaries. Retain the last site and direction; keep this site on the left when pulling.
- Show source-to-destination component comparisons with destination upgrade, new-install and downgrade highlights. Matching requires both version and content hash; large database tables are highlighted.
- Present recent migrations as detailed records of the transferred selection and result, shown only for the Most recent preset.
- Validate endpoints and Trickle compatibility before exposing presets. Unify notices, connection-code copying and navigation across admin pages; prevent the saved-site menu from overlapping its labels.
- Add completed-comparison cleanup and a remote Settings link when retained work blocks Sync. Release read-only comparison markers while retaining credential ownership and protecting real snapshots, jobs and source locks.
- Add Settings → Retained source preparations with explicit cancellation. Cancellation stops the original source worker and preserves destination staging and backups.
- Identify native requests with `Trickle/<version> (+https://github.com/jonschr/trickle)`. Space background comparisons, reduce competing polls and honor server-requested cooldowns, including HTML rate-limit responses.
- Reuse acknowledged upload offsets, rediscover offsets after uncertain responses and share a bounded processing budget across small-transfer stages. Verify changed media and rebuilt components while preserving full verification for older recovery cursors.
- Avoid separate browser preflight round trips and unrelated WordPress update checks on completion. Keep verified source snapshots, staging, resumable checkpoints and destination backups.
- Remove internal working documents and obsolete test scripts from the repository. Add an isolated test runner and a reproducible WordPress Coding Standards audit configuration.

Validation: 68 isolated checks passed, including WordPress core authorization/session fixtures. Disposable peers passed push/pull data verification, lost-receipt retry, backup restoration, legacy comparison cleanup, retained-snapshot protection, source cancellation and subsequent build sync. Packaged PHP/JavaScript syntax, archive integrity and runtime file/hash equality passed. Existing WordPress Coding Standards findings remain unresolved. Harrison dev rejected native endpoint requests with HTTP 403; hosted sync and a representative hosted performance improvement remain unverified.

## 0.1.29 — 2026-11-17
- Show skipped plugins and themes by their readable names, with folder paths retained as tooltips and as a fallback when inventory is unavailable.
- Separate skipped items from transfer activity with a clear heading, consistent spacing and a compact list that wraps on smaller screens.

Validation: skipped-name, component-presentation and workflow JavaScript checks passed; JavaScript/PHP syntax and diff checks passed. Rendered modal fixtures at desktop and phone widths confirmed a 20px gap below activity and no horizontal overflow. No live transfer was run.

## 0.1.28 — 2026-11-17
- Support InnoDB, MEMORY and MyISAM tables with consistent snapshots, transactional staging, original-engine restoration and durable destination backups.
- Import related foreign-key tables together, validate their staged relationships before replacement, and preserve constraints through rollback and saved-backup restoration.
- Drain existing ordinary site requests and pause new plugin execution during replacement and verification; keep authenticated migration and recovery available, including when polling authentication changes during a database swap.
- Preserve the local controlling user’s browser cookies, session token and original session lifetime across database pulls and rollback, including changed numeric user IDs; no live-site browser login is required.
- Group repeated transfer errors, including rollback and last-activity wrappers, into one copyable issue with a report count; keep distinct failing paths separate and clear retry notices after recovery.
- Add a primary Copy connection code button beside the secondary Show and Regenerate actions, and remove the confusing local-address connection message.
- Refresh the recovery loader when its contents change within the same plugin version.

Validation: all 18 JavaScript suites and 36 isolated PHP runs passed, including cookie/session preservation, loader authentication, request draining, recovery and packaged component ranges. Main Dev passed 38 mixed-engine checks and 14 foreign-key checks covering staging, replacement, rollback and retained-backup restoration. Runtime/vendor PHP syntax and diff checks passed. Live Oak browser pulls verified 52 tables, 19,896 rows and 18,426 files without transfer errors; a fresh admin-page request retained the same local user. Desktop/mobile checks covered grouped error activity and connection-code copy/fallback behavior. No site content was pushed to a remote destination.

## 0.1.27 — 2026-11-13
- Stage changed plugins and themes as complete private components, then rename the original into backup and install the staged component; retain per-file media transfers and skip fully matching components.
- Preserve destination exclusions and protected nested Git folders; block destination symlinks before replacement and verify copied matching files after the swap.
- Journal both renames for interrupted-apply recovery; restore complete component backups repeatedly while retaining compatibility with existing per-file backups.
- Measure complete component backup sizes in previews; probe actual directory rename support. On separate or bind-mounted filesystems, back up the complete component, remove the old directory and copy the complete staged snapshot into a fresh directory with resumable recovery.

Validation: 18 JavaScript and 10 isolated PHP suites passed, including 29 component-replacement checks covering rename and cross-mount copies, exclusions, interrupted removal/copy and rollback. Disposable QA-A/C sites passed 36 backup/workflow checks, 20 exact-build updater checks, and recovery-plan, large-checkpoint and compatibility suites. Harrison staging passed two real plugin/theme pushes, obsolete-file removal, exact component hashes, unchanged activation, both snapshot restores and complete fixture cleanup. PHP/Python syntax and diff checks passed. Both Harrison sites run the matching 0.1.27 runtime.

## 0.1.26 — 2026-11-11
- Highlight new plugin/theme installs in green; fade inactive source plugins while keeping selection controls and change indicators clear, with full detail on hover or keyboard focus.
- Use larger Push site / Pull site buttons without destination names.
- Detect plugin/theme symlinks before preparation; require per-component source approval and copy linked contents as regular files while keeping destination links protected.
- Reject source link loops, broken links and component escapes; retain exclusions through resolved targets, add development/credential exclusions, and remove dangling links during cleanup.

Validation: all 18 JavaScript suites, nine isolated PHP suites, runtime/vendor PHP syntax and offline security checks passed. Linked-source fixtures covered resumable packaging, staging, actual file replacement, matching destination hashes, repeat folder checksums, plugin/theme links, Git review, secret-target exclusions, cycles, escapes, broken links and destination protection. Desktop/mobile checks covered button sizing, new-install/inactive styling and the real source-link approval handler. No remote migrations were run for this release.

## 0.1.25 — 2026-11-10
- Reuse stable verification receipts across staging previews, while rehashing changed/recent files and retaining installed-byte verification.
- Calculate source checksums while packaging where possible; preserve EOF digests and safe mid-file resume fallback.
- Batch file records into bounded compressed frames, flushing complete acknowledged output at checkpoints.
- Resume source planning and obsolete-file walks from stored directory cursors; retain Git and environment-file exclusions.
- Back up only files in the replacement/removal plan; leave checksum-matched files untouched during backup and restore.
- Show affected backup components while preserving older full-component snapshot restores.

Validation: all 18 JavaScript suites, six isolated PHP suites, runtime PHP syntax and offline security checks passed. Disposable Cove peers passed a file/database pull, corruption rollback, cancellation recovery, a streaming push crossing the 16 MiB upload window, and repeat streaming/chunked pushes that skipped matching files. Destination hashes matched; unchanged themes were absent from new backups. Release testing used synthetic fixtures and disposable local sites.

## 0.1.24 — 2026-11-05
- Add one-click transfer diagnostics inside the lightbox for all outcomes, with redacted retained logs and a selectable-text clipboard fallback.
- Tighten wrapped transfer-button labels while retaining comfortable padding and the minimum button height.

Validation: all 18 JavaScript suites, diagnostic-report and AJAX guard PHP checks, runtime PHP syntax and offline security checks passed. Desktop/mobile renders covered successful, failed and warning outcomes plus wrapped enabled/disabled button labels. Native controller diagnostics remained readable while the mutation lock was held. No live migrations were run for this release.

## 0.1.23 — 2026-11-05
- Compact plugin/theme comparisons into single-line rows; show green/up upgrades, red/down downgrades and neutral matches while retaining versions, activation, sizes and available updates.
- Verify installed file hashes, obsolete-file removal, table swaps and activation directly in resumable steps; rollback real replacement failures without making HTTP self-requests.
- Improve TLS and request diagnostics for optional HTTP probes, preserving explicit CA configuration.
- Keep table replacement from replaying while final verification resumes.
- Display quoted site titles and saved labels as plain text in both directions without weakening HTML escaping.
- Open Settings once after individual dashboard activation; leave bulk activation, CLI and silent updates unchanged.

Validation: all 17 JavaScript suites, six isolated PHP suites plus the CLI activation check, runtime PHP syntax and offline security checks passed. Desktop/mobile rendering covered 17 compact plugin rows and active parent themes. The native disposable-peer cycle passed file/database pull, resumable verification after one table swap, backup restore, corruption rollback and cancellation recovery. No customer production sites were modified. Verification checks migration integrity; it does not replace frontend functional testing.

## 0.1.22 — 2026-11-04
- Trim repeated setup instructions, media help, connection copy and save confirmations.
- Keep progress sizes beside component names on desktop and left-aligned beneath them on mobile.
- Hide the transfer heading's focus outline while preserving keyboard focus on controls.

Validation: all 17 JavaScript suites and changed PHP template syntax checks passed. Offline desktop/mobile renders covered Settings, Diagnostics and media selection; dialog checks verified size alignment, heading focus and keyboard indicators on controls. UI-only changes; no migration or production-site testing was run for this release.

## 0.1.21 — 2026-11-04
- Keep progress monotonic across delayed polls and reloads; use half-tone transferred fill, full-tone verification and gold replacement.
- Consolidate View backup, Done and View site in one completion footer and remove redundant success text.
- Show component transfer progress from measured compressed byte ranges and acknowledged offsets; keep older snapshots’ progress at the whole-stream level.
- Sort and group activity, remove duplicate completion/stream entries, and retain exact failure details.
- Distinguish uncertain errors from confirmed stopped/cancelled transfers; include final cleanup in elapsed time.
- Use binary size units consistently and report independent force settings for plugins, themes and media.
- Restore the most recent transfer direction on page load.
- Keep bounded replacement checks available during maintenance and respect retry pauses during cancellation.

Validation: 17 JavaScript suites, 9 isolated PHP suites, runtime PHP syntax checks and offline security regressions passed. A full forced Harrison local → Harrison dev push completed with 24 tables, 10,132 files, matching builds and HTTP 200 health. The final progress changes passed replay of all 117 saved reports and desktop/mobile rendering checks. No customer production sites were modified.

## 0.1.20 — 2026-11-03
- Install the exact local Trickle build on connected sites with one click, including unreleased and same-version changes.
- Validate uploaded builds, destination PHP syntax, permissions and checksums; verify the installed build before migrations.
- Preserve the controlling administrator session across database replacement and improve authentication/recovery diagnostics.
- Stop on failed database reads or corrupt checkpoints instead of continuing with incomplete state.
- Consolidate migration selection, size estimates and connection controls; use a focused transfer dialog and compact component progress.
- Keep estimated component sizes visible during preparation and group transfer activity by plugin, theme, table or media year.
- Show clear incoming upgrade/downgrade and activation states; log each unavailable source plugin by filename.
- Share navigation with Backups, bound browser request timeouts, and simplify job storage and database helpers.

Older installations need this build installed once to enable direct local-build copying. The published JSON updater remains available for released versions. Active transfers, remote Git checkouts and host filesystem restrictions prevent plugin replacement.

Validation: 20 direct-build and 26 legacy-updater checks, browser one-click installation with exact-build verification, JavaScript regressions, PHP checks, security checks and QA pull/recovery tests. No customer production sites were modified for this release.

## Earlier releases

See the [GitHub release history](https://github.com/jonschr/trickle/releases) and the changelog in `update.json`.
