# Trickle 0.1.0 — preview

A shared WordPress plugin for selective push/pull migrations, controlled from the local site's **Tools → Trickle** page. Install the same ZIP at both ends.

## Included

- Select plugin folders, themes, and individual database tables.
- Compare installed plugin/theme versions at both ends. Update badges use each site's WordPress update information, including vendor updates exposed through WordPress. **Refresh versions** requests fresh information; a missing badge is not proof that the vendor has no newer version.
- Transfer all uploads, files modified since the last successful all/changed media transfer, or selected attachments and their generated image sizes. Individual selection currently lists the latest 250 attachments; all/changed modes cover the entire uploads directory.
- Control both directions from local. The remote site never initiates a connection back to local.
- Send one gzip-compressed database stream and one gzip-compressed file stream per transfer, when those components are selected. No complete export archive is built on the source disk.
- Stage privately, verify stream/file checksums, review counts, then explicitly apply.
- Retain destination database tables and replaced/deleted files for rollback until **Discard transfer & backup**.

## Installation and use

1. Upload `trickle-0.1.0.zip` through Plugins → Add Plugin on both sites and activate it.
   Activation installs `wp-content/mu-plugins/0-trickle-loader.php`. That directory must be writable. The loader isolates streaming/recovery requests and the Trickle dashboard from ordinary plugins and theme code, so a broken active plugin need not block rollback. Inventory requests load ordinary plugins so their vendor update filters still work. A failed inventory request does not hide an existing transfer's recovery controls. Other must-use plugins and host firewalls still run. The loader is dormant when Trickle is deactivated.
2. Open Tools → Trickle on the remote site. Reads are allowed by default; incoming remote writes are disabled by default. Enable incoming writes if you want to push into this site.
3. Use **Copy connection code** at the top of the remote Trickle page, then paste both lines into the local site's connection panel. A complete code connects automatically: the visible site URL followed by its private key. Check the URL before pasting. Permission checkboxes save automatically. Treat the code like a password; do not put the key in a URL or support ticket. Older base64 connection codes are still accepted.
4. Choose push or pull, review source/destination versions, and select components. Selecting a child theme also selects its parent.
5. Select **Stage transfer**. This reads the source and prepares temporary destination tables/files without replacing live content. The transfer page shows which stream is running, not a precise byte percentage.
6. Review the verified table, row and file counts and destination URL, then **Apply staged transfer**.
7. Keep the rollback backup until satisfied, then discard it. Only one pending/retained transfer is allowed per destination.

The preview respects `DISALLOW_FILE_MODS` and filesystem permissions. It does not grant FTP access or bypass file ownership restrictions. New plugins are not automatically activated; destination activation state is preserved.

## Transfer protocol

All remote requests are HTTP POSTs to the WordPress installation URL with a dedicated `trickle` query parameter. These are lightweight plugin endpoints, not REST or admin-ajax handlers. The local dashboard uses normal authenticated admin AJAX to invoke its controller.

| Action | Request | Response | Permission |
| --- | --- | --- | --- |
| `manifest` | Small JSON body | Version, component and table inventory | Remote reads |
| `export` | JSON selection and stream kind | One continuous gzip body | Remote reads |
| `prepare` | JSON selection | Destination transfer ID | Incoming writes |
| `receive` | Continuous gzip body, signed ID/kind headers | Validated staging summary | Incoming writes |
| `status` | JSON transfer ID | Staging/apply status | Incoming writes |
| `commit` | JSON transfer ID | Apply result | Incoming writes |
| `rollback` | JSON transfer ID | Restore result | Incoming writes |
| `discard` | JSON transfer ID | Cleanup result | Incoming writes |

Control requests carry a SHA-256 body digest and an HMAC-SHA256 signature. `X-CSM-Time`, `X-CSM-Nonce`, `X-CSM-Hash`, `X-CSM-Params`, and `X-CSM-Signature` authenticate requests. Timestamps have a five-minute window; nonces are single-use. Stream uploads authenticate their transfer ID/kind in signed headers and rely on verified TLS plus final stream/file digests for body integrity. Connection secrets never appear in request URLs. Redirects are not followed. The user agent is `Trickle/0.1.0 (WordPress site migration)`.

Database streams contain schema records and base64-encoded row values. They are logical data streams, not SQL files to execute wholesale. The receiver validates schemas and constructs its own inserts. The source uses one InnoDB repeatable-read snapshot, reading bounded batches with primary-key pagination where available. Tables without primary keys use offset pagination. Ordinary serialized strings, arrays, objects and references are handled without instantiating objects; unsupported custom serialization stops the import. Binary columns are not searched/replaced.

File streams contain path/size/mtime headers, raw file bytes, per-file SHA-256 trailers, and a final manifest digest. No archive-extraction library or shell command is required. Only selected plugin/theme paths and permitted uploads are accepted; symlinks, traversal, executable uploads, and replacing Trickle itself are blocked.

