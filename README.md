# Trickle

**Move the parts you need, without migrating the whole site.**

Trickle is a WordPress plugin for the back-and-forth between local, staging, and production sites:

- Push a plugin or theme to staging without touching its database.
- Pull fresh content locally without replacing your code.
- Sync missing or changed media without downloading the entire library again.

## Why Trickle?

**Control what gets replaced.** Choose individual plugins, themes, database tables, and media. Compare installed versions before deciding what to move.

**One connection, both directions.** Save a remote site once, then push or pull from the same dashboard. Transfers run directly between your sites, without an external migration service.

**Less repeated work.** Matching code and media are skipped. Interrupted transfers resume, and recent settings make recurring tasks easy to repeat.

**Complete code replacements.** Selected plugins and themes are replaced as complete components, removing obsolete code instead of leaving old files behind.

**A way back.** Trickle verifies the installed result, restores failed replacements, and keeps three destination backups you can restore yourself.

The WordPress dashboard handles everyday transfers. WP-CLI supports scripted workflows and AI operators.

## Get started

1. [Download the latest ZIP](https://github.com/jonschr/trickle/releases/latest/download/trickle.zip), install it on both sites, and activate it. Both sites must use the same Trickle version.
2. Open **Tools → Trickle** on the remote site and copy its connection code.
3. Add that connection on the controlling site.
4. Choose **Push** or **Pull**, select what to transfer, and start.

Treat connection codes like passwords. Selected items replace their destination counterparts. Plugin activation follows the source by default, with an option to keep destination settings.

Requires single-site WordPress 6.4+, 64-bit PHP 8.1+, cURL, and writable storage. Remote connections require HTTPS. Keep an independent site backup before significant changes.

## More information

- [Releases and downloads](https://github.com/jonschr/trickle/releases)
- [Changelog](changes.md)
- [WP-CLI and AI operator guide](AI-USAGE.md)
