<?php
/**
 * Secure Storage, Backups Vault & Retention Manager — Liberty WP Migrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWPM_Storage {

	/**
	 * Normalize path slashes to forward slashes without trailing slash.
	 *
	 * @param string $path File or directory path.
	 * @return string
	 */
	public static function normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '#/+#', '/', $path );
		return rtrim( $path, '/' );
	}

	/**
	 * Return absolute path to the protected backups vault directory.
	 *
	 * @return string
	 */
	public static function get_backups_dir() {
		return self::normalize_path( WP_CONTENT_DIR ) . '/lwpm-backups';
	}

	/**
	 * Return absolute path to the jobs root directory.
	 *
	 * @return string
	 */
	public static function get_jobs_root() {
		return self::get_backups_dir() . '/jobs';
	}

	/**
	 * Validate job ID strictly to prevent directory traversal.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public static function is_valid_job_id( $job_id ) {
		return is_string( $job_id ) && 1 === preg_match( '/^[a-zA-Z0-9_-]{8,64}$/', $job_id );
	}

	/**
	 * Validate backup archive filename strictly.
	 *
	 * @param string $filename Archive filename.
	 * @return bool
	 */
	public static function is_valid_backup_filename( $filename ) {
		if ( ! is_string( $filename ) || '' === $filename ) {
			return false;
		}
		if ( basename( $filename ) !== $filename ) {
			return false;
		}
		if ( false !== strpos( $filename, '..' ) ) {
			return false;
		}
		return 1 === preg_match( '/^[a-zA-Z0-9._-]+\.zip$/i', $filename );
	}

	/**
	 * Ensure storage & jobs directories exist and are hardened against direct HTTP access.
	 *
	 * @return bool True on success.
	 */
	public static function ensure_storage_ready() {
		$backups_dir = self::get_backups_dir();
		$jobs_dir    = self::get_jobs_root();

		if ( ! is_dir( $backups_dir ) ) {
			wp_mkdir_p( $backups_dir );
		}
		if ( ! is_dir( $jobs_dir ) ) {
			wp_mkdir_p( $jobs_dir );
		}

		if ( ! is_dir( $backups_dir ) || ! is_writable( $backups_dir ) ) {
			return false;
		}

		// Apache 2.2 / 2.4 / LiteSpeed protection.
		$htaccess = $backups_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "# Liberty WP Migrator Security Protection\n";
			$rules .= "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
			@file_put_contents( $htaccess, $rules );
		}

		// IIS protection.
		$webconfig = $backups_dir . '/web.config';
		if ( ! file_exists( $webconfig ) ) {
			$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
			$xml .= "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n";
			@file_put_contents( $webconfig, $xml );
		}

		// Directory listing fallback protection.
		$index = $backups_dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$jobs_index = $jobs_dir . '/index.php';
		if ( ! file_exists( $jobs_index ) ) {
			@file_put_contents( $jobs_index, "<?php\n// Silence is golden.\n" );
		}

		return true;
	}

	/**
	 * Create a fresh job workspace directory and return its ID and path.
	 *
	 * @param string $prefix Job prefix ('exp', 'bak', 'sch', or 'imp').
	 * @return array{job_id:string,job_dir:string}|WP_Error
	 */
	public static function create_job( $prefix = 'job' ) {
		if ( ! self::ensure_storage_ready() ) {
			return new WP_Error( 'storage_not_writable', 'Backup directory wp-content/lwpm-backups is not writable.' );
		}

		try {
			$rand = bin2hex( random_bytes( 8 ) );
		} catch ( Exception $e ) {
			$rand = strtolower( wp_generate_password( 16, false, false ) );
		}

		$clean_prefix = preg_replace( '/[^a-z0-9]/i', '', $prefix );
		$job_id       = $clean_prefix . '_' . gmdate( 'YmdHis' ) . '_' . $rand;
		$job_dir      = self::get_jobs_root() . '/' . $job_id;

		if ( ! wp_mkdir_p( $job_dir ) ) {
			return new WP_Error( 'job_dir_failed', 'Could not create temporary job workspace directory.' );
		}

		return array(
			'job_id'  => $job_id,
			'job_dir' => $job_dir,
		);
	}

	/**
	 * Get absolute path to an existing job directory.
	 *
	 * @param string $job_id Job ID.
	 * @return string|WP_Error
	 */
	public static function get_job_dir( $job_id ) {
		if ( ! self::is_valid_job_id( $job_id ) ) {
			return new WP_Error( 'invalid_job_id', 'Invalid migration job identifier.' );
		}
		$dir = self::get_jobs_root() . '/' . $job_id;
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'job_not_found', 'Migration job workspace not found or already completed.' );
		}
		return $dir;
	}

	/**
	 * Atomically persist job state JSON.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $state  State payload.
	 * @return bool
	 */
	public static function save_job_state( $job_id, array $state ) {
		$dir = self::get_job_dir( $job_id );
		if ( is_wp_error( $dir ) ) {
			return false;
		}
		$state['updated_at'] = time();
		$json                = wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return false;
		}

		$target = $dir . '/state.json';
		$tmp    = $dir . '/state.json.tmp.' . wp_rand( 1000, 999999 );

		if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
			return false;
		}

		if ( ! @rename( $tmp, $target ) ) {
			if ( @copy( $tmp, $target ) ) {
				@unlink( $tmp );
				return true;
			}
			@unlink( $tmp );
			return false;
		}

		return true;
	}

	/**
	 * Load job state JSON.
	 *
	 * @param string $job_id Job ID.
	 * @return array|WP_Error
	 */
	public static function load_job_state( $job_id ) {
		$dir = self::get_job_dir( $job_id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$target = $dir . '/state.json';
		if ( ! file_exists( $target ) ) {
			return new WP_Error( 'state_missing', 'Migration job state file is missing.' );
		}
		$raw = @file_get_contents( $target );
		if ( false === $raw ) {
			return new WP_Error( 'state_unreadable', 'Could not read migration job state.' );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'state_corrupt', 'Migration job state JSON is corrupt.' );
		}
		return $decoded;
	}

	/**
	 * Delete a job workspace recursively.
	 *
	 * @param string $job_id Job ID.
	 */
	public static function delete_job( $job_id ) {
		if ( ! self::is_valid_job_id( $job_id ) ) {
			return;
		}
		$dir = self::get_jobs_root() . '/' . $job_id;
		if ( is_dir( $dir ) ) {
			self::rrmdir( $dir );
		}
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 */
	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = @scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Clean up abandoned job directories and orphan .part files older than 24 hours.
	 */
	public static function cleanup_stale_jobs() {
		$cutoff   = time() - DAY_IN_SECONDS;
		$jobs_dir = self::get_jobs_root();

		if ( is_dir( $jobs_dir ) ) {
			$items = @scandir( $jobs_dir );
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( '.' === $item || '..' === $item || 'index.php' === $item ) {
						continue;
					}
					$path = $jobs_dir . '/' . $item;
					if ( is_dir( $path ) ) {
						$state_file = $path . '/state.json';
						$mtime      = file_exists( $state_file ) ? (int) @filemtime( $state_file ) : (int) @filemtime( $path );
						if ( $mtime > 0 && $mtime < $cutoff ) {
							self::rrmdir( $path );
						}
					}
				}
			}
		}

		$parts = glob( self::get_backups_dir() . '/*.zip.part' );
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part_file ) {
				$mt = (int) @filemtime( $part_file );
				if ( $mt > 0 && $mt < $cutoff ) {
					@unlink( $part_file );
				}
			}
		}
	}

	/**
	 * Generate a unique, non-guessable backup filename for this site.
	 *
	 * @param string $trigger 'export', 'manual', or 'scheduled'.
	 * @return string Filename ending with .zip
	 */
	public static function generate_backup_filename( $trigger = 'export' ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		$slug = sanitize_title( ( $host ? $host : 'wordpress' ) . ( $path ? '-' . trim( $path, '/' ) : '' ) );
		if ( '' === $slug ) {
			$slug = 'wp-site';
		}
		$slug = substr( $slug, 0, 42 );

		$tag = 'exp';
		if ( 'manual' === $trigger ) {
			$tag = 'manual';
		} elseif ( 'scheduled' === $trigger ) {
			$tag = 'auto';
		}

		try {
			$secret = bin2hex( random_bytes( 4 ) );
		} catch ( Exception $e ) {
			$secret = strtolower( wp_generate_password( 8, false, false ) );
		}

		return $slug . '-' . $tag . '-' . gmdate( 'Ymd-His' ) . '-' . $secret . '.zip';
	}

	/**
	 * List all stored .zip archives in wp-content/lwpm-backups sorted newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_backups() {
		$dir = self::get_backups_dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( $dir . '/*.zip' );
		if ( empty( $files ) || ! is_array( $files ) ) {
			return array();
		}

		$is_admin_ctx = is_user_logged_in() && current_user_can( 'manage_options' );
		$list         = array();

		foreach ( $files as $filepath ) {
			$filename  = basename( $filepath );
			$size      = (float) @filesize( $filepath );
			$mtime     = (int) @filemtime( $filepath );
			$meta_file = substr( $filepath, 0, -4 ) . '.meta.json';
			$meta      = array();

			if ( file_exists( $meta_file ) ) {
				$decoded = json_decode( (string) @file_get_contents( $meta_file ), true );
				if ( is_array( $decoded ) ) {
					$meta = $decoded;
				}
			}

			// Infer backup trigger from metadata or filename tag.
			$trigger = isset( $meta['trigger'] ) ? (string) $meta['trigger'] : '';
			if ( ! in_array( $trigger, array( 'manual', 'scheduled', 'export' ), true ) ) {
				if ( false !== strpos( $filename, '-auto-' ) ) {
					$trigger = 'scheduled';
				} elseif ( false !== strpos( $filename, '-manual-' ) ) {
					$trigger = 'manual';
				} else {
					$trigger = 'export';
				}
			}

			$dl_url = '';
			if ( $is_admin_ctx ) {
				$dl_url = add_query_arg(
					array(
						'action'   => 'lwpm_download_backup',
						'file'     => rawurlencode( $filename ),
						'_wpnonce' => wp_create_nonce( 'lwpm_download_' . $filename ),
					),
					admin_url( 'admin-ajax.php' )
				);
			}

			$list[] = array(
				'filename'     => $filename,
				'trigger'      => $trigger,
				'note'         => isset( $meta['note'] ) ? (string) $meta['note'] : '',
				'size_bytes'   => $size,
				'size_human'   => size_format( $size, 2 ),
				'created_ts'   => $mtime,
				'created_at'   => wp_date( 'Y-m-d H:i', $mtime ),
				'source_url'   => isset( $meta['home_url'] ) ? (string) $meta['home_url'] : home_url(),
				'wp_version'   => isset( $meta['wp_version'] ) ? (string) $meta['wp_version'] : '',
				'files'        => isset( $meta['total_files'] ) ? (int) $meta['total_files'] : 0,
				'tables'       => isset( $meta['tables_count'] ) ? (int) $meta['tables_count'] : 0,
				'download_url' => $dl_url,
			);
		}

		usort(
			$list,
			function ( $a, $b ) {
				return $b['created_ts'] <=> $a['created_ts'];
			}
		);

		return $list;
	}

	/**
	 * Enforce retention policy on automated scheduled backups (never deletes manual or export backups).
	 *
	 * @param int $keep_count Number of most-recent scheduled backups to keep (0 = unlimited).
	 * @return int Number of old scheduled backups pruned.
	 */
	public static function enforce_scheduled_retention( $keep_count ) {
		$keep_count = (int) $keep_count;
		if ( $keep_count <= 0 ) {
			return 0;
		}

		$all       = self::list_backups(); // Already sorted newest first.
		$scheduled = array();
		foreach ( $all as $item ) {
			if ( 'scheduled' === $item['trigger'] ) {
				$scheduled[] = $item['filename'];
			}
		}

		if ( count( $scheduled ) <= $keep_count ) {
			return 0;
		}

		$to_prune = array_slice( $scheduled, $keep_count );
		$pruned   = 0;
		foreach ( $to_prune as $fname ) {
			$res = self::delete_backup( $fname );
			if ( true === $res ) {
				$pruned++;
			}
		}

		return $pruned;
	}

	/**
	 * Delete a stored backup .zip and its sidecar .meta.json.
	 *
	 * @param string $filename Archive filename.
	 * @return bool|WP_Error
	 */
	public static function delete_backup( $filename ) {
		if ( ! self::is_valid_backup_filename( $filename ) ) {
			return new WP_Error( 'invalid_filename', 'Invalid backup filename.' );
		}
		$filepath = self::get_backups_dir() . '/' . $filename;
		if ( ! file_exists( $filepath ) ) {
			return new WP_Error( 'not_found', 'Backup archive does not exist.' );
		}
		$meta_file = substr( $filepath, 0, -4 ) . '.meta.json';
		if ( file_exists( $meta_file ) ) {
			@unlink( $meta_file );
		}
		if ( ! @unlink( $filepath ) ) {
			return new WP_Error( 'delete_failed', 'Could not delete backup file from disk.' );
		}
		return true;
	}
}
