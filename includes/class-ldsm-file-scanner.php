<?php
/**
 * Resumable Queue-Based Filesystem Scanner across Configured WordPress Roots — LibertyDev Site Migrator.
 *
 * Uses LDSM_Storage::get_content_roots() (wp_upload_dir(), WP_PLUGIN_DIR, WPMU_PLUGIN_DIR,
 * get_theme_root(), WP_LANG_DIR) to support standard and relocated WordPress directory layouts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LDSM_File_Scanner {

	/**
	 * Backup directories inside uploads or content folders that should always be skipped.
	 *
	 * @var array<int,string>
	 */
	private static $excluded_backup_dirs = array(
		'libertydev-site-migrator',
		'lwpm-backups',
		'owpm-backups',
		'ai1wm-backups',
		'updraft',
		'wpvividbackups',
		'backups-dup-lite',
		'backups-dup-pro',
	);

	/**
	 * Cache directories inside uploads excluded when exclude_cache is enabled.
	 *
	 * @var array<int,string>
	 */
	private static $excluded_cache_dirs = array(
		'cache',
		'wp-rocket-cache',
		'litespeed',
		'et-cache',
		'autoptimize',
	);

	/**
	 * Run a time-boxed scan step appending discovered files into protected non-executable data file $files_dat.
	 *
	 * @param array  $state     Job state array (modified by reference).
	 * @param string $files_dat Path to protected files.dat output list.
	 * @param float  $deadline  Unix microtime deadline for this tick.
	 * @return bool|WP_Error True when scan is 100% complete, false when more directories remain, or WP_Error.
	 */
	public static function scan_step( array &$state, $files_dat, $deadline ) {
		$roots   = LDSM_Storage::get_content_roots();
		$options = isset( $state['options'] ) && is_array( $state['options'] ) ? $state['options'] : array();

		$exclude_cache   = ! empty( $options['exclude_cache'] );
		$exclude_media   = ! empty( $options['exclude_media'] );
		$exclude_themes  = ! empty( $options['exclude_themes'] );
		$exclude_plugins = ! empty( $options['exclude_plugins'] );

		if ( ! isset( $state['scan_queue'] ) || ! is_array( $state['scan_queue'] ) ) {
			$queue = array();
			foreach ( $roots as $root_key => $abs_root ) {
				if ( $exclude_media && 'uploads' === $root_key ) {
					continue;
				}
				if ( is_dir( $abs_root ) && is_readable( $abs_root ) ) {
					$queue[] = array(
						'root' => $root_key,
						'sub'  => '',
					);
				}
			}
			$state['scan_queue']  = $queue;
			$state['total_files'] = 0;
			$state['total_bytes'] = 0;
		}

		if ( is_link( $files_dat ) ) {
			return new WP_Error( 'manifest_symlink_blocked', 'Refusing to write scan manifest through symbolic link.' );
		}

		$allowed_themes  = $exclude_themes ? self::get_active_theme_slugs() : array();
		$allowed_plugins = $exclude_plugins ? self::get_active_plugin_roots() : array();
		$own_vault_outer = LDSM_Storage::get_outer_uploads_folder();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming append to protected non-executable manifest data file across ticks.
		$out = @fopen( $files_dat, 'ab' );
		if ( ! $out ) {
			return new WP_Error( 'manifest_open_failed', 'Could not open protected files manifest for writing.' );
		}

		$buffer = '';

		while ( ! empty( $state['scan_queue'] ) ) {
			$q_item   = array_shift( $state['scan_queue'] );
			$root_key = (string) $q_item['root'];
			$sub_dir  = (string) $q_item['sub'];

			if ( ! isset( $roots[ $root_key ] ) ) {
				continue;
			}

			$root_abs = $roots[ $root_key ];
			$full_dir = '' === $sub_dir ? $root_abs : ( $root_abs . '/' . $sub_dir );

			if ( ! is_dir( $full_dir ) || ( '' !== $sub_dir && is_link( $full_dir ) ) || ! is_readable( $full_dir ) ) {
				continue;
			}

			$entries = @scandir( $full_dir );
			if ( ! is_array( $entries ) ) {
				continue;
			}

			foreach ( $entries as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				if ( '.git' === $item || '.svn' === $item || 'node_modules' === $item || '.DS_Store' === $item ) {
					continue;
				}

				$child_sub  = '' === $sub_dir ? $item : ( $sub_dir . '/' . $item );
				$child_full = $full_dir . '/' . $item;

				// Never follow symbolic links.
				if ( is_link( $child_full ) ) {
					continue;
				}

				// Always skip our own uploads backup vault folder regardless of where uploads lives.
				if ( LDSM_Storage::normalize_path( $child_full ) === $own_vault_outer ) {
					continue;
				}

				if ( is_dir( $child_full ) ) {
					if ( self::should_skip_directory( $root_key, $child_sub, $item, $exclude_cache, $exclude_themes, $allowed_themes, $exclude_plugins, $allowed_plugins ) ) {
						continue;
					}
					$state['scan_queue'][] = array(
						'root' => $root_key,
						'sub'  => $child_sub,
					);
				} elseif ( is_file( $child_full ) && is_readable( $child_full ) ) {
					if ( self::should_skip_file( $root_key, $sub_dir, $item, $exclude_cache, $exclude_plugins, $allowed_plugins ) ) {
						continue;
					}

					$size = (int) @filesize( $child_full );
					$mt   = (int) @filemtime( $child_full );

					$record = array(
						'root'  => $root_key,
						'sub'   => $child_sub,
						'rel'   => $root_key . '/' . $child_sub,
						'size'  => max( 0, $size ),
						'mtime' => $mt > 0 ? $mt : time(),
					);

					$buffer               .= wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
					$state['total_files'] += 1;
					$state['total_bytes'] += max( 0, $size );

					if ( strlen( $buffer ) >= 128 * 1024 ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						if ( false === fwrite( $out, $buffer ) ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							fclose( $out );
							return new WP_Error( 'manifest_write_failed', 'Failed writing scanned file list.' );
						}
						$buffer = '';
					}
				}
			}

			if ( ! empty( $state['scan_queue'] ) && microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( '' !== $buffer ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === fwrite( $out, $buffer ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $out );
				return new WP_Error( 'manifest_write_failed', 'Failed flushing scanned file list.' );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );

		return empty( $state['scan_queue'] );
	}

	/**
	 * Determine if a subdirectory inside one of the configured roots should be skipped.
	 */
	private static function should_skip_directory( $root_key, $child_sub, $item, $exclude_cache, $exclude_themes, array $allowed_themes, $exclude_plugins, array $allowed_plugins ) {
		$lower_item = strtolower( $item );

		if ( in_array( $lower_item, self::$excluded_backup_dirs, true ) ) {
			return true;
		}
		if ( 0 === strpos( $lower_item, 'backwpup-' ) || 0 === strpos( $lower_item, 'ai1wm-' ) ) {
			return true;
		}

		if ( $exclude_cache && in_array( $lower_item, self::$excluded_cache_dirs, true ) ) {
			return true;
		}

		if ( $exclude_themes && 'themes' === $root_key && false === strpos( $child_sub, '/' ) ) {
			if ( ! isset( $allowed_themes[ $item ] ) ) {
				return true;
			}
		}

		if ( $exclude_plugins && 'plugins' === $root_key && false === strpos( $child_sub, '/' ) ) {
			if ( ! isset( $allowed_plugins[ $item ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine if an individual file should be excluded.
	 */
	private static function should_skip_file( $root_key, $sub_dir, $item, $exclude_cache, $exclude_plugins, array $allowed_plugins ) {
		$lower_item = strtolower( $item );

		if ( $exclude_cache && ( '.log' === substr( $lower_item, -4 ) || ( 0 === strpos( $lower_item, 'core.' ) && ctype_digit( substr( $lower_item, 5 ) ) ) ) ) {
			return true;
		}

		if ( $exclude_plugins && 'plugins' === $root_key && '' === $sub_dir && 'index.php' !== $lower_item ) {
			if ( ! isset( $allowed_plugins[ $item ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return map of active theme directory names (child + parent).
	 */
	private static function get_active_theme_slugs() {
		$slugs                = array();
		$stylesheet           = (string) get_stylesheet();
		$template             = (string) get_template();
		$slugs[ $stylesheet ] = true;
		$slugs[ $template ]   = true;
		return $slugs;
	}

	/**
	 * Return map of active plugin top-level directories/files inside WP_PLUGIN_DIR.
	 */
	private static function get_active_plugin_roots() {
		$own_folder = basename( rtrim( LDSM_PLUGIN_DIR, '/\\' ) );
		$roots      = array(
			$own_folder                => true,
			'libertydev-site-migrator' => true,
		);

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$net_active = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$active     = array_merge( $active, $net_active );
		}

		foreach ( $active as $plugin_rel ) {
			if ( ! is_string( $plugin_rel ) || '' === $plugin_rel ) {
				continue;
			}
			$parts              = explode( '/', $plugin_rel );
			$roots[ $parts[0] ] = true;
		}

		return $roots;
	}
}
