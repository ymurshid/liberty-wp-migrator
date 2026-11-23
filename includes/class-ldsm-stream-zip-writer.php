<?php
/**
 * Pure-PHP Resumable Append-Only Streaming ZIP / ZIP64 Writer — LibertyDev Site Migrator.
 *
 * Uses PHP-exit-guarded central_dir.php metadata log and supports skipping leading guard bytes
 * when streaming temporary database.sql.php dumps into standard ZIP entries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LDSM_Stream_Zip_Writer {

	/**
	 * Extensions that are already compressed; stored with method 0 (STORE) for maximum speed.
	 *
	 * @var array<string,bool>
	 */
	private static $store_extensions = array(
		'jpg'   => true,
		'jpeg'  => true,
		'png'   => true,
		'gif'   => true,
		'webp'  => true,
		'avif'  => true,
		'mp4'   => true,
		'm4v'   => true,
		'mov'   => true,
		'webm'  => true,
		'avi'   => true,
		'mkv'   => true,
		'mp3'   => true,
		'ogg'   => true,
		'wav'   => true,
		'flac'  => true,
		'm4a'   => true,
		'woff'  => true,
		'woff2' => true,
		'zip'   => true,
		'gz'    => true,
		'tgz'   => true,
		'bz2'   => true,
		'xz'    => true,
		'7z'    => true,
		'rar'   => true,
		'pdf'   => true,
	);

	/**
	 * Sanitize internal ZIP entry path (UTF-8, forward slashes, no leading slash).
	 *
	 * @param string $entry_name Entry path inside ZIP.
	 * @return string
	 */
	public static function sanitize_entry_name( $entry_name ) {
		$name = str_replace( '\\', '/', (string) $entry_name );
		$name = preg_replace( '#/+#', '/', $name );
		return ltrim( $name, '/' );
	}

	/**
	 * Convert Unix timestamp into DOS time & date integers.
	 *
	 * @param int $mtime Unix modification timestamp.
	 * @return array{0:int,1:int}
	 */
	public static function unix_to_dos_datetime( $mtime ) {
		$ts       = max( 315532800, (int) $mtime );
		$dt       = getdate( $ts );
		$dos_time = ( ( $dt['hours'] & 0x1F ) << 11 ) | ( ( $dt['minutes'] & 0x3F ) << 5 ) | ( intdiv( (int) $dt['seconds'], 2 ) & 0x1F );
		$dos_date = ( ( min( 127, max( 0, $dt['year'] - 1980 ) ) & 0x7F ) << 9 ) | ( ( $dt['mon'] & 0x0F ) << 5 ) | ( $dt['mday'] & 0x1F );
		return array( $dos_time, $dos_date );
	}

	/**
	 * Append an in-memory string entry (e.g. manifest.json) to the streaming ZIP archive.
	 *
	 * @param string $zip_path   Path to .zip.part file.
	 * @param string $cd_php     Path to PHP-guarded central_dir.php metadata log.
	 * @param int    $zip_offset Current write offset in .zip.part (updated by reference).
	 * @param string $entry_name Internal ZIP path.
	 * @param string $content    Raw string bytes.
	 * @param int    $mtime      Optional Unix timestamp.
	 * @return true|WP_Error
	 */
	public static function add_from_string( $zip_path, $cd_php, &$zip_offset, $entry_name, $content, $mtime = 0 ) {
		$entry_name  = self::sanitize_entry_name( $entry_name );
		$mtime       = $mtime > 0 ? (int) $mtime : time();
		$uncomp_size = strlen( $content );
		$crc_hex     = hash( 'crc32b', $content );
		$crc_le4     = strrev( (string) hex2bin( str_pad( $crc_hex, 8, '0', STR_PAD_LEFT ) ) );

		$method    = 0;
		$comp_data = $content;
		if ( $uncomp_size > 32 && function_exists( 'gzdeflate' ) ) {
			$deflated = @gzdeflate( $content, 6 );
			if ( false !== $deflated && strlen( $deflated ) < $uncomp_size ) {
				$method    = 8;
				$comp_data = $deflated;
			}
		}
		$comp_size = strlen( $comp_data );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming random-offset binary archive I/O requires native file pointers.
		$fp = @fopen( $zip_path, file_exists( $zip_path ) ? 'r+b' : 'w+b' );
		if ( ! $fp ) {
			return new WP_Error( 'zip_open_failed', 'Cannot open temporary ZIP archive for writing.' );
		}

		if ( 0 !== @fseek( $fp, $zip_offset, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'zip_seek_failed', 'Failed seeking to ZIP archive offset.' );
		}

		$header_offset = $zip_offset;
		$local_header  = self::build_local_file_header( $entry_name, $method, $mtime, $crc_le4, $comp_size, $uncomp_size );

		if ( false === self::fwrite_all( $fp, $local_header ) || ( $comp_size > 0 && false === self::fwrite_all( $fp, $comp_data ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'zip_write_failed', 'Disk write failed while writing archive entry: ' . $entry_name );
		}

		$zip_offset = $header_offset + strlen( $local_header ) + $comp_size;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		return self::record_central_dir_entry(
			$cd_php,
			array(
				'name'        => $entry_name,
				'method'      => $method,
				'mtime'       => $mtime,
				'crc_hex'     => str_pad( $crc_hex, 8, '0', STR_PAD_LEFT ),
				'comp_size'   => $comp_size,
				'uncomp_size' => $uncomp_size,
				'offset'      => $header_offset,
				'is_dir'      => false,
			)
		);
	}

	/**
	 * Add or continue streaming a file from disk into the .zip.part archive.
	 *
	 * @param string     $zip_path           Path to .zip.part file.
	 * @param string     $cd_php             Path to PHP-guarded central_dir.php metadata log.
	 * @param int        $zip_offset         Current end offset in .zip.part (updated by reference).
	 * @param string     $source_path        Absolute file path on disk.
	 * @param string     $entry_name         Relative path inside the ZIP archive.
	 * @param array|null $partial            Active partial-file transfer state (updated by reference).
	 * @param float      $deadline           Unix microtime deadline for this tick.
	 * @param int        $skip_leading_bytes Optional leading PHP guard bytes to skip from $source_path.
	 * @return bool|WP_Error
	 */
	public static function add_file_chunked( $zip_path, $cd_php, &$zip_offset, $source_path, $entry_name, &$partial, $deadline, $skip_leading_bytes = 0 ) {
		$entry_name = self::sanitize_entry_name( $entry_name );
		$skip_bytes = max( 0, (int) $skip_leading_bytes );

		if ( ! is_file( $source_path ) || is_link( $source_path ) || ! is_readable( $source_path ) ) {
			$partial = null;
			return true;
		}

		if ( is_array( $partial ) && isset( $partial['entry_name'] ) && $partial['entry_name'] === $entry_name ) {
			return self::continue_large_store_file( $zip_path, $cd_php, $zip_offset, $source_path, $partial, $deadline );
		}

		clearstatcache( true, $source_path );
		$raw_file_size = (int) @filesize( $source_path );
		$uncomp_size   = max( 0, $raw_file_size - $skip_bytes );
		$mtime         = (int) @filemtime( $source_path );
		if ( $mtime <= 0 ) {
			$mtime = time();
		}

		$ext           = strtolower( (string) pathinfo( $entry_name, PATHINFO_EXTENSION ) );
		$already_compr = isset( self::$store_extensions[ $ext ] );

		if ( $uncomp_size <= 6 * 1024 * 1024 ) {
			$raw = @file_get_contents( $source_path );
			if ( false === $raw ) {
				$partial = null;
				return true;
			}
			if ( $skip_bytes > 0 ) {
				$raw = ( strlen( $raw ) > $skip_bytes ) ? substr( $raw, $skip_bytes ) : '';
			}
			$actual_len = strlen( $raw );
			$crc_hex    = hash( 'crc32b', $raw );
			$crc_le4    = strrev( (string) hex2bin( str_pad( $crc_hex, 8, '0', STR_PAD_LEFT ) ) );

			$method    = 0;
			$comp_data = $raw;

			if ( ! $already_compr && $actual_len > 48 && function_exists( 'gzdeflate' ) ) {
				$deflated = @gzdeflate( $raw, 6 );
				if ( false !== $deflated && strlen( $deflated ) < $actual_len ) {
					$method    = 8;
					$comp_data = $deflated;
				}
			}
			unset( $raw );
			$comp_size = strlen( $comp_data );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$fp = @fopen( $zip_path, file_exists( $zip_path ) ? 'r+b' : 'w+b' );
			if ( ! $fp ) {
				return new WP_Error( 'zip_open_failed', 'Cannot open temporary ZIP archive for writing.' );
			}
			if ( 0 !== @fseek( $fp, $zip_offset, SEEK_SET ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'zip_seek_failed', 'Failed seeking in ZIP archive.' );
			}

			$header_offset = $zip_offset;
			$local_header  = self::build_local_file_header( $entry_name, $method, $mtime, $crc_le4, $comp_size, $actual_len );

			if ( false === self::fwrite_all( $fp, $local_header ) || ( $comp_size > 0 && false === self::fwrite_all( $fp, $comp_data ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'zip_write_failed', 'Failed writing entry to ZIP archive: ' . $entry_name );
			}
			unset( $comp_data );
			$zip_offset = $header_offset + strlen( $local_header ) + $comp_size;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );

			$partial = null;
			$rec     = self::record_central_dir_entry(
				$cd_php,
				array(
					'name'        => $entry_name,
					'method'      => $method,
					'mtime'       => $mtime,
					'crc_hex'     => str_pad( $crc_hex, 8, '0', STR_PAD_LEFT ),
					'comp_size'   => $comp_size,
					'uncomp_size' => $actual_len,
					'offset'      => $header_offset,
					'is_dir'      => false,
				)
			);
			return is_wp_error( $rec ) ? $rec : true;
		}

		if ( ! $already_compr && $uncomp_size <= 96 * 1024 * 1024 && function_exists( 'deflate_init' ) && function_exists( 'deflate_add' ) ) {
			$res = self::stream_deflate_medium_file( $zip_path, $cd_php, $zip_offset, $source_path, $entry_name, $mtime, $skip_bytes );
			if ( ! is_wp_error( $res ) ) {
				$partial = null;
				return true;
			}
		}

		$crc_hex = self::compute_stream_crc32_hex( $source_path, $skip_bytes );
		if ( false === $crc_hex ) {
			$partial = null;
			return true;
		}
		$crc_le4 = strrev( (string) hex2bin( $crc_hex ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $zip_path, file_exists( $zip_path ) ? 'r+b' : 'w+b' );
		if ( ! $fp ) {
			return new WP_Error( 'zip_open_failed', 'Cannot open temporary ZIP archive for writing.' );
		}
		if ( 0 !== @fseek( $fp, $zip_offset, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'zip_seek_failed', 'Failed seeking in ZIP archive.' );
		}

		$header_offset = $zip_offset;
		$local_header  = self::build_local_file_header( $entry_name, 0, $mtime, $crc_le4, $uncomp_size, $uncomp_size );

		if ( false === self::fwrite_all( $fp, $local_header ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'zip_write_failed', 'Failed writing local header for large file: ' . $entry_name );
		}

		$zip_offset = $header_offset + strlen( $local_header );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		$partial = array(
			'entry_name'    => $entry_name,
			'header_offset' => $header_offset,
			'uncomp_size'   => $uncomp_size,
			'skip_bytes'    => $skip_bytes,
			'bytes_copied'  => 0,
			'mtime'         => $mtime,
			'crc_hex'       => $crc_hex,
		);

		return self::continue_large_store_file( $zip_path, $cd_php, $zip_offset, $source_path, $partial, $deadline );
	}

	/**
	 * Compute 8-char CRC32b hex of $source_path starting at $skip_bytes using zero-RAM streaming.
	 *
	 * @param string $source_path File path.
	 * @param int    $skip_bytes  Leading bytes to skip.
	 * @return string|false
	 */
	private static function compute_stream_crc32_hex( $source_path, $skip_bytes = 0 ) {
		if ( 0 === $skip_bytes ) {
			$h = @hash_file( 'crc32b', $source_path );
			return false !== $h ? str_pad( $h, 8, '0', STR_PAD_LEFT ) : false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $source_path, 'rb' );
		if ( ! $fp ) {
			return false;
		}
		if ( 0 !== @fseek( $fp, $skip_bytes, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return false;
		}
		$ctx = hash_init( 'crc32b' );
		while ( ! feof( $fp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$buf = fread( $fp, 1024 * 1024 );
			if ( false !== $buf && '' !== $buf ) {
				hash_update( $ctx, $buf );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );
		return str_pad( hash_final( $ctx ), 8, '0', STR_PAD_LEFT );
	}

	/**
	 * Stream-compress a 6 MB – 96 MB text/SQL file in 1 MB RAM chunks.
	 */
	private static function stream_deflate_medium_file( $zip_path, $cd_php, &$zip_offset, $source_path, $entry_name, $mtime, $skip_bytes = 0 ) {
		$tmp_deflated = $zip_path . '.deflate.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$src = @fopen( $source_path, 'rb' );
		if ( ! $src ) {
			return new WP_Error( 'src_open_failed', 'Cannot open source file for deflate.' );
		}
		if ( $skip_bytes > 0 && 0 !== @fseek( $src, $skip_bytes, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $src );
			return new WP_Error( 'src_seek_failed', 'Cannot seek past guard header.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$dst = @fopen( $tmp_deflated, 'wb' );
		if ( ! $dst ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $src );
			return new WP_Error( 'tmp_open_failed', 'Cannot open temp deflate buffer.' );
		}

		$deflate_ctx = @deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) );
		$hash_ctx    = hash_init( 'crc32b' );
		if ( false === $deflate_ctx ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $src );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $dst );
			wp_delete_file( $tmp_deflated );
			return new WP_Error( 'deflate_init_failed', 'Could not initialize raw deflate stream.' );
		}

		$actual_uncomp = 0;
		$comp_size     = 0;

		while ( ! feof( $src ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk = fread( $src, 1024 * 1024 );
			if ( false === $chunk ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $src );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $dst );
				wp_delete_file( $tmp_deflated );
				return new WP_Error( 'src_read_failed', 'Failed reading source chunk.' );
			}
			$len = strlen( $chunk );
			if ( $len > 0 ) {
				$actual_uncomp += $len;
				hash_update( $hash_ctx, $chunk );
				$out = deflate_add( $deflate_ctx, $chunk, ZLIB_NO_FLUSH );
				if ( false === $out || ( '' !== $out && false === self::fwrite_all( $dst, $out ) ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $src );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $dst );
					wp_delete_file( $tmp_deflated );
					return new WP_Error( 'deflate_write_failed', 'Failed writing deflated stream.' );
				}
				$comp_size += strlen( $out );
			}
		}

		$tail = deflate_add( $deflate_ctx, '', ZLIB_FINISH );
		if ( false === $tail || ( '' !== $tail && false === self::fwrite_all( $dst, $tail ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $src );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $dst );
			wp_delete_file( $tmp_deflated );
			return new WP_Error( 'deflate_finish_failed', 'Failed finalizing deflated stream.' );
		}
		$comp_size += strlen( $tail );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $src );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $dst );

		$crc_hex = str_pad( hash_final( $hash_ctx ), 8, '0', STR_PAD_LEFT );
		$crc_le4 = strrev( (string) hex2bin( $crc_hex ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $zip_path, file_exists( $zip_path ) ? 'r+b' : 'w+b' );
		if ( ! $fp ) {
			wp_delete_file( $tmp_deflated );
			return new WP_Error( 'zip_open_failed', 'Cannot open temporary ZIP archive.' );
		}
		if ( 0 !== @fseek( $fp, $zip_offset, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			wp_delete_file( $tmp_deflated );
			return new WP_Error( 'zip_seek_failed', 'Failed seeking in ZIP archive.' );
		}

		$header_offset = $zip_offset;
		$local_header  = self::build_local_file_header( $entry_name, 8, $mtime, $crc_le4, $comp_size, $actual_uncomp );
		if ( false === self::fwrite_all( $fp, $local_header ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			wp_delete_file( $tmp_deflated );
			return new WP_Error( 'zip_write_failed', 'Failed writing local header.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$def_in = @fopen( $tmp_deflated, 'rb' );
		if ( ! $def_in ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			wp_delete_file( $tmp_deflated );
			return new WP_Error( 'tmp_reopen_failed', 'Failed reopening deflated temp stream.' );
		}

		while ( ! feof( $def_in ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$buf = fread( $def_in, 1024 * 1024 );
			if ( false !== $buf && '' !== $buf ) {
				if ( false === self::fwrite_all( $fp, $buf ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $def_in );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					wp_delete_file( $tmp_deflated );
					return new WP_Error( 'zip_copy_failed', 'Failed copying deflated stream into archive.' );
				}
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $def_in );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );
		wp_delete_file( $tmp_deflated );

		$zip_offset = $header_offset + strlen( $local_header ) + $comp_size;

		return self::record_central_dir_entry(
			$cd_php,
			array(
				'name'        => $entry_name,
				'method'      => 8,
				'mtime'       => $mtime,
				'crc_hex'     => $crc_hex,
				'comp_size'   => $comp_size,
				'uncomp_size' => $actual_uncomp,
				'offset'      => $header_offset,
				'is_dir'      => false,
			)
		);
	}

	/**
	 * Continue copying bytes of a large STORE-mode file across ticks.
	 */
	private static function continue_large_store_file( $zip_path, $cd_php, &$zip_offset, $source_path, &$partial, $deadline ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$src = @fopen( $source_path, 'rb' );
		if ( ! $src ) {
			return new WP_Error( 'large_src_open_failed', 'Lost access to large source file during archiving: ' . $partial['entry_name'] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $zip_path, 'r+b' );
		if ( ! $fp ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $src );
			return new WP_Error( 'zip_open_failed', 'Cannot open temporary ZIP archive for writing.' );
		}

		$skip_bytes   = isset( $partial['skip_bytes'] ) ? (int) $partial['skip_bytes'] : 0;
		$bytes_copied = (int) $partial['bytes_copied'];
		$expected     = (int) $partial['uncomp_size'];

		if ( 0 !== @fseek( $src, $skip_bytes + $bytes_copied, SEEK_SET ) || 0 !== @fseek( $fp, $zip_offset, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $src );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'large_seek_failed', 'Seek failed while resuming large file archive.' );
		}

		$slice = 2 * 1024 * 1024;
		while ( $bytes_copied < $expected ) {
			$to_read = min( $slice, $expected - $bytes_copied );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$buf = fread( $src, $to_read );
			if ( false === $buf || '' === $buf ) {
				$buf = str_repeat( "\0", min( 65536, $expected - $bytes_copied ) );
			}
			$written = self::fwrite_all( $fp, $buf );
			if ( false === $written ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $src );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'zip_write_failed', 'Disk write failed while streaming large file.' );
			}
			$len           = strlen( $buf );
			$bytes_copied += $len;
			$zip_offset   += $len;

			if ( $bytes_copied < $expected && microtime( true ) >= $deadline ) {
				$partial['bytes_copied'] = $bytes_copied;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $src );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $src );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		$rec = self::record_central_dir_entry(
			$cd_php,
			array(
				'name'        => $partial['entry_name'],
				'method'      => 0,
				'mtime'       => (int) $partial['mtime'],
				'crc_hex'     => $partial['crc_hex'],
				'comp_size'   => $expected,
				'uncomp_size' => $expected,
				'offset'      => (int) $partial['header_offset'],
				'is_dir'      => false,
			)
		);

		$partial = null;
		return is_wp_error( $rec ) ? $rec : true;
	}

	/**
	 * Finalize the streaming ZIP archive by writing Central Directory headers and EOCD.
	 */
	public static function finalize_archive( $zip_path, $cd_php, &$zip_offset ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $zip_path, file_exists( $zip_path ) ? 'r+b' : 'w+b' );
		if ( ! $fp ) {
			return new WP_Error( 'zip_open_failed', 'Cannot open ZIP archive to write Central Directory.' );
		}
		if ( 0 !== @fseek( $fp, $zip_offset, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'zip_seek_failed', 'Cannot seek to Central Directory offset.' );
		}

		$cd_start_offset = $zip_offset;
		$cd_size         = 0;
		$entry_count     = 0;

		if ( file_exists( $cd_php ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$cd_in = @fopen( $cd_php, 'rb' );
			if ( ! $cd_in ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'cd_open_failed', 'Cannot read Central Directory metadata log.' );
			}

			$buffer = '';
			while ( ( $line = fgets( $cd_in ) ) !== false ) {
				$line = trim( $line );
				if ( '' === $line || 0 === strpos( $line, '<?php' ) ) {
					continue;
				}
				$meta = json_decode( $line, true );
				if ( ! is_array( $meta ) ) {
					continue;
				}

				$cd_record    = self::build_central_dir_header( $meta );
				$buffer      .= $cd_record;
				$cd_size     += strlen( $cd_record );
				$entry_count += 1;

				if ( strlen( $buffer ) >= 512 * 1024 ) {
					if ( false === self::fwrite_all( $fp, $buffer ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $cd_in );
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $fp );
						return new WP_Error( 'cd_write_failed', 'Failed writing Central Directory entries.' );
					}
					$buffer = '';
				}
			}

			if ( '' !== $buffer ) {
				if ( false === self::fwrite_all( $fp, $buffer ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $cd_in );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					return new WP_Error( 'cd_write_failed', 'Failed flushing Central Directory entries.' );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $cd_in );
		}

		$zip_offset     = $cd_start_offset + $cd_size;
		$needs_zip64_cd = ( $entry_count >= 0xFFFF ) || ( $cd_size >= 0xFFFFFFFF ) || ( $cd_start_offset >= 0xFFFFFFFF );

		if ( $needs_zip64_cd ) {
			$zip64_eocd_offset = $zip_offset;

			$zip64_eocd  = pack( 'V', 0x06064b50 );
			$zip64_eocd .= pack( 'P', 44 );
			$zip64_eocd .= pack( 'vv', ( 3 << 8 ) | 45, 45 );
			$zip64_eocd .= pack( 'VV', 0, 0 );
			$zip64_eocd .= pack( 'PPPP', $entry_count, $entry_count, $cd_size, $cd_start_offset );

			$zip64_loc  = pack( 'V', 0x07064b50 );
			$zip64_loc .= pack( 'V', 0 );
			$zip64_loc .= pack( 'P', $zip64_eocd_offset );
			$zip64_loc .= pack( 'V', 1 );

			if ( false === self::fwrite_all( $fp, $zip64_eocd . $zip64_loc ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'zip64_eocd_failed', 'Failed writing ZIP64 End of Central Directory.' );
			}
			$zip_offset += strlen( $zip64_eocd ) + strlen( $zip64_loc );
		}

		$eocd_entries = $entry_count >= 0xFFFF ? 0xFFFF : $entry_count;
		$eocd_size    = $cd_size >= 0xFFFFFFFF ? 0xFFFFFFFF : $cd_size;
		$eocd_offset  = $cd_start_offset >= 0xFFFFFFFF ? 0xFFFFFFFF : $cd_start_offset;

		$eocd = pack(
			'VvvvvVVv',
			0x06054b50,
			0,
			0,
			$eocd_entries,
			$eocd_entries,
			$eocd_size,
			$eocd_offset,
			0
		);

		if ( false === self::fwrite_all( $fp, $eocd ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'eocd_write_failed', 'Failed writing End of Central Directory.' );
		}

		$zip_offset += 22;
		@ftruncate( $fp, $zip_offset );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		return true;
	}

	/**
	 * Build PKZIP Local File Header.
	 */
	private static function build_local_file_header( $entry_name, $method, $mtime, $crc_le4, $comp_size, $uncomp_size ) {
		list( $dos_time, $dos_date ) = self::unix_to_dos_datetime( $mtime );

		$needs_zip64 = ( $comp_size >= 0xFFFFFFFF ) || ( $uncomp_size >= 0xFFFFFFFF );
		$ver_needed  = $needs_zip64 ? 45 : 20;
		$gp_flag     = 0x0800;

		if ( $needs_zip64 ) {
			$comp_32     = 0xFFFFFFFF;
			$uncomp_32   = 0xFFFFFFFF;
			$local_extra = pack( 'vvPP', 0x0001, 16, $uncomp_size, $comp_size );
		} else {
			$comp_32     = $comp_size;
			$uncomp_32   = $uncomp_size;
			$local_extra = '';
		}

		$header  = pack( 'Vvvvvv', 0x04034b50, $ver_needed, $gp_flag, $method, $dos_time, $dos_date );
		$header .= $crc_le4;
		$header .= pack( 'VVvv', $comp_32, $uncomp_32, strlen( $entry_name ), strlen( $local_extra ) );
		$header .= $entry_name . $local_extra;

		return $header;
	}

	/**
	 * Build PKZIP Central Directory File Header.
	 */
	private static function build_central_dir_header( array $meta ) {
		$entry_name  = (string) $meta['name'];
		$method      = (int) $meta['method'];
		$mtime       = (int) $meta['mtime'];
		$crc_hex     = str_pad( (string) $meta['crc_hex'], 8, '0', STR_PAD_LEFT );
		$crc_le4     = strrev( (string) hex2bin( $crc_hex ) );
		$comp_size   = (int) $meta['comp_size'];
		$uncomp_size = (int) $meta['uncomp_size'];
		$offset      = (int) $meta['offset'];
		$is_dir      = ! empty( $meta['is_dir'] );

		list( $dos_time, $dos_date ) = self::unix_to_dos_datetime( $mtime );

		$zip64_payload = '';
		if ( $uncomp_size >= 0xFFFFFFFF ) {
			$uncomp_32      = 0xFFFFFFFF;
			$zip64_payload .= pack( 'P', $uncomp_size );
		} else {
			$uncomp_32 = $uncomp_size;
		}

		if ( $comp_size >= 0xFFFFFFFF ) {
			$comp_32        = 0xFFFFFFFF;
			$zip64_payload .= pack( 'P', $comp_size );
		} else {
			$comp_32 = $comp_size;
		}

		if ( $offset >= 0xFFFFFFFF ) {
			$offset_32      = 0xFFFFFFFF;
			$zip64_payload .= pack( 'P', $offset );
		} else {
			$offset_32 = $offset;
		}

		$needs_zip64 = ( '' !== $zip64_payload );
		$cd_extra    = $needs_zip64 ? ( pack( 'vv', 0x0001, strlen( $zip64_payload ) ) . $zip64_payload ) : '';

		$ver_needed  = $needs_zip64 ? 45 : 20;
		$ver_made_by = ( 3 << 8 ) | $ver_needed;
		$gp_flag     = 0x0800;
		$ext_attr    = $is_dir ? ( ( 0040755 << 16 ) | 0x10 ) : ( 0100644 << 16 );

		$cd  = pack( 'Vvvvvvv', 0x02014b50, $ver_made_by, $ver_needed, $gp_flag, $method, $dos_time, $dos_date );
		$cd .= $crc_le4;
		$cd .= pack(
			'VVvvvvvVV',
			$comp_32,
			$uncomp_32,
			strlen( $entry_name ),
			strlen( $cd_extra ),
			0,
			0,
			0,
			$ext_attr,
			$offset_32
		);
		$cd .= $entry_name . $cd_extra;

		return $cd;
	}

	/**
	 * Append one entry record to PHP-exit-guarded central_dir.php.
	 */
	private static function record_central_dir_entry( $cd_php, array $record ) {
		if ( ! LDSM_Storage::ensure_guarded_php_file( $cd_php ) ) {
			return new WP_Error( 'cd_guard_failed', 'Failed initializing guarded Central Directory log.' );
		}
		$line = wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
		if ( false === @file_put_contents( $cd_php, $line, FILE_APPEND | LOCK_EX ) ) {
			return new WP_Error( 'cd_log_failed', 'Failed recording entry in Central Directory log.' );
		}
		return true;
	}

	/**
	 * Reliably write all bytes to a stream handle.
	 */
	private static function fwrite_all( $fp, $data ) {
		$total   = strlen( $data );
		$written = 0;
		while ( $written < $total ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$n = @fwrite( $fp, 0 === $written ? $data : substr( $data, $written ) );
			if ( false === $n || 0 === $n ) {
				return false;
			}
			$written += $n;
		}
		return $written;
	}
}
