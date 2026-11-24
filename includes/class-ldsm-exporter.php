<?php
/**
 * Export & Backup State-Machine Coordinator — LibertyDev Site Migrator.
 *
 * Powers interactive Exports, 1-click Manual Backups, and chunked Background Scheduled Backups
 * using canonical WordPress directory resolution and PHP-exit-guarded temporary files.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LDSM_Exporter {

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

		$job = LDSM_Storage::create_job( $prefix_map[ $trigger ] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$custom_replaces = array();
		if ( ! empty( $raw_options['custom_replaces'] ) && is_array( $raw_options['custom_replaces'] ) ) {
			foreach ( $raw_options['custom_replaces'] as $pair ) {
				if ( is_array( $pair ) && isset( $pair['search'], $pair['replace'] ) ) {
					$s = sanitize_text_field( (string) $pair['search'] );
					$r = sanitize_text_field( (string) $pair['replace'] );
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
		$backup_filename = LDSM_Storage::generate_backup_filename( $trigger );

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
			'files_php_offset'   => 0,
			'current_file_entry' => null,
			'partial_file'       => null,
			'zip_offset'         => 0,
			'manifest_written'   => false,
			'db_archived'        => false,
			'manifest_summary'   => array(),
		);

		if ( ! LDSM_Storage::save_job_state( $job['job_id'], $state ) ) {
			LDSM_Storage::delete_job( $job['job_id'] );
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
	 * @param float  $time_budget Optional custom time budget in seconds (defaults to LDSM_TIME_BUDGET).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function run_tick( $job_id, $time_budget = 0.0 ) {
		$job_dir = LDSM_Storage::get_job_dir( $job_id );
		if ( is_wp_error( $job_dir ) ) {
			return $job_dir;
		}

		$state = LDSM_Storage::load_job_state( $job_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$budget   = $time_budget > 0.5 ? (float) $time_budget : LDSM_TIME_BUDGET;
		$deadline = microtime( true ) + $budget;
		$phase    = (string) $state['phase'];

		// Phase 1: Scan configured WordPress content roots (plugins, mu-plugins, themes, uploads, languages).
		if ( 'scan_files' === $phase ) {
			$files_php = $job_dir . '/files.php';
			$done_scan = LDSM_File_Scanner::scan_step( $state, $files_php, $deadline );
			if ( is_wp_error( $done_scan ) ) {
				self::abort_export_files( $state );
				LDSM_Storage::delete_job( $job_id );
				return $done_scan;
			}

			if ( true === $done_scan ) {
				$state['phase'] = 'export_db';
			}

			LDSM_Storage::save_job_state( $job_id, $state );

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

		// Phase 2: Export Database tables & views into PHP-exit-guarded database.sql.php.
		if ( 'export_db' === $phase ) {
			$sql_php  = $job_dir . '/database.sql.php';
			$replacer = null;
			if ( ! empty( $state['options']['custom_replaces'] ) ) {
				$raw_map = array();
				foreach ( $state['options']['custom_replaces'] as $pair ) {
					$raw_map[ $pair['search'] ] = $pair['replace'];
				}
				$replacer = new LDSM_Replacer( $raw_map );
			}

			$done_db = LDSM_DB_Exporter::export_step( $state, $sql_php, $deadline, $replacer );
			if ( is_wp_error( $done_db ) ) {
				self::abort_export_files( $state );
				LDSM_Storage::delete_job( $job_id );
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

			LDSM_Storage::save_job_state( $job_id, $state );

			return array(
				'done'     => false,
				'job_id'   => $job_id,
				'phase'    => $state['phase'],
				'step_key' => ( true === $done_db ) ? 'progress_archiving_files' : 'progress_exporting_db',
				'percent'  => min( 45, max( 18, $pct ) ),
				'detail'   => $done_tables . ' / ' . $total_tables . ' tables (' . number_format_i18n( (int) $state['db_rows_exported'] ) . ' rows)',
			);
		}

		// Phase 3: Stream manifest.json, database.sql, and scanned files into archive.zip.part.
		if ( 'archive_files' === $phase ) {
			$res = self::archive_step( $job_dir, $state, $deadline );
			if ( is_wp_error( $res ) ) {
				self::abort_export_files( $state );
				LDSM_Storage::delete_job( $job_id );
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

			LDSM_Storage::save_job_state( $job_id, $state );

			return array(
				'done'     => false,
				'job_id'   => $job_id,
				'phase'    => $state['phase'],
				'step_key' => ( true === $res ) ? 'progress_finalizing_zip' : 'progress_archiving_files',
				'percent'  => min( 95, max( 45, $pct ) ),
				'detail'   => number_format_i18n( (int) $state['files_archived'] ) . ' / ' . number_format_i18n( (int) $state['total_files'] ) . ' files',
			);
		}

		// Phase 4: Write Central Directory & EOCD, move guarded .zip.php archive into secret vault, write guarded .meta.php, clean job dir.
		if ( 'finalize_zip' === $phase ) {
			$backups_dir     = LDSM_Storage::get_backups_dir();
			$zip_part        = $job_dir . '/archive.zip.part.php';
			$final_disk_path = $backups_dir . '/' . $state['backup_filename'] . '.php';
			$cd_php          = $job_dir . '/central_dir.php';

			$fin = LDSM_Stream_Zip_Writer::finalize_archive( $zip_part, $cd_php, $state['zip_offset'] );
			if ( is_wp_error( $fin ) ) {
				self::abort_export_files( $state );
				LDSM_Storage::delete_job( $job_id );
				return $fin;
			}

			if ( ! LDSM_Storage::move_file( $zip_part, $final_disk_path ) ) {
				wp_delete_file( $zip_part );
				LDSM_Storage::delete_job( $job_id );
				return new WP_Error( 'zip_move_failed', 'Failed moving finalized archive into backups vault.' );
			}

			$meta_php = $backups_dir . '/' . substr( $state['backup_filename'], 0, -4 ) . '.meta.php';
			if ( ! empty( $state['manifest_summary'] ) && is_array( $state['manifest_summary'] ) ) {
				LDSM_Storage::write_guarded_json_file( $meta_php, $state['manifest_summary'] );
			}

			clearstatcache( true, $final_disk_path );
			$final_size = max( 0.0, (float) @filesize( $final_disk_path ) - LDSM_Storage::PHP_EXIT_GUARD_LEN );
			$trigger    = isset( $state['trigger'] ) ? (string) $state['trigger'] : 'export';

			LDSM_Storage::delete_job( $job_id );

			if ( 'scheduled' === $trigger ) {
				$sched_settings = LDSM_Scheduler::get_settings();
				LDSM_Storage::enforce_scheduled_retention( (int) $sched_settings['retention'] );
			}

			$download_url = '';
			if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
				$download_url = add_query_arg(
					array(
						'action'   => 'ldsm_download_backup',
						'file'     => rawurlencode( $state['backup_filename'] ),
						'_wpnonce' => wp_create_nonce( 'ldsm_download_' . $state['backup_filename'] ),
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
				'backups'      => LDSM_Storage::list_backups(),
			);
		}

		return new WP_Error( 'unknown_export_phase', 'Unknown export phase: ' . $phase );
	}

	/**
	 * Perform one time-boxed archiving batch into archive.zip.part.
	 *
	 * @param string $job_dir  Job workspace directory.
	 * @param array  $state    Job state (modified by reference).
	 * @param float  $deadline Unix microtime deadline.
	 * @return bool|WP_Error
	 */
	private static function archive_step( $job_dir, array &$state, $deadline ) {
		global $wpdb;

		$zip_part = $job_dir . '/archive.zip.part.php';
		$cd_php   = $job_dir . '/central_dir.php';
		$roots    = LDSM_Storage::get_content_roots();

		if ( empty( $state['manifest_written'] ) ) {
			$upload_dir   = wp_upload_dir( null, false );
			$tables_count = ( isset( $state['db_tables'] ) ? count( $state['db_tables'] ) : 0 ) + ( isset( $state['db_views'] ) ? count( $state['db_views'] ) : 0 );
			$trigger      = isset( $state['trigger'] ) ? (string) $state['trigger'] : 'export';
			$note         = isset( $state['note'] ) ? (string) $state['note'] : '';

			$manifest = array(
				'generator'       => 'libertydev-site-migrator',
				'brand'           => 'LibertyDev',
				'plugin_version'  => LDSM_VERSION,
				'format_version'  => 2,
				'trigger'         => $trigger,
				'note'            => $note,
				'created_at'      => gmdate( 'c' ),
				'home_url'        => untrailingslashit( home_url() ),
				'site_url'        => untrailingslashit( site_url() ),
				'content_url'     => untrailingslashit( content_url() ),
				'plugins_url'     => untrailingslashit( plugins_url() ),
				'uploads_url'     => isset( $upload_dir['baseurl'] ) ? untrailingslashit( (string) $upload_dir['baseurl'] ) : '',
				'abspath'         => LDSM_Storage::normalize_path( ABSPATH ),
				'uploads_path'    => isset( $roots['uploads'] ) ? $roots['uploads'] : '',
				'plugins_path'    => isset( $roots['plugins'] ) ? $roots['plugins'] : '',
				'mu_plugins_path' => isset( $roots['mu-plugins'] ) ? $roots['mu-plugins'] : '',
				'themes_path'     => isset( $roots['themes'] ) ? $roots['themes'] : '',
				'languages_path'  => isset( $roots['languages'] ) ? $roots['languages'] : '',
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
			$m_res         = LDSM_Stream_Zip_Writer::add_from_string(
				$zip_part,
				$cd_php,
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
			$sql_php = $job_dir . '/database.sql.php';
			$db_res  = LDSM_Stream_Zip_Writer::add_file_chunked(
				$zip_part,
				$cd_php,
				$state['zip_offset'],
				$sql_php,
				'database/database.sql',
				$state['partial_file'],
				$deadline,
				LDSM_Storage::PHP_EXIT_GUARD_LEN
			);
			if ( is_wp_error( $db_res ) ) {
				return $db_res;
			}
			if ( false === $db_res ) {
				return false;
			}

			$state['db_archived'] = true;
			wp_delete_file( $sql_php );

			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}

		if ( ! empty( $state['current_file_entry'] ) && ! empty( $state['partial_file'] ) ) {
			$curr     = $state['current_file_entry'];
			$root_key = isset( $curr['root'] ) ? (string) $curr['root'] : '';
			$sub_path = isset( $curr['sub'] ) ? (string) $curr['sub'] : '';
			$src_abs  = ( isset( $roots[ $root_key ] ) && '' !== $sub_path ) ? ( $roots[ $root_key ] . '/' . $sub_path ) : '';

			if ( '' !== $src_abs ) {
				$p_res = LDSM_Stream_Zip_Writer::add_file_chunked(
					$zip_part,
					$cd_php,
					$state['zip_offset'],
					$src_abs,
					(string) $curr['rel'],
					$state['partial_file'],
					$deadline
				);
				if ( is_wp_error( $p_res ) ) {
					return $p_res;
				}
				if ( false === $p_res ) {
					return false;
				}
			}

			$state['files_archived']    += 1;
			$state['bytes_archived']    += isset( $curr['size'] ) ? (int) $curr['size'] : 0;
			$state['current_file_entry'] = null;

			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}

		$files_php = $job_dir . '/files.php';
		if ( ! file_exists( $files_php ) ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming byte-offset file list cursor across ticks.
		$fp = @fopen( $files_php, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'files_list_unreadable', 'Cannot open guarded files manifest during archive step.' );
		}

		if ( 0 !== @fseek( $fp, (int) $state['files_php_offset'], SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'files_list_seek_failed', 'Failed seeking in guarded files manifest.' );
		}

		while ( ( $line = fgets( $fp ) ) !== false ) {
			$next_line_offset = (int) ftell( $fp );
			$trimmed          = trim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '<?php' ) ) {
				$state['files_php_offset'] = $next_line_offset;
				continue;
			}

			$entry = json_decode( $trimmed, true );
			if ( ! is_array( $entry ) || empty( $entry['rel'] ) || empty( $entry['root'] ) || ! isset( $entry['sub'] ) ) {
				$state['files_php_offset'] = $next_line_offset;
				continue;
			}

			$root_key = (string) $entry['root'];
			$sub_path = (string) $entry['sub'];
			if ( ! isset( $roots[ $root_key ] ) || '' === $sub_path ) {
				$state['files_php_offset'] = $next_line_offset;
				$state['files_archived']  += 1;
				continue;
			}

			$src_abs = $roots[ $root_key ] . '/' . $sub_path;

			$f_res = LDSM_Stream_Zip_Writer::add_file_chunked(
				$zip_part,
				$cd_php,
				$state['zip_offset'],
				$src_abs,
				(string) $entry['rel'],
				$state['partial_file'],
				$deadline
			);

			if ( is_wp_error( $f_res ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return $f_res;
			}

			$state['files_php_offset'] = $next_line_offset;

			if ( false === $f_res ) {
				$state['current_file_entry'] = $entry;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return false;
			}

			$state['files_archived'] += 1;
			$state['bytes_archived'] += isset( $entry['size'] ) ? (int) $entry['size'] : 0;

			if ( microtime( true ) >= $deadline ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return false;
			}
		}

		$is_eof                    = feof( $fp );
		$state['files_php_offset'] = (int) ftell( $fp );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		return $is_eof && empty( $state['partial_file'] );
	}

	/**
	 * Remove incomplete .zip.part file if an export/backup fails or is cancelled.
	 *
	 * @param array $state Job state.
	 */
	public static function abort_export_files( array $state ) {
		if ( ! empty( $state['job_id'] ) ) {
			$jdir = LDSM_Storage::get_job_dir( (string) $state['job_id'] );
			if ( ! is_wp_error( $jdir ) ) {
				foreach ( array( 'archive.zip.part.php', 'archive.zip.part' ) as $part_name ) {
					if ( file_exists( $jdir . '/' . $part_name ) ) {
						wp_delete_file( $jdir . '/' . $part_name );
					}
				}
			}
		}
		if ( ! empty( $state['backup_filename'] ) ) {
			$bdir = LDSM_Storage::get_backups_dir();
			if ( '' !== $bdir ) {
				foreach ( array( '.part.php', '.part' ) as $suffix ) {
					$part = $bdir . '/' . $state['backup_filename'] . $suffix;
					if ( file_exists( $part ) ) {
						wp_delete_file( $part );
					}
				}
			}
		}
	}
}
