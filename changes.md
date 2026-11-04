# Changes

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
