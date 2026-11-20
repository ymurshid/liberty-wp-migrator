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
			'plugin_title' => __( 'Liberty WP Migrator — Migration & Automated Backup', 'liberty-wp-migrator' ),
			'menu_title' => __( 'Liberty Migrator', 'liberty-wp-migrator' ),
			'open_migrator' => __( 'Open Liberty Migrator', 'liberty-wp-migrator' ),
			'brand_by' => __( 'by LibertyDev', 'liberty-wp-migrator' ),
			'subtitle' => __( 'Unlimited-size WordPress migration, one-click manual backups, and automated scheduled backups (daily / weekly / monthly) with serialization-safe domain replacement.', 'liberty-wp-migrator' ),
			'tab_export' => __( 'Export Site', 'liberty-wp-migrator' ),
			'tab_import' => __( 'Import Site', 'liberty-wp-migrator' ),
			'tab_schedule' => __( 'Backup & Schedule', 'liberty-wp-migrator' ),
			'tab_backups' => __( 'Backups Vault', 'liberty-wp-migrator' ),
			'badge_unlimited' => __( 'Unlimited Size • LibertyDev Engine', 'liberty-wp-migrator' ),

			// Export tab.
			'export_card_title' => __( 'Create Full Compressed Site Archive (.zip)', 'liberty-wp-migrator' ),
			'export_card_desc' => __( 'Packages your database and wp-content in small time-boxed batches to prevent PHP timeouts, ready to import onto any other domain.', 'liberty-wp-migrator' ),
			'find_replace_title' => __( 'Custom Find & Replace During Export (Optional)', 'liberty-wp-migrator' ),
			'find_replace_hint' => __( 'Note: Domain URLs and server file paths are automatically replaced during import on a new domain. Only add rules here for extra custom text changes.', 'liberty-wp-migrator' ),
			'find_placeholder' => __( 'Search for text...', 'liberty-wp-migrator' ),
			'replace_placeholder' => __( 'Replace with...', 'liberty-wp-migrator' ),
			'add_pair_btn' => __( '+ Add Replace Rule', 'liberty-wp-migrator' ),
			'advanced_options_title' => __( 'Advanced Exclusion Options', 'liberty-wp-migrator' ),
			'opt_exclude_cache' => __( 'Exclude cache folders & debug logs (Recommended)', 'liberty-wp-migrator' ),
			'opt_exclude_transients' => __( 'Exclude temporary DB transients (Recommended)', 'liberty-wp-migrator' ),
			'opt_exclude_revisions' => __( 'Exclude old post revisions', 'liberty-wp-migrator' ),
			'opt_exclude_spam' => __( 'Exclude spam & trashed comments', 'liberty-wp-migrator' ),
			'opt_exclude_media' => __( 'Exclude media library (wp-content/uploads)', 'liberty-wp-migrator' ),
			'opt_exclude_themes' => __( 'Exclude inactive themes', 'liberty-wp-migrator' ),
			'opt_exclude_plugins' => __( 'Exclude inactive plugins', 'liberty-wp-migrator' ),
			'opt_include_custom_tables' => __( 'Include non-WP-prefixed custom database tables', 'liberty-wp-migrator' ),
			'start_export_btn' => __( 'Export Site to Compressed .ZIP', 'liberty-wp-migrator' ),
			'export_ready_title' => __( 'Your compressed site archive is ready!', 'liberty-wp-migrator' ),
			'download_zip_btn' => __( 'Download Archive (.zip)', 'liberty-wp-migrator' ),

			// Import tab.
			'import_card_title' => __( 'Import Site Archive onto This Domain', 'liberty-wp-migrator' ),
			'import_card_desc' => __( 'Upload a .zip archive created by Liberty WP Migrator. Large files are sliced into small chunks automatically to bypass server upload limits.', 'liberty-wp-migrator' ),
			'dropzone_title' => __( 'Drag & drop your site archive (.zip) here', 'liberty-wp-migrator' ),
			'dropzone_sub' => __( 'or click to browse — supports multi-GB archives via automatic chunked upload', 'liberty-wp-migrator' ),
			'select_file_btn' => __( 'Choose .zip Archive', 'liberty-wp-migrator' ),
			'server_limit_note' => __( 'Standard server upload limit: %s — bypassed automatically via chunked upload.', 'liberty-wp-migrator' ),
			'warning_overwrite_title' => __( 'Important Notice Before Importing', 'liberty-wp-migrator' ),
			'warning_overwrite_desc' => __( 'Importing an archive will replace this site’s database, themes, plugins, and media files with the contents of the archive. Back up first if needed.', 'liberty-wp-migrator' ),

			// Pre-import inspection.
			'inspect_title' => __( 'Review Archive Before Overwriting Site', 'liberty-wp-migrator' ),
			'inspect_source_url' => __( 'Source Site URL', 'liberty-wp-migrator' ),
			'inspect_dest_url' => __( 'Destination URL (This Site)', 'liberty-wp-migrator' ),
			'inspect_wp_php' => __( 'Archive WP / PHP Version', 'liberty-wp-migrator' ),
			'inspect_files_count' => __( 'Archived Files', 'liberty-wp-migrator' ),
			'inspect_tables_count' => __( 'Database Tables', 'liberty-wp-migrator' ),
			'inspect_uncompressed_size' => __( 'Total Data Size', 'liberty-wp-migrator' ),
			'opt_keep_current_admin' => __( 'Preserve my current Administrator account & password so I stay logged in after migration', 'liberty-wp-migrator' ),
			'opt_skip_guid' => __( 'Do not replace URLs inside posts GUID column', 'liberty-wp-migrator' ),
			'confirm_import_btn' => __( 'Confirm & Start Site Import Now', 'liberty-wp-migrator' ),
			'cancel_btn' => __( 'Cancel', 'liberty-wp-migrator' ),

			// Backup & Schedule tab.
			'manual_backup_title' => __( 'One-Click Manual Backup', 'liberty-wp-migrator' ),
			'manual_backup_desc' => __( 'Create an instant full compressed backup of this site and store it securely inside your server Backups Vault for 1-click restore anytime.', 'liberty-wp-migrator' ),
			'manual_note_label' => __( 'Backup Label / Note (Optional)', 'liberty-wp-migrator' ),
			'manual_note_placeholder' => __( 'e.g., Before theme or WooCommerce update...', 'liberty-wp-migrator' ),
			'create_manual_backup_btn' => __( 'Create Manual Backup Now', 'liberty-wp-migrator' ),

			'schedule_card_title' => __( 'Automated Scheduled Backups', 'liberty-wp-migrator' ),
			'schedule_card_desc' => __( 'Runs chunked background backups automatically without slowing down visitors or hitting timeouts, and rotates old backups automatically.', 'liberty-wp-migrator' ),
			'sched_enable_label' => __( 'Enable Automated Scheduled Backups', 'liberty-wp-migrator' ),
			'sched_frequency_label' => __( 'Backup Frequency', 'liberty-wp-migrator' ),
			'freq_every_6h' => __( 'Every 6 Hours', 'liberty-wp-migrator' ),
			'freq_twicedaily' => __( 'Twice Daily (Every 12 Hours)', 'liberty-wp-migrator' ),
			'freq_daily' => __( 'Daily', 'liberty-wp-migrator' ),
			'freq_weekly' => __( 'Weekly', 'liberty-wp-migrator' ),
			'freq_biweekly' => __( 'Bi-Weekly (Every 14 Days)', 'liberty-wp-migrator' ),
			'freq_monthly' => __( 'Monthly (Every 30 Days)', 'liberty-wp-migrator' ),
			'sched_day_label' => __( 'Preferred Day (for Weekly Schedule)', 'liberty-wp-migrator' ),
			'day_0' => __( 'Sunday', 'liberty-wp-migrator' ),
			'day_1' => __( 'Monday', 'liberty-wp-migrator' ),
			'day_2' => __( 'Tuesday', 'liberty-wp-migrator' ),
			'day_3' => __( 'Wednesday', 'liberty-wp-migrator' ),
			'day_4' => __( 'Thursday', 'liberty-wp-migrator' ),
			'day_5' => __( 'Friday', 'liberty-wp-migrator' ),
			'day_6' => __( 'Saturday', 'liberty-wp-migrator' ),
			'sched_time_label' => __( 'Preferred Run Time (Site Timezone)', 'liberty-wp-migrator' ),
			'sched_retention_label' => __( 'Scheduled Backup Retention Limit', 'liberty-wp-migrator' ),
			'ret_unlimited' => __( 'Keep All Backups (Unlimited)', 'liberty-wp-migrator' ),
			'ret_keep_n' => __( 'Keep Last %d Scheduled Backups', 'liberty-wp-migrator' ),
			'sched_exclusions_title' => __( 'Scheduled Backup Content Rules', 'liberty-wp-migrator' ),
			'sched_notify_label' => __( 'Send email notification when a scheduled backup completes', 'liberty-wp-migrator' ),
			'sched_email_placeholder' => __( 'Notification recipient email...', 'liberty-wp-migrator' ),
			'save_schedule_btn' => __( 'Save Schedule Settings', 'liberty-wp-migrator' ),
			'run_scheduled_now_btn' => __( 'Run Scheduled Backup Now', 'liberty-wp-migrator' ),
			'schedule_saved_msg' => __( 'Backup schedule settings saved and synchronized!', 'liberty-wp-migrator' ),
			'status_box_state' => __( 'Schedule Status', 'liberty-wp-migrator' ),
			'status_active' => __( 'Active & Scheduled', 'liberty-wp-migrator' ),
			'status_disabled' => __( 'Disabled (Manual Only)', 'liberty-wp-migrator' ),
			'status_next_run' => __( 'Next Scheduled Run', 'liberty-wp-migrator' ),
			'status_last_run' => __( 'Last Scheduled Backup', 'liberty-wp-migrator' ),
			'status_none' => __( 'None yet', 'liberty-wp-migrator' ),

			// Backups Vault tab.
			'backups_card_title' => __( 'Backups Vault Stored on This Server', 'liberty-wp-migrator' ),
			'backups_card_desc' => __( 'All manual, scheduled, and export archives stored securely inside wp-content/lwpm-backups (direct public HTTP access is blocked).', 'liberty-wp-migrator' ),
			'col_filename' => __( 'Archive & Label', 'liberty-wp-migrator' ),
			'col_type' => __( 'Type', 'liberty-wp-migrator' ),
			'col_created' => __( 'Created At', 'liberty-wp-migrator' ),
			'col_size' => __( 'Size', 'liberty-wp-migrator' ),
			'col_source' => __( 'Source URL', 'liberty-wp-migrator' ),
			'col_actions' => __( 'Actions', 'liberty-wp-migrator' ),
			'type_manual' => __( 'Manual', 'liberty-wp-migrator' ),
			'type_scheduled' => __( 'Scheduled', 'liberty-wp-migrator' ),
			'type_export' => __( 'Export', 'liberty-wp-migrator' ),
			'btn_download' => __( 'Download', 'liberty-wp-migrator' ),
			'btn_restore' => __( 'Restore Here', 'liberty-wp-migrator' ),
			'btn_delete' => __( 'Delete', 'liberty-wp-migrator' ),
			'empty_backups' => __( 'No saved backups yet. Create a manual backup or enable automated scheduling.', 'liberty-wp-migrator' ),
			'confirm_delete' => __( 'Are you sure you want to permanently delete this backup archive?', 'liberty-wp-migrator' ),

			// Progress & states.
			'progress_preparing' => __( 'Initializing migration/backup job...', 'liberty-wp-migrator' ),
			'progress_scanning_files' => __( 'Scanning wp-content files...', 'liberty-wp-migrator' ),
			'progress_exporting_db' => __( 'Exporting database tables in batches...', 'liberty-wp-migrator' ),
			'progress_archiving_files' => __( 'Compressing files into streaming ZIP archive...', 'liberty-wp-migrator' ),
			'progress_finalizing_zip' => __( 'Writing ZIP Central Directory & finalizing...', 'liberty-wp-migrator' ),
			'progress_uploading_chunks' => __( 'Uploading archive in chunks...', 'liberty-wp-migrator' ),
			'progress_inspecting_zip' => __( 'Inspecting archive & reading manifest...', 'liberty-wp-migrator' ),
			'progress_extracting_files' => __( 'Extracting site files safely...', 'liberty-wp-migrator' ),
			'progress_importing_db' => __( 'Importing database into shadow tables...', 'liberty-wp-migrator' ),
			'progress_replacing_urls' => __( 'Running serialization-safe domain & path replacement...', 'liberty-wp-migrator' ),
			'progress_swapping_tables' => __( 'Atomically swapping tables & flushing caches/permalinks...', 'liberty-wp-migrator' ),
			'import_complete_title' => __( 'Site Migration Completed 100% Successfully!', 'liberty-wp-migrator' ),
			'import_complete_desc' => __( 'Domain URLs, file paths, table prefixes, and permalinks have all been updated.', 'liberty-wp-migrator' ),
			'visit_site_btn' => __( 'Visit Live Site', 'liberty-wp-migrator' ),
			'reload_admin_btn' => __( 'Reload WP Admin', 'liberty-wp-migrator' ),
			'retrying_network' => __( 'Temporary network/server hiccup, retrying automatically...', 'liberty-wp-migrator' ),
			'error_prefix' => __( 'Error: ', 'liberty-wp-migrator' ),
			'invalid_zip_ext' => __( 'Please select a valid .zip archive file.', 'liberty-wp-migrator' ),
		);
	}
}