On pull, local cURL writes the compressed stream into private temporary storage before parsing it into staging. On push, cURL reads the source generator directly and sends a streaming POST to the remote receiver. Hosting proxies may buffer incoming uploads before PHP sees them. There is no requirement that the remote server can resolve or reach the local hostname.

Database apply uses one multi-table `RENAME TABLE` operation to exchange staged and destination tables. File apply uses per-file replacements, removes obsolete files inside selected plugin/theme components, and backs up original files outside the webroot. Whole-site file application is not atomic: use a maintenance window for busy sites. Database and filesystem snapshots are not synchronized with each other.

Destination `siteurl`, `home`, upload configuration, privacy flag, active plugin list, cron schedule, and Trickle keys/settings/control state are preserved, including preserving absent optional settings. When the logged-in local controller imports user data, its administrator login/password are retained, its session is refreshed, and it remains an administrator. Remote token-only writes do not preserve an interactive remote administrator account. Other imported users/settings and integration credentials are replaced by the selected source data.

## Requirements and limits

- Single-site WordPress 6.4+, 64-bit PHP 8.1+, cURL and zlib.
- InnoDB tables without foreign-key constraints. Multisite, views, non-InnoDB tables and foreign-key schemas are not supported in this preview.
- Writable private system temporary storage **outside the WordPress public directory**, enough disk space for staging plus rollback copies, and permission to create/rename/drop database tables.
- Permission to install the recovery loader in `wp-content/mu-plugins`; file application is blocked if the expected loader is missing. Must-use plugin failures and core/bootstrap failures are outside its recovery coverage.
- Selected destination plugin/theme/upload paths must be writable; shell access is not required. WordPress core, wp-config.php, MU plugins, server configuration, and external storage providers are not transferred.
- A 32 MB decoded JSON record limit applies, including base64 overhead for an individual database row. The limit stops large unsupported rows rather than truncating them.
- Selected database tables replace entire destination tables. This is not an orders/content merge. Moving new attachments normally also requires their posts/postmeta data; selecting media files alone does not create attachment records.
- Changed media uses source filesystem modification times, not content hashes. Selected-attachment transfers do not advance the global all/changed media watermark. Deletions are not mirrored. After rollback, the earlier watermark is restored.
- A failed stream is retried from its beginning. An incomplete stream cannot be applied. Retry status survives a page reload; this is not byte-range resume or a durable background worker.
- Keep the dashboard open while staging. Long requests can still be interrupted by browser disconnects, PHP-FPM limits, proxies, firewalls or hosting timeouts. A killed apply can leave partial file changes; the current session offers destination restore.
- Rollback overwrites later destination changes. It is not a replacement for independent backups. Discard permanently removes the transfer's rollback copies; there is no automatic backup rotation in this version.
- Source table row counts in the selection UI are estimates from database metadata; the completed transfer reports the actual exported row count.

## SiteDistrict compatibility

The installed SiteDistrict 3.2.2 exporter accepts its data requests only when the caller has a recognized SiteDistrict server IP **and** a token or administrator session. It is a source-side exporter for SiteDistrict's separate importer; its code does not reveal the host's WAF configuration.

Trickle uses its own signed authentication rather than SiteDistrict's fixed caller-IP list. That does **not** bypass a host firewall. Ask the host to permit the dedicated `?trickle=…` actions, the `X-CSM-*` headers, long streamed responses, and streamed/chunked POST uploads. A large push can still hit upload limits even though it is streamed. Test this on a disposable SiteDistrict staging site before any client/production use. No SiteDistrict firewall compatibility or production-scale performance has been established locally.

Verified HTTPS is required except for HTTP localhost development URLs. A PHP runtime that does not trust Cove's CA can use `CSM_CA_BUNDLE` in wp-config.php or the `csm_ca_bundle` filter to point to a trusted CA bundle. This does not disable certificate or hostname verification. Never ship local test configuration to the hosted site.

## Local test environment

Two disposable Cove sites were created:

- `https://cove-stream-local.localhost:8453`
- `https://cove-stream-remote.localhost:8453`

Both have three real image attachments with generated sizes, test content, a parent/child theme pair, a custom plugin, and a 613-row InnoDB table containing nested serialized values, serialized objects and binary data. Local also has Classic Editor, Contact Form 7 and Query Monitor. Remote has an older Classic Editor and Twenty Twenty-Five to exercise real plugin/theme update badges.

`tests/seed.php` is restricted to these named sites. Its separate MU plugin supplies local CA trust and suppresses outbound email. These test files and configuration are excluded from the installable ZIP.

Run the destructive integration suite only against these disposable sites:

```sh
python3 tests/integration.py
```

The suite covers signed TLS requests, both transfer directions, real PHP cURL streaming, WordPress admin AJAX control, full database import including users, URL/path/serialized replacements, binary values, staging isolation, obsolete-file removal, rollback, authentication/replay checks, changed media, truncated streams, traversal rejection, recovery access during an ordinary plugin fatal, custom vendor update filters, and key rotation across rollback. Real SiteDistrict WAF/proxy behavior, large production datasets, and concurrent production writes remain untested.
