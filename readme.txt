=== LibertyDev Site Migrator ===
Contributors: yassermurshid
Tags: migration, backup, clone, restore, transfer
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.4.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Unlimited-size site migration, one-click manual backups, and automated scheduled ZIP backups with serialization-safe domain replacement.

== Description ==

**LibertyDev Site Migrator** is a complete, zero-dependency WordPress site migration, cloning, manual backup, and automated scheduled backup solution engineered to run reliably on shared, VPS, and managed cloud hosting without timeouts or upload size barriers.

Move any WordPress site from staging to production—or between completely different domains, subdirectories, relocated content/uploads setups, database table prefixes, and MySQL/MariaDB versions—using standard `.zip` archives.

= Key Features =

* **Unlimited-Size Chunked Export & Import:** Every stage (filesystem scan, database dump, archive compression, upload slicing, extraction, and URL replacement) executes in small `2.5s` time-boxed AJAX batches to avoid `max_execution_time` timeouts.
* **Adaptive Chunked File Upload:** Automatically slices large `.zip` archives in the browser below your server's `upload_max_filesize` and `post_max_size`, with automatic chunk downshifting if a reverse proxy (e.g., Nginx) responds with `413 Payload Too Large`.
* **Canonical WordPress Directory Support:** Dynamically resolves `wp_upload_dir()`, `WP_PLUGIN_DIR`, `WPMU_PLUGIN_DIR`, `get_theme_root()`, and `WP_LANG_DIR`, supporting standard and custom relocated WordPress directory layouts out of the box.
* **Pure-PHP Streaming ZIP / ZIP64 Engine:** Requires no `ZipArchive` (`ext-zip`) server extension, avoids `ZipArchive::close()` memory/timeout spikes on multi-GB sites, supports UTF-8 / Arabic filenames, and produces standard `.zip` files you can open on Windows, macOS, or Linux.
* **Byte-Exact Serialization-Safe & JSON-Safe Domain Replacement:** Updates `https://`, `http://`, protocol-relative `//`, JSON-escaped `https:\/\/`, URL-encoded `https%3A%2F%2F`, and server filesystem paths across nested PHP serialized arrays/objects (including private/protected properties and PHP 8.1+ Enums) and JSON builders (**Elementor**, **Gutenberg**) without touching email addresses (`user@domain.com`).
* **Atomic Shadow-Table Database Import:** Imports SQL data into isolated temporary shadow tables first, normalizes MySQL 8.0 (`utf8mb4_0900_*`) collations for MariaDB compatibility, adapts table prefixes automatically, and swaps tables atomically via `RENAME TABLE` so a live site is never left in a half-imported state.
* **One-Click Manual Backups & Automated Scheduled Backups:** Create instant labeled backups or configure recurring background backups (**Every 6 Hours, Every 12 Hours, Daily, Weekly, Bi-Weekly, or Monthly**) with smart retention rotation and optional email notifications.
* **Bilingual Admin Interface (English & Arabic RTL):** Automatically adapts to your WordPress user locale with an instant one-click English / Arabic (RTL) toggle.

= Privacy & Data Handling =

LibertyDev Site Migrator operates **100% locally on your own WordPress server**. It does not connect to any external SaaS API, does not transmit telemetry or user data to third-party servers, stores transient migration job state in non-autoloaded WordPress database options, and stores all backup archives and temporary work files as non-executable protected data files inside a cryptographically unguessable subdirectory within your WordPress uploads folder (`uploads/libertydev-site-migrator/vault-{key}/`) shielded across Apache, Nginx, and IIS. On WordPress Multisite, access is restricted exclusively to Network Super Admins.

== Installation ==

1. In your WordPress Admin dashboard, navigate to **Plugins > Add New** and search for **LibertyDev Site Migrator** (or upload `libertydev-site-migrator.zip` via **Upload Plugin**).
2. Click **Install Now**, then click **Activate**.
3. Open **LibertyDev Migrator** from the WordPress admin sidebar.
4. Use **Export Site** on your source site to create and download a compressed `.zip` archive, then upload that `.zip` under **Import Site** on your destination domain—or configure recurring backups under **Backup & Schedule**.

== Frequently Asked Questions ==

= Is there a maximum site size or upload size limit? =

No artificial size limits are imposed by the plugin. Exports stream directly to disk in `2.5s` ticks, and imports slice your `.zip` archive in the browser into small chunks calibrated below your host's `upload_max_filesize` and `post_max_size`.

= Will Elementor, Gutenberg, WooCommerce, or theme widgets break when changing domains? =

No. LibertyDev Site Migrator uses a byte-exact PHP Serialization AST parser and type-preserving JSON walker (`stdClass` `{}` vs `array` `[]`) to update domain URLs and server paths accurately, and automatically clears stale Elementor CSS caches upon completion.

