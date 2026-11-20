# Trickle

Trickle transfers selected database tables, plugins, themes, and media between WordPress sites. Connections are saved for subsequent pushes and pulls. Transfers can run from **Tools → Trickle** or WP-CLI.

## Installation

1. [Download the latest ZIP](https://github.com/jonschr/trickle/releases/latest/download/trickle.zip), install it on both sites, and activate it. Both sites must run the same Trickle version and build.
2. Open **Tools → Trickle** on the remote site and copy its connection code.
3. Add the connection on the controlling site.
4. Choose a direction and preset, adjust the selection, and start the transfer.

Treat connection codes like passwords. Selected items replace their destination counterparts. Plugin activation follows the source by default, with an option to retain destination settings.

## Transfer behavior

- File comparisons use content hashes. Matching file contents are not uploaded again.
- Changed plugins and themes replace complete components, including removal of obsolete files. Protected destination exclusions are retained.
- Interrupted transfers retain checkpoints for resuming. Failed replacements attempt rollback; three destination backups are retained for manual restoration.
- Database imports use private staging tables before replacement. InnoDB, MyISAM, and MEMORY source tables are supported. Nontransactional source tables require read locks and `LOCK TABLES` permission; writes may wait during export. Related foreign-key tables must be selected together.
- Ordinary destination web requests pause during replacement and verification. Trickle control and recovery requests remain available.
- Installed-file verification checks changed media and rebuilt components. It verifies migration integrity, not application behavior.

## Network requests

The controlling site's PHP cURL transport sends authenticated HTTPS requests directly to the connected site at `/?trickle=<operation>`. Requests exchange inventories, comparison hashes, database and file exports, and transfer status. There is no external migration service.

The default migration and health-check header is:

```http
User-Agent: Trickle/0.1.30 (+https://github.com/jonschr/trickle)
```

The version follows the installed plugin. A `csm_user_agent` filter can override this value. These are plugin-originated requests, whether initiated through the dashboard or WP-CLI.

Background comparisons run serially with spacing between requests and pause when the site requests a cooldown. Progress and size polling use slower intervals to reduce competing traffic. Rate-limit responses preserve `Retry-After`, including non-JSON firewall responses.

## Requirements

Single-site WordPress 6.4+, 64-bit PHP 8.1+, cURL, writable private storage, and HTTPS for remote connections. Keep an independent backup before significant changes.

## Documentation

- WP-CLI command help: `wp help trickle`
- [Changelog](changes.md)
- [Releases](https://github.com/jonschr/trickle/releases)

## Development checks

Run `python3 tests/run-offline.py` for isolated checks. Optional core API checks use `--wordpress-core /path/to/wordpress` without loading a site or its database. Integration scripts require their named disposable sites and are not part of this runner.

Run `phpcs` with WordPress Coding Standards installed to audit first-party PHP using `.phpcs.xml.dist`. Existing standards findings are not suppressed. Shared design tokens are defined at the beginning of `assets/admin.css`.
