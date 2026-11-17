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

### Mixed database engines

Database transfers support InnoDB, MEMORY and MyISAM source tables. InnoDB uses a consistent transaction snapshot. When nontransactional tables are selected, a separate connection holds read locks on those tables before the InnoDB snapshot starts; writes to those tables may wait during source packaging. This requires source `LOCK TABLES` permission. A lock or read failure stops preparation rather than exporting inconsistent data. The source tables and their engines are not modified.

Destination imports always stage rows in InnoDB so row batches and resume checkpoints commit together. Before replacement, Trickle converts each staged table to its original source engine. Retained destination backups use InnoDB for durable storage; restoration returns the original destination engine. MEMORY tables retain their normal volatile behavior after installation or restoration.

Repeated transfer errors appear as one activity entry with a report count and copyable details. Identical messages for different file paths remain separate. Connection retries clear their active notice when the connection recovers; terminal failures still require attention.

Foreign-key tables are imported without constraints until all selected tables have been staged. Trickle then validates and attaches the constraints against the private stage tables before swapping the related tables together. Constraint names are isolated from retained backups. Ordinary same-database foreign keys, including composite keys and cascading actions, are supported; related tables must be selected together. Backup restoration preserves these relationships too.

During destination replacement and verification, Trickle pauses ordinary web requests, including admin, AJAX and REST requests. A shared/exclusive runtime gate lets already-running ordinary requests finish before mutation begins. Authenticated Trickle control and recovery requests remain available; stale or unauthenticated requests cannot load ordinary plugins during this protected window. Normal plugin initialization resumes after verification and maintenance teardown. File checksums remain mandatory; no per-plugin generated-file exceptions are needed.

Browser-driven database pulls preserve the local controlling administrator by username, including the existing browser cookies, session token and session lifetime. This also works when that username has a different numeric ID in the imported database. Rollback preserves the authenticated local session before restoring user tables. No browser login to the source site is required.
