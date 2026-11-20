<?php
/**
 * Time-Boxed Shadow-Table Database Importer, Collation Normalizer & Atomic Swapper — Liberty WP Migrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWPM_DB_Importer {

	/**
	 * Execute a time-boxed step importing SQL statements from $sql_filepath into shadow tables.
	 */
	public static function import_sql_step( array &$state, $sql_filepath, $deadline ) {
		global $wpdb;

		if ( ! is_file( $sql_filepath ) || ! is_readable( $sql_filepath ) ) {
			return new WP_Error( 'sql_missing', 'Extracted database.sql file is missing or unreadable.' );
		}

		$total_sql_bytes = (int) @filesize( $sql_filepath );
		if ( ! isset( $state['sql_byte_offset'] ) ) {
			$state['sql_byte_offset']    = 0;
			$state['sql_total_bytes']    = max( 1, $total_sql_bytes );
			$state['shadow_map']         = array();
			$state['deferred_views']     = array();
			$state['fallback_collation'] = self::detect_safe_utf8mb4_collation();
			$state['supports_0900']      = self::server_supports_collation( 'utf8mb4_0900_ai_ci' );
		}

		$fp = @fopen( $sql_filepath, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'sql_open_failed', 'Cannot open database.sql for reading.' );
		}

		if ( 0 !== @fseek( $fp, (int) $state['sql_byte_offset'], SEEK_SET ) ) {
			fclose( $fp );
			return new WP_Error( 'sql_seek_failed', 'Failed seeking to offset in database.sql.' );
		}

		$wpdb->query( 'SET NAMES utf8mb4' );
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		$wpdb->query( "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'" );

		$dest_prefix   = (string) $state['dest_info']['table_prefix'];
		$source_prefix = isset( $state['manifest']['table_prefix'] ) ? (string) $state['manifest']['table_prefix'] : '';
		$job_short     = substr( preg_replace( '/[^a-z0-9]/i', '', (string) $state['job_id'] ), -4 );
		if ( strlen( $job_short ) < 4 ) {
			$job_short = '0001';
		}

		while ( ! feof( $fp ) ) {
			$line = fgets( $fp );
			if ( false === $line ) {
				break;
			}

			$trimmed = trim( $line );
			if ( '' === $trimmed ) {
				$state['sql_byte_offset'] = (int) ftell( $fp );
				continue;
			}

			// Support #LWPM# (and legacy #OWPM#) framed SQL statements.
			if ( 0 === strpos( $trimmed, '-- #LWPM#' ) || 0 === strpos( $trimmed, '-- #OWPM#' ) ) {
				$json_part = substr( $trimmed, 9 );
				$meta      = json_decode( $json_part, true );
				if ( ! is_array( $meta ) || ! isset( $meta['bytes'] ) ) {
					fclose( $fp );
					return new WP_Error( 'sql_frame_corrupt', 'Corrupt statement frame in database.sql.' );
				}

				$byte_len = (int) $meta['bytes'];
				$sql_raw  = self::fread_exact( $fp, $byte_len );
				if ( false === $sql_raw ) {
					fclose( $fp );
					return new WP_Error( 'sql_unexpected_eof', 'Unexpected end of database.sql while reading statement.' );
				}

				$exec_res = self::execute_framed_statement(
					$meta,
					trim( $sql_raw ),
					$state,
					$source_prefix,
					$dest_prefix,
					$job_short
				);

				if ( is_wp_error( $exec_res ) ) {
					fclose( $fp );
					return $exec_res;
				}

				$state['sql_byte_offset'] = (int) ftell( $fp );

				if ( microtime( true ) >= $deadline ) {
					fclose( $fp );
					return false;
				}
				continue;
			}

			$state['sql_byte_offset'] = (int) ftell( $fp );
		}

		$is_eof                   = feof( $fp );
		$state['sql_byte_offset'] = (int) ftell( $fp );
		fclose( $fp );

		if ( $is_eof || $state['sql_byte_offset'] >= $total_sql_bytes ) {
			$state['shadow_list']     = array_values( $state['shadow_map'] );
			$state['sr_table_idx']    = 0;
			$state['sr_table_cursor'] = null;
			return true;
		}

		return false;
	}

	/**
	 * Rewrite and execute a single framed SQL statement against shadow tables.
	 */
	private static function execute_framed_statement( array $meta, $sql, array &$state, $source_prefix, $dest_prefix, $job_short ) {
		global $wpdb;

		$op         = (string) $meta['op'];
		$src_table  = (string) $meta['table'];
		$has_prefix = ! empty( $meta['has_prefix'] );
		$suffix     = (string) $meta['suffix'];

		if ( 'drop_table' === $op || 'drop_view' === $op ) {
			return true;
		}

		if ( 'create_view' === $op ) {
			$final_view = $has_prefix ? ( $dest_prefix . $suffix ) : $src_table;
			$view_sql   = self::replace_first_table_ident( $sql, $src_table, $final_view );
			if ( '' !== $source_prefix && $source_prefix !== $dest_prefix ) {
				$view_sql = str_replace( '`' . $source_prefix, '`' . $dest_prefix, $view_sql );
			}
			$state['deferred_views'][] = array(
				'view_name' => $final_view,
				'sql'       => $view_sql,
			);
			return true;
		}

		if ( ! isset( $state['shadow_map'][ $src_table ] ) ) {
			$idx          = count( $state['shadow_map'] );
			$shadow_table = 'lwpmt_' . $job_short . '_' . $idx;
			$final_table  = $has_prefix ? ( $dest_prefix . $suffix ) : $src_table;

			$state['shadow_map'][ $src_table ] = array(
				'source'     => $src_table,
				'shadow'     => $shadow_table,
				'final'      => $final_table,
				'suffix'     => $suffix,
				'has_prefix' => $has_prefix,
			);
		}

		$shadow_table = $state['shadow_map'][ $src_table ]['shadow'];

		if ( 'create_table' === $op ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $shadow_table ) );

			$rewritten = self::replace_first_table_ident( $sql, $src_table, $shadow_table );

			if ( empty( $state['supports_0900'] ) && false !== stripos( $rewritten, 'utf8mb4_0900_' ) ) {
				$fallback  = (string) $state['fallback_collation'];
				$rewritten = preg_replace( '/\butf8mb4_0900_[a-z0-9_]+\b/i', $fallback, $rewritten );
			}

			$rewritten = preg_replace_callback(
				'/\bCONSTRAINT\s+`([^`]+)`/i',
				function ( $m ) use ( $job_short ) {
					$base = substr( $m[1], 0, 48 );
					return 'CONSTRAINT `' . $base . '_lw' . $job_short . '`';
				},
				$rewritten
			);

			if ( '' !== $source_prefix && $source_prefix !== $dest_prefix ) {
				$rewritten = preg_replace(
					'/\bREFERENCES\s+`' . preg_quote( $source_prefix, '/' ) . '([^`]+)`/i',
					'REFERENCES `' . $dest_prefix . '$1`',
					$rewritten
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Executing validated CREATE TABLE statement from site archive into shadow table.
			$res = $wpdb->query( $rewritten );
			if ( false === $res ) {
				$no_fk = preg_replace( '/,\s*(?:CONSTRAINT\s+`[^`]+`\s+)?FOREIGN\s+KEY\s*\([^)]+\)\s*REFERENCES\s+`[^`]+`\s*\([^)]+\)(?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|SET NULL|RESTRICT|NO ACTION))*/i', '', $rewritten );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fallback shadow table DDL without foreign keys.
				$res2 = $wpdb->query( $no_fk );
				if ( false === $res2 ) {
					return new WP_Error(
						'create_table_failed',
						'Failed creating shadow table for ' . $src_table . ': ' . $wpdb->last_error
					);
				}
			}

			return true;
		}

		if ( 'insert' === $op ) {
			$rewritten = self::replace_first_table_ident( $sql, $src_table, $shadow_table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Executing framed bulk INSERT statement into isolated shadow table.
			$res = $wpdb->query( $rewritten );
			if ( false === $res ) {
				return new WP_Error(
					'insert_failed',
					'Failed inserting batch into ' . $src_table . ': ' . $wpdb->last_error
				);
			}
			return true;
		}

		return true;
	}

	/**
	 * Run a time-boxed Serialization-Safe Search & Replace step across all shadow tables.
	 */
	public static function search_replace_step( array &$state, LWPM_Replacer $replacer, $deadline ) {
		global $wpdb;

		if ( ! $replacer->has_rules() || empty( $state['shadow_list'] ) ) {
			return true;
		}

		$shadow_list = $state['shadow_list'];
		$total       = count( $shadow_list );
		$skip_guid   = ! empty( $state['import_options']['skip_guid'] );

		while ( $state['sr_table_idx'] < $total ) {
			$t_entry      = $shadow_list[ $state['sr_table_idx'] ];
			$shadow_table = $t_entry['shadow'];
			$suffix       = $t_entry['suffix'];
			$has_prefix   = ! empty( $t_entry['has_prefix'] );

			if ( ! is_array( $state['sr_table_cursor'] ) || $state['sr_table_cursor']['shadow'] !== $shadow_table ) {
				$meta = self::inspect_shadow_table_for_sr( $shadow_table, ( $has_prefix && 'posts' === $suffix ) ? $skip_guid : false );
				if ( empty( $meta['text_cols'] ) ) {
					$state['sr_table_idx']   += 1;
					$state['sr_table_cursor'] = null;
					continue;
				}
				$state['sr_table_cursor'] = array(
					'shadow'    => $shadow_table,
					'int_pk'    => $meta['int_pk'],
					'pk_cols'   => $meta['pk_cols'],
					'text_cols' => $meta['text_cols'],
					'last_pk'   => null,
					'offset'    => 0,
				);
			}

			$cursor    = &$state['sr_table_cursor'];
			$esc_table = LWPM_DB_Exporter::quote_ident( $shadow_table );
			$int_pk    = $cursor['int_pk'];
			$pk_cols   = $cursor['pk_cols'];
			$text_cols = $cursor['text_cols'];

			$select_cols = array_values( array_unique( array_merge( $pk_cols, $text_cols ) ) );
			if ( empty( $pk_cols ) ) {
				$col_sql = '*';
			} else {
				$col_sql = implode( ',', array_map( array( 'LWPM_DB_Exporter', 'quote_ident' ), $select_cols ) );
			}

			if ( null !== $int_pk ) {
				$esc_pk = LWPM_DB_Exporter::quote_ident( $int_pk );
				$where  = ( null !== $cursor['last_pk'] ) ? ( 'WHERE ' . $esc_pk . ' > ' . (int) $cursor['last_pk'] ) : '';
				$query  = "SELECT {$col_sql} FROM {$esc_table} {$where} ORDER BY {$esc_pk} ASC LIMIT 300";
			} else {
				$offset = (int) $cursor['offset'];
				$query  = "SELECT {$col_sql} FROM {$esc_table} LIMIT {$offset}, 200";
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Keyset pagination over shadow table columns during search & replace.
			$rows = $wpdb->get_results( $query, ARRAY_A );

			if ( empty( $rows ) ) {
				$state['sr_table_idx']   += 1;
				$state['sr_table_cursor'] = null;
			} else {
				foreach ( $rows as $row ) {
					$updates = array();
					foreach ( $text_cols as $tcol ) {
						if ( ! isset( $row[ $tcol ] ) || ! is_string( $row[ $tcol ] ) || '' === $row[ $tcol ] ) {
							continue;
						}
						$old_val = $row[ $tcol ];
						$new_val = $replacer->transform( $old_val );
						if ( $new_val !== $old_val ) {
							$updates[ $tcol ] = $new_val;
						}
					}

					if ( ! empty( $updates ) ) {
						self::apply_row_update( $shadow_table, $row, $updates, $pk_cols );
					}
				}

				$count = count( $rows );
				if ( null !== $int_pk ) {
					$cursor['last_pk'] = (int) $rows[ $count - 1 ][ $int_pk ];
				} else {
					$cursor['offset'] += $count;
				}

				$batch_target = ( null !== $int_pk ) ? 300 : 200;
				if ( $count < $batch_target ) {
					$state['sr_table_idx']   += 1;
					$state['sr_table_cursor'] = null;
				}
			}

			unset( $cursor );

			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Finalize shadow tables and atomically RENAME shadow tables into live destination tables.
	 */
	public static function finalize_and_atomic_swap( array &$state ) {
		global $wpdb;

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		$source_prefix = isset( $state['manifest']['table_prefix'] ) ? (string) $state['manifest']['table_prefix'] : '';
		$dest_prefix   = (string) $state['dest_info']['table_prefix'];

		$shadow_by_suffix = array();
		foreach ( $state['shadow_map'] as $info ) {
			if ( ! empty( $info['has_prefix'] ) ) {
				$shadow_by_suffix[ $info['suffix'] ] = $info['shadow'];
			}
		}

		// 1. Update table prefix keys in options & usermeta when source and destination prefixes differ.
		if ( '' !== $source_prefix && $source_prefix !== $dest_prefix ) {
			if ( isset( $shadow_by_suffix['options'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET `option_name` = %s WHERE `option_name` = %s',
						$shadow_by_suffix['options'],
						$dest_prefix . 'user_roles',
						$source_prefix . 'user_roles'
					)
				);
			}

			if ( isset( $shadow_by_suffix['usermeta'] ) ) {
				$src_len  = strlen( $source_prefix ) + 1;
				$like_pat = $wpdb->esc_like( $source_prefix ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET `meta_key` = CONCAT(%s, SUBSTRING(`meta_key`, %d)) WHERE `meta_key` LIKE %s',
						$shadow_by_suffix['usermeta'],
						$dest_prefix,
						$src_len,
						$like_pat
					)
				);
			}
		}

		// 2. Enforce destination siteurl & home, keep Liberty WP Migrator active, clear builder caches.
		if ( isset( $shadow_by_suffix['options'] ) ) {
			$opt_shadow = $shadow_by_suffix['options'];

			$wpdb->update( $opt_shadow, array( 'option_value' => $state['dest_info']['site_url'] ), array( 'option_name' => 'siteurl' ) );
			$wpdb->update( $opt_shadow, array( 'option_value' => $state['dest_info']['home_url'] ), array( 'option_name' => 'home' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$active_raw = $wpdb->get_var(
				$wpdb->prepare( 'SELECT `option_value` FROM %i WHERE `option_name` = %s LIMIT 1', $opt_shadow, 'active_plugins' )
			);
			$active_arr = is_string( $active_raw ) ? @unserialize( $active_raw, array( 'allowed_classes' => false ) ) : array();
			if ( ! is_array( $active_arr ) ) {
				$active_arr = array();
			}
			// Remove old omni slug if present and ensure liberty-wp-migrator is active.
			$active_arr = array_values( array_diff( $active_arr, array( 'omni-wp-migrator/omni-wp-migrator.php' ) ) );
			if ( ! in_array( LWPM_PLUGIN_BASENAME, $active_arr, true ) ) {
				$active_arr[] = LWPM_PLUGIN_BASENAME;
			}
			sort( $active_arr );
			$wpdb->update(
				$opt_shadow,
				array( 'option_value' => serialize( $active_arr ) ),
				array( 'option_name' => 'active_plugins' )
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE `option_name` IN (%s, %s)',
					$opt_shadow,
					'_elementor_global_css',
					'elementor_css_print_method_cache'
				)
			);
		}

		if ( isset( $shadow_by_suffix['postmeta'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE `meta_key` IN (%s, %s)',
					$shadow_by_suffix['postmeta'],
					'_elementor_css',
					'_et_builder_module_features_cache'
				)
			);
		}

		// 3. Optional: Preserve current logged-in Administrator account.
		$preserved_user_id = null;
		if (
			! empty( $state['import_options']['keep_current_admin'] ) &&
			! empty( $state['preserved_admin'] ) &&
			isset( $shadow_by_suffix['users'], $shadow_by_suffix['usermeta'] )
		) {
			$preserved_user_id = self::restore_admin_into_shadow(
				$shadow_by_suffix['users'],
				$shadow_by_suffix['usermeta'],
				$state['preserved_admin'],
				$dest_prefix
			);
		}

		// 4. Atomic RENAME TABLE swap!
		$existing_raw   = $wpdb->get_col( 'SHOW FULL TABLES WHERE Table_type = "BASE TABLE"', 0 );
		$existing_set   = is_array( $existing_raw ) ? array_flip( $existing_raw ) : array();
		$existing_views = $wpdb->get_col( 'SHOW FULL TABLES WHERE Table_type = "VIEW"', 0 );
		$views_set      = is_array( $existing_views ) ? array_flip( $existing_views ) : array();

		$rename_clauses = array();
		$old_drops      = array();
		$idx            = 0;
		$job_short      = substr( preg_replace( '/[^a-z0-9]/i', '', (string) $state['job_id'] ), -4 );

		foreach ( $state['shadow_map'] as $info ) {
			$shadow = $info['shadow'];
			$final  = $info['final'];

			if ( isset( $views_set[ $final ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( 'DROP VIEW IF EXISTS %i', $final ) );
			}

			if ( isset( $existing_set[ $final ] ) ) {
				$old_tmp          = 'lwpmold_' . $job_short . '_' . $idx;
				$old_drops[]      = LWPM_DB_Exporter::quote_ident( $old_tmp );
				$rename_clauses[] = LWPM_DB_Exporter::quote_ident( $final ) . ' TO ' . LWPM_DB_Exporter::quote_ident( $old_tmp );
				$idx++;
			}

			$rename_clauses[] = LWPM_DB_Exporter::quote_ident( $shadow ) . ' TO ' . LWPM_DB_Exporter::quote_ident( $final );
		}

		if ( ! empty( $rename_clauses ) ) {
			if ( ! empty( $old_drops ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dropping internal temporary rotation tables.
				$wpdb->query( 'DROP TABLE IF EXISTS ' . implode( ', ', $old_drops ) );
			}

			$rename_sql = 'RENAME TABLE ' . implode( ', ', $rename_clauses );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic RENAME TABLE swap across validated shadow tables.
			$swap_ok = $wpdb->query( $rename_sql );
			if ( false === $swap_ok ) {
				return new WP_Error( 'atomic_swap_failed', 'Atomic RENAME TABLE failed: ' . $wpdb->last_error );
			}

			if ( ! empty( $old_drops ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dropping rotated old tables after successful swap.
				$wpdb->query( 'DROP TABLE IF EXISTS ' . implode( ', ', $old_drops ) );
			}
		}

		// 5. Recreate deferred Views.
		if ( ! empty( $state['deferred_views'] ) ) {
			foreach ( $state['deferred_views'] as $v ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( 'DROP VIEW IF EXISTS %i', $v['view_name'] ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Recreating validated view definition from archive.
				$wpdb->query( $v['sql'] );
			}
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

		return $preserved_user_id;
	}

	/**
	 * Ensure preserved Administrator user exists in shadow users & usermeta tables.
	 */
	private static function restore_admin_into_shadow( $users_shadow, $usermeta_shadow, array $admin_data, $dest_prefix ) {
		global $wpdb;

		if ( empty( $admin_data['user_login'] ) || empty( $admin_data['user_pass'] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `ID` FROM %i WHERE `user_login` = %s LIMIT 1',
				$users_shadow,
				$admin_data['user_login']
			)
		);

		if ( $existing_id ) {
			$target_id = (int) $existing_id;
			$wpdb->update(
				$users_shadow,
				array( 'user_pass' => $admin_data['user_pass'] ),
				array( 'ID' => $target_id )
			);
		} else {
			$wpdb->insert(
				$users_shadow,
				array(
					'user_login'          => $admin_data['user_login'],
					'user_pass'           => $admin_data['user_pass'],
					'user_nicename'       => $admin_data['user_nicename'],
					'user_email'          => $admin_data['user_email'],
					'user_url'            => '',
					'user_registered'     => gmdate( 'Y-m-d H:i:s' ),
					'user_activation_key' => '',
					'user_status'         => 0,
					'display_name'        => $admin_data['display_name'],
				)
			);
			$target_id = (int) $wpdb->insert_id;
		}

		if ( $target_id <= 0 ) {
			return null;
		}

		$cap_key   = $dest_prefix . 'capabilities';
		$level_key = $dest_prefix . 'user_level';
		$caps_val  = serialize( array( 'administrator' => true ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$has_cap = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `umeta_id` FROM %i WHERE `user_id` = %d AND `meta_key` = %s LIMIT 1',
				$usermeta_shadow,
				$target_id,
				$cap_key
			)
		);
		if ( $has_cap ) {
			$wpdb->update( $usermeta_shadow, array( 'meta_value' => $caps_val ), array( 'umeta_id' => (int) $has_cap ) );
		} else {
			$wpdb->insert(
				$usermeta_shadow,
				array(
					'user_id'    => $target_id,
					'meta_key'   => $cap_key,
					'meta_value' => $caps_val,
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$has_lvl = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `umeta_id` FROM %i WHERE `user_id` = %d AND `meta_key` = %s LIMIT 1',
				$usermeta_shadow,
				$target_id,
				$level_key
			)
		);
		if ( $has_lvl ) {
			$wpdb->update( $usermeta_shadow, array( 'meta_value' => '10' ), array( 'umeta_id' => (int) $has_lvl ) );
		} else {
			$wpdb->insert(
				$usermeta_shadow,
				array(
					'user_id'    => $target_id,
					'meta_key'   => $level_key,
					'meta_value' => '10',
				)
			);
		}

		return $target_id;
	}

	/**
	 * Inspect shadow table columns to identify primary keys and text/JSON columns.
	 */
	private static function inspect_shadow_table_for_sr( $shadow_table, $skip_guid = false ) {
		global $wpdb;

		$text_cols = array();
		$int_cols  = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cols = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $shadow_table ), ARRAY_A );
		if ( is_array( $cols ) ) {
			foreach ( $cols as $c ) {
				$name = (string) $c['Field'];
				$type = strtolower( (string) $c['Type'] );
				$base = preg_replace( '/\(.*/', '', $type );
				$base = trim( (string) explode( ' ', $base )[0] );

				if ( in_array( $base, array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint' ), true ) ) {
					$int_cols[ $name ] = true;
				} elseif ( in_array( $base, array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json' ), true ) ) {
					if ( $skip_guid && 'guid' === $name ) {
						continue;
					}
					$text_cols[] = $name;
				}
			}
		}

		$pk_cols = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$keys = $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $shadow_table, 'PRIMARY' ), ARRAY_A );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $k ) {
				$pk_cols[] = (string) $k['Column_name'];
			}
		}

		$int_pk = null;
		if ( 1 === count( $pk_cols ) && isset( $int_cols[ $pk_cols[0] ] ) ) {
			$int_pk = $pk_cols[0];
		}

		return array(
			'int_pk'    => $int_pk,
			'pk_cols'   => $pk_cols,
			'text_cols' => $text_cols,
		);
	}

	/**
	 * Update modified columns on a single row in $shadow_table.
	 */
	private static function apply_row_update( $shadow_table, array $original_row, array $updates, array $pk_cols ) {
		global $wpdb;

		if ( ! empty( $pk_cols ) ) {
			$where = array();
			foreach ( $pk_cols as $pk ) {
				$where[ $pk ] = $original_row[ $pk ];
			}
			$wpdb->update( $shadow_table, $updates, $where );
			return;
		}

		$set_parts   = array();
		$where_parts = array();
		foreach ( $updates as $col => $val ) {
			$set_parts[] = LWPM_DB_Exporter::quote_ident( $col ) . " = '" . LWPM_DB_Exporter::escape_sql_string( (string) $val ) . "'";
		}
		foreach ( $original_row as $col => $val ) {
			$qcol = LWPM_DB_Exporter::quote_ident( $col );
			if ( null === $val ) {
				$where_parts[] = "{$qcol} IS NULL";
			} else {
				$where_parts[] = "{$qcol} = '" . LWPM_DB_Exporter::escape_sql_string( (string) $val ) . "'";
			}
		}

		$esc_table = LWPM_DB_Exporter::quote_ident( $shadow_table );
		$sql       = "UPDATE {$esc_table} SET " . implode( ', ', $set_parts ) . ' WHERE ' . implode( ' AND ', $where_parts ) . ' LIMIT 1';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- PK-less custom table fallback update with escaped values.
		$wpdb->query( $sql );
	}

	/**
	 * Replace the first occurrence of `$old_table` backticked identifier in a statement.
	 */
	private static function replace_first_table_ident( $sql, $old_table, $new_table ) {
		$search  = LWPM_DB_Exporter::quote_ident( $old_table );
		$replace = LWPM_DB_Exporter::quote_ident( $new_table );
		$pos     = strpos( $sql, $search );
		if ( false === $pos ) {
			return $sql;
		}
		return substr_replace( $sql, $replace, $pos, strlen( $search ) );
	}

	/**
	 * Check whether the current MySQL / MariaDB server supports a given collation.
	 */
	private static function server_supports_collation( $collation ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW COLLATION LIKE %s', $collation ) );
		return ! empty( $found );
	}

	/**
	 * Detect the best universally supported utf8mb4 collation on this server.
	 */
	private static function detect_safe_utf8mb4_collation() {
		if ( self::server_supports_collation( 'utf8mb4_unicode_520_ci' ) ) {
			return 'utf8mb4_unicode_520_ci';
		}
		if ( self::server_supports_collation( 'utf8mb4_unicode_ci' ) ) {
			return 'utf8mb4_unicode_ci';
		}
		return 'utf8mb4_general_ci';
	}

	/**
	 * Read exact byte count from file stream.
	 */
	private static function fread_exact( $fp, $length ) {
		if ( $length <= 0 ) {
			return '';
		}
		$buf = '';
		$rem = $length;
		while ( $rem > 0 && ! feof( $fp ) ) {
			$chunk = fread( $fp, min( 1024 * 1024, $rem ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$buf .= $chunk;
			$rem -= strlen( $chunk );
		}
		return ( strlen( $buf ) === $length ) ? $buf : false;
	}
}
