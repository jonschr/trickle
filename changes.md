# Changes

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
