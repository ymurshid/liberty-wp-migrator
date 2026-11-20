<p align="center">
  <img src="assets/img/liberty-dev-mark-dark.svg" width="76" alt="LibertyDev" />
</p>

<h1 align="center">Liberty WP Migrator</h1>

<p align="center">
  <strong>Enterprise-Grade WordPress Site Migration, Manual Backups & Automated Scheduled Backups by LibertyDev</strong><br/>
  Unlimited-size streaming <code>.zip</code> archives • Serialization-safe domain & path replacement • Atomic shadow-table import • Bilingual UI (Arabic RTL & English)
</p>

---

## Key Capabilities

### 1. Unlimited-Size Export & Cross-Domain Migration
- **Time-Boxed Chunked State Machine:** File scanning, database dumping, archive streaming, upload slicing, extraction, and URL replacement execute in small `2.5s` AJAX ticks to prevent `max_execution_time` timeouts on shared or managed hosts.
- **Adaptive Chunked Uploader:** Slices large `.zip` archives via HTML5 `File.slice()` below `upload_max_filesize` / `post_max_size`, automatically downshifting chunk sizes if a reverse proxy (e.g., Nginx) responds with `413 Payload Too Large`.
- **Pure-PHP Streaming PKZIP / ZIP64 Engine:** Zero dependency on PHP's `ext-zip` (`ZipArchive`), eliminating `ZipArchive::close()` hangs on multi-GB sites while producing 100% standard `.zip` archives with full UTF-8 filename support.

### 2. One-Click Manual Backups & Automated Scheduled Backups
- **Instant Manual Backups:** Create labeled full-site snapshots directly inside the hardened `wp-content/lwpm-backups` vault with 1-click restore anytime.
- **Flexible Automated Scheduling:** Schedule recurring background backups **Every 6 Hours**, **Every 12 Hours**, **Daily**, **Weekly** (with preferred day of week), **Bi-Weekly**, or **Monthly** at your preferred local site time.
- **Non-Blocking Background Runner:** Executes scheduled backups across chained WP-Cron background slices without slowing down front-end traffic or timing out.
- **Smart Retention Policy & Email Alerts:** Automatically prunes older scheduled backups beyond your retention limit (while keeping manual backups untouched) and sends optional completion/error email notifications.

### 3. Serialization-Safe & Page-Builder-Safe Domain Replacement
- **Byte-Exact PHP Serialization AST Parser (`LWPM_Replacer`):** Recalculates `s:XX:"..."` byte lengths without invoking `unserialize()` (eliminating object-injection risks and `__PHP_Incomplete_Class` corruption) while preserving custom classes, `private`/`protected` properties, references, and PHP 8.1+ Enums.
- **JSON Fidelity (Elementor & Gutenberg):** Preserves `{}` vs `[]` container types, updates escaped (`https:\/\/`) and URL-encoded (`https%3A%2F%2F`) links, leaves email addresses (`user@domain.com`) untouched, and purges stale Elementor CSS caches automatically.
- **Atomic Shadow-Table Import:** Imports into isolated `lwpmt_*` shadow tables first, normalizes MySQL 8.0 (`utf8mb4_0900_*`) collations for MariaDB compatibility, adapts table prefixes (`wp_`, custom prefixes), optionally preserves your current Administrator session, and swaps tables atomically via `RENAME TABLE`.

---

## نظرة عامة بالعربية

إضافة **Liberty WP Migrator** من **LibertyDev** لنقل مواقع ووردبريس بين الدومينات والسيرفرات وعمل نسخ احتياطية يدوية أو مجدولة تلقائياً بدون حد أقصى للحجم:

1. **نقل كامل للموقع بين أي دومينين:** ضغط الموقع بالكامل بصيغة `.zip` متدفقة ورفعه على الدومين الجديد مع استبدال ذكي وآمن للدومين والمسارات وبادئة الجداول والـ Serialized Data وبيانات Elementor.
2. **نسخ احتياطي يدوي ومجدول تلقائياً:** إنشاء نسخة فورية بضغطة واحدة، أو ضبط جدول تلقائي (**كل 6 ساعات / كل 12 ساعة / يومي / أسبوعي / كل أسبوعين / شهري**) مع تحديد عدد النسخ المحتفظ بها وحذف الأقدم تلقائياً وإشعارات البريد الإلكتروني.
3. **واجهة ثنائية اللغة (عربي RTL + إنجليزي LTR):** تتكيف تلقائياً مع لغة لوحة تحكم ووردبريس مع زر تبديل فوري.

---

## Installation

### Option A: Upload Release `.zip` in WordPress Admin
1. Download `liberty-wp-migrator.zip` from [GitHub Releases](../../releases) (or build locally).
2. In WordPress Admin, navigate to **Plugins → Add New → Upload Plugin**, select `liberty-wp-migrator.zip`, and click **Install Now → Activate**.
3. Open **Liberty Migrator** from the WordPress admin sidebar.

### Option B: Clone Directly into `wp-content/plugins/`
```bash
cd wp-content/plugins
git clone https://github.com/ymurshid/liberty-wp-migrator.git
```
Then activate **Liberty WP Migrator** in **Plugins → Installed Plugins**.

---

## License

Released under the [GPL v2 or later](LICENSE) by **LibertyDev**.
