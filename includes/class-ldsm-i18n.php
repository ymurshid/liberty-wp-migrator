<?php
/**
 * Bilingual Internationalization Helper (Arabic RTL + English LTR) — LibertyDev Site Migrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LDSM_I18n {

	/**
	 * Determine active UI language ('ar' or 'en').
	 *
	 * @return string 'ar' or 'en'
	 */
	public static function get_active_lang() {
		$saved = get_user_meta( get_current_user_id(), 'ldsm_preferred_lang', true );
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
				'plugin_title'               => 'LibertyDev Site Migrator — نقل ونسخ احتياطي للمواقع',
				'menu_title'                 => 'LibertyDev Migrator',
				'open_migrator'              => 'فتح أداة النقل',
				'brand_by'                   => 'بواسطة LibertyDev',
				'subtitle'                   => 'تصدير واستيراد المواقع بحجم غير محدود مع نسخ احتياطي يدوي ومجدول تلقائياً (يومي / أسبوعي / شهري) وتحديث ذكي للدومين.',
				'tab_export'                 => 'تصدير الموقع (Export)',
				'tab_import'                 => 'استيراد موقع (Import)',
				'tab_schedule'               => 'النسخ الاحتياطي والجدولة',
				'tab_backups'                => 'خزنة النسخ المحفوظة',
				'badge_unlimited'            => 'بدون حد أقصى للحجم • LibertyDev Engine',

				// Export tab.
				'export_card_title'          => 'تصدير نسخة كاملة مضغوطة لنقل الموقع (.zip)',
				'export_card_desc'           => 'يتم ضغط قاعدة البيانات والملفات (الإضافات، القوالب، الوسائط، واللغات) على دفعات صغيرة متتالية لتفادي أي Timeout.',
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
				'opt_exclude_media'          => 'استثناء مكتبة الوسائط (Uploads)',
				'opt_exclude_themes'         => 'استثناء القوالب غير المفعلة',
				'opt_exclude_plugins'        => 'استثناء الإضافات غير المفعلة',
				'opt_include_custom_tables'  => 'تضمين الجداول الإضافية خارج بادئة الجداول الأساسية',
				'start_export_btn'           => 'بدء تصدير الموقع إلى ملف ZIP',
				'export_ready_title'         => 'اكتمل تجهيز وحفظ النسخة المضغوطة بنجاح!',
				'download_zip_btn'           => 'تحميل النسخة المضغوطة (.zip)',

				// Import tab.
				'import_card_title'          => 'استيراد نسخة موقع على هذا الدومين',
				'import_card_desc'           => 'ارفع ملف الـ (.zip) المُصدَّر بواسطة LibertyDev Site Migrator. يتم تقسيم الرفع تلقائياً لتجاوز قيود السيرفر ثم تحديث الدومين والمسارات بأمان.',
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
				'inspect_wp_php'             => 'إصدار النظام / PHP في النسخة',
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
				'backups_card_desc'          => 'جميع النسخ اليدوية والمجدولة مخزنة داخل مجلد مشفر المسار ومحمي ضد الاستعراض المباشر في Apache و Nginx و IIS.',
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
			'plugin_title'               => __( 'LibertyDev Site Migrator — Migration & Automated Backup', 'libertydev-site-migrator' ),
			'menu_title'                 => __( 'LibertyDev Migrator', 'libertydev-site-migrator' ),
			'open_migrator'              => __( 'Open Migrator', 'libertydev-site-migrator' ),
			'brand_by'                   => __( 'by LibertyDev', 'libertydev-site-migrator' ),
			'subtitle'                   => __( 'Unlimited-size site migration, one-click manual backups, and automated scheduled backups (daily / weekly / monthly) with serialization-safe domain replacement.', 'libertydev-site-migrator' ),
			'tab_export'                 => __( 'Export Site', 'libertydev-site-migrator' ),
			'tab_import'                 => __( 'Import Site', 'libertydev-site-migrator' ),
			'tab_schedule'               => __( 'Backup & Schedule', 'libertydev-site-migrator' ),
			'tab_backups'                => __( 'Backups Vault', 'libertydev-site-migrator' ),
			'badge_unlimited'            => __( 'Unlimited Size • LibertyDev Engine', 'libertydev-site-migrator' ),

			// Export tab.
			'export_card_title'          => __( 'Create Full Compressed Site Archive (.zip)', 'libertydev-site-migrator' ),
			'export_card_desc'           => __( 'Packages your database, plugins, themes, uploads, and languages in small time-boxed batches to prevent PHP timeouts.', 'libertydev-site-migrator' ),
			'find_replace_title'         => __( 'Custom Find & Replace During Export (Optional)', 'libertydev-site-migrator' ),
			'find_replace_hint'          => __( 'Note: Domain URLs and server file paths are automatically replaced during import on a new domain. Only add rules here for extra custom text changes.', 'libertydev-site-migrator' ),
			'find_placeholder'           => __( 'Search for text...', 'libertydev-site-migrator' ),
			'replace_placeholder'        => __( 'Replace with...', 'libertydev-site-migrator' ),
			'add_pair_btn'               => __( '+ Add Replace Rule', 'libertydev-site-migrator' ),
			'advanced_options_title'     => __( 'Advanced Exclusion Options', 'libertydev-site-migrator' ),
			'opt_exclude_cache'          => __( 'Exclude cache folders & debug logs (Recommended)', 'libertydev-site-migrator' ),
			'opt_exclude_transients'     => __( 'Exclude temporary DB transients (Recommended)', 'libertydev-site-migrator' ),
			'opt_exclude_revisions'      => __( 'Exclude old post revisions', 'libertydev-site-migrator' ),
			'opt_exclude_spam'           => __( 'Exclude spam & trashed comments', 'libertydev-site-migrator' ),
			'opt_exclude_media'          => __( 'Exclude media library (Uploads)', 'libertydev-site-migrator' ),
			'opt_exclude_themes'         => __( 'Exclude inactive themes', 'libertydev-site-migrator' ),
			'opt_exclude_plugins'        => __( 'Exclude inactive plugins', 'libertydev-site-migrator' ),
			'opt_include_custom_tables'  => __( 'Include non-prefixed custom database tables', 'libertydev-site-migrator' ),
			'start_export_btn'           => __( 'Export Site to Compressed .ZIP', 'libertydev-site-migrator' ),
			'export_ready_title'         => __( 'Your compressed site archive is ready!', 'libertydev-site-migrator' ),
			'download_zip_btn'           => __( 'Download Archive (.zip)', 'libertydev-site-migrator' ),

			// Import tab.
			'import_card_title'          => __( 'Import Site Archive onto This Domain', 'libertydev-site-migrator' ),
			'import_card_desc'           => __( 'Upload a .zip archive created by LibertyDev Site Migrator. Large files are sliced into small chunks automatically to bypass server upload limits.', 'libertydev-site-migrator' ),
			'dropzone_title'             => __( 'Drag & drop your site archive (.zip) here', 'libertydev-site-migrator' ),
			'dropzone_sub'               => __( 'or click to browse — supports multi-GB archives via automatic chunked upload', 'libertydev-site-migrator' ),
			'select_file_btn'            => __( 'Choose .zip Archive', 'libertydev-site-migrator' ),
			/* translators: %s: formatted maximum server upload size (e.g. 2 MB). */
			'server_limit_note'          => __( 'Standard server upload limit: %s — bypassed automatically via chunked upload.', 'libertydev-site-migrator' ),
			'warning_overwrite_title'    => __( 'Important Notice Before Importing', 'libertydev-site-migrator' ),
			'warning_overwrite_desc'     => __( 'Importing an archive will replace this site’s database, themes, plugins, and media files with the contents of the archive. Back up first if needed.', 'libertydev-site-migrator' ),

			// Pre-import inspection.
			'inspect_title'              => __( 'Review Archive Before Overwriting Site', 'libertydev-site-migrator' ),
			'inspect_source_url'         => __( 'Source Site URL', 'libertydev-site-migrator' ),
			'inspect_dest_url'           => __( 'Destination URL (This Site)', 'libertydev-site-migrator' ),
			'inspect_wp_php'             => __( 'Archive Core / PHP Version', 'libertydev-site-migrator' ),
			'inspect_files_count'        => __( 'Archived Files', 'libertydev-site-migrator' ),
			'inspect_tables_count'       => __( 'Database Tables', 'libertydev-site-migrator' ),
			'inspect_uncompressed_size'  => __( 'Total Data Size', 'libertydev-site-migrator' ),
			'opt_keep_current_admin'     => __( 'Preserve my current Administrator account & password so I stay logged in after migration', 'libertydev-site-migrator' ),
			'opt_skip_guid'              => __( 'Do not replace URLs inside posts GUID column', 'libertydev-site-migrator' ),
			'confirm_import_btn'         => __( 'Confirm & Start Site Import Now', 'libertydev-site-migrator' ),
			'cancel_btn'                 => __( 'Cancel', 'libertydev-site-migrator' ),

			// Backup & Schedule tab.
			'manual_backup_title'        => __( 'One-Click Manual Backup', 'libertydev-site-migrator' ),
			'manual_backup_desc'         => __( 'Create an instant full compressed backup of this site and store it securely inside your server Backups Vault for 1-click restore anytime.', 'libertydev-site-migrator' ),
			'manual_note_label'          => __( 'Backup Label / Note (Optional)', 'libertydev-site-migrator' ),
			'manual_note_placeholder'    => __( 'e.g., Before theme or plugin update...', 'libertydev-site-migrator' ),
			'create_manual_backup_btn'   => __( 'Create Manual Backup Now', 'libertydev-site-migrator' ),

			'schedule_card_title'        => __( 'Automated Scheduled Backups', 'libertydev-site-migrator' ),
			'schedule_card_desc'         => __( 'Runs chunked background backups automatically without slowing down visitors or hitting timeouts, and rotates old backups automatically.', 'libertydev-site-migrator' ),
			'sched_enable_label'         => __( 'Enable Automated Scheduled Backups', 'libertydev-site-migrator' ),
			'sched_frequency_label'      => __( 'Backup Frequency', 'libertydev-site-migrator' ),
			'freq_every_6h'              => __( 'Every 6 Hours', 'libertydev-site-migrator' ),
			'freq_twicedaily'            => __( 'Twice Daily (Every 12 Hours)', 'libertydev-site-migrator' ),
			'freq_daily'                 => __( 'Daily', 'libertydev-site-migrator' ),
			'freq_weekly'                => __( 'Weekly', 'libertydev-site-migrator' ),
			'freq_biweekly'              => __( 'Bi-Weekly (Every 14 Days)', 'libertydev-site-migrator' ),
			'freq_monthly'               => __( 'Monthly (Every 30 Days)', 'libertydev-site-migrator' ),
			'sched_day_label'            => __( 'Preferred Day (for Weekly Schedule)', 'libertydev-site-migrator' ),
			'day_0'                      => __( 'Sunday', 'libertydev-site-migrator' ),
			'day_1'                      => __( 'Monday', 'libertydev-site-migrator' ),
			'day_2'                      => __( 'Tuesday', 'libertydev-site-migrator' ),
			'day_3'                      => __( 'Wednesday', 'libertydev-site-migrator' ),
			'day_4'                      => __( 'Thursday', 'libertydev-site-migrator' ),
			'day_5'                      => __( 'Friday', 'libertydev-site-migrator' ),
			'day_6'                      => __( 'Saturday', 'libertydev-site-migrator' ),
			'sched_time_label'           => __( 'Preferred Run Time (Site Timezone)', 'libertydev-site-migrator' ),
			'sched_retention_label'      => __( 'Scheduled Backup Retention Limit', 'libertydev-site-migrator' ),
			'ret_unlimited'              => __( 'Keep All Backups (Unlimited)', 'libertydev-site-migrator' ),
			/* translators: %d: number of scheduled backups to retain. */
			'ret_keep_n'                 => __( 'Keep Last %d Scheduled Backups', 'libertydev-site-migrator' ),
			'sched_exclusions_title'     => __( 'Scheduled Backup Content Rules', 'libertydev-site-migrator' ),
			'sched_notify_label'         => __( 'Send email notification when a scheduled backup completes', 'libertydev-site-migrator' ),
			'sched_email_placeholder'    => __( 'Notification recipient email...', 'libertydev-site-migrator' ),
			'save_schedule_btn'          => __( 'Save Schedule Settings', 'libertydev-site-migrator' ),
			'run_scheduled_now_btn'      => __( 'Run Scheduled Backup Now', 'libertydev-site-migrator' ),
			'schedule_saved_msg'         => __( 'Backup schedule settings saved and synchronized!', 'libertydev-site-migrator' ),
			'status_box_state'           => __( 'Schedule Status', 'libertydev-site-migrator' ),
			'status_active'              => __( 'Active & Scheduled', 'libertydev-site-migrator' ),
			'status_disabled'            => __( 'Disabled (Manual Only)', 'libertydev-site-migrator' ),
			'status_next_run'            => __( 'Next Scheduled Run', 'libertydev-site-migrator' ),
			'status_last_run'            => __( 'Last Scheduled Backup', 'libertydev-site-migrator' ),
			'status_none'                => __( 'None yet', 'libertydev-site-migrator' ),

			// Backups Vault tab.
			'backups_card_title'         => __( 'Backups Vault Stored on This Server', 'libertydev-site-migrator' ),
			'backups_card_desc'          => __( 'All manual, scheduled, and export archives are stored inside a cryptographically randomized uploads vault shielded across Apache, Nginx, and IIS.', 'libertydev-site-migrator' ),
			'col_filename'               => __( 'Archive & Label', 'libertydev-site-migrator' ),
			'col_type'                   => __( 'Type', 'libertydev-site-migrator' ),
			'col_created'                => __( 'Created At', 'libertydev-site-migrator' ),
			'col_size'                   => __( 'Size', 'libertydev-site-migrator' ),
			'col_source'                 => __( 'Source URL', 'libertydev-site-migrator' ),
			'col_actions'                => __( 'Actions', 'libertydev-site-migrator' ),
			'type_manual'                => __( 'Manual', 'libertydev-site-migrator' ),
			'type_scheduled'             => __( 'Scheduled', 'libertydev-site-migrator' ),
			'type_export'                => __( 'Export', 'libertydev-site-migrator' ),
			'btn_download'               => __( 'Download', 'libertydev-site-migrator' ),
			'btn_restore'                => __( 'Restore Here', 'libertydev-site-migrator' ),
			'btn_delete'                 => __( 'Delete', 'libertydev-site-migrator' ),
			'empty_backups'              => __( 'No saved backups yet. Create a manual backup or enable automated scheduling.', 'libertydev-site-migrator' ),
			'confirm_delete'             => __( 'Are you sure you want to permanently delete this backup archive?', 'libertydev-site-migrator' ),

			// Progress & states.
			'progress_preparing'         => __( 'Initializing migration/backup job...', 'libertydev-site-migrator' ),
			'progress_scanning_files'    => __( 'Scanning site directories...', 'libertydev-site-migrator' ),
			'progress_exporting_db'      => __( 'Exporting database tables in batches...', 'libertydev-site-migrator' ),
			'progress_archiving_files'   => __( 'Compressing files into streaming ZIP archive...', 'libertydev-site-migrator' ),
			'progress_finalizing_zip'    => __( 'Writing ZIP Central Directory & finalizing...', 'libertydev-site-migrator' ),
			'progress_uploading_chunks'  => __( 'Uploading archive in chunks...', 'libertydev-site-migrator' ),
			'progress_inspecting_zip'    => __( 'Inspecting archive & reading manifest...', 'libertydev-site-migrator' ),
			'progress_extracting_files'  => __( 'Extracting site files safely...', 'libertydev-site-migrator' ),
			'progress_importing_db'      => __( 'Importing database into shadow tables...', 'libertydev-site-migrator' ),
			'progress_replacing_urls'    => __( 'Running serialization-safe domain & path replacement...', 'libertydev-site-migrator' ),
			'progress_swapping_tables'   => __( 'Atomically swapping tables & flushing caches/permalinks...', 'libertydev-site-migrator' ),
			'import_complete_title'      => __( 'Site Migration Completed 100% Successfully!', 'libertydev-site-migrator' ),
			'import_complete_desc'       => __( 'Domain URLs, file paths, table prefixes, and permalinks have all been updated.', 'libertydev-site-migrator' ),
			'visit_site_btn'             => __( 'Visit Live Site', 'libertydev-site-migrator' ),
			'reload_admin_btn'           => __( 'Reload Admin Dashboard', 'libertydev-site-migrator' ),
			'retrying_network'           => __( 'Temporary network/server hiccup, retrying automatically...', 'libertydev-site-migrator' ),
			'error_prefix'               => __( 'Error: ', 'libertydev-site-migrator' ),
			'invalid_zip_ext'            => __( 'Please select a valid .zip archive file.', 'libertydev-site-migrator' ),
		);
	}
}
