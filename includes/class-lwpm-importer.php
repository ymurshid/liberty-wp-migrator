<?php
/**
 * Import State-Machine Coordinator — Liberty WP Migrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWPM_Importer {

	/**
	 * Capture current destination WordPress environment & active administrator details.
	 */
	private static function capture_destination_context() {
		global $wpdb;

		$upload_dir = wp_upload_dir();
		$dest_info  = array(
			'home_url'       => untrailingslashit( home_url() ),
			'site_url'       => untrailingslashit( site_url() ),
			'abspath'        => LWPM_Storage::normalize_path( ABSPATH ),
			'wp_content_dir' => LWPM_Storage::normalize_path( WP_CONTENT_DIR ),
			'uploads_url'    => isset( $upload_dir['baseurl'] ) ? untrailingslashit( (string) $upload_dir['baseurl'] ) : '',
			'uploads_path'   => isset( $upload_dir['basedir'] ) ? LWPM_Storage::normalize_path( (string) $upload_dir['basedir'] ) : '',
			'table_prefix'   => (string) $wpdb->prefix,
		);

		$preserved_admin = array();
		$curr_user       = wp_get_current_user();
		if ( $curr_user && $curr_user->exists() ) {
			$raw_row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE `ID` = %d LIMIT 1", (int) $curr_user->ID ),
				ARRAY_A
			);
			if ( is_array( $raw_row ) ) {
				$preserved_admin = array(
					'user_login'    => (string) $raw_row['user_login'],
					'user_pass'     => (string) $raw_row['user_pass'],
					'user_nicename' => (string) $raw_row['user_nicename'],
					'user_email'    => (string) $raw_row['user_email'],
					'display_name'  => (string) $raw_row['display_name'],
				);
			}
		}

		return array(
			'dest_info'       => $dest_info,
			'preserved_admin' => $preserved_admin,
		);
	}

	/**
	 * Initialize a new chunked upload Import job.
	 */
	public static function init_upload_job( $filename, $total_size ) {
		if ( '.zip' !== strtolower( substr( (string) $filename, -4 ) ) ) {
			return new WP_Error( 'invalid_extension', 'Only .zip archives created by Liberty WP Migrator are supported.' );
		}
		if ( $total_size < 22 ) {
			return new WP_Error( 'file_too_small', 'Selected archive file is empty or invalid.' );
		}

		$job = LWPM_Storage::create_job( 'imp' );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$ctx      = self::capture_destination_context();
		$zip_path = $job['job_dir'] . '/package.zip';

		$state = array(
			'job_id'             => $job['job_id'],
			'type'               => 'import',
			'phase'              => 'uploading',
			'created_at'         => time(),
			'zip_path'           => $zip_path,
			'delete_zip_after'   => true,
			'total_upload_bytes' => (int) $total_size,
			'uploaded_bytes'     => 0,
			'dest_info'          => $ctx['dest_info'],
			'preserved_admin'    => $ctx['preserved_admin'],
		);

		if ( ! LWPM_Storage::save_job_state( $job['job_id'], $state ) ) {
			LWPM_Storage::delete_job( $job['job_id'] );
			return new WP_Error( 'state_save_failed', 'Could not save initial import state.' );
		}

		return array(
			'job_id'         => $job['job_id'],
			'phase'          => 'uploading',
			'uploaded_bytes' => 0,
		);
	}

	/**
	 * Append an uploaded binary chunk at $offset (idempotent on network retries).
	 */
	public static function receive_upload_chunk( $job_id, $offset, $tmp_filepath ) {
		$state = LWPM_Storage::load_job_state( $job_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( 'uploading' !== $state['phase'] && 'ready_to_inspect' !== $state['phase'] ) {
			return new WP_Error( 'invalid_upload_phase', 'Job is no longer accepting upload chunks.' );
		}

		if ( ! is_file( $tmp_filepath ) || ! is_readable( $tmp_filepath ) ) {
			return new WP_Error( 'chunk_missing', 'Uploaded chunk payload was not received by PHP.' );
		}

		$chunk_size   = (int) @filesize( $tmp_filepath );
		$current_done = (int) $state['uploaded_bytes'];

		if ( $offset < $current_done && ( $offset + $chunk_size ) === $current_done ) {
			$complete = ( $current_done >= (int) $state['total_upload_bytes'] );
			return array(
				'job_id'          => $job_id,
				'uploaded_bytes'  => $current_done,
				'upload_complete' => $complete,
			);
		}

		if ( $offset !== $current_done ) {
			return new WP_Error(
				'chunk_offset_mismatch',
				sprintf( 'Upload chunk out of sync (expected offset %d, got %d).', $current_done, $offset )
			);
		}

		$zip_path = (string) $state['zip_path'];
		$out      = @fopen( $zip_path, file_exists( $zip_path ) ? 'r+b' : 'w+b' );
		if ( ! $out ) {
			return new WP_Error( 'pkg_open_failed', 'Cannot open target package.zip for writing.' );
		}

		if ( 0 !== @fseek( $out, $offset, SEEK_SET ) ) {
			fclose( $out );
			return new WP_Error( 'pkg_seek_failed', 'Failed seeking to chunk offset.' );
		}

		$in = @fopen( $tmp_filepath, 'rb' );
		if ( ! $in ) {
			fclose( $out );
			return new WP_Error( 'chunk_open_failed', 'Failed reading uploaded temporary chunk.' );
		}

		$copied = stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );

		if ( false === $copied || $copied !== $chunk_size ) {
			return new WP_Error( 'chunk_write_failed', 'Disk write failed while appending upload chunk.' );
		}

		$state['uploaded_bytes'] = $offset + $copied;
		$complete                = ( $state['uploaded_bytes'] >= (int) $state['total_upload_bytes'] );
		if ( $complete ) {
			$state['phase'] = 'ready_to_inspect';
		}

		LWPM_Storage::save_job_state( $job_id, $state );

		return array(
			'job_id'          => $job_id,
			'uploaded_bytes'  => $state['uploaded_bytes'],
			'upload_complete' => $complete,
		);
	}

	/**
	 * Initialize an Import job directly from an existing .zip archive stored in wp-content/lwpm-backups.
	 */
	public static function init_from_stored_backup( $backup_filename ) {
		if ( ! LWPM_Storage::is_valid_backup_filename( $backup_filename ) ) {
			return new WP_Error( 'invalid_backup', 'Invalid backup filename.' );
		}

		$zip_path = LWPM_Storage::get_backups_dir() . '/' . $backup_filename;
		if ( ! is_file( $zip_path ) || ! is_readable( $zip_path ) ) {
			return new WP_Error( 'backup_not_found', 'Stored backup file does not exist.' );
		}

		$job = LWPM_Storage::create_job( 'imp' );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$ctx = self::capture_destination_context();

		$state = array(
			'job_id'           => $job['job_id'],
			'type'             => 'import',
			'phase'            => 'ready_to_inspect',
			'created_at'       => time(),
			'zip_path'         => $zip_path,
			'delete_zip_after' => false,
			'dest_info'        => $ctx['dest_info'],
			'preserved_admin'  => $ctx['preserved_admin'],
		);

		LWPM_Storage::save_job_state( $job['job_id'], $state );

		return self::inspect_package( $job['job_id'] );
	}

	/**
	 * Inspect the uploaded/selected .zip archive and return pre-flight summary.
	 */
	public static function inspect_package( $job_id ) {
		$job_dir = LWPM_Storage::get_job_dir( $job_id );
		if ( is_wp_error( $job_dir ) ) {
			return $job_dir;
		}

		$state = LWPM_Storage::load_job_state( $job_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$index_jsonl = $job_dir . '/extract_index.jsonl';
		$summary     = LWPM_Stream_Zip_Reader::index_archive( (string) $state['zip_path'], $index_jsonl );
		if ( is_wp_error( $summary ) ) {
			LWPM_Storage::delete_job( $job_id );
			return $summary;
		}

		$manifest = $summary['manifest'];

		$state['phase']             = 'awaiting_confirmation';
		$state['manifest']          = $manifest;
		$state['db_entry']          = $summary['db_entry'];
		$state['files_count']       = (int) $summary['files_count'];
		$state['uncompressed_size'] = (int) $summary['uncompressed_size'];

		LWPM_Storage::save_job_state( $job_id, $state );

		return array(
			'job_id'          => $job_id,
			'phase'           => 'awaiting_confirmation',
			'source_url'      => isset( $manifest['home_url'] ) ? (string) $manifest['home_url'] : 'Unknown',
			'dest_url'        => (string) $state['dest_info']['home_url'],
			'wp_version'      => isset( $manifest['wp_version'] ) ? (string) $manifest['wp_version'] : '-',
			'php_version'     => isset( $manifest['php_version'] ) ? (string) $manifest['php_version'] : '-',
			'files_count'     => number_format_i18n( (int) $summary['files_count'] ),
			'tables_count'    => isset( $manifest['tables_count'] ) ? (int) $manifest['tables_count'] : 0,
			'data_size_human' => size_format( (float) $summary['uncompressed_size'], 2 ),
		);
	}

	/**
	 * Confirm and kick off extraction & shadow-DB import.
	 */
	public static function start_confirmed_import( $job_id, array $import_options ) {
		$state = LWPM_Storage::load_job_state( $job_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( 'awaiting_confirmation' !== $state['phase'] ) {
			return new WP_Error( 'invalid_confirm_state', 'Archive must be inspected before starting import.' );
		}

		// Preserve existing destination schedule configuration across site imports if present.
		$existing_sched = get_option( 'lwpm_schedule_settings', null );

		$state['import_options'] = array(
			'keep_current_admin' => ! empty( $import_options['keep_current_admin'] ),
			'skip_guid'          => ! empty( $import_options['skip_guid'] ),
		);
		$state['saved_dest_schedule'] = $existing_sched;

		$state['phase']                = 'extract_files';
		$state['db_extracted']         = false;
		$state['extract_index_offset'] = 0;
		$state['files_extracted']      = 0;
		$state['current_extract_item'] = null;
		$state['partial_extract']      = null;

		LWPM_Storage::save_job_state( $job_id, $state );
		update_option( 'lwpm_maintenance_active', time(), false );

		return array(
			'done'     => false,
			'job_id'   => $job_id,
			'phase'    => 'extract_files',
			'step_key' => 'progress_extracting_files',
			'percent'  => 10,
			'detail'   => '0 / ' . number_format_i18n( (int) $state['files_count'] ),
		);
	}

	/**
	 * Execute one time-boxed Import tick.
	 */
	public static function run_tick( $job_id ) {
		$job_dir = LWPM_Storage::get_job_dir( $job_id );
		if ( is_wp_error( $job_dir ) ) {
			delete_option( 'lwpm_maintenance_active' );
			return $job_dir;
		}

		$state = LWPM_Storage::load_job_state( $job_id );
		if ( is_wp_error( $state ) ) {
			delete_option( 'lwpm_maintenance_active' );
			return $state;
		}

		update_option( 'lwpm_maintenance_active', time(), false );

		$deadline = microtime( true ) + LWPM_TIME_BUDGET;
		$phase    = (string) $state['phase'];

		if ( 'extract_files' === $phase ) {
			$ext_res = self::extract_step( $job_dir, $state, $deadline );
			if ( is_wp_error( $ext_res ) ) {
				self::cleanup_failed_import( $job_id, $state );
				return $ext_res;
			}

			$total_f = max( 1, (int) $state['files_count'] );
			$done_f  = (int) $state['files_extracted'];

			if ( true === $ext_res ) {
				$state['phase'] = 'import_db';
				$pct            = 52;
			} else {
				$pct = 10 + (int) floor( ( $done_f / $total_f ) * 41 );
			}

			LWPM_Storage::save_job_state( $job_id, $state );

			return array(
				'done'     => false,
				'job_id'   => $job_id,
				'phase'    => $state['phase'],
				'step_key' => ( true === $ext_res ) ? 'progress_importing_db' : 'progress_extracting_files',
				'percent'  => min( 52, max( 10, $pct ) ),
				'detail'   => number_format_i18n( $done_f ) . ' / ' . number_format_i18n( (int) $state['files_count'] ) . ' files',
			);
		}

		if ( 'import_db' === $phase ) {
			$sql_file = $job_dir . '/database.sql';
			$db_res   = LWPM_DB_Importer::import_sql_step( $state, $sql_file, $deadline );
			if ( is_wp_error( $db_res ) ) {
				self::cleanup_failed_import( $job_id, $state );
				return $db_res;
			}

			if ( true === $db_res ) {
				wp_delete_file( $sql_file );
				$state['phase'] = 'search_replace';
				$pct            = 76;
			} else {
				$tot_b  = max( 1, isset( $state['sql_total_bytes'] ) ? (int) $state['sql_total_bytes'] : 1 );
				$done_b = isset( $state['sql_byte_offset'] ) ? (int) $state['sql_byte_offset'] : 0;
				$pct    = 52 + (int) floor( ( $done_b / $tot_b ) * 23 );
			}

			LWPM_Storage::save_job_state( $job_id, $state );

			return array(
				'done'     => false,
				'job_id'   => $job_id,
				'phase'    => $state['phase'],
				'step_key' => ( true === $db_res ) ? 'progress_replacing_urls' : 'progress_importing_db',
				'percent'  => min( 76, max( 52, $pct ) ),
				'detail'   => isset( $state['shadow_map'] ) ? ( count( $state['shadow_map'] ) . ' tables' ) : '',
			);
		}

		if ( 'search_replace' === $phase ) {
			$replacer = LWPM_Replacer::from_migration_context(
				(array) $state['manifest'],
				(array) $state['dest_info']
			);

			$sr_res = LWPM_DB_Importer::search_replace_step( $state, $replacer, $deadline );
			if ( is_wp_error( $sr_res ) ) {
				self::cleanup_failed_import( $job_id, $state );
				return $sr_res;
			}

			$tot_t  = isset( $state['shadow_list'] ) ? max( 1, count( $state['shadow_list'] ) ) : 1;
			$done_t = isset( $state['sr_table_idx'] ) ? (int) $state['sr_table_idx'] : 0;

			if ( true === $sr_res ) {
				$state['phase'] = 'atomic_swap';
				$pct            = 94;
			} else {
				$pct = 76 + (int) floor( ( $done_t / $tot_t ) * 17 );
			}

			LWPM_Storage::save_job_state( $job_id, $state );

			return array(
				'done'     => false,
				'job_id'   => $job_id,
				'phase'    => $state['phase'],
				'step_key' => ( true === $sr_res ) ? 'progress_swapping_tables' : 'progress_replacing_urls',
				'percent'  => min( 94, max( 76, $pct ) ),
				'detail'   => $done_t . ' / ' . $tot_t . ' tables',
			);
		}

		if ( 'atomic_swap' === $phase ) {
			$swap_res = LWPM_DB_Importer::finalize_and_atomic_swap( $state );
			if ( is_wp_error( $swap_res ) ) {
				self::cleanup_failed_import( $job_id, $state );
				return $swap_res;
			}

			$staged_mu = $job_dir . '/staged_mu_plugins';
			if ( is_dir( $staged_mu ) ) {
				$target_mu = LWPM_Storage::normalize_path( WP_CONTENT_DIR ) . '/mu-plugins';
				self::copy_dir_recursive( $staged_mu, $target_mu );
			}

			$elementor_css_dir = LWPM_Storage::normalize_path( WP_CONTENT_DIR ) . '/uploads/elementor/css';
			if ( is_dir( $elementor_css_dir ) ) {
				$css_files = glob( $elementor_css_dir . '/*.css' );
				if ( is_array( $css_files ) ) {
					foreach ( $css_files as $cf ) {
						wp_delete_file( $cf );
					}
				}
			}

			if ( function_exists( 'wp_cache_flush' ) ) {
				@wp_cache_flush();
			}

			// Re-sync backup cron schedule after database swap.
			LWPM_Scheduler::sync_cron_with_settings();

			update_option( 'lwpm_needs_rewrite_flush', 1, true );
			delete_option( 'lwpm_maintenance_active' );

			if ( is_int( $swap_res ) && $swap_res > 0 && ! headers_sent() ) {
				wp_clear_auth_cookie();
				wp_set_current_user( $swap_res );
				wp_set_auth_cookie( $swap_res, true );
			}

			$dest_home = (string) $state['dest_info']['home_url'];
			$dest_site = (string) $state['dest_info']['site_url'];

			LWPM_Storage::delete_job( $job_id );

			return array(
				'done'      => true,
				'job_id'    => $job_id,
				'phase'     => 'complete',
				'percent'   => 100,
				'home_url'  => $dest_home,
				'admin_url' => trailingslashit( $dest_site ) . 'wp-admin/admin.php?page=liberty-wp-migrator',
			);
		}

		delete_option( 'lwpm_maintenance_active' );
		return new WP_Error( 'unknown_import_phase', 'Unknown import phase: ' . $phase );
	}

	/**
	 * Extract database.sql and wp-content files in time-boxed batches.
	 */
	private static function extract_step( $job_dir, array &$state, $deadline ) {
		$zip_path = (string) $state['zip_path'];

		if ( empty( $state['db_extracted'] ) ) {
			$sql_dest = $job_dir . '/database.sql';
			$db_res   = LWPM_Stream_Zip_Reader::extract_entry_to_file_chunked(
				$zip_path,
				(array) $state['db_entry'],
				$sql_dest,
				$state['partial_extract'],
				$deadline
			);
			if ( is_wp_error( $db_res ) ) {
				return $db_res;
			}
			if ( false === $db_res ) {
				return false;
			}
			$state['db_extracted'] = true;

			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}

		$wp_content_norm = LWPM_Storage::normalize_path( WP_CONTENT_DIR );

		if ( ! empty( $state['current_extract_item'] ) && ! empty( $state['partial_extract'] ) ) {
			$item     = $state['current_extract_item'];
			$dest_abs = self::resolve_safe_extract_destination( $item['rel_path'], $wp_content_norm, $job_dir );
			if ( null !== $dest_abs ) {
				$p_res = LWPM_Stream_Zip_Reader::extract_entry_to_file_chunked(
					$zip_path,
					$item,
					$dest_abs,
					$state['partial_extract'],
					$deadline
				);
				if ( is_wp_error( $p_res ) ) {
					return $p_res;
				}
				if ( false === $p_res ) {
					return false;
				}
			}
			$state['files_extracted']      += 1;
			$state['current_extract_item'] = null;

			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}

		$index_jsonl = $job_dir . '/extract_index.jsonl';
		if ( ! file_exists( $index_jsonl ) ) {
			return true;
		}

		$fp = @fopen( $index_jsonl, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'extract_index_open_failed', 'Cannot open extract_index.jsonl.' );
		}

		if ( 0 !== @fseek( $fp, (int) $state['extract_index_offset'], SEEK_SET ) ) {
			fclose( $fp );
			return new WP_Error( 'extract_index_seek_failed', 'Failed seeking in extract_index.jsonl.' );
		}

		while ( ( $line = fgets( $fp ) ) !== false ) {
			$next_offset = (int) ftell( $fp );
			$trimmed     = trim( $line );
			if ( '' === $trimmed ) {
				$state['extract_index_offset'] = $next_offset;
				continue;
			}

			$item = json_decode( $trimmed, true );
			if ( ! is_array( $item ) || empty( $item['rel_path'] ) ) {
				$state['extract_index_offset'] = $next_offset;
				continue;
			}

			$dest_abs = self::resolve_safe_extract_destination( (string) $item['rel_path'], $wp_content_norm, $job_dir );
			if ( null === $dest_abs ) {
				$state['extract_index_offset'] = $next_offset;
				$state['files_extracted']     += 1;
				continue;
			}

			$res = LWPM_Stream_Zip_Reader::extract_entry_to_file_chunked(
				$zip_path,
				$item,
				$dest_abs,
				$state['partial_extract'],
				$deadline
			);

			if ( is_wp_error( $res ) ) {
				fclose( $fp );
				return $res;
			}

			$state['extract_index_offset'] = $next_offset;

			if ( false === $res ) {
				$state['current_extract_item'] = $item;
				fclose( $fp );
				return false;
			}

			$state['files_extracted'] += 1;

			if ( microtime( true ) >= $deadline ) {
				fclose( $fp );
				return false;
			}
		}

		$is_eof                        = feof( $fp );
		$state['extract_index_offset'] = (int) ftell( $fp );
		fclose( $fp );

		return $is_eof && empty( $state['partial_extract'] );
	}

	/**
	 * Map relative archive path (wp-content/...) to safe destination path on disk.
	 */
	private static function resolve_safe_extract_destination( $rel_path, $wp_content_norm, $job_dir ) {
		if ( 0 !== strpos( $rel_path, 'wp-content/' ) ) {
			return null;
		}

		$inside = substr( $rel_path, 11 );
		if ( '' === $inside ) {
			return null;
		}

		$lower_inside = strtolower( $inside );

		if (
			0 === strpos( $lower_inside, 'lwpm-backups/' ) ||
			0 === strpos( $lower_inside, 'owpm-backups/' ) ||
			0 === strpos( $lower_inside, 'plugins/liberty-wp-migrator/' ) ||
			0 === strpos( $lower_inside, 'plugins/omni-wp-migrator/' ) ||
			'object-cache.php' === $lower_inside ||
			'advanced-cache.php' === $lower_inside ||
			'wp-cache-config.php' === $lower_inside
		) {
			return null;
		}

		if ( 0 === strpos( $inside, 'mu-plugins/' ) ) {
			$mu_sub = substr( $inside, 11 );
			return '' !== $mu_sub ? ( $job_dir . '/staged_mu_plugins/' . $mu_sub ) : null;
		}

		return $wp_content_norm . '/' . $inside;
	}

	/**
	 * Recursively copy staged directory contents into target directory.
	 */
	private static function copy_dir_recursive( $src, $dst ) {
		if ( ! is_dir( $src ) ) {
			return;
		}
		if ( ! is_dir( $dst ) ) {
			wp_mkdir_p( $dst );
		}
		$items = @scandir( $src );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$s = $src . '/' . $item;
			$d = $dst . '/' . $item;
			if ( is_dir( $s ) && ! is_link( $s ) ) {
				self::copy_dir_recursive( $s, $d );
			} elseif ( is_file( $s ) ) {
				@copy( $s, $d );
			}
		}
	}

	/**
	 * Clean up shadow tables, maintenance flag, and temporary job workspace on failure/cancellation.
	 */
	public static function cleanup_failed_import( $job_id, array $state ) {
		global $wpdb;

		delete_option( 'lwpm_maintenance_active' );

		if ( ! empty( $state['shadow_map'] ) && is_array( $state['shadow_map'] ) ) {
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
			foreach ( $state['shadow_map'] as $info ) {
				if ( ! empty( $info['shadow'] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $info['shadow'] ) );
				}
			}
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
		}

		LWPM_Storage::delete_job( $job_id );
	}
}
