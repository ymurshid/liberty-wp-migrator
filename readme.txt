=== Liberty WP Migrator ===
Contributors: ymurshid
Tags: migration, backup, clone, restore, transfer
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Unlimited-size WordPress site migration, one-click manual backups, and automated scheduled ZIP backups with serialization-safe domain replacement.

== Description ==

**Liberty WP Migrator** by **LibertyDev** is a complete, zero-dependency WordPress site migration, cloning, manual backup, and automated scheduled backup solution engineered to run reliably on shared, VPS, and managed cloud hosting without timeouts or upload size barriers.

Move any WordPress site from staging to production—or between completely different domains, subdirectories, database table prefixes, and MySQL/MariaDB versions—using standard `.zip` archives.

= Key Features =

* **Unlimited-Size Chunked Export & Import:** Every stage (filesystem scan, database dump, archive compression, upload slicing, extraction, and URL replacement) executes in small `2.5s` time-boxed AJAX batches to avoid `max_execution_time` timeouts.
* **Adaptive Chunked File Upload:** Automatically slices large `.zip` archives in the browser below your server's `upload_max_filesize` and `post_max_size`, with automatic chunk downshifting if a reverse proxy (e.g., Nginx) responds with `413 Payload Too Large`.
* **Pure-PHP Streaming ZIP / ZIP64 Engine:** Requires no `ZipArchive` (`ext-zip`) server extension, avoids `ZipArchive::close()` memory/timeout spikes on multi-GB sites, supports UTF-8 / Arabic filenames, and produces standard `.zip` files you can open on Windows, macOS, or Linux.
* **Byte-Exact Serialization-Safe & JSON-Safe Domain Replacement:** Updates `https://`, `http://`, protocol-relative `//`, JSON-escaped `https:\/\/`, URL-encoded `https%3A%2F%2F`, and server filesystem paths across nested PHP serialized arrays/objects (including private/protected properties and PHP 8.1+ Enums) and JSON builders (**Elementor**, **Gutenberg**) without touching email addresses (`user@domain.com`).
* **Atomic Shadow-Table Database Import:** Imports SQL data into isolated temporary shadow tables first, normalizes MySQL 8.0 (`utf8mb4_0900_*`) collations for MariaDB compatibility, adapts table prefixes automatically, and swaps tables atomically via `RENAME TABLE` so a live site is never left in a half-imported state.
* **One-Click Manual Backups & Automated Scheduled Backups:** Create instant labeled backups or configure recurring background backups (**Every 6 Hours, Every 12 Hours, Daily, Weekly, Bi-Weekly, or Monthly**) with smart retention rotation and optional email notifications.
* **Bilingual Admin Interface (English & Arabic RTL):** Automatically adapts to your WordPress user locale with an instant one-click English / Arabic (RTL) toggle.

= Privacy & Data Handling =

Liberty WP Migrator operates **100% locally on your own WordPress server**. It does not connect to any external SaaS API, does not transmit telemetry or user data to third-party servers, and stores all backup archives inside a protected local directory (`wp-content/lwpm-backups`) shielded with `.htaccess`, `web.config`, and authenticated download nonces.

== Installation ==

1. In your WordPress Admin dashboard, navigate to **Plugins > Add New** and search for **Liberty WP Migrator** (or upload `liberty-wp-migrator.zip` via **Upload Plugin**).
2. Click **Install Now**, then click **Activate**.
3. Open **Liberty Migrator** from the WordPress admin sidebar.
4. Use **Export Site** on your source site to create and download a compressed `.zip` archive, then upload that `.zip` under **Import Site** on your destination domain—or configure recurring backups under **Backup & Schedule**.

== Frequently Asked Questions ==

= Is there a maximum site size or upload size limit? =

No artificial size limits are imposed by the plugin. Exports stream directly to disk in `2.5s` ticks, and imports slice your `.zip` archive in the browser into small chunks calibrated below your host's `upload_max_filesize` and `post_max_size`.

= Will Elementor, Gutenberg, WooCommerce, or theme widgets break when changing domains? =

No. Liberty WP Migrator uses a byte-exact PHP Serialization AST parser and type-preserving JSON walker (`stdClass` `{}` vs `array` `[]`) to update domain URLs and server paths accurately, and automatically clears stale Elementor CSS caches upon completion.

= What happens if my internet connection drops halfway through an import? =

Database imports are staged inside isolated shadow tables (`lwpmt_*`) and only swapped into your live tables at the final step using an atomic `RENAME TABLE` operation. In addition, the chunked AJAX runner automatically retries transient network/gateway hiccups with exponential backoff.

= Can I stay logged in with my current administrator password after importing another site? =

Yes. On the Pre-Import Review screen, keep **"Preserve my current Administrator account & password"** checked, and Liberty WP Migrator will preserve your admin credentials and refresh your authentication cookie seamlessly.

= Where are backups stored, and are they protected from public access? =

Backups are stored inside `wp-content/lwpm-backups/` with automatic `.htaccess` (Apache/LiteSpeed), `web.config` (IIS), and `index.php` protections, plus cryptographically randomized filenames. Downloads are served exclusively through an authenticated admin endpoint supporting HTTP `Range` requests.

== Screenshots ==

1. Export Site dashboard with exclusion filters and optional custom find & replace rules.
2. Import Site drag-and-drop chunked `.zip` uploader and pre-import manifest inspection summary.
3. One-click Manual Backup & Automated Scheduled Backups (6h, 12h, Daily, Weekly, Bi-Weekly, Monthly) with retention controls.
4. Protected Backups Vault with type badges, one-click restore, and resumable download.

== Changelog ==

= 1.2.0 =
* Added full WordPress.org Plugin Directory readiness (`readme.txt`, `.pot` translation catalog, and store artwork).
* Upgraded database identifier queries to WordPress 6.2+ `%i` prepared placeholders and `wp_delete_file()` wrappers.
* Streamlined binary archive download output via `php://output` stream handles.

= 1.1.0 =
* Rebranded to Liberty WP Migrator by LibertyDev with official vector mark and executive palette.
* Added One-Click Manual Backups with custom notes/labels.
* Added Automated Scheduled Backups (Every 6 Hours, Twice Daily, Daily, Weekly, Bi-Weekly, Monthly) with background WP-Cron slice chaining, retention rotation, and email notifications.

= 1.0.0 =
* Initial release featuring chunked AJAX export/import, pure-PHP streaming ZIP/ZIP64 writer & reader, serialization-safe AST & JSON domain replacer, and bilingual English / Arabic (RTL) interface.

== Upgrade Notice ==

= 1.2.0 =
WordPress 7.1 compatibility, WordPress.org Coding Standards hardening, and translation catalog support.