= What happens if my internet connection drops halfway through an import? =

Database imports are staged inside isolated shadow tables (`ldsmt_*`) and only swapped into your live tables at the final step using an atomic `RENAME TABLE` operation. In addition, the chunked AJAX runner automatically retries transient network/gateway hiccups with exponential backoff.

= Can I stay logged in with my current administrator password after importing another site? =

Yes. On the Pre-Import Review screen, keep **"Preserve my current Administrator account & password"** checked, and LibertyDev Site Migrator will preserve your admin credentials and refresh your authentication cookie seamlessly.

= Where are backups stored, and how are they protected? =

Backups and temporary work files are stored as strictly non-executable data files (`.zip`, `.meta.json`, `.dat`, `.sql`) inside your WordPress uploads directory (`wp_upload_dir()`) under a cryptographically randomized 128-bit vault folder (`uploads/libertydev-site-migrator/vault-{secret}/`) with `.htaccess` (Apache/LiteSpeed), `web.config` (IIS), and `index.html` directory barriers plus 96-bit randomized archive filenames. Transient job state is saved in non-autoloaded WordPress database options, and downloads stream directly through an authenticated admin endpoint with HTTP Range resume support.

= How does LibertyDev Site Migrator handle WordPress Multisite permissions? =

On WordPress Multisite networks, all migration, backup, extraction, and restore capabilities are restricted strictly to Network Super Admins (`is_super_admin()` and `manage_network_options`) so subsite administrators cannot modify shared network plugins, themes, or `mu-plugins`.

== Screenshots ==

1. Export Site dashboard with exclusion filters and optional custom find & replace rules.
2. Import Site drag-and-drop chunked `.zip` uploader and pre-import manifest inspection summary.
3. One-click Manual Backup & Automated Scheduled Backups (6h, 12h, Daily, Weekly, Bi-Weekly, Monthly) with retention controls.
4. Protected Backups Vault with type badges, one-click restore, and resumable download.

== Changelog ==

= 1.4.0 =
* Moved transient migration job state into non-autoloaded WordPress database options and replaced executable PHP work/archive files with strictly non-executable protected data files (`.zip`, `.meta.json`, `.dat`, `.sql`) inside the 128-bit randomized uploads vault shielded with `.htaccess`, `web.config`, and `index.html`.
* Restricted all migration, backup, archive extraction, and `mu-plugins` restore operations on WordPress Multisite strictly to Network Super Admins (`is_super_admin()` and `manage_network_options`).
* Converted all dynamic SQL `SELECT`, `UPDATE`, `RENAME TABLE`, and `DROP TABLE` queries to `$wpdb->prepare()` with `%i`, `%s`, and `%d` placeholders (safe even under `NO_BACKSLASH_ESCAPES`).

= 1.3.0 =
* Renamed plugin to LibertyDev Site Migrator (`libertydev-site-migrator`) with `LDSM_` / `ldsm_` prefixing per WordPress.org Plugin Directory guidelines.
* Hardened backup vault with a 128-bit secret vault directory inside `wp_upload_dir()`, `.htaccess`, `web.config`, and `index.php` directory barriers.
* Added segment-by-segment anti-symlink (`is_link()`) and canonical `realpath()` boundary verification across all archive extraction and file restore paths.
* Replaced hardcoded `WP_CONTENT_DIR` assumptions with dynamic WordPress directory resolution (`wp_upload_dir()`, `WP_PLUGIN_DIR`, `WPMU_PLUGIN_DIR`, `get_theme_root()`, `WP_LANG_DIR`).
* Enforced immediate recursive input sanitization across all `$_POST` and `$_FILES` AJAX payloads and removed promotional signatures from scheduled notification emails.

= 1.2.0 =
* Added full WordPress.org Plugin Directory readiness (`readme.txt`, `.pot` translation catalog, and store artwork).
* Upgraded database identifier queries to WordPress 6.2+ `%i` prepared placeholders and `wp_delete_file()` wrappers.
* Streamlined binary archive download output via `php://output` stream handles.

= 1.1.0 =
* Added One-Click Manual Backups with custom notes/labels and Automated Scheduled Backups (Every 6 Hours, Twice Daily, Daily, Weekly, Bi-Weekly, Monthly) with background WP-Cron slice chaining and retention rotation.

= 1.0.0 =
* Initial release featuring chunked AJAX export/import, pure-PHP streaming ZIP/ZIP64 writer & reader, serialization-safe AST & JSON domain replacer, and bilingual English / Arabic (RTL) interface.

== Upgrade Notice ==

= 1.4.0 =
Security & WordPress.org compliance release: database-stored job state with non-executable protected vault data files, Multisite Super Admin enforcement, and 100% `$wpdb->prepare()` SQL parameterization.
