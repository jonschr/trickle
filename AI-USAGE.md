# Trickle for AI operators

Use WP-CLI on the controlling site. The remote needs Trickle and a saved connection, not SSH. The same commands work with ordinary WordPress and local managers such as Cove (`cove wp <site> trickle ...`). Both ends must run the same Trickle version.

## Discover the project

```sh
wp trickle sites --format=json
wp trickle presets --format=json
wp trickle inventory --remote=Production --format=json
```

Use the exact saved name, ID or URL. With several saved remotes, `run` requires `--remote`; never infer Production from an ambiguous request. Do not print connection codes. New connections use the existing `connect --code-file` command with a private file or stdin.

Inventory exposes local/remote component IDs, versions, activation, tables and capabilities. Its `version_controlled` field lists eligible **local** plugin/theme folders. Its `operator_guide` field points to this file. It excludes connection secrets.

**Push version-controlled code** selects plugin/theme folders containing a `.git` directory or file, including worktrees and nested repositories. A repository above `wp-content` alone does not select every vendor component. Trickle itself is excluded; use `update-remote` for that plugin. No author/publisher or “custom versus premium” classification is made.

The preset copies current folder contents, including uncommitted changes and generated assets. It is not a Git push or a `git ls-files` export. Never create repository markers merely to broaden this preset. For authorized non-Git code, use the advanced explicit plugin/theme selection commands.

## The three workflows

| Preset | Database | Plugin files | Theme files | Media |
| --- | --- | --- | --- | --- |
| `pull-db` | All eligible source WordPress tables | None | None | None |
| `pull-db-missing` | All eligible source WordPress tables | Only folders absent from the destination | None | None |
| `push-git` | No tables | Git-backed plugin folders | Git-backed theme folders | None |

“Missing” uses WordPress component IDs. It does not overwrite an installed plugin to match a source version. A Git-backed child theme does not automatically include a non-Git parent; the parent must already be installed on the destination. A database pull similarly reports missing source-active themes before starting. These presets do not include core, configuration, MU plugins or external storage.

Plugin activation follows the user's global default: **the full source active list**, even on a version-controlled-code push or a database-only pull. This can change activation of unselected plugins. The plan exposes `activation.activate`, `activation.deactivate` and `activation.missing`. Use `--keep-destination-plugins` only when preserving destination activation is intended. This opt-out does not alter selected files or tables. File-only pushes do not switch the active theme.

## Plan, then execute the authorized scope

Without `--yes`, `run` returns a read-only component-selection plan:

```sh
wp trickle run pull-db-missing --remote=Production --format=json
wp trickle run push-git --remote=Staging --format=json
```

Inspect `source`, `destination`, `direction`, `selection`, `activation`, `git_folders`, `can_run` and `blockers`. `--dry-run` always plans, including when `--yes` is also present. No staging job or backup is created. A plan is a current inventory snapshot, not a reservation or an exact file-change diff. Execution rechecks the actual source/destination and normal staging checks still apply.

When the user has authorized that transfer, execute the same workflow:

```sh
wp --user=admin trickle run pull-db-missing --remote=Production --yes --format=json
wp trickle run push-git --remote=Staging --yes --format=json
```

For a database pull that includes users, use the intended **local administrator** with WordPress's global `--user` flag to retain that account's credentials. Discover local administrator logins with `wp user list --role=administrator --fields=ID,user_login --format=json` if necessary; do not assume every site uses `admin`.

Execution stages, checks and applies, then releases the active session while retaining a destination backup. Repeated `run` commands need no manual discard. Success reports `ok: true`, `action: "transfer"`, `completed: true`, `status: "applied"`, source/destination URLs, counts, `backup_id`, health and missing-activation information. Confirm health and report any `activation_missing` or cleanup warning; a completed transfer with an unverified health result is not proof that every application feature works.

