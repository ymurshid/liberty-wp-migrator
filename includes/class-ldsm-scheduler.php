<?php
/**
 * Automated Scheduled Backup Engine & Chunked WP-Cron Runner — LibertyDev Site Migrator.
 *
 * Supports 6-hour, 12-hour, daily, weekly, bi-weekly, and monthly automated backups,
 * executing in time-boxed background slices with automatic retention pruning & email notifications.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LDSM_Scheduler {

	const HOOK_TRIGGER  = 'ldsm_run_scheduled_backup';
	const HOOK_CONTINUE = 'ldsm_continue_scheduled_backup';
	const OPT_SETTINGS  = 'ldsm_schedule_settings';
	const OPT_STATUS    = 'ldsm_schedule_status';
	const LOCK_KEY      = 'ldsm_bg_runner_lock';

	/**
	 * Register cron schedules and action hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_intervals' ) );
		add_action( self::HOOK_TRIGGER, array( __CLASS__, 'handle_scheduled_trigger' ) );
		add_action( self::HOOK_CONTINUE, array( __CLASS__, 'handle_continue_backup' ), 10, 1 );
	}

	/**
	 * Register custom backup intervals in WP-Cron.
	 *
	 * @param array $schedules Existing WP-Cron schedules.
	 * @return array
	 */
	public static function register_cron_intervals( $schedules ) {
		$schedules['ldsm_every_6h'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 Hours (LibertyDev Site Migrator)', 'libertydev-site-migrator' ),
		);
		$schedules['ldsm_twicedaily'] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 12 Hours (LibertyDev Site Migrator)', 'libertydev-site-migrator' ),
		);
		$schedules['ldsm_daily'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Daily (LibertyDev Site Migrator)', 'libertydev-site-migrator' ),
		);
		$schedules['ldsm_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly (LibertyDev Site Migrator)', 'libertydev-site-migrator' ),
		);
		$schedules['ldsm_biweekly'] = array(
			'interval' => 2 * WEEK_IN_SECONDS,
			'display'  => __( 'Every 2 Weeks (LibertyDev Site Migrator)', 'libertydev-site-migrator' ),
		);
		$schedules['ldsm_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Monthly / 30 Days (LibertyDev Site Migrator)', 'libertydev-site-migrator' ),
		);
		return $schedules;
	}

	/**
	 * Default schedule configuration.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_default_settings() {
		return array(
			'enabled'            => false,
			'frequency'          => 'ldsm_daily',
			'day_of_week'        => 5,
			'run_time'           => '03:00',
			'retention'          => 5,
			'exclude_cache'      => true,
			'exclude_transients' => true,
			'exclude_revisions'  => false,
			'exclude_spam'       => true,
			'exclude_media'      => false,
			'notify_enabled'     => false,
			'notify_email'       => (string) get_option( 'admin_email' ),
		);
	}

	/**
	 * Load sanitized schedule settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_settings() {
		$saved = get_option( self::OPT_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::get_default_settings(), $saved );
	}

	/**
	 * Validate, persist, and synchronize schedule settings with WP-Cron.
	 *
	 * @param array $input Sanitized user settings from AJAX.
	 * @return array<string,mixed> Updated status payload for UI.
	 */
	public static function update_settings( array $input ) {
		$valid_freqs = array(
			'ldsm_every_6h',
			'ldsm_twicedaily',
			'ldsm_daily',
			'ldsm_weekly',
			'ldsm_biweekly',
			'ldsm_monthly',
		);

		$freq = isset( $input['frequency'] ) ? sanitize_key( (string) $input['frequency'] ) : 'ldsm_daily';
		if ( ! in_array( $freq, $valid_freqs, true ) ) {
			$freq = 'ldsm_daily';
		}

		$day = isset( $input['day_of_week'] ) ? (int) $input['day_of_week'] : 5;
		if ( $day < 0 || $day > 6 ) {
			$day = 5;
		}

		$run_time = isset( $input['run_time'] ) ? sanitize_text_field( trim( (string) $input['run_time'] ) ) : '03:00';
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $run_time ) ) {
			$run_time = '03:00';
		}

		$retention = isset( $input['retention'] ) ? max( 0, min( 100, (int) $input['retention'] ) ) : 5;

		$email = isset( $input['notify_email'] ) ? sanitize_email( (string) $input['notify_email'] ) : '';
		if ( '' === $email ) {
			$email = (string) get_option( 'admin_email' );
		}

		$clean = array(
			'enabled'            => ! empty( $input['enabled'] ),
			'frequency'          => $freq,
			'day_of_week'        => $day,
			'run_time'           => $run_time,
			'retention'          => $retention,
			'exclude_cache'      => ! empty( $input['exclude_cache'] ),
			'exclude_transients' => ! empty( $input['exclude_transients'] ),
			'exclude_revisions'  => ! empty( $input['exclude_revisions'] ),
			'exclude_spam'       => ! empty( $input['exclude_spam'] ),
			'exclude_media'      => ! empty( $input['exclude_media'] ),
			'notify_enabled'     => ! empty( $input['notify_enabled'] ),
			'notify_email'       => $email,
		);

		update_option( self::OPT_SETTINGS, $clean, false );
		self::sync_cron_with_settings( $clean );

		if ( $clean['retention'] > 0 ) {
			LDSM_Storage::enforce_scheduled_retention( $clean['retention'] );
		}

		return self::get_status_payload();
	}

	/**
	 * Synchronize WordPress WP-Cron event with saved settings.
	 *
	 * @param array|null $settings Optional preloaded settings.
	 */
	public static function sync_cron_with_settings( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = self::get_settings();
		}

		wp_clear_scheduled_hook( self::HOOK_TRIGGER );

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$first_ts = self::compute_next_timestamp(
			(string) $settings['frequency'],
			(string) $settings['run_time'],
			(int) $settings['day_of_week']
		);

		wp_schedule_event( $first_ts, (string) $settings['frequency'], self::HOOK_TRIGGER );
	}

	/**
	 * Unschedule all recurring & single continuation cron hooks.
	 */
	public static function clear_scheduled_hooks() {
		wp_clear_scheduled_hook( self::HOOK_TRIGGER );
		wp_clear_scheduled_hook( self::HOOK_CONTINUE );
	}

	/**
	 * Compute exact Unix timestamp for the next scheduled run in WordPress's configured local timezone.
	 *
	 * @param string $frequency   Cron schedule key.
	 * @param string $run_time    'HH:MM' in 24-hour format.
	 * @param int    $day_of_week 0 (Sunday) .. 6 (Saturday).
	 * @return int Unix UTC timestamp.
	 */
	public static function compute_next_timestamp( $frequency, $run_time, $day_of_week ) {
		$parts = explode( ':', $run_time );
		$hour  = isset( $parts[0] ) ? (int) $parts[0] : 3;
		$min   = isset( $parts[1] ) ? (int) $parts[1] : 0;

		try {
			$tz        = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			$now       = new DateTimeImmutable( 'now', $tz );
			$candidate = $now->setTime( $hour, $min, 0 );

			if ( 'ldsm_weekly' === $frequency ) {
				$curr_w    = (int) $now->format( 'w' );
				$days_diff = ( $day_of_week - $curr_w + 7 ) % 7;
				$candidate = $candidate->modify( '+' . $days_diff . ' days' );
				if ( $candidate->getTimestamp() <= ( time() + 60 ) ) {
					$candidate = $candidate->modify( '+7 days' );
				}
				return $candidate->getTimestamp();
			}

			if ( 'ldsm_every_6h' === $frequency || 'ldsm_twicedaily' === $frequency ) {
				$step_hours = ( 'ldsm_every_6h' === $frequency ) ? 6 : 12;
				while ( $candidate->getTimestamp() <= ( time() + 60 ) ) {
					$candidate = $candidate->modify( '+' . $step_hours . ' hours' );
				}
				return $candidate->getTimestamp();
			}

			if ( $candidate->getTimestamp() <= ( time() + 60 ) ) {
				$candidate = $candidate->modify( '+1 day' );
			}
			return $candidate->getTimestamp();
		} catch ( Exception $e ) {
			return time() + 3600;
		}
	}

	/**
	 * Return full schedule settings & runtime status payload for the Admin UI.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_status_payload() {
		$settings = self::get_settings();
		$status   = get_option( self::OPT_STATUS, array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}

		$next_ts = wp_next_scheduled( self::HOOK_TRIGGER );
		if ( ! empty( $settings['enabled'] ) && ! $next_ts ) {
			self::sync_cron_with_settings( $settings );
			$next_ts = wp_next_scheduled( self::HOOK_TRIGGER );
		}

		$next_human = '';
		if ( ! empty( $settings['enabled'] ) && $next_ts ) {
			$next_human = wp_date( 'Y-m-d H:i', (int) $next_ts );
		}

		$last_human = '';
		if ( ! empty( $status['last_run_ts'] ) ) {
			$last_human = wp_date( 'Y-m-d H:i', (int) $status['last_run_ts'] );
			if ( ! empty( $status['last_size_human'] ) ) {
				$last_human .= ' (' . $status['last_size_human'] . ')';
			}
		}

		return array(
			'settings'     => $settings,
			'next_run_ts'  => $next_ts ? (int) $next_ts : 0,
			'next_run_str' => $next_human,
			'last_run_str' => $last_human,
			'last_status'  => isset( $status['last_status'] ) ? (string) $status['last_status'] : '',
			'last_error'   => isset( $status['last_error'] ) ? (string) $status['last_error'] : '',
		);
	}

	/**
	 * WP-Cron entry point: initiate a new automated scheduled backup in the background.
	 */
	public static function handle_scheduled_trigger() {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, 1, 90 );

		$export_opts = array(
			'exclude_cache'         => ! empty( $settings['exclude_cache'] ),
			'exclude_transients'    => ! empty( $settings['exclude_transients'] ),
			'exclude_revisions'     => ! empty( $settings['exclude_revisions'] ),
			'exclude_spam'          => ! empty( $settings['exclude_spam'] ),
			'exclude_media'         => ! empty( $settings['exclude_media'] ),
			'exclude_themes'        => false,
			'exclude_plugins'       => false,
			'include_custom_tables' => false,
		);

		$note  = 'Automated (' . str_replace( 'ldsm_', '', (string) $settings['frequency'] ) . ')';
		$start = LDSM_Exporter::start_job( $export_opts, 'scheduled', $note );

		delete_transient( self::LOCK_KEY );

		if ( is_wp_error( $start ) ) {
			self::record_run_outcome( false, '', '', $start->get_error_message() );
			return;
		}

		self::process_background_slices( (string) $start['job_id'] );
	}

	/**
	 * WP-Cron continuation hook: advance an in-progress background scheduled backup.
	 *
	 * @param string $job_id Scheduled export job ID.
	 */
	public static function handle_continue_backup( $job_id ) {
		if ( ! LDSM_Storage::is_valid_job_id( $job_id ) ) {
			return;
		}
		self::process_background_slices( $job_id );
	}

	/**
	 * Run up to ~10 seconds of export ticks in background, chaining continuation via WP-Cron if needed.
	 *
	 * @param string $job_id Job ID.
	 */
	private static function process_background_slices( $job_id ) {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, 1, 90 );

		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}

		$slice_end = microtime( true ) + 9.5;
		$finished  = false;

		while ( microtime( true ) < $slice_end ) {
			$tick = LDSM_Exporter::run_tick( $job_id, 3.5 );

			if ( is_wp_error( $tick ) ) {
				delete_transient( self::LOCK_KEY );
				self::record_run_outcome( false, '', '', $tick->get_error_message() );
				return;
			}

			if ( ! empty( $tick['done'] ) ) {
				$finished = true;
				delete_transient( self::LOCK_KEY );
				self::record_run_outcome(
					true,
					isset( $tick['filename'] ) ? (string) $tick['filename'] : '',
					isset( $tick['size_human'] ) ? (string) $tick['size_human'] : '',
					''
				);
				return;
			}
		}

		delete_transient( self::LOCK_KEY );

		if ( ! $finished ) {
			if ( ! wp_next_scheduled( self::HOOK_CONTINUE, array( $job_id ) ) ) {
				wp_schedule_single_event( time() + 2, self::HOOK_CONTINUE, array( $job_id ) );
			}
			if ( function_exists( 'spawn_cron' ) ) {
				@spawn_cron();
			}
		}
	}

	/**
	 * Record last scheduled backup status & send optional notification email (zero credit/promotional links per Guideline 10).
	 *
	 * @param bool   $ok         Whether backup succeeded.
	 * @param string $filename   Archive filename.
	 * @param string $size_human Formatted size.
	 * @param string $error_msg  Error message on failure.
	 */
	public static function record_run_outcome( $ok, $filename = '', $size_human = '', $error_msg = '' ) {
		$status = array(
			'last_run_ts'     => time(),
			'last_status'     => $ok ? 'success' : 'error',
			'last_filename'   => $filename,
			'last_size_human' => $size_human,
			'last_error'      => $error_msg,
		);
		update_option( self::OPT_STATUS, $status, false );

		$settings = self::get_settings();
		if ( ! empty( $settings['notify_enabled'] ) && ! empty( $settings['notify_email'] ) && is_email( $settings['notify_email'] ) ) {
			$site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			if ( $ok ) {
				$subject = sprintf( '[%s] Scheduled Backup Completed', $site_name );
				$body    = "Hello,\n\n";
				$body   .= "Your automated scheduled site backup has completed successfully.\n\n";
				$body   .= 'Site URL: ' . home_url() . "\n";
				$body   .= 'Archive File: ' . $filename . "\n";
				$body   .= 'Archive Size: ' . $size_human . "\n";
				$body   .= 'Completed At: ' . wp_date( 'Y-m-d H:i:s' ) . "\n\n";
				$body   .= 'Manage Backups: ' . admin_url( 'admin.php?page=libertydev-site-migrator' ) . "\n";
			} else {
				$subject = sprintf( '[%s] Scheduled Backup Failed', $site_name );
				$body    = "Hello,\n\n";
				$body   .= "An error occurred while running your automated scheduled site backup:\n\n";
				$body   .= 'Error: ' . $error_msg . "\n";
				$body   .= 'Site URL: ' . home_url() . "\n";
			}
			@wp_mail( $settings['notify_email'], $subject, $body );
		}
	}
}
