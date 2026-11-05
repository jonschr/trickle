# Trickle

Trickle is a WordPress plugin for administrators to push or pull selected content between WordPress sites running Trickle. The plugin also provides WP-CLI commands and workflow presets.

Administrators choose the source, destination, direction, and components: database tables, plugins, themes, and media files. The interface compares installed destination versions with incoming source versions and reports database table sizes. Upgrades are green, downgrades red, and matching versions neutral; compact single-line rows retain both versions, sizes and available updates. Up/down icons show upgrades and downgrades; filled/hollow status icons show activation on both sides, including active parent themes. Database URL and path replacements support serialized values. Media files and attachment records are separate selections.

Transfers prepare private source snapshots, receive resumable streams, verify checksums, and replace selected destination data. Destination backups and recovery are part of the workflow. The monitor uses one size-weighted component bar to show transfer and replacement, with total elapsed time and component activity. Installed files, table swaps and activation state are verified directly before completion; failures trigger recovery. Migration completion does not depend on an HTTP request back to the destination.

The product includes connection and access settings, migration, backups, and diagnostics. Connections require Trickle on both sites. The documented transfer scope excludes WordPress core and wp-config.php.

## Interface commitments

- Keep WordPress blue for primary actions and use concise, direct copy.
- Manage remote connections on Migrate. Keep this site on the left; use a dashed Add source card when no remote is selected, a three-dot menu on the remote card for switching/adding/updating, and a modal for connection fields. The center arrow flips Push/Pull. Keep Preset and Recent on one row when space permits. Settings contains permissions, this site’s connection key and transfer preferences.
- During setup, keep component inclusion, selection counts and source sizes together in the component tabs, with the transfer action immediately beneath them. Do not add a separate selection sidebar or source-size strip.
- During monitoring, use a compact white modal with a single size-weighted bar for Database, Plugins, Themes and Media. Light colors mean pending, dark colors transferred, and gold replaced. Show component sizes, state labels, one total elapsed timer, and transfer activity. Component transfer fill uses measured compressed byte ranges and acknowledged stream offsets. Older snapshots without boundaries show only whole-stream percentages. Replacement uses actual per-component file counts. Keep site cards on setup, installed-state checks in the workflow, and cancellation outside the scrolling content.

The current visual replacement covers the migration workspace and monitor. Settings, backups, and diagnostics retain their existing shared plugin components.
