<?php
/**
 * Admin Dashboard Template — Liberty WP Migrator by LibertyDev.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$active_lang = LWPM_I18n::get_active_lang();
$s           = LWPM_I18n::get_strings( $active_lang );
$backups     = LWPM_Storage::list_backups();
$sched_info  = LWPM_Scheduler::get_status_payload();
$sched       = $sched_info['settings'];
$max_post    = size_format( wp_max_upload_size() );
$logo_url    = LWPM_PLUGIN_URL . 'assets/img/liberty-dev-lockup-dark.svg';
?>
<div id="lwpm-app" class="lwpm-wrap" dir="<?php echo esc_attr( $s['dir'] ); ?>" data-lang="<?php echo esc_attr( $s['lang_code'] ); ?>">

	<!-- Executive LibertyDev Header -->
	<header class="lwpm-header">
		<div class="lwpm-header-brand">
			<img src="<?php echo esc_url( $logo_url ); ?>" alt="LibertyDev" class="lwpm-brand-lockup" />
			<div class="lwpm-brand-copy">
				<div class="lwpm-title-row">
					<h1 class="lwpm-title" data-i18n="plugin_title"><?php echo esc_html( $s['plugin_title'] ); ?></h1>
					<span class="lwpm-badge" data-i18n="badge_unlimited"><?php echo esc_html( $s['badge_unlimited'] ); ?></span>
				</div>
				<p class="lwpm-subtitle" data-i18n="subtitle"><?php echo esc_html( $s['subtitle'] ); ?></p>
			</div>
		</div>

		<div class="lwpm-header-actions">
			<div class="lwpm-env-pill" title="Active WordPress Environment">
				<span><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ?: home_url() ); ?></span>
				<span class="lwpm-dot">•</span>
				<span>WP <?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
				<span class="lwpm-dot">•</span>
				<span>PHP <?php echo esc_html( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ); ?></span>
			</div>
			<button type="button" id="lwpm-lang-toggle" class="lwpm-btn lwpm-btn-header-ghost">
				<span class="dashicons dashicons-translation"></span>
				<span id="lwpm-lang-toggle-text" data-i18n="switch_lang_label"><?php echo esc_html( $s['switch_lang_label'] ); ?></span>
			</button>
		</div>
	</header>

	<!-- Navigation Tabs -->
	<nav class="lwpm-tabs" role="tablist">
		<button type="button" class="lwpm-tab is-active" data-tab="export" role="tab" aria-selected="true">
			<span class="dashicons dashicons-migrate"></span>
			<span data-i18n="tab_export"><?php echo esc_html( $s['tab_export'] ); ?></span>
		</button>
		<button type="button" class="lwpm-tab" data-tab="import" role="tab" aria-selected="false">
			<span class="dashicons dashicons-upload"></span>
			<span data-i18n="tab_import"><?php echo esc_html( $s['tab_import'] ); ?></span>
		</button>
		<button type="button" class="lwpm-tab" data-tab="schedule" role="tab" aria-selected="false">
			<span class="dashicons dashicons-calendar-alt"></span>
			<span data-i18n="tab_schedule"><?php echo esc_html( $s['tab_schedule'] ); ?></span>
		</button>
		<button type="button" class="lwpm-tab" data-tab="backups" role="tab" aria-selected="false">
			<span class="dashicons dashicons-shield-alt"></span>
			<span data-i18n="tab_backups"><?php echo esc_html( $s['tab_backups'] ); ?></span>
			<span id="lwpm-backups-count" class="lwpm-tab-count"><?php echo count( $backups ); ?></span>
		</button>
	</nav>

	<!-- Error & Toast Banners -->
	<div id="lwpm-error-box" class="lwpm-alert lwpm-alert-error" hidden>
		<span class="dashicons dashicons-warning"></span>
		<div id="lwpm-error-text"></div>
		<button type="button" id="lwpm-error-close" class="lwpm-alert-close" aria-label="Close">&times;</button>
	</div>

	<div id="lwpm-toast-box" class="lwpm-alert lwpm-alert-success" hidden>
		<span class="dashicons dashicons-yes-alt"></span>
		<div id="lwpm-toast-text"></div>
	</div>

	<!-- Shared Live Progress Panel -->
	<section id="lwpm-progress-panel" class="lwpm-card lwpm-progress-card" hidden>
		<div class="lwpm-progress-top">
			<div>
				<h3 id="lwpm-progress-label" class="lwpm-progress-title"><?php echo esc_html( $s['progress_preparing'] ); ?></h3>
				<p id="lwpm-progress-detail" class="lwpm-progress-detail"></p>
			</div>
			<div class="lwpm-progress-pct-wrap">
				<span id="lwpm-progress-pct" class="lwpm-progress-pct">0%</span>
			</div>
		</div>

		<div class="lwpm-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
			<div id="lwpm-progress-bar" class="lwpm-progress-fill" style="width: 0%;"></div>
		</div>

		<div id="lwpm-retry-notice" class="lwpm-retry-note" hidden data-i18n="retrying_network">
			<?php echo esc_html( $s['retrying_network'] ); ?>
		</div>

		<div class="lwpm-progress-footer">
			<div id="lwpm-progress-log" class="lwpm-mini-log"></div>
			<button type="button" id="lwpm-cancel-job-btn" class="lwpm-btn lwpm-btn-danger-outline" data-i18n="cancel_btn">
				<?php echo esc_html( $s['cancel_btn'] ); ?>
			</button>
		</div>
	</section>

	<!-- Completed Archive Download Banner (Shared for Export & Manual/Test Backups) -->
	<div id="lwpm-export-ready" class="lwpm-success-box" hidden>
		<div class="lwpm-success-icon"><span class="dashicons dashicons-yes-alt"></span></div>
		<div class="lwpm-success-body">
			<h3 data-i18n="export_ready_title"><?php echo esc_html( $s['export_ready_title'] ); ?></h3>
			<p id="lwpm-export-ready-meta"></p>
		</div>
		<a id="lwpm-export-download-link" href="#" class="lwpm-btn lwpm-btn-success">
			<span class="dashicons dashicons-download"></span>
			<span data-i18n="download_zip_btn"><?php echo esc_html( $s['download_zip_btn'] ); ?></span>
		</a>
	</div>

	<!-- ==================== TAB 1: EXPORT ==================== -->
	<section id="lwpm-panel-export" class="lwpm-panel is-active">
		<div class="lwpm-card">
			<div class="lwpm-card-head">
				<h2 data-i18n="export_card_title"><?php echo esc_html( $s['export_card_title'] ); ?></h2>
				<p data-i18n="export_card_desc"><?php echo esc_html( $s['export_card_desc'] ); ?></p>
			</div>

			<div class="lwpm-section-block">
				<h3 class="lwpm-section-subtitle" data-i18n="advanced_options_title"><?php echo esc_html( $s['advanced_options_title'] ); ?></h3>
				<div class="lwpm-checkbox-grid">
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-exp-exclude-cache" checked>
						<span data-i18n="opt_exclude_cache"><?php echo esc_html( $s['opt_exclude_cache'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-exp-exclude-transients" checked>
						<span data-i18n="opt_exclude_transients"><?php echo esc_html( $s['opt_exclude_transients'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-exp-exclude-revisions">
						<span data-i18n="opt_exclude_revisions"><?php echo esc_html( $s['opt_exclude_revisions'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-exp-exclude-spam">
						<span data-i18n="opt_exclude_spam"><?php echo esc_html( $s['opt_exclude_spam'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-exp-exclude-media">
						<span data-i18n="opt_exclude_media"><?php echo esc_html( $s['opt_exclude_media'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-exp-exclude-themes">
						<span data-i18n="opt_exclude_themes"><?php echo esc_html( $s['opt_exclude_themes'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-exp-exclude-plugins">
						<span data-i18n="opt_exclude_plugins"><?php echo esc_html( $s['opt_exclude_plugins'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-exp-custom-tables">
						<span data-i18n="opt_include_custom_tables"><?php echo esc_html( $s['opt_include_custom_tables'] ); ?></span>
					</label>
				</div>
			</div>

			<div class="lwpm-section-block">
				<h3 class="lwpm-section-subtitle" data-i18n="find_replace_title"><?php echo esc_html( $s['find_replace_title'] ); ?></h3>
				<p class="lwpm-muted-note" data-i18n="find_replace_hint"><?php echo esc_html( $s['find_replace_hint'] ); ?></p>

				<div id="lwpm-replace-rows" class="lwpm-replace-list"></div>

				<button type="button" id="lwpm-add-replace-btn" class="lwpm-btn lwpm-btn-secondary" data-i18n="add_pair_btn">
					<?php echo esc_html( $s['add_pair_btn'] ); ?>
				</button>
			</div>

			<div class="lwpm-card-footer">
				<button type="button" id="lwpm-start-export-btn" class="lwpm-btn lwpm-btn-primary lwpm-btn-lg">
					<span class="dashicons dashicons-archive"></span>
					<span data-i18n="start_export_btn"><?php echo esc_html( $s['start_export_btn'] ); ?></span>
				</button>
			</div>
		</div>
	</section>

	<!-- ==================== TAB 2: IMPORT ==================== -->
	<section id="lwpm-panel-import" class="lwpm-panel" hidden>
		<div class="lwpm-card">
			<div class="lwpm-card-head">
				<h2 data-i18n="import_card_title"><?php echo esc_html( $s['import_card_title'] ); ?></h2>
				<p data-i18n="import_card_desc"><?php echo esc_html( $s['import_card_desc'] ); ?></p>
			</div>

			<div class="lwpm-alert lwpm-alert-warn">
				<span class="dashicons dashicons-info-outline"></span>
				<div>
					<strong data-i18n="warning_overwrite_title"><?php echo esc_html( $s['warning_overwrite_title'] ); ?></strong>
					<p data-i18n="warning_overwrite_desc"><?php echo esc_html( $s['warning_overwrite_desc'] ); ?></p>
				</div>
			</div>

			<div id="lwpm-dropzone-wrap">
				<div id="lwpm-dropzone" class="lwpm-dropzone" tabindex="0" role="button">
					<input type="file" id="lwpm-file-input" accept=".zip,application/zip" hidden>
					<div class="lwpm-dropzone-icon">
						<span class="dashicons dashicons-cloud-upload"></span>
					</div>
					<h3 data-i18n="dropzone_title"><?php echo esc_html( $s['dropzone_title'] ); ?></h3>
					<p data-i18n="dropzone_sub"><?php echo esc_html( $s['dropzone_sub'] ); ?></p>
					<span class="lwpm-btn lwpm-btn-primary" data-i18n="select_file_btn"><?php echo esc_html( $s['select_file_btn'] ); ?></span>
				</div>
				<p id="lwpm-server-limit-note" class="lwpm-center-note">
					<?php echo esc_html( sprintf( $s['server_limit_note'], $max_post ) ); ?>
				</p>
			</div>

			<!-- Pre-Import Inspection & Confirmation Box -->
			<div id="lwpm-inspect-box" class="lwpm-inspect-box" hidden>
				<h3 class="lwpm-inspect-heading" data-i18n="inspect_title"><?php echo esc_html( $s['inspect_title'] ); ?></h3>

				<div class="lwpm-inspect-grid">
					<div class="lwpm-stat-item">
						<span class="lwpm-stat-label" data-i18n="inspect_source_url"><?php echo esc_html( $s['inspect_source_url'] ); ?></span>
						<strong id="lwpm-ins-source" class="lwpm-stat-value lwpm-ltr"></strong>
					</div>
					<div class="lwpm-stat-item">
						<span class="lwpm-stat-label" data-i18n="inspect_dest_url"><?php echo esc_html( $s['inspect_dest_url'] ); ?></span>
						<strong id="lwpm-ins-dest" class="lwpm-stat-value lwpm-ltr"></strong>
					</div>
					<div class="lwpm-stat-item">
						<span class="lwpm-stat-label" data-i18n="inspect_wp_php"><?php echo esc_html( $s['inspect_wp_php'] ); ?></span>
						<strong id="lwpm-ins-versions" class="lwpm-stat-value lwpm-ltr"></strong>
					</div>
					<div class="lwpm-stat-item">
						<span class="lwpm-stat-label" data-i18n="inspect_files_count"><?php echo esc_html( $s['inspect_files_count'] ); ?></span>
						<strong id="lwpm-ins-files" class="lwpm-stat-value"></strong>
					</div>
					<div class="lwpm-stat-item">
						<span class="lwpm-stat-label" data-i18n="inspect_tables_count"><?php echo esc_html( $s['inspect_tables_count'] ); ?></span>
						<strong id="lwpm-ins-tables" class="lwpm-stat-value"></strong>
					</div>
					<div class="lwpm-stat-item">
						<span class="lwpm-stat-label" data-i18n="inspect_uncompressed_size"><?php echo esc_html( $s['inspect_uncompressed_size'] ); ?></span>
						<strong id="lwpm-ins-size" class="lwpm-stat-value lwpm-ltr"></strong>
					</div>
				</div>

				<div class="lwpm-inspect-options">
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-imp-keep-admin" checked>
						<span data-i18n="opt_keep_current_admin"><?php echo esc_html( $s['opt_keep_current_admin'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-imp-skip-guid">
						<span data-i18n="opt_skip_guid"><?php echo esc_html( $s['opt_skip_guid'] ); ?></span>
					</label>
				</div>

				<div class="lwpm-inspect-actions">
					<button type="button" id="lwpm-confirm-import-btn" class="lwpm-btn lwpm-btn-primary lwpm-btn-lg">
						<span class="dashicons dashicons-database-import"></span>
						<span data-i18n="confirm_import_btn"><?php echo esc_html( $s['confirm_import_btn'] ); ?></span>
					</button>
					<button type="button" id="lwpm-cancel-inspect-btn" class="lwpm-btn lwpm-btn-secondary" data-i18n="cancel_btn">
						<?php echo esc_html( $s['cancel_btn'] ); ?>
					</button>
				</div>
			</div>

			<!-- Import Completed Box -->
			<div id="lwpm-import-done-box" class="lwpm-success-box lwpm-import-complete" hidden>
				<div class="lwpm-success-icon"><span class="dashicons dashicons-saved"></span></div>
				<div class="lwpm-success-body">
					<h3 data-i18n="import_complete_title"><?php echo esc_html( $s['import_complete_title'] ); ?></h3>
					<p data-i18n="import_complete_desc"><?php echo esc_html( $s['import_complete_desc'] ); ?></p>
				</div>
				<div class="lwpm-complete-btns">
					<a id="lwpm-visit-site-link" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener" class="lwpm-btn lwpm-btn-success">
						<span class="dashicons dashicons-external"></span>
						<span data-i18n="visit_site_btn"><?php echo esc_html( $s['visit_site_btn'] ); ?></span>
					</a>
					<a id="lwpm-reload-admin-link" href="<?php echo esc_url( admin_url( 'admin.php?page=liberty-wp-migrator' ) ); ?>" class="lwpm-btn lwpm-btn-secondary">
						<span class="dashicons dashicons-update"></span>
						<span data-i18n="reload_admin_btn"><?php echo esc_html( $s['reload_admin_btn'] ); ?></span>
					</a>
				</div>
			</div>
		</div>
	</section>

	<!-- ==================== TAB 3: BACKUP & SCHEDULE ==================== -->
	<section id="lwpm-panel-schedule" class="lwpm-panel" hidden>

		<!-- Card A: 1-Click Manual Backup -->
		<div class="lwpm-card">
			<div class="lwpm-card-head">
				<h2 data-i18n="manual_backup_title"><?php echo esc_html( $s['manual_backup_title'] ); ?></h2>
				<p data-i18n="manual_backup_desc"><?php echo esc_html( $s['manual_backup_desc'] ); ?></p>
			</div>

			<div class="lwpm-manual-backup-bar">
				<div class="lwpm-field-grow">
					<label for="lwpm-manual-note" class="lwpm-field-label" data-i18n="manual_note_label"><?php echo esc_html( $s['manual_note_label'] ); ?></label>
					<input type="text" id="lwpm-manual-note" class="lwpm-input" maxlength="120" placeholder="<?php echo esc_attr( $s['manual_note_placeholder'] ); ?>">
				</div>
				<button type="button" id="lwpm-manual-backup-btn" class="lwpm-btn lwpm-btn-primary lwpm-btn-lg">
					<span class="dashicons dashicons-backup"></span>
					<span data-i18n="create_manual_backup_btn"><?php echo esc_html( $s['create_manual_backup_btn'] ); ?></span>
				</button>
			</div>
		</div>

		<!-- Card B: Automated Scheduled Backups -->
		<div class="lwpm-card">
			<div class="lwpm-card-head">
				<h2 data-i18n="schedule_card_title"><?php echo esc_html( $s['schedule_card_title'] ); ?></h2>
				<p data-i18n="schedule_card_desc"><?php echo esc_html( $s['schedule_card_desc'] ); ?></p>
			</div>

			<!-- Live Schedule Status Summary Strip -->
			<div class="lwpm-sched-status-strip">
				<div class="lwpm-stat-item">
					<span class="lwpm-stat-label" data-i18n="status_box_state"><?php echo esc_html( $s['status_box_state'] ); ?></span>
					<strong id="lwpm-sched-state-badge" class="lwpm-stat-value"></strong>
				</div>
				<div class="lwpm-stat-item">
					<span class="lwpm-stat-label" data-i18n="status_next_run"><?php echo esc_html( $s['status_next_run'] ); ?></span>
					<strong id="lwpm-sched-next-run" class="lwpm-stat-value lwpm-ltr"></strong>
				</div>
				<div class="lwpm-stat-item">
					<span class="lwpm-stat-label" data-i18n="status_last_run"><?php echo esc_html( $s['status_last_run'] ); ?></span>
					<strong id="lwpm-sched-last-run" class="lwpm-stat-value lwpm-ltr"></strong>
				</div>
			</div>

			<!-- Master Enable Switch -->
			<div class="lwpm-section-block">
				<label class="lwpm-switch-card">
					<input type="checkbox" id="lwpm-sched-enabled" <?php checked( ! empty( $sched['enabled'] ) ); ?>>
					<span class="lwpm-switch-title" data-i18n="sched_enable_label"><?php echo esc_html( $s['sched_enable_label'] ); ?></span>
				</label>
			</div>

			<!-- Schedule Timing & Retention Form Grid -->
			<div class="lwpm-form-grid">
				<div class="lwpm-form-field">
					<label for="lwpm-sched-frequency" class="lwpm-field-label" data-i18n="sched_frequency_label"><?php echo esc_html( $s['sched_frequency_label'] ); ?></label>
					<select id="lwpm-sched-frequency" class="lwpm-select">
						<option value="lwpm_every_6h" <?php selected( $sched['frequency'], 'lwpm_every_6h' ); ?> data-i18n="freq_every_6h"><?php echo esc_html( $s['freq_every_6h'] ); ?></option>
						<option value="lwpm_twicedaily" <?php selected( $sched['frequency'], 'lwpm_twicedaily' ); ?> data-i18n="freq_twicedaily"><?php echo esc_html( $s['freq_twicedaily'] ); ?></option>
						<option value="lwpm_daily" <?php selected( $sched['frequency'], 'lwpm_daily' ); ?> data-i18n="freq_daily"><?php echo esc_html( $s['freq_daily'] ); ?></option>
						<option value="lwpm_weekly" <?php selected( $sched['frequency'], 'lwpm_weekly' ); ?> data-i18n="freq_weekly"><?php echo esc_html( $s['freq_weekly'] ); ?></option>
						<option value="lwpm_biweekly" <?php selected( $sched['frequency'], 'lwpm_biweekly' ); ?> data-i18n="freq_biweekly"><?php echo esc_html( $s['freq_biweekly'] ); ?></option>
						<option value="lwpm_monthly" <?php selected( $sched['frequency'], 'lwpm_monthly' ); ?> data-i18n="freq_monthly"><?php echo esc_html( $s['freq_monthly'] ); ?></option>
					</select>
				</div>

				<div class="lwpm-form-field" id="lwpm-sched-day-wrap">
					<label for="lwpm-sched-day" class="lwpm-field-label" data-i18n="sched_day_label"><?php echo esc_html( $s['sched_day_label'] ); ?></label>
					<select id="lwpm-sched-day" class="lwpm-select">
						<?php for ( $d = 0; $d <= 6; $d++ ) : ?>
							<option value="<?php echo (int) $d; ?>" <?php selected( (int) $sched['day_of_week'], $d ); ?> data-i18n="day_<?php echo (int) $d; ?>">
								<?php echo esc_html( $s[ 'day_' . $d ] ); ?>
							</option>
						<?php endfor; ?>
					</select>
				</div>

				<div class="lwpm-form-field">
					<label for="lwpm-sched-time" class="lwpm-field-label" data-i18n="sched_time_label"><?php echo esc_html( $s['sched_time_label'] ); ?></label>
					<input type="time" id="lwpm-sched-time" class="lwpm-input lwpm-ltr" value="<?php echo esc_attr( $sched['run_time'] ); ?>">
				</div>

				<div class="lwpm-form-field">
					<label for="lwpm-sched-retention" class="lwpm-field-label" data-i18n="sched_retention_label"><?php echo esc_html( $s['sched_retention_label'] ); ?></label>
					<select id="lwpm-sched-retention" class="lwpm-select">
						<?php foreach ( array( 3, 5, 7, 10, 14, 30 ) as $n ) : ?>
							<option value="<?php echo (int) $n; ?>" <?php selected( (int) $sched['retention'], $n ); ?> data-ret-n="<?php echo (int) $n; ?>">
								<?php echo esc_html( sprintf( $s['ret_keep_n'], $n ) ); ?>
							</option>
						<?php endforeach; ?>
						<option value="0" <?php selected( (int) $sched['retention'], 0 ); ?> data-i18n="ret_unlimited"><?php echo esc_html( $s['ret_unlimited'] ); ?></option>
					</select>
				</div>
			</div>

			<!-- Scheduled Backup Exclusions -->
			<div class="lwpm-section-block">
				<h3 class="lwpm-section-subtitle" data-i18n="sched_exclusions_title"><?php echo esc_html( $s['sched_exclusions_title'] ); ?></h3>
				<div class="lwpm-checkbox-grid">
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-sch-exclude-cache" <?php checked( ! empty( $sched['exclude_cache'] ) ); ?>>
						<span data-i18n="opt_exclude_cache"><?php echo esc_html( $s['opt_exclude_cache'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-sch-exclude-transients" <?php checked( ! empty( $sched['exclude_transients'] ) ); ?>>
						<span data-i18n="opt_exclude_transients"><?php echo esc_html( $s['opt_exclude_transients'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-sch-exclude-revisions" <?php checked( ! empty( $sched['exclude_revisions'] ) ); ?>>
						<span data-i18n="opt_exclude_revisions"><?php echo esc_html( $s['opt_exclude_revisions'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-sch-exclude-spam" <?php checked( ! empty( $sched['exclude_spam'] ) ); ?>>
						<span data-i18n="opt_exclude_spam"><?php echo esc_html( $s['opt_exclude_spam'] ); ?></span>
					</label>
					<label class="lwpm-check-item">
						<input type="checkbox" id="lwpm-sch-exclude-media" <?php checked( ! empty( $sched['exclude_media'] ) ); ?>>
						<span data-i18n="opt_exclude_media"><?php echo esc_html( $s['opt_exclude_media'] ); ?></span>
					</label>
				</div>
			</div>

			<!-- Email Notification -->
			<div class="lwpm-section-block">
				<div class="lwpm-email-notify-row">
					<label class="lwpm-check-item lwpm-flex-1">
						<input type="checkbox" id="lwpm-sch-notify-enabled" <?php checked( ! empty( $sched['notify_enabled'] ) ); ?>>
						<span data-i18n="sched_notify_label"><?php echo esc_html( $s['sched_notify_label'] ); ?></span>
					</label>
					<input type="email" id="lwpm-sch-notify-email" class="lwpm-input lwpm-ltr lwpm-email-input" value="<?php echo esc_attr( $sched['notify_email'] ); ?>" placeholder="<?php echo esc_attr( $s['sched_email_placeholder'] ); ?>">
				</div>
			</div>

			<div class="lwpm-card-footer lwpm-footer-split">
				<button type="button" id="lwpm-save-schedule-btn" class="lwpm-btn lwpm-btn-primary lwpm-btn-lg">
					<span class="dashicons dashicons-saved"></span>
					<span data-i18n="save_schedule_btn"><?php echo esc_html( $s['save_schedule_btn'] ); ?></span>
				</button>

				<button type="button" id="lwpm-run-sched-test-btn" class="lwpm-btn lwpm-btn-secondary">
					<span class="dashicons dashicons-controls-play"></span>
					<span data-i18n="run_scheduled_now_btn"><?php echo esc_html( $s['run_scheduled_now_btn'] ); ?></span>
				</button>
			</div>
		</div>
	</section>

	<!-- ==================== TAB 4: BACKUPS VAULT ==================== -->
	<section id="lwpm-panel-backups" class="lwpm-panel" hidden>
		<div class="lwpm-card">
			<div class="lwpm-card-head">
				<h2 data-i18n="backups_card_title"><?php echo esc_html( $s['backups_card_title'] ); ?></h2>
				<p data-i18n="backups_card_desc"><?php echo esc_html( $s['backups_card_desc'] ); ?></p>
			</div>

			<div class="lwpm-table-wrap">
				<table class="lwpm-table" id="lwpm-backups-table">
					<thead>
						<tr>
							<th data-i18n="col_filename"><?php echo esc_html( $s['col_filename'] ); ?></th>
							<th data-i18n="col_type"><?php echo esc_html( $s['col_type'] ); ?></th>
							<th data-i18n="col_source"><?php echo esc_html( $s['col_source'] ); ?></th>
							<th data-i18n="col_created"><?php echo esc_html( $s['col_created'] ); ?></th>
							<th data-i18n="col_size"><?php echo esc_html( $s['col_size'] ); ?></th>
							<th data-i18n="col_actions"><?php echo esc_html( $s['col_actions'] ); ?></th>
						</tr>
					</thead>
					<tbody id="lwpm-backups-tbody"></tbody>
				</table>
				<div id="lwpm-backups-empty" class="lwpm-empty-state" hidden data-i18n="empty_backups">
					<?php echo esc_html( $s['empty_backups'] ); ?>
				</div>
			</div>
		</div>
	</section>

</div>
