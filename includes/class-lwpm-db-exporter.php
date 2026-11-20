<?php
/**
 * Time-Boxed Chunked MySQL / MariaDB Database Exporter — Liberty WP Migrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LWPM_DB_Exporter {

	/**
	 * Max bytes per single INSERT statement to stay well below MySQL max_allowed_packet.
	 */
	const MAX_INSERT_BYTES = 524288; // 512 KB.

	/**
	 * Rows fetched per SELECT query batch.
	 */
	const SELECT_BATCH_SIZE = 350;

	/**
	 * Initialize database export list of tables and views into $state.
	 */
	public static function init_tables_list( array &$state ) {
		global $wpdb;

		$options        = isset( $state['options'] ) && is_array( $state['options'] ) ? $state['options'] : array();
		$include_custom = ! empty( $options['include_custom_tables'] );
		$prefix         = (string) $wpdb->prefix;

		$raw_tables = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
		if ( ! is_array( $raw_tables ) ) {
			return new WP_Error( 'db_list_failed', 'Could not list database tables via SHOW FULL TABLES.' );
		}

		$base_tables = array();
		$views       = array();

		foreach ( $raw_tables as $row ) {
			if ( empty( $row[0] ) ) {
				continue;
			}
			$table_name = (string) $row[0];
			$table_type = isset( $row[1] ) ? strtoupper( (string) $row[1] ) : 'BASE TABLE';

			// Never export temporary shadow/old tables.
			if ( 0 === strpos( $table_name, 'lwpmt_' ) || 0 === strpos( $table_name, 'lwpmold_' ) ) {
				continue;
			}

			$has_prefix = ( '' !== $prefix && 0 === strpos( $table_name, $prefix ) );
			if ( ! $has_prefix && ! $include_custom ) {
				continue;
			}

			$item = array(
				'name'       => $table_name,
				'has_prefix' => $has_prefix,
				'suffix'     => $has_prefix ? substr( $table_name, strlen( $prefix ) ) : $table_name,
			);

			if ( 'VIEW' === $table_type ) {
				$views[] = $item;
			} else {
				$base_tables[] = $item;
			}
		}

		$state['db_tables']        = $base_tables;
		$state['db_views']         = $views;
		$state['db_table_idx']     = 0;
		$state['db_table_cursor']  = null;
		$state['db_views_done']    = false;
		$state['db_rows_exported'] = 0;

		return true;
	}

	/**
	 * Run a time-boxed database export step writing to $sql_filepath.
	 */
	public static function export_step( array &$state, $sql_filepath, $deadline, $replacer = null ) {
		global $wpdb;

		if ( ! isset( $state['db_tables'] ) ) {
			$init = self::init_tables_list( $state );
			if ( is_wp_error( $init ) ) {
				return $init;
			}
		}

		$is_new_file = ! file_exists( $sql_filepath ) || 0 === (int) @filesize( $sql_filepath );
		$fp          = @fopen( $sql_filepath, 'ab' );
		if ( ! $fp ) {
			return new WP_Error( 'sql_open_failed', 'Cannot open database.sql for writing.' );
		}

		if ( $is_new_file ) {
			$header  = "-- Liberty WP Migrator SQL Dump (by LibertyDev)\n";
			$header .= '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
			$header .= '-- Source URL: ' . home_url() . "\n";
			$header .= "SET NAMES utf8mb4;\n";
			$header .= "SET FOREIGN_KEY_CHECKS = 0;\n";
			$header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
			if ( false === fwrite( $fp, $header ) ) {
				fclose( $fp );
				return new WP_Error( 'sql_write_failed', 'Failed writing SQL header.' );
			}
		}

		$tables      = $state['db_tables'];
		$total_count = count( $tables );
		$options     = isset( $state['options'] ) && is_array( $state['options'] ) ? $state['options'] : array();

		while ( $state['db_table_idx'] < $total_count ) {
			$t_info     = $tables[ $state['db_table_idx'] ];
			$table_name = $t_info['name'];
			$has_prefix = ! empty( $t_info['has_prefix'] );
			$suffix     = (string) $t_info['suffix'];

			if ( ! is_array( $state['db_table_cursor'] ) || $state['db_table_cursor']['table'] !== $table_name ) {
				$schema_res = self::emit_table_schema_and_inspect( $fp, $table_name, $has_prefix, $suffix );
				if ( is_wp_error( $schema_res ) ) {
					fclose( $fp );
					return $schema_res;
				}
				$state['db_table_cursor'] = array(
					'table'    => $table_name,
					'pk_col'   => $schema_res['pk_col'],
					'last_pk'  => null,
					'offset'   => 0,
					'col_meta' => $schema_res['col_meta'],
				);
			}

			$cursor    = &$state['db_table_cursor'];
			$pk_col    = $cursor['pk_col'];
			$col_meta  = $cursor['col_meta'];
			$where     = self::build_table_filter_clause( $has_prefix ? $suffix : '', $options );
			$esc_table = self::quote_ident( $table_name );

			if ( null !== $pk_col ) {
				$esc_pk = self::quote_ident( $pk_col );
				$conds  = array();
				if ( null !== $cursor['last_pk'] ) {
					$conds[] = $esc_pk . ' > ' . (int) $cursor['last_pk'];
				}
				if ( '' !== $where ) {
					$conds[] = '(' . $where . ')';
				}
				$where_sql = ! empty( $conds ) ? ( 'WHERE ' . implode( ' AND ', $conds ) ) : '';
				$query     = "SELECT * FROM {$esc_table} {$where_sql} ORDER BY {$esc_pk} ASC LIMIT " . self::SELECT_BATCH_SIZE;
			} else {
				$where_sql = '' !== $where ? ( 'WHERE ' . $where ) : '';
				$offset    = (int) $cursor['offset'];
				$query     = "SELECT * FROM {$esc_table} {$where_sql} LIMIT {$offset}, " . self::SELECT_BATCH_SIZE;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $query, ARRAY_A );

			if ( empty( $rows ) ) {
				$state['db_table_idx']   += 1;
				$state['db_table_cursor'] = null;
			} else {
				$write_res = self::emit_insert_batches( $fp, $table_name, $has_prefix, $suffix, $rows, $col_meta, $replacer );
				if ( is_wp_error( $write_res ) ) {
					fclose( $fp );
					return $write_res;
				}

				$fetched_count              = count( $rows );
				$state['db_rows_exported'] += $fetched_count;

				if ( null !== $pk_col ) {
					$last_row          = $rows[ $fetched_count - 1 ];
					$cursor['last_pk'] = (int) $last_row[ $pk_col ];
				} else {
					$cursor['offset'] += $fetched_count;
				}

				if ( $fetched_count < self::SELECT_BATCH_SIZE ) {
					$state['db_table_idx']   += 1;
					$state['db_table_cursor'] = null;
				}
			}

			unset( $cursor );

			if ( microtime( true ) >= $deadline ) {
				fclose( $fp );
				return false;
			}
		}

		if ( empty( $state['db_views_done'] ) ) {
			if ( ! empty( $state['db_views'] ) ) {
				foreach ( $state['db_views'] as $v_info ) {
					$v_res = self::emit_view_schema( $fp, $v_info['name'], ! empty( $v_info['has_prefix'] ), (string) $v_info['suffix'] );
					if ( is_wp_error( $v_res ) ) {
						fclose( $fp );
						return $v_res;
					}
				}
			}
			fwrite( $fp, "\nSET FOREIGN_KEY_CHECKS = 1;\n" );
			$state['db_views_done'] = true;
		}

		fclose( $fp );
		return true;
	}

	/**
	 * Write CREATE TABLE statement and inspect columns + integer primary key.
	 */
	private static function emit_table_schema_and_inspect( $fp, $table_name, $has_prefix, $suffix ) {
		global $wpdb;

		$esc_table = self::quote_ident( $table_name );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$create_row = $wpdb->get_row( "SHOW CREATE TABLE {$esc_table}", ARRAY_N );
		if ( ! is_array( $create_row ) || empty( $create_row[1] ) ) {
			return new WP_Error( 'show_create_failed', 'Failed reading schema for table: ' . $table_name );
		}

		$create_sql = rtrim( trim( (string) $create_row[1] ), ';' ) . ';';
		$drop_sql   = "DROP TABLE IF EXISTS {$esc_table};";

		if ( false === self::write_framed_statement( $fp, 'drop_table', $table_name, $has_prefix, $suffix, $drop_sql ) ) {
			return new WP_Error( 'sql_write_failed', 'Failed writing DROP TABLE for: ' . $table_name );
		}
		if ( false === self::write_framed_statement( $fp, 'create_table', $table_name, $has_prefix, $suffix, $create_sql ) ) {
			return new WP_Error( 'sql_write_failed', 'Failed writing CREATE TABLE for: ' . $table_name );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cols     = $wpdb->get_results( "SHOW COLUMNS FROM {$esc_table}", ARRAY_A );
		$col_meta = array();
		$int_cols = array();

		if ( is_array( $cols ) ) {
			foreach ( $cols as $c ) {
				$cname = (string) $c['Field'];
				$ctype = strtolower( (string) $c['Type'] );
				$base  = preg_replace( '/\(.*/', '', $ctype );
				$base  = trim( (string) explode( ' ', $base )[0] );

				if ( in_array( $base, array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'geometry', 'point', 'linestring', 'polygon' ), true ) ) {
					$col_meta[ $cname ] = 'binary';
				} elseif ( 'bit' === $base ) {
					$col_meta[ $cname ] = 'bit';
				} elseif ( in_array( $base, array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint' ), true ) ) {
					$col_meta[ $cname ] = 'int';
					$int_cols[ $cname ] = true;
				} else {
					$col_meta[ $cname ] = 'text';
				}
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$pk_rows = $wpdb->get_results( "SHOW KEYS FROM {$esc_table} WHERE Key_name = 'PRIMARY'", ARRAY_A );
		$pk_col  = null;
		if ( is_array( $pk_rows ) && 1 === count( $pk_rows ) ) {
			$candidate = (string) $pk_rows[0]['Column_name'];
			if ( isset( $int_cols[ $candidate ] ) ) {
				$pk_col = $candidate;
			}
		}

		return array(
			'pk_col'   => $pk_col,
			'col_meta' => $col_meta,
		);
	}

	/**
	 * Emit batched INSERT statements capped at MAX_INSERT_BYTES per packet.
	 */
	private static function emit_insert_batches( $fp, $table_name, $has_prefix, $suffix, array $rows, array $col_meta, $replacer = null ) {
		if ( empty( $rows ) ) {
			return true;
		}

		$first_row = $rows[0];
		$col_names = array_keys( $first_row );
		$quoted    = array_map( array( __CLASS__, 'quote_ident' ), $col_names );
		$prefix_in = 'INSERT INTO ' . self::quote_ident( $table_name ) . ' (' . implode( ',', $quoted ) . ') VALUES ';

		$tuples     = array();
		$curr_bytes = strlen( $prefix_in );
		$has_rules  = ( $replacer instanceof LWPM_Replacer ) && $replacer->has_rules();

		foreach ( $rows as $row ) {
			$encoded_vals = array();
			foreach ( $col_names as $col ) {
				$val  = $row[ $col ];
				$kind = isset( $col_meta[ $col ] ) ? $col_meta[ $col ] : 'text';

				if ( null === $val ) {
					$encoded_vals[] = 'NULL';
					continue;
				}

				if ( 'binary' === $kind ) {
					$encoded_vals[] = "X'" . bin2hex( (string) $val ) . "'";
				} elseif ( 'bit' === $kind ) {
					$str_val = (string) $val;
					if ( 1 === strlen( $str_val ) && ( "\x00" === $str_val || "\x01" === $str_val ) ) {
						$encoded_vals[] = 'b\'' . ( ord( $str_val ) ? '1' : '0' ) . '\'';
					} elseif ( ctype_digit( $str_val ) ) {
						$encoded_vals[] = $str_val;
					} else {
						$encoded_vals[] = "X'" . bin2hex( $str_val ) . "'";
					}
				} elseif ( 'int' === $kind && 1 === preg_match( '/^-?\d+$/', (string) $val ) ) {
					$encoded_vals[] = (string) $val;
				} else {
					$text_val = (string) $val;
					if ( $has_rules ) {
						$text_val = (string) $replacer->transform( $text_val );
					}
					$encoded_vals[] = "'" . self::escape_sql_string( $text_val ) . "'";
				}
			}

			$tuple      = '(' . implode( ',', $encoded_vals ) . ')';
			$tuple_size = strlen( $tuple ) + 2;

			if ( ! empty( $tuples ) && ( ( $curr_bytes + $tuple_size ) > self::MAX_INSERT_BYTES || count( $tuples ) >= 150 ) ) {
				$stmt = $prefix_in . implode( ',', $tuples ) . ';';
				if ( false === self::write_framed_statement( $fp, 'insert', $table_name, $has_prefix, $suffix, $stmt ) ) {
					return new WP_Error( 'sql_write_failed', 'Disk write error exporting rows for: ' . $table_name );
				}
				$tuples     = array();
				$curr_bytes = strlen( $prefix_in );
			}

			$tuples[]    = $tuple;
			$curr_bytes += $tuple_size;
		}

		if ( ! empty( $tuples ) ) {
			$stmt = $prefix_in . implode( ',', $tuples ) . ';';
			if ( false === self::write_framed_statement( $fp, 'insert', $table_name, $has_prefix, $suffix, $stmt ) ) {
				return new WP_Error( 'sql_write_failed', 'Disk write error flushing rows for: ' . $table_name );
			}
		}

		return true;
	}

	/**
	 * Emit CREATE VIEW statement stripping DEFINER clauses.
	 */
	private static function emit_view_schema( $fp, $view_name, $has_prefix, $suffix ) {
		global $wpdb;

		$esc_view = self::quote_ident( $view_name );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( "SHOW CREATE VIEW {$esc_view}", ARRAY_N );
		if ( ! is_array( $row ) || empty( $row[1] ) ) {
			return true;
		}

		$view_sql = rtrim( trim( (string) $row[1] ), ';' ) . ';';
		$view_sql = preg_replace( '/\bDEFINER\s*=\s*`[^`]+`@`[^`]+`\s*/i', '', $view_sql );
		$view_sql = preg_replace( '/\bSQL\s+SECURITY\s+DEFINER\b/i', 'SQL SECURITY INVOKER', $view_sql );

		$drop_sql = "DROP VIEW IF EXISTS {$esc_view};";
		self::write_framed_statement( $fp, 'drop_view', $view_name, $has_prefix, $suffix, $drop_sql );
		if ( false === self::write_framed_statement( $fp, 'create_view', $view_name, $has_prefix, $suffix, $view_sql ) ) {
			return new WP_Error( 'sql_write_failed', 'Failed writing view schema: ' . $view_name );
		}

		return true;
	}

	/**
	 * Build optional WHERE filter clause for core WP tables.
	 */
	private static function build_table_filter_clause( $suffix, array $options ) {
		if ( 'posts' === $suffix && ! empty( $options['exclude_revisions'] ) ) {
			return "`post_type` != 'revision'";
		}

		if ( 'comments' === $suffix && ! empty( $options['exclude_spam'] ) ) {
			return "`comment_approved` NOT IN ('spam', 'trash')";
		}

		if ( 'options' === $suffix ) {
			$conds = array(
				"`option_name` NOT IN ('lwpm_maintenance_active', 'lwpm_needs_rewrite_flush', 'lwpm_schedule_status', '_transient_lwpm_bg_runner_lock', '_transient_timeout_lwpm_bg_runner_lock')",
			);
			if ( ! empty( $options['exclude_transients'] ) ) {
				$conds[] = "`option_name` NOT LIKE '\\_transient\\_%'";
				$conds[] = "`option_name` NOT LIKE '\\_site\\_transient\\_%'";
			}
			return implode( ' AND ', $conds );
		}

		return '';
	}

	/**
	 * Write a standard SQL statement preceded by a single-line -- #LWPM#{json} framing header.
	 */
	private static function write_framed_statement( $fp, $op, $table_name, $has_prefix, $suffix, $sql ) {
		$sql_line = $sql . "\n";
		$meta     = array(
			'op'         => $op,
			'table'      => $table_name,
			'has_prefix' => (bool) $has_prefix,
			'suffix'     => $suffix,
			'bytes'      => strlen( $sql_line ),
		);
		$frame    = '-- #LWPM#' . wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
		$payload  = $frame . $sql_line;
		$total    = strlen( $payload );
		$written  = 0;

		while ( $written < $total ) {
			$n = @fwrite( $fp, 0 === $written ? $payload : substr( $payload, $written ) );
			if ( false === $n || 0 === $n ) {
				return false;
			}
			$written += $n;
		}
		return true;
	}

	/**
	 * Quote a MySQL identifier with backticks.
	 */
	public static function quote_ident( $ident ) {
		return '`' . str_replace( '`', '``', (string) $ident ) . '`';
	}

	/**
	 * Escape raw string bytes for a single-quoted MySQL string literal.
	 */
	public static function escape_sql_string( $str ) {
		return strtr(
			$str,
			array(
				'\\'   => '\\\\',
				"\x00" => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}
}
