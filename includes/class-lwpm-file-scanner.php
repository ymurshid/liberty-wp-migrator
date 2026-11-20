<?php
/**
 * Resumable Queue-Based Filesystem Scanner for wp-content — Liberty WP Migrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWPM_File_Scanner {

	/**
	 * Backup directories that should always be skipped to prevent nesting multi-GB archives.
	 *
	 * @var array<int,string>
	 */
	private static $always_excluded_top_dirs = array(
		'lwpm-backups',
		'owpm-backups',
		'ai1wm-backups',
		'updraft',
		'wpvividbackups',
		'backups-dup-lite',
		'backups-dup-pro',
		'envato-backups',
		'wphb-cache',
	);

	/**
	 * Cache folders inside wp-content excluded when exclude_cache is enabled.
	 *
	 * @var array<int,string>
	 */
	private static $cache_top_dirs = array(
		'cache',
		'litespeed',
		'et-cache',
		'w3tc-config',
		'wp-rocket-config',
		'autoptimize',
		'mmr',
		'breeze-minification',
		'flying-press',
	);

	/**
	 * Drop-in cache/log files directly inside wp-content excluded when exclude_cache is enabled.
	 *
	 * @var array<int,string>
	 */
	private static $cache_root_files = array(
		'object-cache.php',
		'advanced-cache.php',
		'wp-cache-config.php',
		'debug.log',
		'error_log',
	);

	/**
	 * Run a time-boxed scan step appending discovered files to $files_jsonl.
	 */
	public static function scan_step( array &$state, $files_jsonl, $deadline ) {
		$wp_content = LWPM_Storage::normalize_path( WP_CONTENT_DIR );
		if ( ! is_dir( $wp_content ) || ! is_readable( $wp_content ) ) {
			return new WP_Error( 'wp_content_unreadable', 'Cannot read wp-content directory.' );
		}

		if ( ! isset( $state['scan_queue'] ) || ! is_array( $state['scan_queue'] ) ) {
			$state['scan_queue']  = array( '' );
			$state['total_files'] = 0;
			$state['total_bytes'] = 0;
		}

		$options         = isset( $state['options'] ) && is_array( $state['options'] ) ? $state['options'] : array();
		$exclude_cache   = ! empty( $options['exclude_cache'] );
		$exclude_media   = ! empty( $options['exclude_media'] );
		$exclude_themes  = ! empty( $options['exclude_themes'] );
		$exclude_plugins = ! empty( $options['exclude_plugins'] );

		$allowed_themes  = $exclude_themes ? self::get_active_theme_slugs() : array();
		$allowed_plugins = $exclude_plugins ? self::get_active_plugin_roots() : array();

		$out = @fopen( $files_jsonl, 'ab' );
		if ( ! $out ) {
			return new WP_Error( 'manifest_open_failed', 'Could not open files.jsonl for writing.' );
		}

		$buffer = '';

		while ( ! empty( $state['scan_queue'] ) ) {
			$rel_dir  = array_shift( $state['scan_queue'] );
			$full_dir = '' === $rel_dir ? $wp_content : ( $wp_content . '/' . $rel_dir );

			if ( ! is_dir( $full_dir ) || is_link( $full_dir ) || ! is_readable( $full_dir ) ) {
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

				$child_rel  = '' === $rel_dir ? $item : ( $rel_dir . '/' . $item );
				$child_full = $full_dir . '/' . $item;

				if ( is_link( $child_full ) ) {
					continue;
				}

				if ( is_dir( $child_full ) ) {
					if ( self::should_skip_directory( $child_rel, $item, $exclude_cache, $exclude_media, $exclude_themes, $allowed_themes, $exclude_plugins, $allowed_plugins ) ) {
						continue;
					}
					$state['scan_queue'][] = $child_rel;
				} elseif ( is_file( $child_full ) && is_readable( $child_full ) ) {
					if ( self::should_skip_file( $rel_dir, $child_rel, $item, $exclude_cache, $exclude_plugins, $allowed_plugins ) ) {
						continue;
					}

					$size = (int) @filesize( $child_full );
					$mt   = (int) @filemtime( $child_full );

					$record = array(
						'rel'   => 'wp-content/' . $child_rel,
						'size'  => max( 0, $size ),
						'mtime' => $mt > 0 ? $mt : time(),
					);

					$buffer               .= wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
					$state['total_files'] += 1;
					$state['total_bytes'] += max( 0, $size );

					if ( strlen( $buffer ) >= 128 * 1024 ) {
						if ( false === fwrite( $out, $buffer ) ) {
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
			if ( false === fwrite( $out, $buffer ) ) {
				fclose( $out );
				return new WP_Error( 'manifest_write_failed', 'Failed flushing scanned file list.' );
			}
		}

		fclose( $out );

		return empty( $state['scan_queue'] );
	}

	/**
	 * Determine if a directory inside wp-content should be excluded.
	 */
	private static function should_skip_directory( $child_rel, $item, $exclude_cache, $exclude_media, $exclude_themes, array $allowed_themes, $exclude_plugins, array $allowed_plugins ) {
		$lower_rel  = strtolower( $child_rel );
		$lower_item = strtolower( $item );

		if ( false === strpos( $child_rel, '/' ) ) {
			if ( in_array( $lower_item, self::$always_excluded_top_dirs, true ) ) {
				return true;
			}
			if ( 0 === strpos( $lower_item, 'backwpup-' ) || 0 === strpos( $lower_item, 'ai1wm-' ) ) {
				return true;
			}
			if ( $exclude_cache && in_array( $lower_item, self::$cache_top_dirs, true ) ) {
				return true;
			}
			if ( $exclude_media && 'uploads' === $lower_item ) {
				return true;
			}
		}

		if ( $exclude_cache && ( 'uploads/cache' === $lower_rel || 'uploads/wp-rocket-cache' === $lower_rel ) ) {
			return true;
		}

		if ( $exclude_themes && 0 === strpos( $child_rel, 'themes/' ) ) {
			$parts = explode( '/', $child_rel );
			if ( 2 === count( $parts ) && ! isset( $allowed_themes[ $parts[1] ] ) ) {
				return true;
			}
		}

		if ( $exclude_plugins && 0 === strpos( $child_rel, 'plugins/' ) ) {
			$parts = explode( '/', $child_rel );
			if ( 2 === count( $parts ) && ! isset( $allowed_plugins[ $parts[1] ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine if an individual file inside wp-content should be excluded.
	 */
	private static function should_skip_file( $rel_dir, $child_rel, $item, $exclude_cache, $exclude_plugins, array $allowed_plugins ) {
		$lower_item = strtolower( $item );

		if ( '' === $rel_dir ) {
			if ( $exclude_cache && in_array( $lower_item, self::$cache_root_files, true ) ) {
				return true;
			}
		}

		if ( $exclude_cache && ( '.log' === substr( $lower_item, -4 ) || ( 0 === strpos( $lower_item, 'core.' ) && ctype_digit( substr( $lower_item, 5 ) ) ) ) ) {
			return true;
		}

		if ( $exclude_plugins && 'plugins' === $rel_dir && 'index.php' !== $lower_item ) {
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
	 * Return map of active plugin top-level directories/files inside wp-content/plugins.
	 */
	private static function get_active_plugin_roots() {
		$roots = array(
			'liberty-wp-migrator' => true,
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
