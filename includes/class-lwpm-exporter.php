<?php
/**
 * Export & Backup State-Machine Coordinator — Liberty WP Migrator.
 *
 * Powers interactive Exports, 1-click Manual Backups, and chunked Background Scheduled Backups.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWPM_Exporter {

	/**
	 * Initialize a new Export or Backup job.
	 *
	 * @param array  $raw_options User or schedule options.
	 * @param string $trigger     'export', 'manual', or 'scheduled'.
	 * @param string $note        Optional label/note attached to the backup.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function start_job( array $raw_options, $trigger = 'export', $note = '' ) {
		if ( ! in_array( $trigger, array( 'export', 'manual', 'scheduled' ), true ) ) {
			$trigger = 'export';
		}

		$prefix_map = array(
			'export'    => 'exp',
			'manual'    => 'bak',
			'scheduled' => 'sch',
		);

		$job = LWPM_Storage::create_job( $prefix_map[ $trigger ] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$custom_replaces = array();
		if ( ! empty( $raw_options['custom_replaces'] ) && is_array( $raw_options['custom_replaces'] ) ) {
			foreach ( $raw_options['custom_replaces'] as $pair ) {
				if ( is_array( $pair ) && isset( $pair['search'], $pair['replace'] ) ) {
					$s = (string) $pair['search'];
					$r = (string) $pair['replace'];
					if ( '' !== $s && $s !== $r ) {
						$custom_replaces[] = array(
							'search'  => $s,
							'replace' => $r,
						);
					}
				}
			}
		}

		$clean_options = array(
			'exclude_cache'         => isset( $raw_options['exclude_cache'] ) ? ! empty( $raw_options['exclude_cache'] ) : true,
			'exclude_transients'    => isset( $raw_options['exclude_transients'] ) ? ! empty( $raw_options['exclude_transients'] ) : true,
			'exclude_revisions'     => ! empty( $raw_options['exclude_revisions'] ),
			'exclude_spam'          => ! empty( $raw_options['exclude_spam'] ),
			'exclude_media'         => ! empty( $raw_options['exclude_media'] ),
			'exclude_themes'        => ! empty( $raw_options['exclude_themes'] ),
			'exclude_plugins'       => ! empty( $raw_options['exclude_plugins'] ),
			'include_custom_tables' => ! empty( $raw_options['include_custom_tables'] ),
			'custom_replaces'       => $custom_replaces,
		);

		$clean_note      = mb_substr( sanitize_text_field( (string) $note ), 0, 120 );
		$backup_filename = LWPM_Storage::generate_backup_filename( $trigger );

		$state = array(
			'job_id'             => $job['job_id'],
			'type'               => 'export',
			'trigger'            => $trigger,
			'note'               => $clean_note,
			'phase'              => 'scan_files',
			'created_at'         => time(),
			'backup_filename'    => $backup_filename,
			'options'            => $clean_options,
			'total_files'        => 0,
			'total_bytes'        => 0,
			'files_archived'     => 0,
			'bytes_archived'     => 0,
			'files_jsonl_offset' => 0,
			'current_file_entry' => null,
			'partial_file'       => null,
			'zip_offset'         => 0,
			'manifest_written'   => false,
			'db_archived'        => false,
			'manifest_summary'   => array(),
		);

		if ( ! LWPM_Storage::save_job_state( $job['job_id'], $state ) ) {
			LWPM_Storage::delete_job( $job['job_id'] );
			return new WP_Error( 'state_save_failed', 'Failed saving initial export/backup job state.' );
		}

		return array(
			'done'     => false,
			'job_id'   => $job['job_id'],
			'trigger'  => $trigger,
			'phase'    => 'scan_files',
			'step_key' => 'progress_scanning_files',
			'percent'  => 3,
			'detail'   => '0 files',
		);
	}

	/**
	 * Advance an Export/Backup job by one time-boxed tick.
	 *
	 * @param string $job_id      Export job ID.
	 * @param float  $time_budget Optional custom time budget in seconds (defaults to LWPM_TIME_BUDGET).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function run_tick( $job_id, $time_budget = 0.0 ) {
		$job_dir = LWPM_Storage::get_job_dir( $job_id );
		if ( is_wp_error( $job_dir ) ) {
			return $job_dir;
		}

		$state = LWPM_Storage::load_job_state( $job_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$budget   = $time_budget > 0.5 ? (float) $time_budget : LWPM_TIME_BUDGET;
		$deadline = microtime( true ) + $budget;
		$phase    = (string) $state['phase'];

		// Phase 1: Scan wp-content files.
		if ( 'scan_files' === $phase ) {
			$files_jsonl = $job_dir . '/files.jsonl';
			$done_scan   = LWPM_File_Scanner::scan_step( $state, $files_jsonl, $deadline );
			if ( is_wp_error( $done_scan ) ) {
				self::abort_export_files( $state );
				LWPM_Storage::delete_job( $job_id );
				return $done_scan;
			}

			if ( true === $done_scan ) {
				$state['phase'] = 'export_db';
			}

			LWPM_Storage::save_job_state( $job_id, $state );

			$pct = ( true === $done_scan ) ? 18 : min( 17, 4 + (int) floor( min( 13, $state['total_files'] / 500 ) ) );
			return array(
				'done'     => false,
				'job_id'   => $job_id,
				'phase'    => $state['phase'],
				'step_key' => ( true === $done_scan ) ? 'progress_exporting_db' : 'progress_scanning_files',
				'percent'  => $pct,
				'detail'   => number_format_i18n( (int) $state['total_files'] ) . ' files (' . size_format( (float) $state['total_bytes'], 1 ) . ')',
			);
		}

		// Phase 2: Export Database tables & views.
		if ( 'export_db' === $phase ) {
			$sql_file = $job_dir . '/database.sql';
			$replacer = null;
			if ( ! empty( $state['options']['custom_replaces'] ) ) {
				$raw_map = array();
				foreach ( $state['options']['custom_replaces'] as $pair ) {
					$raw_map[ $pair['search'] ] = $pair['replace'];
				}
				$replacer = new LWPM_Replacer( $raw_map );
			}

			$done_db = LWPM_DB_Exporter::export_step( $state, $sql_file, $deadline, $replacer );
			if ( is_wp_error( $done_db ) ) {
				self::abort_export_files( $state );
				LWPM_Storage::delete_job( $job_id );
				return $done_db;
			}

			$total_tables = isset( $state['db_tables'] ) ? max( 1, count( $state['db_tables'] ) ) : 1;
			$done_tables  = isset( $state['db_table_idx'] ) ? (int) $state['db_table_idx'] : 0;

			if ( true === $done_db ) {
				$state['phase'] = 'archive_files';
				$pct            = 45;
			} else {
				$pct = 18 + (int) floor( ( $done_tables / $total_tables ) * 26 );
			}

			LWPM_Storage::save_job_state( $job_id, $state );

			return array(
				'done'     => false,
				'job_id'   => $job_id,
				'phase'    => $state['phase'],
				'step_key' => ( true === $done_db ) ? 'progress_archiving_files' : 'progress_exporting_db',
				'percent'  => min( 45, max( 18, $pct ) ),
				'detail'   => $done_tables . ' / ' . $total_tables . ' tables (' . number_format_i18n( (int) $state['db_rows_exported'] ) . ' rows)',
			);
		}

		// Phase 3: Stream manifest.json, database.sql, and wp-content files into .zip.part.
		if ( 'archive_files' === $phase ) {
			$res = self::archive_step( $job_dir, $state, $deadline );
			if ( is_wp_error( $res ) ) {
				self::abort_export_files( $state );
				LWPM_Storage::delete_job( $job_id );
				return $res;
			}

			if ( true === $res ) {
				$state['phase'] = 'finalize_zip';
				$pct            = 95;
			} else {
				$total_f = max( 1, (int) $state['total_files'] );
				$done_f  = (int) $state['files_archived'];
				$pct     = 45 + (int) floor( ( $done_f / $total_f ) * 49 );
			}

			LWPM_Storage::save_job_state( $job_id, $state );

			return array(
				'done'     => false,
				'job_id'   => $job_id,
				'phase'    => $state['phase'],
				'step_key' => ( true === $res ) ? 'progress_finalizing_zip' : 'progress_archiving_files',
				'percent'  => min( 95, max( 45, $pct ) ),
				'detail'   => number_format_i18n( (int) $state['files_archived'] ) . ' / ' . number_format_i18n( (int) $state['total_files'] ) . ' files',
			);
		}

		// Phase 4: Write Central Directory & EOCD, rename .zip.part -> .zip, write .meta.json, clean job dir.
		if ( 'finalize_zip' === $phase ) {
			$backups_dir = LWPM_Storage::get_backups_dir();
			$zip_part    = $backups_dir . '/' . $state['backup_filename'] . '.part';
			$final_zip   = $backups_dir . '/' . $state['backup_filename'];
			$cd_jsonl    = $job_dir . '/central_dir.jsonl';

			$fin = LWPM_Stream_Zip_Writer::finalize_archive( $zip_part, $cd_jsonl, $state['zip_offset'] );
			if ( is_wp_error( $fin ) ) {
				self::abort_export_files( $state );
				LWPM_Storage::delete_job( $job_id );
				return $fin;
			}

			if ( ! @rename( $zip_part, $final_zip ) ) {
				if ( ! @copy( $zip_part, $final_zip ) ) {
					wp_delete_file( $zip_part );
					LWPM_Storage::delete_job( $job_id );
					return new WP_Error( 'zip_rename_failed', 'Failed moving finalized archive into backups directory.' );
				}
				wp_delete_file( $zip_part );
			}

			$meta_file = substr( $final_zip, 0, -4 ) . '.meta.json';
			if ( ! empty( $state['manifest_summary'] ) ) {
				@file_put_contents(
					$meta_file,
					wp_json_encode( $state['manifest_summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				);
			}

			clearstatcache( true, $final_zip );
			$final_size = (float) @filesize( $final_zip );
			$trigger    = isset( $state['trigger'] ) ? (string) $state['trigger'] : 'export';

			LWPM_Storage::delete_job( $job_id );

			// If this was a scheduled backup (including test runs), enforce retention rotation automatically!
			if ( 'scheduled' === $trigger ) {
				$sched_settings = LWPM_Scheduler::get_settings();
				LWPM_Storage::enforce_scheduled_retention( (int) $sched_settings['retention'] );
			}

			$download_url = '';
			if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
				$download_url = add_query_arg(
					array(
						'action'   => 'lwpm_download_backup',
						'file'     => rawurlencode( $state['backup_filename'] ),
						'_wpnonce' => wp_create_nonce( 'lwpm_download_' . $state['backup_filename'] ),
					),
					admin_url( 'admin-ajax.php' )
				);
			}

			return array(
				'done'         => true,
				'job_id'       => $job_id,
				'trigger'      => $trigger,
				'phase'        => 'complete',
				'percent'      => 100,
				'filename'     => $state['backup_filename'],
				'size_bytes'   => $final_size,
				'size_human'   => size_format( $final_size, 2 ),
				'download_url' => $download_url,
				'backups'      => LWPM_Storage::list_backups(),
			);
		}

		return new WP_Error( 'unknown_export_phase', 'Unknown export phase: ' . $phase );
	}

	/**
	 * Perform one time-boxed archiving batch into .zip.part.
	 */
	private static function archive_step( $job_dir, array &$state, $deadline ) {
		global $wpdb;

		$zip_part = LWPM_Storage::get_backups_dir() . '/' . $state['backup_filename'] . '.part';
		$cd_jsonl = $job_dir . '/central_dir.jsonl';

		if ( empty( $state['manifest_written'] ) ) {
			$upload_dir   = wp_upload_dir();
			$tables_count = ( isset( $state['db_tables'] ) ? count( $state['db_tables'] ) : 0 ) + ( isset( $state['db_views'] ) ? count( $state['db_views'] ) : 0 );
			$trigger      = isset( $state['trigger'] ) ? (string) $state['trigger'] : 'export';
			$note         = isset( $state['note'] ) ? (string) $state['note'] : '';

			$manifest = array(
				'generator'       => 'liberty-wp-migrator',
				'brand'           => 'LibertyDev',
				'plugin_version'  => LWPM_VERSION,
				'format_version'  => 1,
				'trigger'         => $trigger,
				'note'            => $note,
				'created_at'      => gmdate( 'c' ),
				'home_url'        => untrailingslashit( home_url() ),
				'site_url'        => untrailingslashit( site_url() ),
				'abspath'         => LWPM_Storage::normalize_path( ABSPATH ),
				'wp_content_dir'  => LWPM_Storage::normalize_path( WP_CONTENT_DIR ),
				'uploads_url'     => isset( $upload_dir['baseurl'] ) ? untrailingslashit( (string) $upload_dir['baseurl'] ) : '',
				'uploads_path'    => isset( $upload_dir['basedir'] ) ? LWPM_Storage::normalize_path( (string) $upload_dir['basedir'] ) : '',
				'table_prefix'    => (string) $wpdb->prefix,
				'wp_version'      => (string) get_bloginfo( 'version' ),
				'php_version'     => PHP_VERSION,
				'mysql_version'   => (string) $wpdb->db_version(),
				'is_multisite'    => is_multisite(),
				'active_template' => (string) get_template(),
				'active_theme'    => (string) get_stylesheet(),
				'charset'         => (string) $wpdb->charset,
				'collate'         => (string) $wpdb->collate,
				'total_files'     => (int) $state['total_files'],
				'total_bytes'     => (int) $state['total_bytes'],
				'tables_count'    => $tables_count,
			);

			$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$m_res         = LWPM_Stream_Zip_Writer::add_from_string(
				$zip_part,
				$cd_jsonl,
				$state['zip_offset'],
				'manifest.json',
				(string) $manifest_json
			);
			if ( is_wp_error( $m_res ) ) {
				return $m_res;
			}

			$state['manifest_summary'] = array(
				'trigger'      => $trigger,
				'note'         => $note,
				'home_url'     => $manifest['home_url'],
				'wp_version'   => $manifest['wp_version'],
				'php_version'  => $manifest['php_version'],
				'total_files'  => $manifest['total_files'],
				'tables_count' => $manifest['tables_count'],
				'created_at'   => $manifest['created_at'],
			);
			$state['manifest_written'] = true;

			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}

		if ( empty( $state['db_archived'] ) ) {
			$sql_path = $job_dir . '/database.sql';
			$db_res   = LWPM_Stream_Zip_Writer::add_file_chunked(
				$zip_part,
				$cd_jsonl,
				$state['zip_offset'],
				$sql_path,
				'database/database.sql',
				$state['partial_file'],
				$deadline
			);
			if ( is_wp_error( $db_res ) ) {
				return $db_res;
			}
			if ( false === $db_res ) {
				return false;
			}

			$state['db_archived'] = true;
			wp_delete_file( $sql_path );

			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}

		$wp_content_norm = LWPM_Storage::normalize_path( WP_CONTENT_DIR );

		if ( ! empty( $state['current_file_entry'] ) && ! empty( $state['partial_file'] ) ) {
			$curr       = $state['current_file_entry'];
			$rel_inside = ( 0 === strpos( $curr['rel'], 'wp-content/' ) ) ? substr( $curr['rel'], 11 ) : $curr['rel'];
			$src_abs    = $wp_content_norm . '/' . $rel_inside;

			$p_res = LWPM_Stream_Zip_Writer::add_file_chunked(
				$zip_part,
				$cd_jsonl,
				$state['zip_offset'],
				$src_abs,
				$curr['rel'],
				$state['partial_file'],
				$deadline
			);
			if ( is_wp_error( $p_res ) ) {
				return $p_res;
			}
			if ( false === $p_res ) {
				return false;
			}

			$state['files_archived']    += 1;
			$state['bytes_archived']    += (int) $curr['size'];
			$state['current_file_entry'] = null;

			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}

		$files_jsonl = $job_dir . '/files.jsonl';
		if ( ! file_exists( $files_jsonl ) ) {
			return true;
		}

		$fp = @fopen( $files_jsonl, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'files_list_unreadable', 'Cannot open files.jsonl during archive step.' );
		}

		if ( 0 !== @fseek( $fp, (int) $state['files_jsonl_offset'], SEEK_SET ) ) {
			fclose( $fp );
			return new WP_Error( 'files_list_seek_failed', 'Failed seeking in files.jsonl.' );
		}

		while ( ( $line = fgets( $fp ) ) !== false ) {
			$next_line_offset = (int) ftell( $fp );
			$trimmed          = trim( $line );
			if ( '' === $trimmed ) {
				$state['files_jsonl_offset'] = $next_line_offset;
				continue;
			}

			$entry = json_decode( $trimmed, true );
			if ( ! is_array( $entry ) || empty( $entry['rel'] ) ) {
				$state['files_jsonl_offset'] = $next_line_offset;
				continue;
			}

			$rel_inside = ( 0 === strpos( $entry['rel'], 'wp-content/' ) ) ? substr( $entry['rel'], 11 ) : $entry['rel'];
			$src_abs    = $wp_content_norm . '/' . $rel_inside;

			$f_res = LWPM_Stream_Zip_Writer::add_file_chunked(
				$zip_part,
				$cd_jsonl,
				$state['zip_offset'],
				$src_abs,
				$entry['rel'],
				$state['partial_file'],
				$deadline
			);

			if ( is_wp_error( $f_res ) ) {
				fclose( $fp );
				return $f_res;
			}

			$state['files_jsonl_offset'] = $next_line_offset;

			if ( false === $f_res ) {
				$state['current_file_entry'] = $entry;
				fclose( $fp );
				return false;
			}

			$state['files_archived'] += 1;
			$state['bytes_archived'] += isset( $entry['size'] ) ? (int) $entry['size'] : 0;

			if ( microtime( true ) >= $deadline ) {
				fclose( $fp );
				return false;
			}
		}

		$is_eof                      = feof( $fp );
		$state['files_jsonl_offset'] = (int) ftell( $fp );
		fclose( $fp );

		return $is_eof && empty( $state['partial_file'] );
	}

	/**
	 * Remove incomplete .zip.part file if an export/backup fails or is cancelled.
	 */
	public static function abort_export_files( array $state ) {
		if ( ! empty( $state['backup_filename'] ) ) {
			$part = LWPM_Storage::get_backups_dir() . '/' . $state['backup_filename'] . '.part';
			if ( file_exists( $part ) ) {
				wp_delete_file( $part );
			}
		}
	}
}
