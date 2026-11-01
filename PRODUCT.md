# Trickle

Trickle is a WordPress plugin for administrators to push or pull selected content between WordPress sites running Trickle. The plugin also provides WP-CLI commands and workflow presets.

Administrators choose the source, destination, direction, and components: database tables, plugins, themes, and media files. The interface compares both sites, including installed versions and database table sizes. Database URL and path replacements support serialized values. Media files and attachment records are separate selections.

Transfers prepare private source snapshots, receive resumable streams, verify checksums, and replace selected destination data. Destination backups and recovery are part of the workflow. The interface reports preparation, transfer and verification, replacement, and destination health; it distinguishes measured progress from unavailable estimates.

The product includes connection and access settings, migration, backups, and diagnostics. Connections require Trickle on both sites. The documented transfer scope excludes WordPress core and wp-config.php.

## Interface commitments

- Keep WordPress blue for primary actions and use concise, direct copy.
- During setup, combine a component rail and comparison workspace with a persistent selection summary on wide screens.
- During monitoring, use a dark navy telemetry canvas with measured bytes, speed, elapsed time, and remaining-time estimates when available. Keep the transfer pipeline and recent activity visible.

The current visual replacement covers the migration workspace and monitor. Settings, backups, and diagnostics retain their existing shared plugin components.
