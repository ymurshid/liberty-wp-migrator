<?php
/**
 * Import State-Machine Coordinator — LibertyDev Site Migrator.
 *
 * Enforces Multisite Super Admin permissions, restores archives into WordPress's canonically
 * configured directories with strict symlink boundary enforcement, and stores temporary extraction
 * metadata and SQL dumps in non-executable protected data files inside the unguessable vault.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LDSM_Importer {

	/**
	 * Capture current destination WordPress environment, canonical directory roots, & active administrator details.
	 *
	 * @return array<string,mixed>
	 */
	private static function capture_destination_context() {
		global $wpdb;

		$upload_dir = wp_upload_dir( null, false );
		$roots      = LDSM_Storage::get_content_roots();

		$dest_info = array(
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
		);

		$preserved_admin = array();
		$curr_user       = wp_get_current_user();
		if ( $curr_user && $curr_user->exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

		LDSM_Storage::ensure_storage_ready();
		$dest_vault_key = (string) get_option( 'ldsm_vault_key', '' );

		return array(
			'dest_info'       => $dest_info,
			'preserved_admin' => $preserved_admin,
			'dest_vault_key'  => $dest_vault_key,
		);
	}

	/**
	 * Initialize a new chunked upload Import job.
	 *
	 * @param string $filename   Original archive filename.
	 * @param int    $total_size Total archive byte size.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function init_upload_job( $filename, $total_size ) {
		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Super Admin / Administrator permissions are required to import a site.' );
		}

		$clean_filename = sanitize_file_name( (string) $filename );
		if ( '.zip' !== strtolower( substr( $clean_filename, -4 ) ) ) {
			return new WP_Error( 'invalid_extension', 'Only .zip archives created by LibertyDev Site Migrator are supported.' );
		}
		if ( (int) $total_size < 22 ) {
			return new WP_Error( 'file_too_small', 'Selected archive file is empty or invalid.' );
		}

		$job = LDSM_Storage::create_job( 'imp' );
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
			'dest_vault_key'     => $ctx['dest_vault_key'],
		);

		if ( ! LDSM_Storage::save_job_state( $job['job_id'], $state ) ) {
			LDSM_Storage::delete_job( $job['job_id'] );
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
	 *
	 * @param string $job_id       Import job ID.
	 * @param int    $offset       Expected byte offset.
	 * @param string $tmp_filepath Temporary uploaded chunk path from $_FILES.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function receive_upload_chunk( $job_id, $offset, $tmp_filepath ) {
		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Super Admin / Administrator permissions are required to upload site archives.' );
		}

		$job_dir = LDSM_Storage::get_job_dir( $job_id );
		if ( is_wp_error( $job_dir ) ) {
			return $job_dir;
		}

		$state = LDSM_Storage::load_job_state( $job_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( 'uploading' !== $state['phase'] && 'ready_to_inspect' !== $state['phase'] ) {
			return new WP_Error( 'invalid_upload_phase', 'Job is no longer accepting upload chunks.' );
		}

		if ( ! is_uploaded_file( $tmp_filepath ) || ! is_readable( $tmp_filepath ) ) {
			return new WP_Error( 'chunk_missing', 'Uploaded chunk payload failed upload verification.' );
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

		$zip_path   = (string) $state['zip_path'];
		$safe_check = LDSM_Storage::prepare_safe_write_target( $zip_path, $job_dir );
		if ( is_wp_error( $safe_check ) ) {
			return $safe_check;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming binary chunk assembly at exact offset.
		$out = @fopen( $zip_path, file_exists( $zip_path ) ? 'r+b' : 'w+b' );
		if ( ! $out ) {
			return new WP_Error( 'pkg_open_failed', 'Cannot open target upload package for writing.' );
		}

		if ( 0 !== @fseek( $out, $offset, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out );
			return new WP_Error( 'pkg_seek_failed', 'Failed seeking to chunk offset.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$in = @fopen( $tmp_filepath, 'rb' );
		if ( ! $in ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out );
			return new WP_Error( 'chunk_open_failed', 'Failed reading uploaded temporary chunk.' );
		}

		$copied = stream_copy_to_stream( $in, $out );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $in );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );

		if ( false === $copied || $copied !== $chunk_size ) {
			return new WP_Error( 'chunk_write_failed', 'Disk write failed while appending upload chunk.' );
		}

		$state['uploaded_bytes'] = $offset + $copied;
		$complete                = ( $state['uploaded_bytes'] >= (int) $state['total_upload_bytes'] );
		if ( $complete ) {
			$state['phase'] = 'ready_to_inspect';
		}

		LDSM_Storage::save_job_state( $job_id, $state );

		return array(
			'job_id'          => $job_id,
			'uploaded_bytes'  => $state['uploaded_bytes'],
			'upload_complete' => $complete,
		);
	}

	/**
	 * Initialize an Import job directly from an existing .zip archive stored in the secret vault.
	 *
	 * @param string $backup_filename Stored archive filename.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function init_from_stored_backup( $backup_filename ) {
		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Super Admin / Administrator permissions are required to restore backups.' );
		}

		if ( ! LDSM_Storage::is_valid_backup_filename( $backup_filename ) ) {
			return new WP_Error( 'invalid_backup', 'Invalid backup filename.' );
		}

		$zip_path = LDSM_Storage::resolve_backup_disk_path( $backup_filename );
		if ( '' === $zip_path || ! is_file( $zip_path ) || is_link( $zip_path ) || ! is_readable( $zip_path ) ) {
			return new WP_Error( 'backup_not_found', 'Stored backup file does not exist.' );
		}

		$job = LDSM_Storage::create_job( 'imp' );
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
			'dest_vault_key'   => $ctx['dest_vault_key'],
		);

		LDSM_Storage::save_job_state( $job['job_id'], $state );

		return self::inspect_package( $job['job_id'] );
	}

	/**
	 * Inspect the uploaded/selected .zip archive and return pre-flight summary.
	 *
	 * @param string $job_id Import job ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function inspect_package( $job_id ) {
		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Insufficient permissions to inspect migration archives.' );
		}

		$job_dir = LDSM_Storage::get_job_dir( $job_id );
		if ( is_wp_error( $job_dir ) ) {
			return $job_dir;
		}

		$state = LDSM_Storage::load_job_state( $job_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$index_dat = $job_dir . '/extract_index.dat';
		$summary   = LDSM_Stream_Zip_Reader::index_archive( (string) $state['zip_path'], $index_dat );
		if ( is_wp_error( $summary ) ) {
			LDSM_Storage::delete_job( $job_id );
			return $summary;
		}

		$manifest = $summary['manifest'];

		$state['phase']             = 'awaiting_confirmation';
		$state['manifest']          = $manifest;
		$state['db_entry']          = $summary['db_entry'];
		$state['files_count']       = (int) $summary['files_count'];
		$state['uncompressed_size'] = (int) $summary['uncompressed_size'];

		LDSM_Storage::save_job_state( $job_id, $state );

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
	 *
	 * @param string $job_id         Import job ID.
	 * @param array  $import_options Sanitized import options.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function start_confirmed_import( $job_id, array $import_options ) {
		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Super Admin / Administrator permissions are required to execute a site import.' );
		}

		$state = LDSM_Storage::load_job_state( $job_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( 'awaiting_confirmation' !== $state['phase'] ) {
			return new WP_Error( 'invalid_confirm_state', 'Archive must be inspected before starting import.' );
		}

		$state['import_options'] = array(
			'keep_current_admin' => ! empty( $import_options['keep_current_admin'] ),
			'skip_guid'          => ! empty( $import_options['skip_guid'] ),
		);

		$state['phase']                = 'extract_files';
		$state['db_extracted']         = false;
		$state['extract_index_offset'] = 0;
		$state['files_extracted']      = 0;
		$state['current_extract_item'] = null;
		$state['partial_extract']      = null;

		LDSM_Storage::save_job_state( $job_id, $state );
		update_option( 'ldsm_maintenance_active', time(), false );

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
	 *
	 * @param string $job_id Import job ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function run_tick( $job_id ) {
		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Super Admin / Administrator permissions are required to continue site import.' );
		}

		$job_dir = LDSM_Storage::get_job_dir( $job_id );
		if ( is_wp_error( $job_dir ) ) {
			delete_option( 'ldsm_maintenance_active' );
			return $job_dir;
		}

		$state = LDSM_Storage::load_job_state( $job_id );
		if ( is_wp_error( $state ) ) {
			delete_option( 'ldsm_maintenance_active' );
			return $state;
		}

		update_option( 'ldsm_maintenance_active', time(), false );

		$deadline = microtime( true ) + LDSM_TIME_BUDGET;
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

			LDSM_Storage::save_job_state( $job_id, $state );

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
			$db_res   = LDSM_DB_Importer::import_sql_step( $state, $sql_file, $deadline );
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

			LDSM_Storage::save_job_state( $job_id, $state );

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
			$replacer = LDSM_Replacer::from_migration_context(
				(array) $state['manifest'],
				(array) $state['dest_info']
			);

			$sr_res = LDSM_DB_Importer::search_replace_step( $state, $replacer, $deadline );
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

			LDSM_Storage::save_job_state( $job_id, $state );

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
			$swap_res = LDSM_DB_Importer::finalize_and_atomic_swap( $state );
			if ( is_wp_error( $swap_res ) ) {
				self::cleanup_failed_import( $job_id, $state );
				return $swap_res;
			}

			$roots     = LDSM_Storage::get_content_roots();
			$staged_mu = $job_dir . '/staged_mu_plugins';
			if ( is_dir( $staged_mu ) && ! empty( $roots['mu-plugins'] ) && LDSM_Storage::current_user_can_manage() ) {
				$target_mu = $roots['mu-plugins'];
				self::copy_dir_safe_recursive( $staged_mu, $target_mu, $target_mu );
			}

			// Purge Elementor generated CSS cache dynamically inside the configured uploads folder.
			$uploads_info = wp_upload_dir( null, false );
			if ( empty( $uploads_info['error'] ) && ! empty( $uploads_info['basedir'] ) ) {
				$elementor_css_dir = LDSM_Storage::normalize_path( $uploads_info['basedir'] ) . '/elementor/css';
				if ( is_dir( $elementor_css_dir ) && ! is_link( $elementor_css_dir ) ) {
					$css_files = glob( $elementor_css_dir . '/*.css' );
					if ( is_array( $css_files ) ) {
						foreach ( $css_files as $cf ) {
							if ( is_file( $cf ) && ! is_link( $cf ) ) {
								wp_delete_file( $cf );
							}
						}
					}
				}
			}

			if ( function_exists( 'wp_cache_flush' ) ) {
				@wp_cache_flush();
			}

			LDSM_Scheduler::sync_cron_with_settings();

			update_option( 'ldsm_needs_rewrite_flush', 1, true );
			delete_option( 'ldsm_maintenance_active' );

			if ( is_int( $swap_res ) && $swap_res > 0 && ! headers_sent() ) {
				wp_clear_auth_cookie();
				wp_set_current_user( $swap_res );
				wp_set_auth_cookie( $swap_res, true );
			}

			$dest_home = (string) $state['dest_info']['home_url'];
			$dest_site = (string) $state['dest_info']['site_url'];

			LDSM_Storage::delete_job( $job_id );

			return array(
				'done'      => true,
				'job_id'    => $job_id,
				'phase'     => 'complete',
				'percent'   => 100,
				'home_url'  => $dest_home,
				'admin_url' => trailingslashit( $dest_site ) . 'wp-admin/admin.php?page=libertydev-site-migrator',
			);
		}

		delete_option( 'ldsm_maintenance_active' );
		return new WP_Error( 'unknown_import_phase', 'Unknown import phase: ' . $phase );
	}

	/**
	 * Extract database.sql (as protected non-executable data file) and site content files in time-boxed batches.
	 *
	 * @param string $job_dir  Job directory.
	 * @param array  $state    Job state (modified by reference).
	 * @param float  $deadline Unix microtime deadline.
	 * @return bool|WP_Error
	 */
	private static function extract_step( $job_dir, array &$state, $deadline ) {
		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Super Admin / Administrator permissions are required for archive extraction.' );
		}

		$zip_path = (string) $state['zip_path'];

		if ( empty( $state['db_extracted'] ) ) {
			$sql_dest = $job_dir . '/database.sql';
			$db_res   = LDSM_Stream_Zip_Reader::extract_entry_to_file_chunked(
				$zip_path,
				(array) $state['db_entry'],
				$sql_dest,
				$job_dir,
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

		$roots = LDSM_Storage::get_content_roots();

		if ( ! empty( $state['current_extract_item'] ) && ! empty( $state['partial_extract'] ) ) {
			$item   = $state['current_extract_item'];
			$target = self::resolve_safe_extract_target( $item, $roots, $job_dir );
			if ( null !== $target ) {
				$p_res = LDSM_Stream_Zip_Reader::extract_entry_to_file_chunked(
					$zip_path,
					$item,
					$target['dest_path'],
					$target['allowed_root'],
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

		$index_dat = $job_dir . '/extract_index.dat';
		if ( ! file_exists( $index_dat ) ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming byte-offset extraction index cursor across ticks.
		$fp = @fopen( $index_dat, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'extract_index_open_failed', 'Cannot open protected extraction index.' );
		}

		if ( 0 !== @fseek( $fp, (int) $state['extract_index_offset'], SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'extract_index_seek_failed', 'Failed seeking in protected extraction index.' );
		}

		while ( ( $line = fgets( $fp ) ) !== false ) {
			$next_offset = (int) ftell( $fp );
			$trimmed     = trim( $line );
			if ( '' === $trimmed ) {
				$state['extract_index_offset'] = $next_offset;
				continue;
			}

			$item = json_decode( $trimmed, true );
			if ( ! is_array( $item ) || empty( $item['root_key'] ) || empty( $item['sub_path'] ) ) {
				$state['extract_index_offset'] = $next_offset;
				continue;
			}

			$target = self::resolve_safe_extract_target( $item, $roots, $job_dir );
			if ( null === $target ) {
				$state['extract_index_offset'] = $next_offset;
				$state['files_extracted']     += 1;
				continue;
			}

			$res = LDSM_Stream_Zip_Reader::extract_entry_to_file_chunked(
				$zip_path,
				$item,
				$target['dest_path'],
				$target['allowed_root'],
				$state['partial_extract'],
				$deadline
			);

			if ( is_wp_error( $res ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return $res;
			}

			$state['extract_index_offset'] = $next_offset;

			if ( false === $res ) {
				$state['current_extract_item'] = $item;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return false;
			}

			$state['files_extracted'] += 1;

			if ( microtime( true ) >= $deadline ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return false;
			}
		}

		$is_eof                        = feof( $fp );
		$state['extract_index_offset'] = (int) ftell( $fp );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		return $is_eof && empty( $state['partial_extract'] );
	}

	/**
	 * Map logical archive entry ({root_key}/{sub_path}) to destination path and canonical allowed root.
	 *
	 * @param array  $item    Indexed archive entry.
	 * @param array  $roots   Canonical destination content roots.
	 * @param string $job_dir Job workspace directory.
	 * @return array{dest_path:string,allowed_root:string}|null
	 */
	private static function resolve_safe_extract_target( array $item, array $roots, $job_dir ) {
		$root_key = isset( $item['root_key'] ) ? (string) $item['root_key'] : '';
		$raw_sub  = isset( $item['sub_path'] ) ? (string) $item['sub_path'] : '';

		$sub_path = LDSM_Stream_Zip_Reader::sanitize_safe_rel_path( $raw_sub );
		if ( false === $sub_path || '' === $sub_path ) {
			return null;
		}

		$lower_sub  = strtolower( $sub_path );
		$own_folder = strtolower( basename( rtrim( LDSM_PLUGIN_DIR, '/\\' ) ) );

		if ( 'plugins' === $root_key ) {
			if (
				0 === strpos( $lower_sub, $own_folder . '/' ) ||
				0 === strpos( $lower_sub, 'libertydev-site-migrator/' ) ||
				0 === strpos( $lower_sub, 'liberty-wp-migrator/' ) ||
				0 === strpos( $lower_sub, 'omni-wp-migrator/' )
			) {
				return null;
			}
		}

		if ( 'uploads' === $root_key ) {
			if (
				0 === strpos( $lower_sub, 'libertydev-site-migrator/' ) ||
				0 === strpos( $lower_sub, 'lwpm-backups/' ) ||
				0 === strpos( $lower_sub, 'owpm-backups/' )
			) {
				return null;
			}
		}

		if ( 'mu-plugins' === $root_key ) {
			$staged_root = $job_dir . '/staged_mu_plugins';
			return array(
				'dest_path'    => $staged_root . '/' . $sub_path,
				'allowed_root' => $staged_root,
			);
		}

		if ( empty( $roots[ $root_key ] ) ) {
			return null;
		}

		return array(
			'dest_path'    => $roots[ $root_key ] . '/' . $sub_path,
			'allowed_root' => $roots[ $root_key ],
		);
	}

	/**
	 * Recursively copy staged mu-plugins into WPMU_PLUGIN_DIR while enforcing Super Admin capability and blocking symlinks.
	 *
	 * @param string $src          Staged source directory.
	 * @param string $dst          Destination directory.
	 * @param string $allowed_root Canonical allowed destination root.
	 */
	private static function copy_dir_safe_recursive( $src, $dst, $allowed_root ) {
		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return;
		}
		if ( ! is_dir( $src ) || is_link( $src ) || is_link( $dst ) ) {
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
			if ( is_link( $s ) || is_link( $d ) ) {
				continue;
			}
			if ( is_dir( $s ) ) {
				self::copy_dir_safe_recursive( $s, $d, $allowed_root );
			} elseif ( is_file( $s ) ) {
				$safe_check = LDSM_Storage::prepare_safe_write_target( $d, $allowed_root );
				if ( ! is_wp_error( $safe_check ) && ! is_link( $d ) ) {
					@copy( $s, $d );
				}
			}
		}
	}

	/**
	 * Clean up shadow tables, maintenance flag, and temporary job workspace on failure/cancellation.
	 *
	 * @param string $job_id Import job ID.
	 * @param array  $state  Import job state.
	 */
	public static function cleanup_failed_import( $job_id, array $state ) {
		global $wpdb;

		delete_option( 'ldsm_maintenance_active' );

		if ( ! empty( $state['shadow_map'] ) && is_array( $state['shadow_map'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
			foreach ( $state['shadow_map'] as $info ) {
				if ( ! empty( $info['shadow'] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $info['shadow'] ) );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
		}

		LDSM_Storage::delete_job( $job_id );
	}
}
