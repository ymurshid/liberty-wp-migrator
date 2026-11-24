<?php
/**
 * WordPress Admin AJAX Controller & Resumable HTTP Range Backup Streamer — LibertyDev Site Migrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LDSM_Ajax {

	/**
	 * Register all wp_ajax_* hooks.
	 */
	public static function init() {
		$actions = array(
			'ldsm_set_lang'            => 'handle_set_lang',
			'ldsm_export_start'        => 'handle_export_start',
			'ldsm_export_tick'         => 'handle_export_tick',
			'ldsm_manual_backup_start' => 'handle_manual_backup_start',
			'ldsm_save_schedule'       => 'handle_save_schedule',
			'ldsm_run_scheduled_test'  => 'handle_run_scheduled_test',
			'ldsm_import_init_upload'  => 'handle_import_init_upload',
			'ldsm_import_upload_chunk' => 'handle_import_upload_chunk',
			'ldsm_import_inspect'      => 'handle_import_inspect',
			'ldsm_import_from_backup'  => 'handle_import_from_backup',
			'ldsm_import_confirm'      => 'handle_import_confirm',
			'ldsm_import_tick'         => 'handle_import_tick',
			'ldsm_cancel_job'          => 'handle_cancel_job',
			'ldsm_list_backups'        => 'handle_list_backups',
			'ldsm_delete_backup'       => 'handle_delete_backup',
			'ldsm_download_backup'     => 'handle_download_backup',
		);

		foreach ( $actions as $hook => $method ) {
			add_action( 'wp_ajax_' . $hook, array( __CLASS__, $method ) );
		}
	}

	/**
	 * Verify admin capability and AJAX nonce.
	 */
	private static function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		if ( ! check_ajax_referer( 'ldsm_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Security token expired. Please reload the page and try again.' ), 403 );
		}
	}

	/**
	 * Decode a sanitized JSON payload string and recursively sanitize all keys and scalar string values.
	 *
	 * @param string $raw_json Sanitized JSON string.
	 * @return array<string|int,mixed>
	 */
	private static function decode_and_sanitize_json_array( $raw_json ) {
		$decoded = json_decode( (string) $raw_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return self::sanitize_array_recursive( $decoded );
	}

	/**
	 * Recursively sanitize decoded JSON array keys and values while preserving booleans and numbers.
	 *
	 * @param array $data Raw decoded array.
	 * @return array
	 */
	private static function sanitize_array_recursive( array $data ) {
		$clean = array();
		foreach ( $data as $key => $val ) {
			$clean_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
			if ( is_array( $val ) ) {
				$clean[ $clean_key ] = self::sanitize_array_recursive( $val );
			} elseif ( is_bool( $val ) || is_int( $val ) || is_float( $val ) ) {
				$clean[ $clean_key ] = $val;
			} elseif ( is_string( $val ) ) {
				$clean[ $clean_key ] = sanitize_text_field( $val );
			}
		}
		return $clean;
	}

	/**
	 * Send standardized WP_Error or success JSON response.
	 *
	 * @param mixed $result Result payload or WP_Error.
	 */
	private static function send_result( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		}
		wp_send_json_success( $result );
	}

	/**
	 * Persist UI language preference ('ar' or 'en') for current administrator.
	 */
	public static function handle_set_lang() {
		self::verify_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : 'en';
		if ( ! in_array( $lang, array( 'ar', 'en' ), true ) ) {
			$lang = 'en';
		}
		update_user_meta( get_current_user_id(), 'ldsm_preferred_lang', $lang );
		wp_send_json_success( array( 'lang' => $lang ) );
	}

	/**
	 * Start a new Export job.
	 */
	public static function handle_export_start() {
		self::verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$raw_json = isset( $_POST['options'] ) ? sanitize_textarea_field( wp_unslash( $_POST['options'] ) ) : '{}';
		$options  = self::decode_and_sanitize_json_array( $raw_json );

		self::send_result( LDSM_Exporter::start_job( $options, 'export', '' ) );
	}

	/**
	 * Start a 1-click Manual Backup job into the Backups Vault.
	 */
	public static function handle_manual_backup_start() {
		self::verify_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

		$opts = array(
			'exclude_cache'         => true,
			'exclude_transients'    => true,
			'exclude_revisions'     => false,
			'exclude_spam'          => false,
			'exclude_media'         => false,
			'exclude_themes'        => false,
			'exclude_plugins'       => false,
			'include_custom_tables' => false,
		);

		self::send_result( LDSM_Exporter::start_job( $opts, 'manual', $note ) );
	}

	/**
	 * Save automated backup schedule settings and return updated schedule status + backups list.
	 */
	public static function handle_save_schedule() {
		self::verify_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$raw_json = isset( $_POST['schedule'] ) ? sanitize_textarea_field( wp_unslash( $_POST['schedule'] ) ) : '{}';
		$decoded  = self::decode_and_sanitize_json_array( $raw_json );

		$status = LDSM_Scheduler::update_settings( $decoded );
		wp_send_json_success(
			array(
				'schedule' => $status,
				'backups'  => LDSM_Storage::list_backups(),
			)
		);
	}

	/**
	 * Start an immediate interactive test run using the user's Scheduled Backup rules.
	 */
	public static function handle_run_scheduled_test() {
		self::verify_request();
		$settings = LDSM_Scheduler::get_settings();

		$opts = array(
			'exclude_cache'         => ! empty( $settings['exclude_cache'] ),
			'exclude_transients'    => ! empty( $settings['exclude_transients'] ),
			'exclude_revisions'     => ! empty( $settings['exclude_revisions'] ),
			'exclude_spam'          => ! empty( $settings['exclude_spam'] ),
			'exclude_media'         => ! empty( $settings['exclude_media'] ),
			'exclude_themes'        => false,
			'exclude_plugins'       => false,
			'include_custom_tables' => false,
		);

		$note = 'Scheduled (' . str_replace( 'ldsm_', '', (string) $settings['frequency'] ) . ')';
		self::send_result( LDSM_Exporter::start_job( $opts, 'scheduled', $note ) );
	}

	/**
	 * Advance an Export / Manual Backup / Scheduled Test job by one tick.
	 */
	public static function handle_export_tick() {
		self::verify_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$res    = LDSM_Exporter::run_tick( $job_id );

		if ( is_array( $res ) && ! empty( $res['done'] ) && isset( $res['trigger'] ) && 'scheduled' === $res['trigger'] ) {
			LDSM_Scheduler::record_run_outcome(
				true,
				isset( $res['filename'] ) ? (string) $res['filename'] : '',
				isset( $res['size_human'] ) ? (string) $res['size_human'] : '',
				''
			);
			$res['schedule'] = LDSM_Scheduler::get_status_payload();
		}

		self::send_result( $res );
	}

	/**
	 * Initialize a chunked .zip upload Import job.
	 */
	public static function handle_import_init_upload() {
		self::verify_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$total_size = isset( $_POST['total_size'] ) ? absint( wp_unslash( $_POST['total_size'] ) ) : 0;

		self::send_result( LDSM_Importer::init_upload_job( $filename, $total_size ) );
	}

	/**
	 * Append one binary upload slice to package.zip.
	 */
	public static function handle_import_upload_chunk() {
		self::verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$upload_err = isset( $_FILES['chunk']['error'] ) ? absint( $_FILES['chunk']['error'] ) : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $upload_err ) {
			wp_send_json_error(
				array(
					'message' => 'PHP file upload error code: ' . $upload_err,
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- System upload path verified via is_uploaded_file(); wp_unslash() would corrupt Windows temp paths.
		$tmp_path = isset( $_FILES['chunk']['tmp_name'] ) && is_string( $_FILES['chunk']['tmp_name'] ) ? $_FILES['chunk']['tmp_name'] : '';
		if ( '' === $tmp_path || ! is_uploaded_file( $tmp_path ) ) {
			wp_send_json_error( array( 'message' => 'Invalid or missing uploaded chunk payload.' ) );
		}

		self::send_result( LDSM_Importer::receive_upload_chunk( $job_id, $offset, $tmp_path ) );
	}

	/**
	 * Inspect uploaded .zip archive and return manifest summary.
	 */
	public static function handle_import_inspect() {
		self::verify_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		self::send_result( LDSM_Importer::inspect_package( $job_id ) );
	}

	/**
	 * Initialize & inspect an Import job from an existing server backup archive.
	 */
	public static function handle_import_from_backup() {
		self::verify_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		self::send_result( LDSM_Importer::init_from_stored_backup( $filename ) );
	}

	/**
	 * Confirm pre-flight summary and begin extraction + database import.
	 */
	public static function handle_import_confirm() {
		self::verify_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$raw_json = isset( $_POST['import_options'] ) ? sanitize_textarea_field( wp_unslash( $_POST['import_options'] ) ) : '{}';
		$opts     = self::decode_and_sanitize_json_array( $raw_json );

		self::send_result( LDSM_Importer::start_confirmed_import( $job_id, $opts ) );
	}

	/**
	 * Advance an Import job by one tick.
	 */
	public static function handle_import_tick() {
		self::verify_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$res    = LDSM_Importer::run_tick( $job_id );
		if ( is_array( $res ) && ! empty( $res['done'] ) ) {
			$res['new_nonce'] = wp_create_nonce( 'ldsm_ajax_nonce' );
		}
		self::send_result( $res );
	}

	/**
	 * Cancel an active Export, Backup, or Import job cleanly.
	 */
	public static function handle_cancel_job() {
		self::verify_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( ! LDSM_Storage::is_valid_job_id( $job_id ) ) {
			wp_send_json_success( array( 'cancelled' => true ) );
		}

		$state = LDSM_Storage::load_job_state( $job_id );
		if ( is_array( $state ) ) {
			if ( isset( $state['type'] ) && 'export' === $state['type'] ) {
				LDSM_Exporter::abort_export_files( $state );
				LDSM_Storage::delete_job( $job_id );
			} else {
				LDSM_Importer::cleanup_failed_import( $job_id, $state );
			}
		} else {
			LDSM_Storage::delete_job( $job_id );
		}

		wp_send_json_success( array( 'cancelled' => true ) );
	}

	/**
	 * Return updated list of saved server backups & schedule status.
	 */
	public static function handle_list_backups() {
		self::verify_request();
		wp_send_json_success(
			array(
				'backups'  => LDSM_Storage::list_backups(),
				'schedule' => LDSM_Scheduler::get_status_payload(),
			)
		);
	}

	/**
	 * Delete a saved server backup.
	 */
	public static function handle_delete_backup() {
		self::verify_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in self::verify_request().
		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		$res      = LDSM_Storage::delete_backup( $filename );
		if ( is_wp_error( $res ) ) {
			self::send_result( $res );
		}
		wp_send_json_success( array( 'backups' => LDSM_Storage::list_backups() ) );
	}

	/**
	 * Stream a backup .zip file to the browser with full HTTP Range support.
	 */
	public static function handle_download_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 'Forbidden', array( 'response' => 403 ) );
		}

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		if ( ! LDSM_Storage::is_valid_backup_filename( $filename ) ) {
			wp_die( 'Invalid backup filename.', 'Bad Request', array( 'response' => 400 ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ldsm_download_' . $filename ) ) {
			wp_die( 'Download link expired. Please refresh the LibertyDev Site Migrator page.', 'Forbidden', array( 'response' => 403 ) );
		}

		$filepath = LDSM_Storage::resolve_backup_disk_path( $filename );
		if ( '' === $filepath || ! is_file( $filepath ) || is_link( $filepath ) || ! is_readable( $filepath ) ) {
			wp_die( 'Backup archive not found.', 'Not Found', array( 'response' => 404 ) );
		}

		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}

		clearstatcache( true, $filepath );
		$guard_len = LDSM_Storage::detect_php_guard_length( $filepath );
		$disk_size = (int) @filesize( $filepath );
		$size      = max( 0, $disk_size - $guard_len );
		$start     = 0;
		$end       = max( 0, $size - 1 );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' );
		header( 'Accept-Ranges: bytes' );

		if ( isset( $_SERVER['HTTP_RANGE'] ) && $size > 0 ) {
			$range_hdr = sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) );
			if ( preg_match( '/bytes=(\d*)-(\d*)/i', $range_hdr, $matches ) ) {
				$req_start = '' !== $matches[1] ? (int) $matches[1] : 0;
				$req_end   = '' !== $matches[2] ? (int) $matches[2] : $end;

				if ( $req_start <= $req_end && $req_start < $size ) {
					$start = $req_start;
					$end   = min( $end, $req_end );
					status_header( 206 );
					header( "Content-Range: bytes {$start}-{$end}/{$size}" );
				}
			}
		}

		$length = ( $size > 0 ) ? ( $end - $start + 1 ) : 0;
		header( 'Content-Length: ' . $length );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming binary HTTP 206 Range download without buffering in PHP memory.
		$fp = @fopen( $filepath, 'rb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = @fopen( 'php://output', 'wb' );
		if ( ! $fp || ! $out ) {
			if ( $fp ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
			}
			exit;
		}
		if ( ( $guard_len + $start ) > 0 ) {
			@fseek( $fp, $guard_len + $start, SEEK_SET );
		}

		$remaining = $length;
		while ( $remaining > 0 && ! feof( $fp ) && ! connection_aborted() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$buf = fread( $fp, min( 1024 * 1024, $remaining ) );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $out, $buf );
			flush();
			$remaining -= strlen( $buf );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );
		exit;
	}
}