Trickle workflow errors return `ok: false`, `code`, `error`, the known transfer ID and recovery status, and exit nonzero. JSON plan/execution/error objects carry `schema_version: 1`; tolerate extra fields. Normal WordPress/WP-CLI bootstrap or argument errors can still appear on stderr before Trickle runs. Do not blindly replay a write after an unknown response. The default streams pulls continuously and sends pushes in signed upload windows of at most 16 MiB; use `--transport=chunks` for bounded 512 KB requests. Both modes verify complete snapshots before apply. Transient reads/transfer steps use bounded backoff; apply is never blindly retried. A network failure in `run` retains staging and reports `recovery: "retained"`; honor `retry_after` before `resume`, then inspect/apply the same transfer. Do not start a second transfer over that checkpoint.

## Protection and recovery

- Git-backed destinations are skipped by default; pulls also protect source repositories. The plan lists skipped paths. `--yes` never grants Git inclusion. Only pass `--include-git=plugins/project-core` for a specifically authorized per-transfer override.
- `.git`, `.gitignore`, `node_modules` and environment files are always excluded, even with Git inclusion. Existing destination copies are preserved.
- Failed staging recovers without applying it. Failed apply restores its backup. An unreachable site may leave recovery pending; inspect the retained session instead of starting another write.
- Keep backups. Use `wp trickle backups --format=json` and `wp trickle restore-backup <id> --yes --format=json`; add `--remote=Staging` for a remote push destination. Restore only when requested or required to recover the authorized operation, because it overwrites later changes to included items.

For an interrupted retained transfer:

```sh
wp trickle status --format=json
wp trickle resume --format=json
wp trickle preview --format=json
wp trickle preview --offset=200 --format=json
wp trickle apply --yes --format=json
```

These advanced commands keep the retained session, unlike `run`. Use them when exact staged file changes need inspection before apply. `preview` paginates verified file changes; database tables are still full replacements, not row merges. Preserve the session's original remote/direction and check its status before choosing resume/apply/restore. If an unrelated broken plugin blocks WordPress bootstrap, skip that specific plugin with WordPress's `--skip-plugins=<id>`; do not skip Trickle or all plugins.

Trickle's CLI does not grant permission to send messages, publish code, or migrate a different site. Use the user's actual task scope, project instructions and displayed URLs.

For media, `--media=all` includes the whole library but skips identical files through the year/month/file checksum cascade. Add `--rehash-media` to verify contents without trusting the cache. Use `--force-media` only when explicitly asked to retransfer matching media too. Force preserves Git/environment exclusions. Saved transfers retain this choice.

## Traffic identity in tests

Invoking Trickle through its dashboard, CLI or APIs must preserve the plugin’s normal user agents, even when an AI operator starts the operation. Do not install agent-identity overrides or change the user agent in migration test fixtures. This keeps host/firewall behavior representative of daily use. Identify the agent only on HTTP requests the agent makes directly outside the plugin.


`wp trickle inventory --remote=Production --format=json` includes `estimates` for database allocation and file metadata, with a `complete` flag. These are estimates before compression and matching-file skips. `wp trickle cancel --yes --format=json` persists cancellation without waiting for the transfer lock; a `cancelling` result means safe cleanup/recovery is still pending. WordPress cron continues that cancellation if the browser/CLI disappears. Check status before starting another transfer. Consistent database export stops after 45 seconds; per-file checksums stop after 15 seconds. Large or slow selections may need narrowing. Downloads, verification, import and preview acknowledge separate steps; CLI commands consume those continuations automatically.

## Install the local Trickle build remotely

Run `wp trickle update-remote --remote=<saved-site> --check --format=json` to check readiness, then `wp trickle update-remote --remote=<saved-site> --yes --format=json` to install this site's exact Trickle runtime. This includes same-version development changes and can replace a newer remote version. The direct installer must already be present on the remote; legacy remotes require a one-time current ZIP installation. No migration starts as part of the update.
