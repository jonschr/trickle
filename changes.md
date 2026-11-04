# Changes

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
