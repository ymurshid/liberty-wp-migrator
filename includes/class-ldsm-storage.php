<?php
/**
 * Protected Uploads Vault, Multisite Capability Gate, Canonical Directory Resolver & Symlink Guard.
 *
 * Security Architecture:
 * 1. Enforces Super Admin (manage_network_options) on WordPress Multisite and Administrator (manage_options)
 *    on single-site installs so subsite admins on Multisite cannot modify shared network plugins/themes/mu-plugins.
 * 2. Stores transient migration job state inside the WordPress database (non-autoloaded options) rather than
 *    executable files on disk, and stores backup archives & large streaming work files as strictly non-executable
 *    protected data files (.zip, .json, .dat, .sql) inside a cryptographically unguessable 128-bit vault under
 *    wp_upload_dir()['basedir'] . '/libertydev-site-migrator/vault-{32_hex}/' shielded with .htaccess, web.config,
 *    and index.html directory-listing barriers (zero .php files are ever written inside uploads).
 * 3. Every extraction/copy destination is validated segment-by-segment with is_link() + realpath() confinement.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LDSM_Storage {

	/**
	 * Option key tracking active migration job IDs for reliable cleanup.
	 */
	const JOB_INDEX_OPTION = 'ldsm_active_jobs';

	/**
	 * Return the minimum WordPress capability required to operate migrations or backups.
	 * On Multisite, only Network Super Admins can modify shared plugins, themes, mu-plugins, or network tables.
	 *
	 * @return string
	 */
	public static function get_required_capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Check whether the current authenticated user is authorized to manage migrations and backups.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		if ( is_multisite() ) {
			return is_super_admin() && current_user_can( 'manage_network_options' );
		}
		return current_user_can( 'manage_options' );
	}

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
	 * Initialize and return WP_Filesystem instance.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	public static function get_wp_filesystem() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
		}
		return $wp_filesystem ? $wp_filesystem : null;
	}

	/**
	 * Move/rename a file atomically using WP_Filesystem with symlink refusal and copy+delete fallback.
	 *
	 * @param string $source      Source file path.
	 * @param string $destination Destination file path.
	 * @return bool
	 */
	public static function move_file( $source, $destination ) {
		if ( is_link( $source ) || is_link( $destination ) ) {
			return false;
		}
		$fs = self::get_wp_filesystem();
		if ( $fs && $fs->move( $source, $destination, true ) ) {
			return true;
		}
		if ( @copy( $source, $destination ) ) {
			wp_delete_file( $source );
			return true;
		}
		return false;
	}

	/**
	 * Recursively delete a directory using WP_Filesystem.
	 *
	 * @param string $dir Directory path.
	 */
	public static function delete_dir_recursive( $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		$fs = self::get_wp_filesystem();
		if ( $fs ) {
			$fs->delete( $dir, true );
		}
	}

	/**
	 * Resolve WordPress's configured directories dynamically (supports relocated uploads, plugins, mu-plugins, themes, and languages).
	 *
	 * @return array<string,string> Map of logical_key => normalized absolute directory path.
	 */
	public static function get_content_roots() {
		$uploads     = wp_upload_dir( null, false );
		$uploads_dir = ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) ? self::normalize_path( $uploads['basedir'] ) : '';
		$plugins_dir = defined( 'WP_PLUGIN_DIR' ) ? self::normalize_path( WP_PLUGIN_DIR ) : self::normalize_path( dirname( LDSM_PLUGIN_DIR ) );
		$mu_dir      = defined( 'WPMU_PLUGIN_DIR' ) ? self::normalize_path( WPMU_PLUGIN_DIR ) : '';
		$themes_dir  = function_exists( 'get_theme_root' ) ? self::normalize_path( get_theme_root() ) : '';
		$lang_dir    = defined( 'WP_LANG_DIR' ) ? self::normalize_path( WP_LANG_DIR ) : '';

		$roots = array(
			'plugins'    => $plugins_dir,
			'mu-plugins' => $mu_dir,
			'themes'     => $themes_dir,
			'uploads'    => $uploads_dir,
			'languages'  => $lang_dir,
		);

		return array_filter(
			$roots,
			function ( $p ) {
				return is_string( $p ) && '' !== $p;
			}
		);
	}

	/**
	 * Return outer plugin folder inside WordPress uploads: wp_upload_dir()['basedir'] . '/libertydev-site-migrator'.
	 * Never falls back to hardcoded directory assumptions.
	 *
	 * @return string Normalized directory path or empty string if wp_upload_dir() is unavailable.
	 */
	public static function get_outer_uploads_folder() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		return self::normalize_path( $uploads['basedir'] ) . '/libertydev-site-migrator';
	}

	/**
	 * Return or generate the 128-bit cryptographic vault directory key.
	 *
	 * @return string 32-character hex key.
	 */
	private static function get_or_create_vault_key() {
		$key = get_option( 'ldsm_vault_key', '' );
		if ( is_string( $key ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return $key;
		}
		try {
			$key = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			$key = md5( wp_generate_password( 64, true, true ) . microtime( true ) );
		}
		update_option( 'ldsm_vault_key', $key, false );
		return $key;
	}

	/**
	 * Return absolute path to the cryptographically unguessable backups vault directory.
	 *
	 * @return string
	 */
	public static function get_backups_dir() {
		$outer = self::get_outer_uploads_folder();
		if ( '' === $outer ) {
			return '';
		}
		return $outer . '/vault-' . self::get_or_create_vault_key();
	}

	/**
	 * Return absolute path to the jobs root directory inside the secret vault.
	 *
	 * @return string
	 */
	public static function get_jobs_root() {
		$vault = self::get_backups_dir();
		if ( '' === $vault ) {
			return '';
		}
		return $vault . '/jobs';
	}

	/**
	 * Validate job ID strictly to prevent directory traversal.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public static function is_valid_job_id( $job_id ) {
		return is_string( $job_id ) && 1 === preg_match( '/^[a-zA-Z0-9_-]{8,80}$/', $job_id );
	}

	/**
	 * Validate logical backup archive filename strictly (ends with .zip).
	 *
	 * @param string $filename Logical archive filename.
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
	 * Resolve on-disk path for a stored backup archive inside the protected vault.
	 *
	 * @param string $filename Archive filename ending with .zip.
	 * @return string Absolute on-disk path or empty string if not found/invalid.
	 */
	public static function resolve_backup_disk_path( $filename ) {
		if ( ! self::is_valid_backup_filename( $filename ) ) {
			return '';
		}
		$dir = self::get_backups_dir();
		if ( '' === $dir ) {
			return '';
		}
		$path = $dir . '/' . $filename;
		if ( is_file( $path ) && ! is_link( $path ) ) {
			return $path;
		}
		return '';
	}

	/**
	 * Ensure outer folder, secret vault, and jobs directories exist and are hardened across Apache, Nginx, and IIS.
	 *
	 * @return bool True on success.
	 */
	public static function ensure_storage_ready() {
		$outer_dir   = self::get_outer_uploads_folder();
		$backups_dir = self::get_backups_dir();
		$jobs_dir    = self::get_jobs_root();

		if ( '' === $outer_dir || '' === $backups_dir || '' === $jobs_dir ) {
			return false;
		}

		foreach ( array( $outer_dir, $backups_dir, $jobs_dir ) as $dir ) {
			if ( is_link( $dir ) ) {
				return false;
			}
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}

		if ( ! is_dir( $backups_dir ) || ! wp_is_writable( $backups_dir ) ) {
			return false;
		}

		$htaccess_rules  = "# LibertyDev Site Migrator Vault Protection\n";
		$htaccess_rules .= "Options -Indexes\n";
		$htaccess_rules .= "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n";
		$htaccess_rules .= "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

		$webconfig_xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$webconfig_xml .= "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n";

		foreach ( array( $outer_dir, $backups_dir, $jobs_dir ) as $sec_dir ) {
			if ( ! file_exists( $sec_dir . '/.htaccess' ) ) {
				@file_put_contents( $sec_dir . '/.htaccess', $htaccess_rules );
			}
			if ( ! file_exists( $sec_dir . '/web.config' ) ) {
				@file_put_contents( $sec_dir . '/web.config', $webconfig_xml );
			}
			if ( ! file_exists( $sec_dir . '/index.html' ) ) {
				@file_put_contents( $sec_dir . '/index.html', '' );
			}
		}

		return true;
	}

	/**
	 * Write a non-executable JSON metadata file atomically inside the protected vault.
	 *
	 * @param string $filepath Target .json file path.
	 * @param array  $data     Array to encode as JSON.
	 * @return bool
	 */
	public static function write_json_file( $filepath, array $data ) {
		if ( is_link( $filepath ) ) {
			return false;
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return false;
		}
		$tmp = $filepath . '.tmp.' . wp_rand( 1000, 999999 );

		if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
			return false;
		}
		if ( ! self::move_file( $tmp, $filepath ) ) {
			wp_delete_file( $tmp );
			return false;
		}
		return true;
	}

	/**
	 * Read and decode a non-executable JSON metadata file from the protected vault.
	 *
	 * @param string $filepath Source .json file path.
	 * @return array|null
	 */
	public static function read_json_file( $filepath ) {
		if ( ! is_file( $filepath ) || is_link( $filepath ) || ! is_readable( $filepath ) ) {
			return null;
		}
		$raw = @file_get_contents( $filepath );
		if ( false === $raw ) {
			return null;
		}
		$decoded = json_decode( trim( $raw ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Validate and prepare a destination file path for extraction or copy,
	 * rejecting any symbolic link in any ancestor segment or target file and verifying realpath confinement.
	 *
	 * @param string $dest_path    Target file path.
	 * @param string $allowed_root Canonical allowed root directory.
	 * @return true|WP_Error
	 */
	public static function prepare_safe_write_target( $dest_path, $allowed_root ) {
		$dest_norm = self::normalize_path( $dest_path );
		$root_norm = self::normalize_path( $allowed_root );

		if ( '' === $root_norm || '' === $dest_norm ) {
			return new WP_Error( 'invalid_target_path', 'Empty extraction path.' );
		}
		if ( false !== strpos( $dest_norm, "\0" ) ) {
			return new WP_Error( 'null_byte_path', 'Null byte in extraction path.' );
		}
		if ( 0 !== strpos( $dest_norm, $root_norm . '/' ) ) {
			return new WP_Error( 'outside_root', 'Target path lies outside allowed directory boundary.' );
		}

		if ( ! is_dir( $root_norm ) ) {
			if ( is_link( $root_norm ) || ! wp_mkdir_p( $root_norm ) ) {
				return new WP_Error( 'root_mkdir_failed', 'Could not initialize target root directory.' );
			}
		}

		$rel      = substr( $dest_norm, strlen( $root_norm ) + 1 );
		$segments = explode( '/', $rel );
		$last_idx = count( $segments ) - 1;
		$curr     = $root_norm;

		foreach ( $segments as $idx => $seg ) {
			if ( '' === $seg || '.' === $seg || '..' === $seg ) {
				return new WP_Error( 'traversal_segment', 'Invalid relative path segment.' );
			}
			$curr .= '/' . $seg;

			if ( is_link( $curr ) ) {
				return new WP_Error( 'symlink_blocked', 'Refusing to write through symbolic link: ' . $seg );
			}

			if ( $idx < $last_idx ) {
				if ( file_exists( $curr ) && ! is_dir( $curr ) ) {
					return new WP_Error( 'not_a_dir', 'Path component exists as a non-directory file.' );
				}
				if ( ! is_dir( $curr ) && ! wp_mkdir_p( $curr ) ) {
					return new WP_Error( 'mkdir_failed', 'Could not create directory component.' );
				}
				if ( is_link( $curr ) ) {
					return new WP_Error( 'symlink_blocked', 'Created directory resolved to a symbolic link.' );
				}
			}
		}

		$real_root   = realpath( $root_norm );
		$real_parent = realpath( dirname( $dest_norm ) );
		if ( false === $real_root || false === $real_parent ) {
			return new WP_Error( 'realpath_failed', 'Could not verify canonical destination path.' );
		}

		$real_root_norm   = self::normalize_path( $real_root );
		$real_parent_norm = self::normalize_path( $real_parent );

		if ( $real_parent_norm !== $real_root_norm && 0 !== strpos( $real_parent_norm, $real_root_norm . '/' ) ) {
			return new WP_Error( 'escaped_boundary', 'Destination directory escaped canonical root boundary.' );
		}

		if ( is_link( $dest_norm ) ) {
			return new WP_Error( 'symlink_target', 'Refusing to overwrite symbolic link target.' );
		}

		return true;
	}

	/**
	 * Create a fresh job workspace directory with an unguessable 128-bit ID and directory shields.
	 *
	 * @param string $prefix Job prefix ('exp', 'bak', 'sch', or 'imp').
	 * @return array{job_id:string,job_dir:string}|WP_Error
	 */
	public static function create_job( $prefix = 'job' ) {
		if ( ! self::ensure_storage_ready() ) {
			return new WP_Error( 'storage_not_writable', 'Uploads backup vault directory is not writable.' );
		}

		try {
			$rand = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			$rand = strtolower( wp_generate_password( 32, false, false ) );
		}

		$clean_prefix = preg_replace( '/[^a-z0-9]/i', '', $prefix );
		$job_id       = $clean_prefix . '_' . gmdate( 'YmdHis' ) . '_' . $rand;
		$job_dir      = self::get_jobs_root() . '/' . $job_id;

		if ( ! wp_mkdir_p( $job_dir ) ) {
			return new WP_Error( 'job_dir_failed', 'Could not create temporary job workspace directory.' );
		}

		@file_put_contents( $job_dir . '/index.html', '' );

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
		$jobs_root = self::get_jobs_root();
		if ( '' === $jobs_root ) {
			return new WP_Error( 'storage_unavailable', 'Uploads directory is unavailable.' );
		}
		$dir = $jobs_root . '/' . $job_id;
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return new WP_Error( 'job_not_found', 'Migration job workspace not found or already completed.' );
		}
		return $dir;
	}

	/**
	 * Persist job state in the WordPress database (non-autoloaded option) rather than an executable file on disk.
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
		$opt_key             = 'ldsm_job_' . $job_id;
		$saved               = update_option( $opt_key, $state, false );

		// update_option() returns false when value is unchanged; verify presence.
		if ( ! $saved && ! is_array( get_option( $opt_key, null ) ) ) {
			return false;
		}

		$index = get_option( self::JOB_INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		$index[ $job_id ] = $state['updated_at'];
		update_option( self::JOB_INDEX_OPTION, $index, false );

		return true;
	}

	/**
	 * Load job state from the WordPress database.
	 *
	 * @param string $job_id Job ID.
	 * @return array|WP_Error
	 */
	public static function load_job_state( $job_id ) {
		$dir = self::get_job_dir( $job_id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$state = get_option( 'ldsm_job_' . $job_id, null );
		if ( ! is_array( $state ) ) {
			return new WP_Error( 'state_missing_or_corrupt', 'Migration job state is missing or expired.' );
		}
		return $state;
	}

	/**
	 * Delete a job workspace recursively and remove its state option from the database.
	 *
	 * @param string $job_id Job ID.
	 */
	public static function delete_job( $job_id ) {
		if ( ! self::is_valid_job_id( $job_id ) ) {
			return;
		}
		delete_option( 'ldsm_job_' . $job_id );

		$index = get_option( self::JOB_INDEX_OPTION, array() );
		if ( is_array( $index ) && isset( $index[ $job_id ] ) ) {
			unset( $index[ $job_id ] );
			update_option( self::JOB_INDEX_OPTION, $index, false );
		}

		$jobs_root = self::get_jobs_root();
		if ( '' === $jobs_root ) {
			return;
		}
		$dir = $jobs_root . '/' . $job_id;
		if ( is_dir( $dir ) && ! is_link( $dir ) ) {
			self::delete_dir_recursive( $dir );
		}
	}

	/**
	 * Clean up abandoned job directories, stale DB job state options, and orphan .part files older than 24 hours.
	 */
	public static function cleanup_stale_jobs() {
		$cutoff   = time() - DAY_IN_SECONDS;
		$jobs_dir = self::get_jobs_root();
		$vault    = self::get_backups_dir();

		$index = get_option( self::JOB_INDEX_OPTION, array() );
		if ( is_array( $index ) && ! empty( $index ) ) {
			$dirty = false;
			foreach ( $index as $jid => $ts ) {
				if ( (int) $ts < $cutoff ) {
					delete_option( 'ldsm_job_' . $jid );
					unset( $index[ $jid ] );
					$dirty = true;
					if ( '' !== $jobs_dir && self::is_valid_job_id( (string) $jid ) ) {
						self::delete_dir_recursive( $jobs_dir . '/' . $jid );
					}
				}
			}
			if ( $dirty ) {
				update_option( self::JOB_INDEX_OPTION, $index, false );
			}
		}

		if ( '' !== $jobs_dir && is_dir( $jobs_dir ) ) {
			$items = @scandir( $jobs_dir );
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( '.' === $item || '..' === $item || 'index.php' === $item || 'index.html' === $item || '.htaccess' === $item || 'web.config' === $item ) {
						continue;
					}
					$path = $jobs_dir . '/' . $item;
					if ( is_dir( $path ) && ! is_link( $path ) ) {
						$mtime = (int) @filemtime( $path );
						if ( $mtime > 0 && $mtime < $cutoff ) {
							self::delete_job( $item );
						}
					}
				}
			}
		}

		if ( '' !== $vault && is_dir( $vault ) ) {
			$parts = glob( $vault . '/*.zip.part' );
			if ( is_array( $parts ) ) {
				foreach ( $parts as $part_file ) {
					$mt = (int) @filemtime( $part_file );
					if ( $mt > 0 && $mt < $cutoff ) {
						wp_delete_file( $part_file );
					}
				}
			}
		}
	}

	/**
	 * Generate a cryptographically unguessable backup filename ending in .zip.
	 *
	 * @param string $trigger 'export', 'manual', or 'scheduled'.
	 * @return string Filename ending with .zip
	 */
	public static function generate_backup_filename( $trigger = 'export' ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		$slug = sanitize_title( ( $host ? $host : 'site' ) . ( $path ? '-' . trim( $path, '/' ) : '' ) );
		if ( '' === $slug ) {
			$slug = 'site';
		}
		$slug = substr( $slug, 0, 36 );

		$tag = 'exp';
		if ( 'manual' === $trigger ) {
			$tag = 'manual';
		} elseif ( 'scheduled' === $trigger ) {
			$tag = 'auto';
		}

		try {
			$secret = bin2hex( random_bytes( 12 ) );
		} catch ( Exception $e ) {
			$secret = strtolower( wp_generate_password( 24, false, false ) );
		}

		return $slug . '-' . $tag . '-' . gmdate( 'Ymd-His' ) . '-' . $secret . '.zip';
	}

	/**
	 * List all stored .zip archives inside the protected secret vault sorted newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_backups() {
		$dir = self::get_backups_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( $dir . '/*.zip' );
		if ( ! is_array( $files ) || empty( $files ) ) {
			return array();
		}

		$is_admin_ctx = is_user_logged_in() && self::current_user_can_manage();
		$list         = array();

		foreach ( $files as $filepath ) {
			$filename = basename( $filepath );
			if ( is_link( $filepath ) || ! self::is_valid_backup_filename( $filename ) ) {
				continue;
			}
			$size      = (float) @filesize( $filepath );
			$mtime     = (int) @filemtime( $filepath );
			$meta_file = $dir . '/' . substr( $filename, 0, -4 ) . '.meta.json';
			$meta      = self::read_json_file( $meta_file );
			if ( ! is_array( $meta ) ) {
				$meta = array();
			}

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
						'action'   => 'ldsm_download_backup',
						'file'     => rawurlencode( $filename ),
						'_wpnonce' => wp_create_nonce( 'ldsm_download_' . $filename ),
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
	 * Enforce retention policy on automated scheduled backups.
	 *
	 * @param int $keep_count Number of most-recent scheduled backups to keep (0 = unlimited).
	 * @return int
	 */
	public static function enforce_scheduled_retention( $keep_count ) {
		$keep_count = (int) $keep_count;
		if ( $keep_count <= 0 ) {
			return 0;
		}

		$all       = self::list_backups();
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
	 * @param string $filename Archive filename ending with .zip.
	 * @return bool|WP_Error
	 */
	public static function delete_backup( $filename ) {
		if ( ! self::is_valid_backup_filename( $filename ) ) {
			return new WP_Error( 'invalid_filename', 'Invalid backup filename.' );
		}
		$dir = self::get_backups_dir();
		if ( '' === $dir ) {
			return new WP_Error( 'storage_unavailable', 'Uploads vault directory is unavailable.' );
		}

		$filepath = $dir . '/' . $filename;
		if ( ! file_exists( $filepath ) ) {
			return new WP_Error( 'not_found', 'Backup archive does not exist.' );
		}

		$meta_file = $dir . '/' . substr( $filename, 0, -4 ) . '.meta.json';
		if ( file_exists( $meta_file ) ) {
			wp_delete_file( $meta_file );
		}
		wp_delete_file( $filepath );
		if ( file_exists( $filepath ) ) {
			return new WP_Error( 'delete_failed', 'Could not delete backup file from disk.' );
		}
		return true;
	}
}
