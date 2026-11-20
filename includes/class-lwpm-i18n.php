<?php
/**
 * Bilingual Internationalization Helper (Arabic RTL + English LTR) — Liberty WP Migrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWPM_I18n {

	/**
	 * Determine active UI language ('ar' or 'en').
	 *
	 * @return string 'ar' or 'en'
	 */
	public static function get_active_lang() {
		$saved = get_user_meta( get_current_user_id(), 'lwpm_preferred_lang', true );
		if ( in_array( $saved, array( 'ar', 'en' ), true ) ) {
			return $saved;
		}

		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		if ( is_string( $locale ) && 0 === strpos( strtolower( $locale ), 'ar' ) ) {
			return 'ar';
		}

		return 'en';
	}

	/**
	 * Return full UI dictionary for the requested language with 100% key parity.
	 *
	 * @param string $lang 'ar' or 'en'.
	 * @return array<string,string>
	 */
	public static function get_strings( $lang = 'en' ) {
		if ( 'ar' === $lang ) {
			return array(
				'dir'                        => 'rtl',
				'lang_code'                  => 'ar',
				'switch_lang_label'          => 'English',
				'plugin_title'               => 'Liberty WP Migrator — نقل ونسخ احتياطي للمواقع',
				'menu_title'                 => 'Liberty Migrator',
				'open_migrator'              => 'فتح Liberty Migrator',
				'brand_by'                   => 'بواسطة LibertyDev',
				'subtitle'                   => 'تصدير واستيراد مواقع ووردبريس بحجم غير محدود مع نسخ احتياطي يدوي ومجدول تلقائياً (يومي / أسبوعي / شهري) وتحديث ذكي للدومين.',
				'tab_export'                 => 'تصدير الموقع (Export)',
				'tab_import'                 => 'استيراد موقع (Import)',
				'tab_schedule'               => 'النسخ الاحتياطي والجدولة',
				'tab_backups'                => 'خزنة النسخ المحفوظة',
				'badge_unlimited'            => 'بدون حد أقصى للحجم • LibertyDev Engine',

				// Export tab.
				'export_card_title'          => 'تصدير نسخة كاملة مضغوطة لنقل الموقع (.zip)',
				'export_card_desc'           => 'يتم ضغط قاعدة البيانات وملفات wp-content على دفعات صغيرة متتالية لتفادي أي Timeout على الاستضافة، لتتمكن من رفعها على أي دومين آخر.',
				'find_replace_title'         => 'بحث واستبدال مخصص أثناء التصدير (اختياري)',
				'find_replace_hint'          => 'ملاحظة: عند الاستيراد على دومين جديد يتم استبدال الدومين والمسارات تلقائياً دون الحاجة لإضافتها هنا. استخدم هذا القسم فقط لتعديلات إضافية.',
				'find_placeholder'           => 'النص المراد البحث عنه...',
				'replace_placeholder'        => 'استبداله بـ...',
				'add_pair_btn'               => '+ إضافة قاعدة استبدال',
				'advanced_options_title'     => 'خيارات الاستثناء المتقدمة',
				'opt_exclude_cache'          => 'استثناء ملفات الكاش والسجلات المؤقتة (مُوصى به)',
				'opt_exclude_transients'     => 'استثناء الـ Transients المؤقتة من قاعدة البيانات (مُوصى به)',
				'opt_exclude_revisions'      => 'استثناء مراجعات المقالات القديمة (Post Revisions)',
				'opt_exclude_spam'           => 'استثناء التعليقات المزعجة والمهملات (Spam & Trash)',
				'opt_exclude_media'          => 'استثناء مكتبة الوسائط (wp-content/uploads)',
				'opt_exclude_themes'         => 'استثناء القوالب غير المفعلة',
				'opt_exclude_plugins'        => 'استثناء الإضافات غير المفعلة',
				'opt_include_custom_tables'  => 'تضمين الجداول الإضافية خارج بادئة ووردبريس',
				'start_export_btn'           => 'بدء تصدير الموقع إلى ملف ZIP',
				'export_ready_title'         => 'اكتمل تجهيز وحفظ النسخة المضغوطة بنجاح!',
				'download_zip_btn'           => 'تحميل النسخة المضغوطة (.zip)',

				// Import tab.
				'import_card_title'          => 'استيراد نسخة موقع على هذا الدومين',
				'import_card_desc'           => 'ارفع ملف الـ (.zip) المُصدَّر بواسطة Liberty WP Migrator. يتم تقسيم الرفع تلقائياً لتجاوز قيود السيرفر ثم تحديث الدومين والمسارات بأمان.',
				'dropzone_title'             => 'اسحب وأفلت ملف النسخة المضغوطة (.zip) هنا',
				'dropzone_sub'               => 'أو اضغط لاختيار الملف من جهازك — يدعم الأحجام الضخمة عبر الرفع المجزأ التلقائي',
				'select_file_btn'            => 'اختيار ملف .zip',
				'server_limit_note'          => 'حد الرفع العادي بالسيرفر: %s — يتم تجاوزه تلقائياً عبر التقسيم الذكي.',
				'warning_overwrite_title'    => 'تنبيه هام قبل الاستيراد',
				'warning_overwrite_desc'     => 'عملية الاستيراد ستقوم باستبدال قاعدة البيانات، الإضافات، القوالب، والوسائط الحالية بمحتويات النسخة المستوردة. تأكد من أخذ نسخة احتياطية أولاً إذا لزم الأمر.',

				// Pre-import inspection.
				'inspect_title'              => 'معاينة النسخة قبل الاستبدال النهائي',
				'inspect_source_url'         => 'دومين الموقع المصدر',
				'inspect_dest_url'           => 'الدومين الحالي (الجديد)',
				'inspect_wp_php'             => 'إصدار WP / PHP في النسخة',
				'inspect_files_count'        => 'عدد الملفات المؤرشفة',
				'inspect_tables_count'       => 'عدد جداول قاعدة البيانات',
				'inspect_uncompressed_size'  => 'الحجم الإجمالي للبيانات',
				'opt_keep_current_admin'     => 'الاحتفاظ بحساب المدير الحالي (اسم المستخدم وكلمة المرور الحالية) للبقاء مسجلاً للدخول بعد النقل',
				'opt_skip_guid'              => 'عدم تغيير عمود GUID في جدول المقالات',
				'confirm_import_btn'         => 'تأكيد وبدء استيراد الموقع الآن',
				'cancel_btn'                 => 'إلغاء العملية',

				// Backup & Schedule tab.
				'manual_backup_title'        => 'نسخ احتياطي يدوي فوري (Manual Backup)',
				'manual_backup_desc'         => 'أنشئ نسخة احتياطية كاملة للموقع بضغطة واحدة واحفظها مباشرة داخل خزنة النسخ المحمية على السيرفر للرجوع إليها في أي وقت.',
				'manual_note_label'          => 'ملاحظة أو عنوان للنسخة (اختياري)',
				'manual_note_placeholder'    => 'مثال: قبل تحديث القالب أو الإضافات...',
				'create_manual_backup_btn'   => 'إنشاء نسخة احتياطية يدوية الآن',

				'schedule_card_title'        => 'جدولة النسخ الاحتياطي التلقائي (Automated Schedule)',
				'schedule_card_desc'         => 'تعمل النسخ المجدولة في الخلفية على دفعات متتالية بدون التسبب في بطء الموقع أو مشاكل Timeout، مع حذف النسخ القديمة تلقائياً حسب سياسة الاحتفاظ.',
				'sched_enable_label'         => 'تفعيل النسخ الاحتياطي التلقائي المجدول',
				'sched_frequency_label'      => 'دورية التكرار',
				'freq_every_6h'              => 'كل 6 ساعات',
				'freq_twicedaily'            => 'مرتين يومياً (كل 12 ساعة)',
				'freq_daily'                 => 'يومياً (Daily)',
				'freq_weekly'                => 'أسبوعياً (Weekly)',
				'freq_biweekly'              => 'كل أسبوعين (Bi-Weekly)',
				'freq_monthly'               => 'شهرياً (Monthly)',
				'sched_day_label'            => 'اليوم المفضل (للجدولة الأسبوعية)',
				'day_0'                      => 'الأحد',
				'day_1'                      => 'الإثنين',
				'day_2'                      => 'الثلاثاء',
				'day_3'                      => 'الأربعاء',
				'day_4'                      => 'الخميس',
				'day_5'                      => 'الجمعة',
				'day_6'                      => 'السبت',
				'sched_time_label'           => 'وقت التشغيل المفضل (بتوقيت الموقع)',
				'sched_retention_label'      => 'الاحتفاظ بآخر عدد من النسخ التلقائية',
				'ret_unlimited'              => 'الاحتفاظ بالكل (بدون حذف تلقائي)',
				'ret_keep_n'                 => 'الاحتفاظ بآخر %d نسخ تلقائية',
				'sched_exclusions_title'     => 'إعدادات محتوى النسخة المجدولة',
				'sched_notify_label'         => 'إرسال إشعار بالبريد الإلكتروني عند اكتمال النسخة المجدولة',
				'sched_email_placeholder'    => 'البريد الإلكتروني للإشعارات...',
				'save_schedule_btn'          => 'حفظ إعدادات الجدولة',
				'run_scheduled_now_btn'      => 'تشغيل نسخة مجدولة تجريبية الآن',
				'schedule_saved_msg'         => 'تم حفظ وتحديث جدول النسخ الاحتياطي بنجاح!',
				'status_box_state'           => 'حالة الجدولة',
				'status_active'              => 'مفعّلة نشطة',
				'status_disabled'            => 'غير مفعّلة (يدوي فقط)',
				'status_next_run'            => 'الموعد التلقائي القادم',
				'status_last_run'            => 'آخر نسخة مجدولة',
				'status_none'                => 'لا يوجد بعد',

				// Backups Vault tab.
				'backups_card_title'         => 'خزنة النسخ الاحتياطية المحفوظة على السيرفر',
				'backups_card_desc'          => 'جميع النسخ اليدوية والمجدولة مخزنة ومحمية داخل wp-content/lwpm-backups ضد الوصول المباشر.',
				'col_filename'               => 'اسم الملف والملاحظة',
				'col_type'                   => 'النوع',
				'col_created'                => 'تاريخ الإنشاء',
				'col_size'                   => 'الحجم',
				'col_source'                 => 'المصدر',
				'col_actions'                => 'إجراءات',
				'type_manual'                => 'نسخة يدوية',
				'type_scheduled'             => 'مجدولة تلقائياً',
				'type_export'                => 'نسخة تصدير',
				'btn_download'               => 'تحميل',
				'btn_restore'                => 'استعادة للموقع',
				'btn_delete'                 => 'حذف',
				'empty_backups'              => 'لا توجد نسخ احتياطية محفوظة حالياً. يمكنك إنشاء نسخة فورية أو تفعيل الجدولة.',
				'confirm_delete'             => 'هل أنت متأكد من حذف هذه النسخة الاحتياطية نهائياً؟',

				// Progress & states.
				'progress_preparing'         => 'جاري تهيئة العملية...',
				'progress_scanning_files'    => 'جاري فحص وحصر ملفات الموقع...',
				'progress_exporting_db'      => 'جاري تصدير جداول قاعدة البيانات على دفعات...',
				'progress_archiving_files'   => 'جاري ضغط الملفات في أرشيف ZIP المتدفق...',
				'progress_finalizing_zip'    => 'جاري إغلاق واعتماد فهرس ملف الـ ZIP...',
				'progress_uploading_chunks'  => 'جاري رفع الملف على أجزاء سريعة...',
				'progress_inspecting_zip'    => 'جاري فحص الأرشيف وقراءة بيانات الموقع المصدر...',
				'progress_extracting_files'  => 'جاري فك ضغط ملفات الموقع بأمان...',
				'progress_importing_db'      => 'جاري استيراد قاعدة البيانات في جداول انتقالية آمنة...',
				'progress_replacing_urls'    => 'جاري تحديث الدومين والمسارات والـ Serialized Data...',
				'progress_swapping_tables'   => 'جاري التبديل الذري للجداول وتنظيف الكاش والروابط الدائمة...',
				'import_complete_title'      => 'تم نقل واستيراد الموقع بنجاح 100%!',
				'import_complete_desc'       => 'تم تحديث الدومين والمسارات، ضبط بادئة الجداول، وتحديث الروابط الدائمة بنجاح.',
				'visit_site_btn'             => 'زيارة الموقع الآن',
				'reload_admin_btn'           => 'تحديث لوحة التحكم',
				'retrying_network'           => 'اضطراب مؤقت في الشبكة/السيرفر، جاري إعادة المحاولة تلقائياً...',
				'error_prefix'               => 'حدث خطأ: ',
				'invalid_zip_ext'            => 'يرجى اختيار ملف بصيغة .zip فقط.',
			);
		}

		return array(
			'dir'                        => 'ltr',
			'lang_code'                  => 'en',
			'switch_lang_label'          => 'العربية',
			'plugin_title'               => 'Liberty WP Migrator — Migration & Automated Backup',
			'menu_title'                 => 'Liberty Migrator',
			'open_migrator'              => 'Open Liberty Migrator',
			'brand_by'                   => 'by LibertyDev',
			'subtitle'                   => 'Unlimited-size WordPress migration, one-click manual backups, and automated scheduled backups (daily / weekly / monthly) with serialization-safe domain replacement.',
			'tab_export'                 => 'Export Site',
			'tab_import'                 => 'Import Site',
			'tab_schedule'               => 'Backup & Schedule',
			'tab_backups'                => 'Backups Vault',
			'badge_unlimited'            => 'Unlimited Size • LibertyDev Engine',

			// Export tab.
			'export_card_title'          => 'Create Full Compressed Site Archive (.zip)',
			'export_card_desc'           => 'Packages your database and wp-content in small time-boxed batches to prevent PHP timeouts, ready to import onto any other domain.',
			'find_replace_title'         => 'Custom Find & Replace During Export (Optional)',
			'find_replace_hint'          => 'Note: Domain URLs and server file paths are automatically replaced during import on a new domain. Only add rules here for extra custom text changes.',
			'find_placeholder'           => 'Search for text...',
			'replace_placeholder'        => 'Replace with...',
			'add_pair_btn'               => '+ Add Replace Rule',
			'advanced_options_title'     => 'Advanced Exclusion Options',
			'opt_exclude_cache'          => 'Exclude cache folders & debug logs (Recommended)',
			'opt_exclude_transients'     => 'Exclude temporary DB transients (Recommended)',
			'opt_exclude_revisions'      => 'Exclude old post revisions',
			'opt_exclude_spam'           => 'Exclude spam & trashed comments',
			'opt_exclude_media'          => 'Exclude media library (wp-content/uploads)',
			'opt_exclude_themes'         => 'Exclude inactive themes',
			'opt_exclude_plugins'        => 'Exclude inactive plugins',
			'opt_include_custom_tables'  => 'Include non-WP-prefixed custom database tables',
			'start_export_btn'           => 'Export Site to Compressed .ZIP',
			'export_ready_title'         => 'Your compressed site archive is ready!',
			'download_zip_btn'           => 'Download Archive (.zip)',

			// Import tab.
			'import_card_title'          => 'Import Site Archive onto This Domain',
			'import_card_desc'           => 'Upload a .zip archive created by Liberty WP Migrator. Large files are sliced into small chunks automatically to bypass server upload limits.',
			'dropzone_title'             => 'Drag & drop your site archive (.zip) here',
			'dropzone_sub'               => 'or click to browse — supports multi-GB archives via automatic chunked upload',
			'select_file_btn'            => 'Choose .zip Archive',
			'server_limit_note'          => 'Standard server upload limit: %s — bypassed automatically via chunked upload.',
			'warning_overwrite_title'    => 'Important Notice Before Importing',
			'warning_overwrite_desc'     => 'Importing an archive will replace this site’s database, themes, plugins, and media files with the contents of the archive. Back up first if needed.',

			// Pre-import inspection.
			'inspect_title'              => 'Review Archive Before Overwriting Site',
			'inspect_source_url'         => 'Source Site URL',
			'inspect_dest_url'           => 'Destination URL (This Site)',
			'inspect_wp_php'             => 'Archive WP / PHP Version',
			'inspect_files_count'        => 'Archived Files',
			'inspect_tables_count'       => 'Database Tables',
			'inspect_uncompressed_size'  => 'Total Data Size',
			'opt_keep_current_admin'     => 'Preserve my current Administrator account & password so I stay logged in after migration',
			'opt_skip_guid'              => 'Do not replace URLs inside posts GUID column',
			'confirm_import_btn'         => 'Confirm & Start Site Import Now',
			'cancel_btn'                 => 'Cancel',

			// Backup & Schedule tab.
			'manual_backup_title'        => 'One-Click Manual Backup',
			'manual_backup_desc'         => 'Create an instant full compressed backup of this site and store it securely inside your server Backups Vault for 1-click restore anytime.',
			'manual_note_label'          => 'Backup Label / Note (Optional)',
			'manual_note_placeholder'    => 'e.g., Before theme or WooCommerce update...',
			'create_manual_backup_btn'   => 'Create Manual Backup Now',

			'schedule_card_title'        => 'Automated Scheduled Backups',
			'schedule_card_desc'         => 'Runs chunked background backups automatically without slowing down visitors or hitting timeouts, and rotates old backups automatically.',
			'sched_enable_label'         => 'Enable Automated Scheduled Backups',
			'sched_frequency_label'      => 'Backup Frequency',
			'freq_every_6h'              => 'Every 6 Hours',
			'freq_twicedaily'            => 'Twice Daily (Every 12 Hours)',
			'freq_daily'                 => 'Daily',
			'freq_weekly'                => 'Weekly',
			'freq_biweekly'              => 'Bi-Weekly (Every 14 Days)',
			'freq_monthly'               => 'Monthly (Every 30 Days)',
			'sched_day_label'            => 'Preferred Day (for Weekly Schedule)',
			'day_0'                      => 'Sunday',
			'day_1'                      => 'Monday',
			'day_2'                      => 'Tuesday',
			'day_3'                      => 'Wednesday',
			'day_4'                      => 'Thursday',
			'day_5'                      => 'Friday',
			'day_6'                      => 'Saturday',
			'sched_time_label'           => 'Preferred Run Time (Site Timezone)',
			'sched_retention_label'      => 'Scheduled Backup Retention Limit',
			'ret_unlimited'              => 'Keep All Backups (Unlimited)',
			'ret_keep_n'                 => 'Keep Last %d Scheduled Backups',
			'sched_exclusions_title'     => 'Scheduled Backup Content Rules',
			'sched_notify_label'         => 'Send email notification when a scheduled backup completes',
			'sched_email_placeholder'    => 'Notification recipient email...',
			'save_schedule_btn'          => 'Save Schedule Settings',
			'run_scheduled_now_btn'      => 'Run Scheduled Backup Now',
			'schedule_saved_msg'         => 'Backup schedule settings saved and synchronized!',
			'status_box_state'           => 'Schedule Status',
			'status_active'              => 'Active & Scheduled',
			'status_disabled'            => 'Disabled (Manual Only)',
			'status_next_run'            => 'Next Scheduled Run',
			'status_last_run'            => 'Last Scheduled Backup',
			'status_none'                => 'None yet',

			// Backups Vault tab.
			'backups_card_title'         => 'Backups Vault Stored on This Server',
			'backups_card_desc'          => 'All manual, scheduled, and export archives stored securely inside wp-content/lwpm-backups (direct public HTTP access is blocked).',
			'col_filename'               => 'Archive & Label',
			'col_type'                   => 'Type',
			'col_created'                => 'Created At',
			'col_size'                   => 'Size',
			'col_source'                 => 'Source URL',
			'col_actions'                => 'Actions',
			'type_manual'                => 'Manual',
			'type_scheduled'             => 'Scheduled',
			'type_export'                => 'Export',
			'btn_download'               => 'Download',
			'btn_restore'                => 'Restore Here',
			'btn_delete'                 => 'Delete',
			'empty_backups'              => 'No saved backups yet. Create a manual backup or enable automated scheduling.',
			'confirm_delete'             => 'Are you sure you want to permanently delete this backup archive?',

			// Progress & states.
			'progress_preparing'         => 'Initializing migration/backup job...',
			'progress_scanning_files'    => 'Scanning wp-content files...',
			'progress_exporting_db'      => 'Exporting database tables in batches...',
			'progress_archiving_files'   => 'Compressing files into streaming ZIP archive...',
			'progress_finalizing_zip'    => 'Writing ZIP Central Directory & finalizing...',
			'progress_uploading_chunks'  => 'Uploading archive in chunks...',
			'progress_inspecting_zip'    => 'Inspecting archive & reading manifest...',
			'progress_extracting_files'  => 'Extracting site files safely...',
			'progress_importing_db'      => 'Importing database into shadow tables...',
			'progress_replacing_urls'    => 'Running serialization-safe domain & path replacement...',
			'progress_swapping_tables'   => 'Atomically swapping tables & flushing caches/permalinks...',
			'import_complete_title'      => 'Site Migration Completed 100% Successfully!',
			'import_complete_desc'       => 'Domain URLs, file paths, table prefixes, and permalinks have all been updated.',
			'visit_site_btn'             => 'Visit Live Site',
			'reload_admin_btn'           => 'Reload WP Admin',
			'retrying_network'           => 'Temporary network/server hiccup, retrying automatically...',
			'error_prefix'               => 'Error: ',
			'invalid_zip_ext'            => 'Please select a valid .zip archive file.',
		);
	}
}
