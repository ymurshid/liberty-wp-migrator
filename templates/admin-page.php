<?php
/**
 * Admin Dashboard Template — LibertyDev Site Migrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$active_lang = LDSM_I18n::get_active_lang();
$s           = LDSM_I18n::get_strings( $active_lang );
$backups     = LDSM_Storage::list_backups();
$sched_info  = LDSM_Scheduler::get_status_payload();
$sched       = $sched_info['settings'];
$max_post    = size_format( wp_max_upload_size() );
$logo_url    = LDSM_PLUGIN_URL . 'assets/img/liberty-dev-lockup-dark.svg';
?>
<div id="ldsm-app" class="ldsm-wrap" dir="<?php echo esc_attr( $s['dir'] ); ?>" data-lang="<?php echo esc_attr( $s['lang_code'] ); ?>">

	<!-- Executive LibertyDev Header -->
	<header class="ldsm-header">
		<div class="ldsm-header-brand">
			<img src="<?php echo esc_url( $logo_url ); ?>" alt="LibertyDev" class="ldsm-brand-lockup" />
			<div class="ldsm-brand-copy">
				<div class="ldsm-title-row">
					<h1 class="ldsm-title" data-i18n="plugin_title"><?php echo esc_html( $s['plugin_title'] ); ?></h1>
					<span class="ldsm-badge" data-i18n="badge_unlimited"><?php echo esc_html( $s['badge_unlimited'] ); ?></span>
				</div>
				<p class="ldsm-subtitle" data-i18n="subtitle"><?php echo esc_html( $s['subtitle'] ); ?></p>
			</div>
		</div>

		<div class="ldsm-header-actions">
			<div class="ldsm-env-pill" title="Active WordPress Environment">
				<span><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ?: home_url() ); ?></span>
				<span class="ldsm-dot">•</span>
				<span>WP <?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
				<span class="ldsm-dot">•</span>
				<span>PHP <?php echo esc_html( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ); ?></span>
			</div>
			<button type="button" id="ldsm-lang-toggle" class="ldsm-btn ldsm-btn-header-ghost">
				<span class="dashicons dashicons-translation"></span>
				<span id="ldsm-lang-toggle-text" data-i18n="switch_lang_label"><?php echo esc_html( $s['switch_lang_label'] ); ?></span>
			</button>
		</div>
	</header>

	<!-- Navigation Tabs -->
	<nav class="ldsm-tabs" role="tablist">
		<button type="button" class="ldsm-tab is-active" data-tab="export" role="tab" aria-selected="true">
			<span class="dashicons dashicons-migrate"></span>
			<span data-i18n="tab_export"><?php echo esc_html( $s['tab_export'] ); ?></span>
		</button>
		<button type="button" class="ldsm-tab" data-tab="import" role="tab" aria-selected="false">
			<span class="dashicons dashicons-upload"></span>
			<span data-i18n="tab_import"><?php echo esc_html( $s['tab_import'] ); ?></span>
		</button>
		<button type="button" class="ldsm-tab" data-tab="schedule" role="tab" aria-selected="false">
			<span class="dashicons dashicons-calendar-alt"></span>
			<span data-i18n="tab_schedule"><?php echo esc_html( $s['tab_schedule'] ); ?></span>
		</button>
		<button type="button" class="ldsm-tab" data-tab="backups" role="tab" aria-selected="false">
			<span class="dashicons dashicons-shield-alt"></span>
			<span data-i18n="tab_backups"><?php echo esc_html( $s['tab_backups'] ); ?></span>
			<span id="ldsm-backups-count" class="ldsm-tab-count"><?php echo count( $backups ); ?></span>
		</button>
	</nav>

	<!-- Error & Toast Banners -->
	<div id="ldsm-error-box" class="ldsm-alert ldsm-alert-error" hidden>
		<span class="dashicons dashicons-warning"></span>
		<div id="ldsm-error-text"></div>
		<button type="button" id="ldsm-error-close" class="ldsm-alert-close" aria-label="Close">&times;</button>
	</div>

	<div id="ldsm-toast-box" class="ldsm-alert ldsm-alert-success" hidden>
		<span class="dashicons dashicons-yes-alt"></span>
		<div id="ldsm-toast-text"></div>
	</div>

	<!-- Shared Live Progress Panel -->
	<section id="ldsm-progress-panel" class="ldsm-card ldsm-progress-card" hidden>
		<div class="ldsm-progress-top">
			<div>
				<h3 id="ldsm-progress-label" class="ldsm-progress-title"><?php echo esc_html( $s['progress_preparing'] ); ?></h3>
				<p id="ldsm-progress-detail" class="ldsm-progress-detail"></p>
			</div>
			<div class="ldsm-progress-pct-wrap">
				<span id="ldsm-progress-pct" class="ldsm-progress-pct">0%</span>
			</div>
		</div>

		<div class="ldsm-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
			<div id="ldsm-progress-bar" class="ldsm-progress-fill" style="width: 0%;"></div>
		</div>

		<div id="ldsm-retry-notice" class="ldsm-retry-note" hidden data-i18n="retrying_network">
			<?php echo esc_html( $s['retrying_network'] ); ?>
		</div>

		<div class="ldsm-progress-footer">
			<div id="ldsm-progress-log" class="ldsm-mini-log"></div>
			<button type="button" id="ldsm-cancel-job-btn" class="ldsm-btn ldsm-btn-danger-outline" data-i18n="cancel_btn">
				<?php echo esc_html( $s['cancel_btn'] ); ?>
			</button>
		</div>
	</section>

	<!-- Completed Archive Download Banner (Shared for Export & Manual/Test Backups) -->
	<div id="ldsm-export-ready" class="ldsm-success-box" hidden>
		<div class="ldsm-success-icon"><span class="dashicons dashicons-yes-alt"></span></div>
		<div class="ldsm-success-body">
			<h3 data-i18n="export_ready_title"><?php echo esc_html( $s['export_ready_title'] ); ?></h3>
			<p id="ldsm-export-ready-meta"></p>
		</div>
		<a id="ldsm-export-download-link" href="#" class="ldsm-btn ldsm-btn-success">
			<span class="dashicons dashicons-download"></span>
			<span data-i18n="download_zip_btn"><?php echo esc_html( $s['download_zip_btn'] ); ?></span>
		</a>
	</div>

	<!-- ==================== TAB 1: EXPORT ==================== -->
	<section id="ldsm-panel-export" class="ldsm-panel is-active">
		<div class="ldsm-card">
			<div class="ldsm-card-head">
				<h2 data-i18n="export_card_title"><?php echo esc_html( $s['export_card_title'] ); ?></h2>
				<p data-i18n="export_card_desc"><?php echo esc_html( $s['export_card_desc'] ); ?></p>
			</div>

			<div class="ldsm-section-block">
				<h3 class="ldsm-section-subtitle" data-i18n="advanced_options_title"><?php echo esc_html( $s['advanced_options_title'] ); ?></h3>
				<div class="ldsm-checkbox-grid">
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-exp-exclude-cache" checked>
						<span data-i18n="opt_exclude_cache"><?php echo esc_html( $s['opt_exclude_cache'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-exp-exclude-transients" checked>
						<span data-i18n="opt_exclude_transients"><?php echo esc_html( $s['opt_exclude_transients'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-exp-exclude-revisions">
						<span data-i18n="opt_exclude_revisions"><?php echo esc_html( $s['opt_exclude_revisions'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-exp-exclude-spam">
						<span data-i18n="opt_exclude_spam"><?php echo esc_html( $s['opt_exclude_spam'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-exp-exclude-media">
						<span data-i18n="opt_exclude_media"><?php echo esc_html( $s['opt_exclude_media'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-exp-exclude-themes">
						<span data-i18n="opt_exclude_themes"><?php echo esc_html( $s['opt_exclude_themes'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-exp-exclude-plugins">
						<span data-i18n="opt_exclude_plugins"><?php echo esc_html( $s['opt_exclude_plugins'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-exp-custom-tables">
						<span data-i18n="opt_include_custom_tables"><?php echo esc_html( $s['opt_include_custom_tables'] ); ?></span>
					</label>
				</div>
			</div>

			<div class="ldsm-section-block">
				<h3 class="ldsm-section-subtitle" data-i18n="find_replace_title"><?php echo esc_html( $s['find_replace_title'] ); ?></h3>
				<p class="ldsm-muted-note" data-i18n="find_replace_hint"><?php echo esc_html( $s['find_replace_hint'] ); ?></p>

				<div id="ldsm-replace-rows" class="ldsm-replace-list"></div>

				<button type="button" id="ldsm-add-replace-btn" class="ldsm-btn ldsm-btn-secondary" data-i18n="add_pair_btn">
					<?php echo esc_html( $s['add_pair_btn'] ); ?>
				</button>
			</div>

			<div class="ldsm-card-footer">
				<button type="button" id="ldsm-start-export-btn" class="ldsm-btn ldsm-btn-primary ldsm-btn-lg">
					<span class="dashicons dashicons-archive"></span>
					<span data-i18n="start_export_btn"><?php echo esc_html( $s['start_export_btn'] ); ?></span>
				</button>
			</div>
		</div>
	</section>

	<!-- ==================== TAB 2: IMPORT ==================== -->
	<section id="ldsm-panel-import" class="ldsm-panel" hidden>
		<div class="ldsm-card">
			<div class="ldsm-card-head">
				<h2 data-i18n="import_card_title"><?php echo esc_html( $s['import_card_title'] ); ?></h2>
				<p data-i18n="import_card_desc"><?php echo esc_html( $s['import_card_desc'] ); ?></p>
			</div>

			<div class="ldsm-alert ldsm-alert-warn">
				<span class="dashicons dashicons-info-outline"></span>
				<div>
					<strong data-i18n="warning_overwrite_title"><?php echo esc_html( $s['warning_overwrite_title'] ); ?></strong>
					<p data-i18n="warning_overwrite_desc"><?php echo esc_html( $s['warning_overwrite_desc'] ); ?></p>
				</div>
			</div>

			<div id="ldsm-dropzone-wrap">
				<div id="ldsm-dropzone" class="ldsm-dropzone" tabindex="0" role="button">
					<input type="file" id="ldsm-file-input" accept=".zip,application/zip" hidden>
					<div class="ldsm-dropzone-icon">
						<span class="dashicons dashicons-cloud-upload"></span>
					</div>
					<h3 data-i18n="dropzone_title"><?php echo esc_html( $s['dropzone_title'] ); ?></h3>
					<p data-i18n="dropzone_sub"><?php echo esc_html( $s['dropzone_sub'] ); ?></p>
					<span class="ldsm-btn ldsm-btn-primary" data-i18n="select_file_btn"><?php echo esc_html( $s['select_file_btn'] ); ?></span>
				</div>
				<p id="ldsm-server-limit-note" class="ldsm-center-note">
					<?php echo esc_html( sprintf( $s['server_limit_note'], $max_post ) ); ?>
				</p>
			</div>

			<!-- Pre-Import Inspection & Confirmation Box -->
			<div id="ldsm-inspect-box" class="ldsm-inspect-box" hidden>
				<h3 class="ldsm-inspect-heading" data-i18n="inspect_title"><?php echo esc_html( $s['inspect_title'] ); ?></h3>

				<div class="ldsm-inspect-grid">
					<div class="ldsm-stat-item">
						<span class="ldsm-stat-label" data-i18n="inspect_source_url"><?php echo esc_html( $s['inspect_source_url'] ); ?></span>
						<strong id="ldsm-ins-source" class="ldsm-stat-value ldsm-ltr"></strong>
					</div>
					<div class="ldsm-stat-item">
						<span class="ldsm-stat-label" data-i18n="inspect_dest_url"><?php echo esc_html( $s['inspect_dest_url'] ); ?></span>
						<strong id="ldsm-ins-dest" class="ldsm-stat-value ldsm-ltr"></strong>
					</div>
					<div class="ldsm-stat-item">
						<span class="ldsm-stat-label" data-i18n="inspect_wp_php"><?php echo esc_html( $s['inspect_wp_php'] ); ?></span>
						<strong id="ldsm-ins-versions" class="ldsm-stat-value ldsm-ltr"></strong>
					</div>
					<div class="ldsm-stat-item">
						<span class="ldsm-stat-label" data-i18n="inspect_files_count"><?php echo esc_html( $s['inspect_files_count'] ); ?></span>
						<strong id="ldsm-ins-files" class="ldsm-stat-value"></strong>
					</div>
					<div class="ldsm-stat-item">
						<span class="ldsm-stat-label" data-i18n="inspect_tables_count"><?php echo esc_html( $s['inspect_tables_count'] ); ?></span>
						<strong id="ldsm-ins-tables" class="ldsm-stat-value"></strong>
					</div>
					<div class="ldsm-stat-item">
						<span class="ldsm-stat-label" data-i18n="inspect_uncompressed_size"><?php echo esc_html( $s['inspect_uncompressed_size'] ); ?></span>
						<strong id="ldsm-ins-size" class="ldsm-stat-value ldsm-ltr"></strong>
					</div>
				</div>

				<div class="ldsm-inspect-options">
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-imp-keep-admin" checked>
						<span data-i18n="opt_keep_current_admin"><?php echo esc_html( $s['opt_keep_current_admin'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-imp-skip-guid">
						<span data-i18n="opt_skip_guid"><?php echo esc_html( $s['opt_skip_guid'] ); ?></span>
					</label>
				</div>

				<div class="ldsm-inspect-actions">
					<button type="button" id="ldsm-confirm-import-btn" class="ldsm-btn ldsm-btn-primary ldsm-btn-lg">
						<span class="dashicons dashicons-database-import"></span>
						<span data-i18n="confirm_import_btn"><?php echo esc_html( $s['confirm_import_btn'] ); ?></span>
					</button>
					<button type="button" id="ldsm-cancel-inspect-btn" class="ldsm-btn ldsm-btn-secondary" data-i18n="cancel_btn">
						<?php echo esc_html( $s['cancel_btn'] ); ?>
					</button>
				</div>
			</div>

			<!-- Import Completed Box -->
			<div id="ldsm-import-done-box" class="ldsm-success-box ldsm-import-complete" hidden>
				<div class="ldsm-success-icon"><span class="dashicons dashicons-saved"></span></div>
				<div class="ldsm-success-body">
					<h3 data-i18n="import_complete_title"><?php echo esc_html( $s['import_complete_title'] ); ?></h3>
					<p data-i18n="import_complete_desc"><?php echo esc_html( $s['import_complete_desc'] ); ?></p>
				</div>
				<div class="ldsm-complete-btns">
					<a id="ldsm-visit-site-link" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener" class="ldsm-btn ldsm-btn-success">
						<span class="dashicons dashicons-external"></span>
						<span data-i18n="visit_site_btn"><?php echo esc_html( $s['visit_site_btn'] ); ?></span>
					</a>
					<a id="ldsm-reload-admin-link" href="<?php echo esc_url( admin_url( 'admin.php?page=libertydev-site-migrator' ) ); ?>" class="ldsm-btn ldsm-btn-secondary">
						<span class="dashicons dashicons-update"></span>
						<span data-i18n="reload_admin_btn"><?php echo esc_html( $s['reload_admin_btn'] ); ?></span>
					</a>
				</div>
			</div>
		</div>
	</section>

	<!-- ==================== TAB 3: BACKUP & SCHEDULE ==================== -->
	<section id="ldsm-panel-schedule" class="ldsm-panel" hidden>

		<!-- Card A: 1-Click Manual Backup -->
		<div class="ldsm-card">
			<div class="ldsm-card-head">
				<h2 data-i18n="manual_backup_title"><?php echo esc_html( $s['manual_backup_title'] ); ?></h2>
				<p data-i18n="manual_backup_desc"><?php echo esc_html( $s['manual_backup_desc'] ); ?></p>
			</div>

			<div class="ldsm-manual-backup-bar">
				<div class="ldsm-field-grow">
					<label for="ldsm-manual-note" class="ldsm-field-label" data-i18n="manual_note_label"><?php echo esc_html( $s['manual_note_label'] ); ?></label>
					<input type="text" id="ldsm-manual-note" class="ldsm-input" maxlength="120" placeholder="<?php echo esc_attr( $s['manual_note_placeholder'] ); ?>">
				</div>
				<button type="button" id="ldsm-manual-backup-btn" class="ldsm-btn ldsm-btn-primary ldsm-btn-lg">
					<span class="dashicons dashicons-backup"></span>
					<span data-i18n="create_manual_backup_btn"><?php echo esc_html( $s['create_manual_backup_btn'] ); ?></span>
				</button>
			</div>
		</div>

		<!-- Card B: Automated Scheduled Backups -->
		<div class="ldsm-card">
			<div class="ldsm-card-head">
				<h2 data-i18n="schedule_card_title"><?php echo esc_html( $s['schedule_card_title'] ); ?></h2>
				<p data-i18n="schedule_card_desc"><?php echo esc_html( $s['schedule_card_desc'] ); ?></p>
			</div>

			<!-- Live Schedule Status Summary Strip -->
			<div class="ldsm-sched-status-strip">
				<div class="ldsm-stat-item">
					<span class="ldsm-stat-label" data-i18n="status_box_state"><?php echo esc_html( $s['status_box_state'] ); ?></span>
					<strong id="ldsm-sched-state-badge" class="ldsm-stat-value"></strong>
				</div>
				<div class="ldsm-stat-item">
					<span class="ldsm-stat-label" data-i18n="status_next_run"><?php echo esc_html( $s['status_next_run'] ); ?></span>
					<strong id="ldsm-sched-next-run" class="ldsm-stat-value ldsm-ltr"></strong>
				</div>
				<div class="ldsm-stat-item">
					<span class="ldsm-stat-label" data-i18n="status_last_run"><?php echo esc_html( $s['status_last_run'] ); ?></span>
					<strong id="ldsm-sched-last-run" class="ldsm-stat-value ldsm-ltr"></strong>
				</div>
			</div>

			<!-- Master Enable Switch -->
			<div class="ldsm-section-block">
				<label class="ldsm-switch-card">
					<input type="checkbox" id="ldsm-sched-enabled" <?php checked( ! empty( $sched['enabled'] ) ); ?>>
					<span class="ldsm-switch-title" data-i18n="sched_enable_label"><?php echo esc_html( $s['sched_enable_label'] ); ?></span>
				</label>
			</div>

			<!-- Schedule Timing & Retention Form Grid -->
			<div class="ldsm-form-grid">
				<div class="ldsm-form-field">
					<label for="ldsm-sched-frequency" class="ldsm-field-label" data-i18n="sched_frequency_label"><?php echo esc_html( $s['sched_frequency_label'] ); ?></label>
					<select id="ldsm-sched-frequency" class="ldsm-select">
						<option value="ldsm_every_6h" <?php selected( $sched['frequency'], 'ldsm_every_6h' ); ?> data-i18n="freq_every_6h"><?php echo esc_html( $s['freq_every_6h'] ); ?></option>
						<option value="ldsm_twicedaily" <?php selected( $sched['frequency'], 'ldsm_twicedaily' ); ?> data-i18n="freq_twicedaily"><?php echo esc_html( $s['freq_twicedaily'] ); ?></option>
						<option value="ldsm_daily" <?php selected( $sched['frequency'], 'ldsm_daily' ); ?> data-i18n="freq_daily"><?php echo esc_html( $s['freq_daily'] ); ?></option>
						<option value="ldsm_weekly" <?php selected( $sched['frequency'], 'ldsm_weekly' ); ?> data-i18n="freq_weekly"><?php echo esc_html( $s['freq_weekly'] ); ?></option>
						<option value="ldsm_biweekly" <?php selected( $sched['frequency'], 'ldsm_biweekly' ); ?> data-i18n="freq_biweekly"><?php echo esc_html( $s['freq_biweekly'] ); ?></option>
						<option value="ldsm_monthly" <?php selected( $sched['frequency'], 'ldsm_monthly' ); ?> data-i18n="freq_monthly"><?php echo esc_html( $s['freq_monthly'] ); ?></option>
					</select>
				</div>

				<div class="ldsm-form-field" id="ldsm-sched-day-wrap">
					<label for="ldsm-sched-day" class="ldsm-field-label" data-i18n="sched_day_label"><?php echo esc_html( $s['sched_day_label'] ); ?></label>
					<select id="ldsm-sched-day" class="ldsm-select">
						<?php for ( $d = 0; $d <= 6; $d++ ) : ?>
							<option value="<?php echo (int) $d; ?>" <?php selected( (int) $sched['day_of_week'], $d ); ?> data-i18n="day_<?php echo (int) $d; ?>">
								<?php echo esc_html( $s[ 'day_' . $d ] ); ?>
							</option>
						<?php endfor; ?>
					</select>
				</div>

				<div class="ldsm-form-field">
					<label for="ldsm-sched-time" class="ldsm-field-label" data-i18n="sched_time_label"><?php echo esc_html( $s['sched_time_label'] ); ?></label>
					<input type="time" id="ldsm-sched-time" class="ldsm-input ldsm-ltr" value="<?php echo esc_attr( $sched['run_time'] ); ?>">
				</div>

				<div class="ldsm-form-field">
					<label for="ldsm-sched-retention" class="ldsm-field-label" data-i18n="sched_retention_label"><?php echo esc_html( $s['sched_retention_label'] ); ?></label>
					<select id="ldsm-sched-retention" class="ldsm-select">
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
			<div class="ldsm-section-block">
				<h3 class="ldsm-section-subtitle" data-i18n="sched_exclusions_title"><?php echo esc_html( $s['sched_exclusions_title'] ); ?></h3>
				<div class="ldsm-checkbox-grid">
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-sch-exclude-cache" <?php checked( ! empty( $sched['exclude_cache'] ) ); ?>>
						<span data-i18n="opt_exclude_cache"><?php echo esc_html( $s['opt_exclude_cache'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-sch-exclude-transients" <?php checked( ! empty( $sched['exclude_transients'] ) ); ?>>
						<span data-i18n="opt_exclude_transients"><?php echo esc_html( $s['opt_exclude_transients'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-sch-exclude-revisions" <?php checked( ! empty( $sched['exclude_revisions'] ) ); ?>>
						<span data-i18n="opt_exclude_revisions"><?php echo esc_html( $s['opt_exclude_revisions'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-sch-exclude-spam" <?php checked( ! empty( $sched['exclude_spam'] ) ); ?>>
						<span data-i18n="opt_exclude_spam"><?php echo esc_html( $s['opt_exclude_spam'] ); ?></span>
					</label>
					<label class="ldsm-check-item">
						<input type="checkbox" id="ldsm-sch-exclude-media" <?php checked( ! empty( $sched['exclude_media'] ) ); ?>>
						<span data-i18n="opt_exclude_media"><?php echo esc_html( $s['opt_exclude_media'] ); ?></span>
					</label>
				</div>
			</div>

			<!-- Email Notification -->
			<div class="ldsm-section-block">
				<div class="ldsm-email-notify-row">
					<label class="ldsm-check-item ldsm-flex-1">
						<input type="checkbox" id="ldsm-sch-notify-enabled" <?php checked( ! empty( $sched['notify_enabled'] ) ); ?>>
						<span data-i18n="sched_notify_label"><?php echo esc_html( $s['sched_notify_label'] ); ?></span>
					</label>
					<input type="email" id="ldsm-sch-notify-email" class="ldsm-input ldsm-ltr ldsm-email-input" value="<?php echo esc_attr( $sched['notify_email'] ); ?>" placeholder="<?php echo esc_attr( $s['sched_email_placeholder'] ); ?>">
				</div>
			</div>

			<div class="ldsm-card-footer ldsm-footer-split">
				<button type="button" id="ldsm-save-schedule-btn" class="ldsm-btn ldsm-btn-primary ldsm-btn-lg">
					<span class="dashicons dashicons-saved"></span>
					<span data-i18n="save_schedule_btn"><?php echo esc_html( $s['save_schedule_btn'] ); ?></span>
				</button>

				<button type="button" id="ldsm-run-sched-test-btn" class="ldsm-btn ldsm-btn-secondary">
					<span class="dashicons dashicons-controls-play"></span>
					<span data-i18n="run_scheduled_now_btn"><?php echo esc_html( $s['run_scheduled_now_btn'] ); ?></span>
				</button>
			</div>
		</div>
	</section>

	<!-- ==================== TAB 4: BACKUPS VAULT ==================== -->
	<section id="ldsm-panel-backups" class="ldsm-panel" hidden>
		<div class="ldsm-card">
			<div class="ldsm-card-head">
				<h2 data-i18n="backups_card_title"><?php echo esc_html( $s['backups_card_title'] ); ?></h2>
				<p data-i18n="backups_card_desc"><?php echo esc_html( $s['backups_card_desc'] ); ?></p>
			</div>

			<div class="ldsm-table-wrap">
				<table class="ldsm-table" id="ldsm-backups-table">
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
					<tbody id="ldsm-backups-tbody"></tbody>
				</table>
				<div id="ldsm-backups-empty" class="ldsm-empty-state" hidden data-i18n="empty_backups">
					<?php echo esc_html( $s['empty_backups'] ); ?>
				</div>
			</div>
		</div>
	</section>

</div>
