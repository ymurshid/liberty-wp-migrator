<?php
/**
 * Time-Boxed Shadow-Table Database Importer, Collation Normalizer & Atomic Swapper — LibertyDev Site Migrator.
 *
 * Enforces Multisite Super Admin permissions and parameterizes all dynamic SQL queries via $wpdb->prepare().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LDSM_DB_Importer {

	/**
	 * Execute a time-boxed step importing SQL statements from protected $sql_filepath into shadow tables.
	 *
	 * @param array  $state        Job state (modified by reference).
	 * @param string $sql_filepath Path to extracted protected database.sql data file.
	 * @param float  $deadline     Unix microtime deadline.
	 * @return bool|WP_Error
	 */
	public static function import_sql_step( array &$state, $sql_filepath, $deadline ) {
		global $wpdb;

		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Insufficient permissions to import database tables.' );
		}

		if ( ! is_file( $sql_filepath ) || is_link( $sql_filepath ) || ! is_readable( $sql_filepath ) ) {
			return new WP_Error( 'sql_missing', 'Extracted database dump file is missing or unreadable.' );
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $sql_filepath, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'sql_open_failed', 'Cannot open database dump for reading.' );
		}

		if ( 0 !== @fseek( $fp, (int) $state['sql_byte_offset'], SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'sql_seek_failed', 'Failed seeking to offset in database dump.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'SET NAMES utf8mb4' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

			if (
				0 === strpos( $trimmed, '-- #LDSM#' ) ||
				0 === strpos( $trimmed, '-- #LWPM#' ) ||
				0 === strpos( $trimmed, '-- #OWPM#' )
			) {
				$json_part = substr( $trimmed, 9 );
				$meta      = json_decode( $json_part, true );
				if ( ! is_array( $meta ) || ! isset( $meta['bytes'] ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					return new WP_Error( 'sql_frame_corrupt', 'Corrupt statement frame in database dump.' );
				}

				$byte_len = (int) $meta['bytes'];
				$sql_raw  = self::fread_exact( $fp, $byte_len );
				if ( false === $sql_raw ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					return new WP_Error( 'sql_unexpected_eof', 'Unexpected end of database dump while reading statement.' );
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
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					return $exec_res;
				}

				$state['sql_byte_offset'] = (int) ftell( $fp );

				if ( microtime( true ) >= $deadline ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					return false;
				}
				continue;
			}

			$state['sql_byte_offset'] = (int) ftell( $fp );
		}

		$is_eof                   = feof( $fp );
		$state['sql_byte_offset'] = (int) ftell( $fp );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
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
			if ( 0 !== stripos( ltrim( $view_sql ), 'CREATE ' ) ) {
				return new WP_Error( 'invalid_view_sql', 'Unexpected SQL statement inside create_view frame.' );
			}
			$state['deferred_views'][] = array(
				'view_name' => $final_view,
				'sql'       => $view_sql,
			);
			return true;
		}

		if ( ! isset( $state['shadow_map'][ $src_table ] ) ) {
			$idx          = count( $state['shadow_map'] );
			$shadow_table = 'ldsmt_' . $job_short . '_' . $idx;
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
			if ( 0 !== stripos( ltrim( $rewritten ), 'CREATE TABLE ' ) ) {
				return new WP_Error( 'invalid_create_sql', 'Unexpected SQL statement inside create_table frame.' );
			}

			if ( empty( $state['supports_0900'] ) && false !== stripos( $rewritten, 'utf8mb4_0900_' ) ) {
				$fallback  = (string) $state['fallback_collation'];
				$rewritten = preg_replace( '/\butf8mb4_0900_[a-z0-9_]+\b/i', $fallback, $rewritten );
			}

			$rewritten = preg_replace_callback(
				'/\bCONSTRAINT\s+`([^`]+)`/i',
				function ( $m ) use ( $job_short ) {
					$base = substr( $m[1], 0, 48 );
					return 'CONSTRAINT `' . $base . '_ld' . $job_short . '`';
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Executing verified CREATE TABLE DDL from migration archive into isolated shadow table.
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
			if ( 0 !== stripos( ltrim( $rewritten ), 'INSERT INTO ' ) ) {
				return new WP_Error( 'invalid_insert_sql', 'Unexpected SQL statement inside insert frame.' );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Executing verified bulk INSERT statement from migration archive into isolated shadow table.
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
	 * Run a time-boxed Serialization-Safe Search & Replace step across all shadow tables using $wpdb->prepare().
	 *
	 * @param array         $state    Job state (modified by reference).
	 * @param LDSM_Replacer $replacer Active URL/path replacer.
	 * @param float         $deadline Unix microtime deadline.
	 * @return bool|WP_Error
	 */
	public static function search_replace_step( array &$state, LDSM_Replacer $replacer, $deadline ) {
		global $wpdb;

		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Insufficient permissions to transform database tables.' );
		}

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
			$int_pk    = $cursor['int_pk'];
			$pk_cols   = $cursor['pk_cols'];
			$text_cols = $cursor['text_cols'];

			$select_cols = array_values( array_unique( array_merge( $pk_cols, $text_cols ) ) );
			if ( empty( $pk_cols ) ) {
				$col_placeholders = '*';
				$col_args         = array();
			} else {
				$col_placeholders = implode( ', ', array_fill( 0, count( $select_cols ), '%i' ) );
				$col_args         = $select_cols;
			}

			if ( null !== $int_pk ) {
				if ( null !== $cursor['last_pk'] ) {
					$prepare_args = array_merge( $col_args, array( $shadow_table, $int_pk, (int) $cursor['last_pk'], $int_pk, 300 ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $col_placeholders contains only static '*' or '%i' placeholders; all identifiers and bounds are passed in $prepare_args.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$col_placeholders} FROM %i WHERE %i > %d ORDER BY %i ASC LIMIT %d", $prepare_args ), ARRAY_A );
				} else {
					$prepare_args = array_merge( $col_args, array( $shadow_table, $int_pk, 300 ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $col_placeholders contains only static '*' or '%i' placeholders; all identifiers and bounds are passed in $prepare_args.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$col_placeholders} FROM %i ORDER BY %i ASC LIMIT %d", $prepare_args ), ARRAY_A );
				}
			} else {
				$prepare_args = array_merge( $col_args, array( $shadow_table, (int) $cursor['offset'], 200 ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $col_placeholders contains only static '*' or '%i' placeholders; all identifiers and bounds are passed in $prepare_args.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$col_placeholders} FROM %i LIMIT %d, %d", $prepare_args ), ARRAY_A );
			}

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
	 * Finalize shadow tables and atomically RENAME shadow tables into live destination tables using $wpdb->prepare().
	 *
	 * @param array $state Job state (modified by reference).
	 * @return int|null|WP_Error Preserved admin user ID or null, or WP_Error on failure.
	 */
	public static function finalize_and_atomic_swap( array &$state ) {
		global $wpdb;

		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Insufficient permissions to finalize database import.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		$source_prefix = isset( $state['manifest']['table_prefix'] ) ? (string) $state['manifest']['table_prefix'] : '';
		$dest_prefix   = (string) $state['dest_info']['table_prefix'];

		$shadow_by_suffix = array();
		foreach ( $state['shadow_map'] as $info ) {
			if ( ! empty( $info['has_prefix'] ) ) {
				$shadow_by_suffix[ $info['suffix'] ] = $info['shadow'];
			}
		}

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

		if ( isset( $shadow_by_suffix['options'] ) ) {
			$opt_shadow = $shadow_by_suffix['options'];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $opt_shadow, array( 'option_value' => $state['dest_info']['site_url'] ), array( 'option_name' => 'siteurl' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $opt_shadow, array( 'option_value' => $state['dest_info']['home_url'] ), array( 'option_name' => 'home' ) );

			// Preserve destination vault key across DB swap so existing server backups remain accessible.
			if ( ! empty( $state['dest_vault_key'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `option_name` = %s', $opt_shadow, 'ldsm_vault_key' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					$opt_shadow,
					array(
						'option_name'  => 'ldsm_vault_key',
						'option_value' => (string) $state['dest_vault_key'],
						'autoload'     => 'no',
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$active_raw = $wpdb->get_var(
				$wpdb->prepare( 'SELECT `option_value` FROM %i WHERE `option_name` = %s LIMIT 1', $opt_shadow, 'active_plugins' )
			);
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Restricted with allowed_classes => false when normalizing WordPress active_plugins option.
			$active_arr = is_string( $active_raw ) ? @unserialize( $active_raw, array( 'allowed_classes' => false ) ) : array();
			if ( ! is_array( $active_arr ) ) {
				$active_arr = array();
			}
			$active_arr = array_values(
				array_diff(
					$active_arr,
					array(
						'omni-wp-migrator/omni-wp-migrator.php',
						'liberty-wp-migrator/liberty-wp-migrator.php',
					)
				)
			);
			if ( ! in_array( LDSM_PLUGIN_BASENAME, $active_arr, true ) ) {
				$active_arr[] = LDSM_PLUGIN_BASENAME;
			}
			sort( $active_arr );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$opt_shadow,
				array( 'option_value' => maybe_serialize( $active_arr ) ),
				array( 'option_name' => 'active_plugins' )
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE `option_name` IN (%s, %s, %s)',
					$opt_shadow,
					'_elementor_global_css',
					'elementor_css_print_method_cache',
					LDSM_Storage::JOB_INDEX_OPTION
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing_raw   = $wpdb->get_col( 'SHOW FULL TABLES WHERE Table_type = "BASE TABLE"', 0 );
		$existing_set   = is_array( $existing_raw ) ? array_flip( $existing_raw ) : array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing_views = $wpdb->get_col( 'SHOW FULL TABLES WHERE Table_type = "VIEW"', 0 );
		$views_set      = is_array( $existing_views ) ? array_flip( $existing_views ) : array();

		$rename_pairs_sql = array();
		$rename_args      = array();
		$old_drop_names   = array();
		$idx              = 0;
		$job_short        = substr( preg_replace( '/[^a-z0-9]/i', '', (string) $state['job_id'] ), -4 );

		foreach ( $state['shadow_map'] as $info ) {
			$shadow = (string) $info['shadow'];
			$final  = (string) $info['final'];

			if ( isset( $views_set[ $final ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( 'DROP VIEW IF EXISTS %i', $final ) );
			}

			if ( isset( $existing_set[ $final ] ) ) {
				$old_tmp            = 'ldsmold_' . $job_short . '_' . $idx;
				$old_drop_names[]   = $old_tmp;
				$rename_pairs_sql[] = '%i TO %i';
				$rename_args[]      = $final;
				$rename_args[]      = $old_tmp;
				$idx++;
			}

			$rename_pairs_sql[] = '%i TO %i';
			$rename_args[]      = $shadow;
			$rename_args[]      = $final;
		}

		if ( ! empty( $rename_pairs_sql ) ) {
			foreach ( $old_drop_names as $old_tmp ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old_tmp ) );
			}

			$rename_clause = implode( ', ', $rename_pairs_sql );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $rename_clause contains only static '%i TO %i' placeholders; all table names are passed in $rename_args.
			$swap_ok = $wpdb->query( $wpdb->prepare( "RENAME TABLE {$rename_clause}", $rename_args ) );
			if ( false === $swap_ok ) {
				return new WP_Error( 'atomic_swap_failed', 'Atomic RENAME TABLE failed: ' . $wpdb->last_error );
			}

			foreach ( $old_drop_names as $old_tmp ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old_tmp ) );
			}
		}

		if ( ! empty( $state['deferred_views'] ) ) {
			foreach ( $state['deferred_views'] as $v ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( 'DROP VIEW IF EXISTS %i', $v['view_name'] ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Recreating verified CREATE VIEW DDL from archive.
				$wpdb->query( $v['sql'] );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$users_shadow,
				array( 'user_pass' => $admin_data['user_pass'] ),
				array( 'ID' => $target_id )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
		$caps_val  = maybe_serialize( array( 'administrator' => true ) );

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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $usermeta_shadow, array( 'meta_value' => $caps_val ), array( 'umeta_id' => (int) $has_cap ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $usermeta_shadow, array( 'meta_value' => '10' ), array( 'umeta_id' => (int) $has_lvl ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
	 * Update modified columns on a single row in $shadow_table using $wpdb->update() or fully prepared UPDATE.
	 */
	private static function apply_row_update( $shadow_table, array $original_row, array $updates, array $pk_cols ) {
		global $wpdb;

		if ( ! empty( $pk_cols ) ) {
			$where = array();
			foreach ( $pk_cols as $pk ) {
				$where[ $pk ] = $original_row[ $pk ];
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $shadow_table, $updates, $where );
			return;
		}

		$set_placeholders   = array();
		$where_placeholders = array();
		$prepare_args       = array( $shadow_table );

		foreach ( $updates as $col => $val ) {
			$set_placeholders[] = '%i = %s';
			$prepare_args[]     = (string) $col;
			$prepare_args[]     = (string) $val;
		}

		foreach ( $original_row as $col => $val ) {
			if ( null === $val ) {
				$where_placeholders[] = '%i IS NULL';
				$prepare_args[]       = (string) $col;
			} else {
				$where_placeholders[] = '%i = %s';
				$prepare_args[]       = (string) $col;
				$prepare_args[]       = (string) $val;
			}
		}

		if ( empty( $set_placeholders ) || empty( $where_placeholders ) ) {
			return;
		}

		$set_clause   = implode( ', ', $set_placeholders );
		$where_clause = implode( ' AND ', $where_placeholders );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $set_clause and $where_clause contain only static '%i = %s' / '%i IS NULL' placeholders; all identifiers and values are passed in $prepare_args (safe even under NO_BACKSLASH_ESCAPES).
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET {$set_clause} WHERE {$where_clause} LIMIT 1", $prepare_args ) );
	}

	/**
	 * Replace the first occurrence of `$old_table` backticked identifier in a statement.
	 */
	private static function replace_first_table_ident( $sql, $old_table, $new_table ) {
		$search  = LDSM_DB_Exporter::quote_ident( $old_table );
		$replace = LDSM_DB_Exporter::quote_ident( $new_table );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
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
