<?php
/**
 * WordPress Admin AJAX Controller & Resumable HTTP Range Backup Streamer — Liberty WP Migrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWPM_Ajax {

	/**
	 * Register all wp_ajax_* hooks.
	 */
	public static function init() {
		$actions = array(
			'lwpm_set_lang'            => 'handle_set_lang',
			'lwpm_export_start'        => 'handle_export_start',
			'lwpm_export_tick'         => 'handle_export_tick',
			'lwpm_manual_backup_start' => 'handle_manual_backup_start',
			'lwpm_save_schedule'       => 'handle_save_schedule',
			'lwpm_run_scheduled_test'  => 'handle_run_scheduled_test',
			'lwpm_import_init_upload'  => 'handle_import_init_upload',
			'lwpm_import_upload_chunk' => 'handle_import_upload_chunk',
			'lwpm_import_inspect'      => 'handle_import_inspect',
			'lwpm_import_from_backup'  => 'handle_import_from_backup',
			'lwpm_import_confirm'      => 'handle_import_confirm',
			'lwpm_import_tick'         => 'handle_import_tick',
			'lwpm_cancel_job'          => 'handle_cancel_job',
			'lwpm_list_backups'        => 'handle_list_backups',
			'lwpm_delete_backup'       => 'handle_delete_backup',
			'lwpm_download_backup'     => 'handle_download_backup',
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
		if ( ! check_ajax_referer( 'lwpm_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Security token expired. Please reload the page and try again.' ), 403 );
		}
	}

	/**
	 * Send standardized WP_Error or success JSON response.
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
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : 'en';
		if ( ! in_array( $lang, array( 'ar', 'en' ), true ) ) {
			$lang = 'en';
		}
		update_user_meta( get_current_user_id(), 'lwpm_preferred_lang', $lang );
		wp_send_json_success( array( 'lang' => $lang ) );
	}

	/**
	 * Start a new Export job.
	 */
	public static function handle_export_start() {
		self::verify_request();

		$raw_json = isset( $_POST['options'] ) ? (string) wp_unslash( $_POST['options'] ) : '{}';
		$options  = json_decode( $raw_json, true );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		self::send_result( LWPM_Exporter::start_job( $options, 'export', '' ) );
	}

	/**
	 * Start a 1-click Manual Backup job into the Backups Vault.
	 */
	public static function handle_manual_backup_start() {
		self::verify_request();
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

		self::send_result( LWPM_Exporter::start_job( $opts, 'manual', $note ) );
	}

	/**
	 * Save automated backup schedule settings and return updated schedule status + backups list.
	 */
	public static function handle_save_schedule() {
		self::verify_request();
		$raw_json = isset( $_POST['schedule'] ) ? (string) wp_unslash( $_POST['schedule'] ) : '{}';
		$decoded  = json_decode( $raw_json, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		$status = LWPM_Scheduler::update_settings( $decoded );
		wp_send_json_success(
			array(
				'schedule' => $status,
				'backups'  => LWPM_Storage::list_backups(),
			)
		);
	}

	/**
	 * Start an immediate interactive test run using the user's Scheduled Backup rules.
	 */
	public static function handle_run_scheduled_test() {
		self::verify_request();
		$settings = LWPM_Scheduler::get_settings();

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

		$note = 'Scheduled (' . str_replace( 'lwpm_', '', (string) $settings['frequency'] ) . ')';
		self::send_result( LWPM_Exporter::start_job( $opts, 'scheduled', $note ) );
	}

	/**
	 * Advance an Export / Manual Backup / Scheduled Test job by one tick.
	 */
	public static function handle_export_tick() {
		self::verify_request();
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$res    = LWPM_Exporter::run_tick( $job_id );

		if ( is_array( $res ) && ! empty( $res['done'] ) && isset( $res['trigger'] ) && 'scheduled' === $res['trigger'] ) {
			LWPM_Scheduler::record_run_outcome(
				true,
				isset( $res['filename'] ) ? (string) $res['filename'] : '',
				isset( $res['size_human'] ) ? (string) $res['size_human'] : '',
				''
			);
			$res['schedule'] = LWPM_Scheduler::get_status_payload();
		}

		self::send_result( $res );
	}

	/**
	 * Initialize a chunked .zip upload Import job.
	 */
	public static function handle_import_init_upload() {
		self::verify_request();
		$filename   = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		$total_size = isset( $_POST['total_size'] ) ? (int) $_POST['total_size'] : 0;

		self::send_result( LWPM_Importer::init_upload_job( $filename, $total_size ) );
	}

	/**
	 * Append one binary upload slice to package.zip.
	 */
	public static function handle_import_upload_chunk() {
		self::verify_request();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( empty( $_FILES['chunk'] ) || ! isset( $_FILES['chunk']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => 'Missing binary chunk file in upload request.' ) );
		}

		if ( ! empty( $_FILES['chunk']['error'] ) && UPLOAD_ERR_OK !== (int) $_FILES['chunk']['error'] ) {
			wp_send_json_error(
				array(
					'message' => 'PHP file upload error code: ' . (int) $_FILES['chunk']['error'],
				)
			);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tmp_path = (string) $_FILES['chunk']['tmp_name'];
		self::send_result( LWPM_Importer::receive_upload_chunk( $job_id, $offset, $tmp_path ) );
	}

	/**
	 * Inspect uploaded .zip archive and return manifest summary.
	 */
	public static function handle_import_inspect() {
		self::verify_request();
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		self::send_result( LWPM_Importer::inspect_package( $job_id ) );
	}

	/**
	 * Initialize & inspect an Import job from an existing server backup archive.
	 */
	public static function handle_import_from_backup() {
		self::verify_request();
		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		self::send_result( LWPM_Importer::init_from_stored_backup( $filename ) );
	}

	/**
	 * Confirm pre-flight summary and begin extraction + database import.
	 */
	public static function handle_import_confirm() {
		self::verify_request();
		$job_id   = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$raw_json = isset( $_POST['import_options'] ) ? (string) wp_unslash( $_POST['import_options'] ) : '{}';
		$opts     = json_decode( $raw_json, true );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}

		self::send_result( LWPM_Importer::start_confirmed_import( $job_id, $opts ) );
	}

	/**
	 * Advance an Import job by one tick.
	 */
	public static function handle_import_tick() {
		self::verify_request();
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$res    = LWPM_Importer::run_tick( $job_id );
		if ( is_array( $res ) && ! empty( $res['done'] ) ) {
			$res['new_nonce'] = wp_create_nonce( 'lwpm_ajax_nonce' );
		}
		self::send_result( $res );
	}

	/**
	 * Cancel an active Export, Backup, or Import job cleanly.
	 */
	public static function handle_cancel_job() {
		self::verify_request();
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( ! LWPM_Storage::is_valid_job_id( $job_id ) ) {
			wp_send_json_success( array( 'cancelled' => true ) );
		}

		$state = LWPM_Storage::load_job_state( $job_id );
		if ( is_array( $state ) ) {
			if ( isset( $state['type'] ) && 'export' === $state['type'] ) {
				LWPM_Exporter::abort_export_files( $state );
				LWPM_Storage::delete_job( $job_id );
			} else {
				LWPM_Importer::cleanup_failed_import( $job_id, $state );
			}
		} else {
			LWPM_Storage::delete_job( $job_id );
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
				'backups'  => LWPM_Storage::list_backups(),
				'schedule' => LWPM_Scheduler::get_status_payload(),
			)
		);
	}

	/**
	 * Delete a saved server backup.
	 */
	public static function handle_delete_backup() {
		self::verify_request();
		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		$res      = LWPM_Storage::delete_backup( $filename );
		if ( is_wp_error( $res ) ) {
			self::send_result( $res );
		}
		wp_send_json_success( array( 'backups' => LWPM_Storage::list_backups() ) );
	}

	/**
	 * Stream a backup .zip file to the browser with full HTTP Range support.
	 */
	public static function handle_download_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 'Forbidden', array( 'response' => 403 ) );
		}

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		if ( ! LWPM_Storage::is_valid_backup_filename( $filename ) ) {
			wp_die( 'Invalid backup filename.', 'Bad Request', array( 'response' => 400 ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lwpm_download_' . $filename ) ) {
			wp_die( 'Download link expired. Please refresh the Liberty Migrator page.', 'Forbidden', array( 'response' => 403 ) );
		}

		$filepath = LWPM_Storage::get_backups_dir() . '/' . $filename;
		if ( ! is_file( $filepath ) || ! is_readable( $filepath ) ) {
			wp_die( 'Backup archive not found.', 'Not Found', array( 'response' => 404 ) );
		}

		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}

		clearstatcache( true, $filepath );
		$size  = (int) @filesize( $filepath );
		$start = 0;
		$end   = max( 0, $size - 1 );

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

		$fp  = @fopen( $filepath, 'rb' );
		$out = @fopen( 'php://output', 'wb' );
		if ( ! $fp || ! $out ) {
			if ( $fp ) {
				fclose( $fp );
			}
			exit;
		}
		if ( $start > 0 ) {
			@fseek( $fp, $start, SEEK_SET );
		}

		$remaining = $length;
		while ( $remaining > 0 && ! feof( $fp ) && ! connection_aborted() ) {
			$buf = fread( $fp, min( 1024 * 1024, $remaining ) );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			fwrite( $out, $buf );
			flush();
			$remaining -= strlen( $buf );
		}

		fclose( $out );
		fclose( $fp );
		exit;
	}
}
