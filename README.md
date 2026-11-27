<p align="center">
  <img src="assets/img/liberty-dev-mark-dark.svg" width="76" alt="LibertyDev" />
</p>

<h1 align="center">LibertyDev Site Migrator</h1>

<p align="center">
  <strong>Enterprise-Grade WordPress Site Migration, Manual Backups & Automated Scheduled Backups by LibertyDev</strong><br/>
  Unlimited-size streaming <code>.zip</code> archives • Serialization-safe domain & path replacement • Atomic shadow-table import • Nginx-hardened vault • Bilingual UI (Arabic RTL & English)
</p>

---

## Key Capabilities

### 1. Unlimited-Size Export & Cross-Domain Migration
- **Time-Boxed Chunked State Machine:** File scanning, database dumping, archive streaming, upload slicing, extraction, and URL replacement execute in small `2.5s` AJAX ticks to prevent `max_execution_time` timeouts on shared or managed hosts.
- **Adaptive Chunked Uploader:** Slices large `.zip` archives via HTML5 `File.slice()` below `upload_max_filesize` / `post_max_size`, automatically downshifting chunk sizes if a reverse proxy (e.g., Nginx) responds with `413 Payload Too Large`.
- **Canonical Relocated Directory Support:** Resolves `wp_upload_dir()`, `WP_PLUGIN_DIR`, `WPMU_PLUGIN_DIR`, `get_theme_root()`, and `WP_LANG_DIR` dynamically—no hardcoded `wp-content` path assumptions.
- **Pure-PHP Streaming PKZIP / ZIP64 Engine:** Zero dependency on PHP's `ext-zip` (`ZipArchive`), eliminating `ZipArchive::close()` hangs on multi-GB sites while producing 100% standard `.zip` archives with full UTF-8 filename support.

### 2. Protected Uploads Vault, Multisite Security & Automated Backups
- **Hardened Vault & Non-Executable Data Files:** Stores transient job state in non-autoloaded WordPress database options and stores archives/work files strictly as non-executable protected data files (`.zip`, `.meta.json`, `.dat`, `.sql`) inside a cryptographically randomized 128-bit vault under `wp_upload_dir()` (`uploads/libertydev-site-migrator/vault-{secret}/`) shielded with `.htaccess`, `web.config`, and `index.html`, plus segment-by-segment anti-symlink (`is_link()` + `realpath()`) extraction checks.
- **Multisite Super Admin Enforcement:** Automatically requires `is_super_admin()` and `manage_network_options` on WordPress Multisite networks so subsite administrators cannot modify shared plugins, themes, or `mu-plugins`.
- **100% `$wpdb->prepare()` Parameterized SQL:** All dynamic export, search-and-replace, table-rename, and cleanup queries use WordPress 6.2+ `%i`, `%s`, and `%d` prepared placeholders (safe even under `NO_BACKSLASH_ESCAPES`).
- **Flexible Automated Scheduling:** Schedule recurring background backups **Every 6 Hours**, **Every 12 Hours**, **Daily**, **Weekly** (with preferred day of week), **Bi-Weekly**, or **Monthly** at your preferred local site time.
- **Smart Retention Policy & Email Alerts:** Automatically prunes older scheduled backups beyond your retention limit (while keeping manual backups untouched) and sends optional completion/error email notifications.

### 3. Serialization-Safe & Page-Builder-Safe Domain Replacement
- **Byte-Exact PHP Serialization AST Parser (`LDSM_Replacer`):** Recalculates `s:XX:"..."` byte lengths without invoking `unserialize()` (eliminating object-injection risks and `__PHP_Incomplete_Class` corruption) while preserving custom classes, `private`/`protected` properties, references, and PHP 8.1+ Enums.
- **JSON Fidelity (Elementor & Gutenberg):** Preserves `{}` vs `[]` container types, updates escaped (`https:\/\/`) and URL-encoded (`https%3A%2F%2F`) links, leaves email addresses (`user@domain.com`) untouched, and purges stale Elementor CSS caches automatically.
- **Atomic Shadow-Table Import:** Imports into isolated `ldsmt_*` shadow tables first, normalizes MySQL 8.0 (`utf8mb4_0900_*`) collations for MariaDB compatibility, adapts table prefixes (`wp_`, custom prefixes), optionally preserves your current Administrator session, and swaps tables atomically via `RENAME TABLE`.

---

## نظرة عامة بالعربية

إضافة **LibertyDev Site Migrator** من **LibertyDev** لنقل مواقع ووردبريس بين الدومينات والسيرفرات وعمل نسخ احتياطية يدوية أو مجدولة تلقائياً بدون حد أقصى للحجم:

1. **نقل كامل للموقع بين أي دومينين:** ضغط الموقع بالكامل بصيغة `.zip` متدفقة ورفعه على الدومين الجديد مع استبدال ذكي وآمن للدومين والمسارات المخصصة وبادئة الجداول والـ Serialized Data وبيانات Elementor.
2. **نسخ احتياطي يدوي ومجدول تلقائياً:** إنشاء نسخة فورية بضغطة واحدة داخل خزنة مشفرة المسار متوافقة مع Nginx وApache، أو ضبط جدول تلقائي (**كل 6 ساعات / كل 12 ساعة / يومي / أسبوعي / كل أسبوعين / شهري**) مع سياسة احتفاظ ذكية وإشعارات البريد الإلكتروني.
3. **واجهة ثنائية اللغة (عربي RTL + إنجليزي LTR):** تتكيف تلقائياً مع لغة لوحة تحكم ووردبريس مع زر تبديل فوري.

---

## Installation

1. Download `libertydev-site-migrator.zip` from [GitHub Releases](../../releases).
2. In WordPress Admin, navigate to **Plugins → Add New → Upload Plugin**, select `libertydev-site-migrator.zip`, and click **Install Now → Activate**.
3. Open **LibertyDev Migrator** from the WordPress admin sidebar.

---

## License

Released under the [GPL v2 or later](LICENSE) by **LibertyDev**.
