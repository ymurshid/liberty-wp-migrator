<?php
/**
 * Pure-PHP Random-Access Streaming ZIP / ZIP64 Reader & Symlink-Safe Extractor — LibertyDev Site Migrator.
 *
 * Enforces Multisite Super Admin permissions and segment-by-segment symlink + realpath confinement
 * before every extraction write, and stores extraction indexes in non-executable protected data files.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LDSM_Stream_Zip_Reader {

	/**
	 * Allowed logical root prefixes inside archives (supports both current v2 logical roots and legacy wp-content/ archives).
	 *
	 * @var array<string,bool>
	 */
	private static $allowed_logical_roots = array(
		'plugins'    => true,
		'mu-plugins' => true,
		'themes'     => true,
		'uploads'    => true,
		'languages'  => true,
	);

	/**
	 * Validate and sanitize a relative entry path inside a ZIP archive (Zip-Slip lexical check).
	 *
	 * @param string $raw_name Raw entry name from Central Directory.
	 * @return string|false
	 */
	public static function sanitize_safe_rel_path( $raw_name ) {
		if ( ! is_string( $raw_name ) || '' === $raw_name ) {
			return false;
		}
		if ( false !== strpos( $raw_name, "\0" ) ) {
			return false;
		}

		$clean = str_replace( '\\', '/', $raw_name );
		$clean = preg_replace( '#/+#', '/', $clean );
		$clean = ltrim( $clean, '/' );

		if ( false !== strpos( $clean, ':' ) ) {
			return false;
		}

		$parts = explode( '/', rtrim( $clean, '/' ) );
		foreach ( $parts as $part ) {
			if ( '..' === $part || '.' === $part ) {
				return false;
			}
		}

		return $clean;
	}

	/**
	 * Read the ZIP Central Directory, locate manifest.json & database/database.sql,
	 * and write all extractable entries into protected non-executable $index_dat_path.
	 *
	 * @param string $zip_path       Path to .zip archive.
	 * @param string $index_dat_path Output path for protected extraction index (.dat).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function index_archive( $zip_path, $index_dat_path ) {
		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Insufficient permissions to inspect migration archives.' );
		}

		if ( ! is_file( $zip_path ) || is_link( $zip_path ) || ! is_readable( $zip_path ) ) {
			return new WP_Error( 'zip_unreadable', 'Archive file does not exist or cannot be read.' );
		}

		if ( is_link( $index_dat_path ) ) {
			return new WP_Error( 'index_symlink_blocked', 'Refusing to write extraction index through symbolic link.' );
		}

		clearstatcache( true, $zip_path );
		$file_size = (int) @filesize( $zip_path );
		if ( $file_size < 22 ) {
			return new WP_Error( 'zip_too_small', 'Selected file is too small to be a valid ZIP archive.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $zip_path, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'zip_open_failed', 'Could not open ZIP archive for reading.' );
		}

		$eocd_info = self::locate_central_directory( $fp, $file_size );
		if ( is_wp_error( $eocd_info ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return $eocd_info;
		}

		$cd_offset     = (int) $eocd_info['cd_offset'];
		$total_entries = (int) $eocd_info['total_entries'];

		if ( 0 !== @fseek( $fp, $cd_offset, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'cd_seek_failed', 'Could not seek to ZIP Central Directory.' );
		}

		$raw_entries   = array();
		$manifest_path = null;

		for ( $i = 0; $i < $total_entries; $i++ ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$hdr = fread( $fp, 46 );
			if ( false === $hdr || strlen( $hdr ) < 46 ) {
				break;
			}
			$u = unpack(
				'Vsig/vmade/vver/vflag/vmethod/vmtime/vmdate/Vcrc/Vcomp/Vuncomp/vnamelen/vextralen/vcomlen/vdisk/vintattr/Vextattr/Voffset',
				$hdr
			);
			if ( ! is_array( $u ) || 0x02014b50 !== (int) $u['sig'] ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'cd_corrupt', 'Corrupt Central Directory signature in ZIP archive.' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$name = $u['namelen'] > 0 ? fread( $fp, $u['namelen'] ) : '';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$extra = $u['extralen'] > 0 ? fread( $fp, $u['extralen'] ) : '';
			if ( $u['comlen'] > 0 ) {
				@fseek( $fp, $u['comlen'], SEEK_CUR );
			}

			$comp_size   = (int) $u['comp'];
			$uncomp_size = (int) $u['uncomp'];
			$offset      = (int) $u['offset'];

			if ( 0xFFFFFFFF === $uncomp_size || 0xFFFFFFFF === $comp_size || 0xFFFFFFFF === $offset ) {
				self::parse_zip64_extra( (string) $extra, $uncomp_size, $comp_size, $offset );
			}

			$safe_name = self::sanitize_safe_rel_path( (string) $name );
			if ( false === $safe_name || '' === $safe_name ) {
				continue;
			}

			$is_dir = ( '/' === substr( (string) $name, -1 ) ) || ( 0 !== ( ( (int) $u['extattr'] ) & 0x10 ) && 0 === $uncomp_size );

			$entry = array(
				'name'        => $safe_name,
				'method'      => (int) $u['method'],
				'comp_size'   => $comp_size,
				'uncomp_size' => $uncomp_size,
				'offset'      => $offset,
				'crc'         => (int) $u['crc'],
				'is_dir'      => $is_dir,
			);

			if ( ! $is_dir && ( 'manifest.json' === $safe_name || '/manifest.json' === substr( $safe_name, -14 ) ) ) {
				if ( null === $manifest_path || strlen( $safe_name ) < strlen( $manifest_path ) ) {
					$manifest_path = $safe_name;
				}
			}

			$raw_entries[] = $entry;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		if ( null === $manifest_path ) {
			return new WP_Error(
				'missing_manifest',
				'This ZIP archive does not contain a valid LibertyDev Site Migrator manifest.json file.'
			);
		}

		$strip_prefix = '';
		if ( 'manifest.json' !== $manifest_path ) {
			$strip_prefix = substr( $manifest_path, 0, -strlen( 'manifest.json' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$idx_out = @fopen( $index_dat_path, 'wb' );
		if ( ! $idx_out ) {
			return new WP_Error( 'index_write_failed', 'Could not create protected extraction index file.' );
		}

		$manifest_entry   = null;
		$db_entry         = null;
		$files_count      = 0;
		$uncompressed_sum = 0;
		$write_buf        = '';

		foreach ( $raw_entries as $entry ) {
			$rel = $entry['name'];
			if ( '' !== $strip_prefix ) {
				if ( 0 !== strpos( $rel, $strip_prefix ) ) {
					continue;
				}
				$rel = substr( $rel, strlen( $strip_prefix ) );
			}
			$rel = trim( $rel, '/' );
			if ( '' === $rel || $entry['is_dir'] ) {
				continue;
			}

			if ( 'manifest.json' === $rel ) {
				$entry['rel_path'] = $rel;
				$manifest_entry    = $entry;
				continue;
			}

			if ( 'database/database.sql' === $rel ) {
				$entry['rel_path'] = $rel;
				$db_entry          = $entry;
				$uncompressed_sum += (int) $entry['uncomp_size'];
				continue;
			}

			// Normalize legacy "wp-content/{root}/..." entries into logical "{root}/..." paths.
			if ( 0 === strpos( $rel, 'wp-content/' ) ) {
				$rel = substr( $rel, 11 );
			}

			$slash_pos = strpos( $rel, '/' );
			if ( false === $slash_pos ) {
				continue;
			}

			$root_key = substr( $rel, 0, $slash_pos );
			$sub_rel  = substr( $rel, $slash_pos + 1 );

			if ( ! isset( self::$allowed_logical_roots[ $root_key ] ) || '' === $sub_rel ) {
				continue;
			}

			// Never overwrite our own running plugin or backups vault directory.
			if ( 'plugins' === $root_key && ( 0 === strpos( $sub_rel, 'libertydev-site-migrator/' ) || 0 === strpos( $sub_rel, 'liberty-wp-migrator/' ) ) ) {
				continue;
			}
			if ( 'uploads' === $root_key && 0 === strpos( $sub_rel, 'libertydev-site-migrator/' ) ) {
				continue;
			}

			$entry['root_key'] = $root_key;
			$entry['sub_path'] = $sub_rel;
			$entry['rel_path'] = $root_key . '/' . $sub_rel;

			$files_count      += 1;
			$uncompressed_sum += (int) $entry['uncomp_size'];
			$write_buf        .= wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";

			if ( strlen( $write_buf ) >= 256 * 1024 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $idx_out, $write_buf );
				$write_buf = '';
			}
		}

		if ( '' !== $write_buf ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $idx_out, $write_buf );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $idx_out );

		if ( null === $manifest_entry || null === $db_entry ) {
			return new WP_Error(
				'incomplete_package',
				'Archive is missing manifest.json or database/database.sql.'
			);
		}

		$manifest_raw = self::extract_entry_to_string( $zip_path, $manifest_entry );
		if ( is_wp_error( $manifest_raw ) ) {
			return $manifest_raw;
		}
		$manifest = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) ) {
			return new WP_Error( 'invalid_manifest_json', 'Archive manifest.json could not be parsed.' );
		}

		return array(
			'manifest'          => $manifest,
			'db_entry'          => $db_entry,
			'files_count'       => $files_count,
			'uncompressed_size' => $uncompressed_sum,
		);
	}

	/**
	 * Extract a small ZIP entry directly into a PHP string.
	 *
	 * @param string $zip_path   Path to .zip archive.
	 * @param array  $entry_meta Entry metadata.
	 * @return string|WP_Error
	 */
	public static function extract_entry_to_string( $zip_path, array $entry_meta ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $zip_path, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'zip_open_failed', 'Cannot open ZIP archive.' );
		}

		$data_offset = self::resolve_entry_data_offset( $fp, (int) $entry_meta['offset'] );
		if ( is_wp_error( $data_offset ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return $data_offset;
		}

		$comp_size = (int) $entry_meta['comp_size'];
		$method    = (int) $entry_meta['method'];

		if ( 0 === $comp_size ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return '';
		}

		if ( 0 !== @fseek( $fp, $data_offset, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			return new WP_Error( 'entry_seek_failed', 'Cannot seek to entry payload.' );
		}

		$comp_bytes = self::fread_exact( $fp, $comp_size );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		if ( false === $comp_bytes ) {
			return new WP_Error( 'entry_read_failed', 'Failed reading compressed bytes from archive.' );
		}

		if ( 0 === $method ) {
			return $comp_bytes;
		}
		if ( 8 === $method ) {
			$inflated = @gzinflate( $comp_bytes );
			if ( false === $inflated ) {
				return new WP_Error( 'entry_inflate_failed', 'Failed decompressing deflate entry: ' . $entry_meta['name'] );
			}
			return $inflated;
		}

		return new WP_Error( 'unsupported_zip_method', 'Unsupported ZIP compression method: ' . $method );
	}

	/**
	 * Extract an entry from the ZIP archive to $dest_path on disk after enforcing Multisite Super Admin
	 * capabilities and strict symlink + realpath boundary verification.
	 *
	 * @param string     $zip_path        Path to .zip archive.
	 * @param array      $entry_meta      Entry metadata.
	 * @param string     $dest_path       Target filesystem path.
	 * @param string     $allowed_root    Canonical allowed root boundary for symlink check.
	 * @param array|null $partial_extract Resumable state for large STORE files.
	 * @param float      $deadline        Unix microtime deadline.
	 * @return bool|WP_Error
	 */
	public static function extract_entry_to_file_chunked( $zip_path, array $entry_meta, $dest_path, $allowed_root, &$partial_extract, $deadline ) {
		if ( ! LDSM_Storage::current_user_can_manage() ) {
			return new WP_Error( 'forbidden', 'Super Admin / Administrator permissions are required for site archive extraction.' );
		}

		// Strict anti-symlink & realpath boundary check BEFORE touching or creating any path.
		$safe_check = LDSM_Storage::prepare_safe_write_target( $dest_path, $allowed_root );
		if ( is_wp_error( $safe_check ) ) {
			return $safe_check;
		}

		$comp_size = (int) $entry_meta['comp_size'];
		$method    = (int) $entry_meta['method'];

		if ( 0 === $comp_size ) {
			if ( is_link( $dest_path ) ) {
				return new WP_Error( 'symlink_blocked', 'Refusing to write to symbolic link.' );
			}
			@file_put_contents( $dest_path, '' );
			$partial_extract = null;
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = @fopen( $zip_path, 'rb' );
		if ( ! $fp ) {
			return new WP_Error( 'zip_open_failed', 'Cannot open ZIP archive for extraction.' );
		}

		if ( is_array( $partial_extract ) && isset( $partial_extract['dest_path'] ) && $partial_extract['dest_path'] === $dest_path ) {
			$data_offset = (int) $partial_extract['data_offset'];
			$bytes_done  = (int) $partial_extract['bytes_done'];
		} else {
			$data_offset = self::resolve_entry_data_offset( $fp, (int) $entry_meta['offset'] );
			if ( is_wp_error( $data_offset ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return $data_offset;
			}
			$bytes_done = 0;
		}

		if ( 0 === $method ) {
			if ( 0 !== @fseek( $fp, $data_offset + $bytes_done, SEEK_SET ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'zip_seek_failed', 'Cannot seek to stored file payload.' );
			}

			if ( is_link( $dest_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'symlink_blocked', 'Refusing to open symbolic link.' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$out = @fopen( $dest_path, $bytes_done > 0 && file_exists( $dest_path ) ? 'r+b' : 'wb' );
			if ( ! $out ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'dest_open_failed', 'Cannot write extracted file.' );
			}
			if ( $bytes_done > 0 ) {
				@fseek( $out, $bytes_done, SEEK_SET );
			}

			$slice = 2 * 1024 * 1024;
			while ( $bytes_done < $comp_size ) {
				$to_read = min( $slice, $comp_size - $bytes_done );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$buf = fread( $fp, $to_read );
				if ( false === $buf || '' === $buf ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $out );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					return new WP_Error( 'zip_eof', 'Unexpected EOF while extracting stored file: ' . $entry_meta['name'] );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				if ( false === fwrite( $out, $buf ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $out );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					return new WP_Error( 'dest_write_failed', 'Disk write error extracting entry.' );
				}
				$bytes_done += strlen( $buf );

				if ( $bytes_done < $comp_size && microtime( true ) >= $deadline ) {
					$partial_extract = array(
						'dest_path'   => $dest_path,
						'data_offset' => $data_offset,
						'bytes_done'  => $bytes_done,
					);
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $out );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					return false;
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			$partial_extract = null;
			return true;
		}

		if ( 8 === $method ) {
			if ( 0 !== @fseek( $fp, $data_offset, SEEK_SET ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'zip_seek_failed', 'Cannot seek to deflated file payload.' );
			}

			if ( $comp_size <= 6 * 1024 * 1024 ) {
				$comp_bytes = self::fread_exact( $fp, $comp_size );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				if ( false === $comp_bytes ) {
					return new WP_Error( 'zip_read_failed', 'Failed reading compressed entry: ' . $entry_meta['name'] );
				}
				$raw = @gzinflate( $comp_bytes );
				unset( $comp_bytes );
				if ( false === $raw ) {
					return new WP_Error( 'inflate_failed', 'Failed inflating entry: ' . $entry_meta['name'] );
				}
				if ( is_link( $dest_path ) ) {
					return new WP_Error( 'symlink_blocked', 'Refusing to write inflated entry to symbolic link.' );
				}
				if ( false === @file_put_contents( $dest_path, $raw ) ) {
					return new WP_Error( 'dest_write_failed', 'Failed writing extracted file.' );
				}
				$partial_extract = null;
				return true;
			}

			if ( ! function_exists( 'inflate_init' ) || ! function_exists( 'inflate_add' ) ) {
				$comp_bytes = self::fread_exact( $fp, $comp_size );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				$raw = ( false !== $comp_bytes ) ? @gzinflate( $comp_bytes ) : false;
				if ( is_link( $dest_path ) || false === $raw || false === @file_put_contents( $dest_path, $raw ) ) {
					return new WP_Error( 'inflate_failed', 'Failed decompressing large entry: ' . $entry_meta['name'] );
				}
				$partial_extract = null;
				return true;
			}

			$inflate_ctx = @inflate_init( ZLIB_ENCODING_RAW );
			if ( false === $inflate_ctx ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'inflate_init_failed', 'Could not initialize streaming zlib inflater.' );
			}

			if ( is_link( $dest_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'symlink_blocked', 'Refusing to write to symbolic link.' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$out = @fopen( $dest_path, 'wb' );
			if ( ! $out ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				return new WP_Error( 'dest_open_failed', 'Cannot open target file for writing.' );
			}

			$remaining = $comp_size;
			while ( $remaining > 0 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$chunk = fread( $fp, min( 1024 * 1024, $remaining ) );
				if ( false === $chunk || '' === $chunk ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $out );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					return new WP_Error( 'zip_eof', 'Unexpected EOF during streaming inflate.' );
				}
				$remaining -= strlen( $chunk );
				$decoded    = @inflate_add( $inflate_ctx, $chunk, 0 === $remaining ? ZLIB_FINISH : ZLIB_NO_FLUSH );
				if ( false === $decoded ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $out );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					return new WP_Error( 'inflate_corrupt', 'Corrupt deflate stream in entry: ' . $entry_meta['name'] );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				if ( '' !== $decoded && false === fwrite( $out, $decoded ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $out );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $fp );
					return new WP_Error( 'dest_write_failed', 'Disk write failed while inflating.' );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			$partial_extract = null;
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );
		return new WP_Error( 'unsupported_zip_method', 'Unsupported ZIP compression method: ' . $method );
	}

	/**
	 * Locate End of Central Directory (and ZIP64 EOCD if present).
	 *
	 * @param resource $fp       Open ZIP file handle.
	 * @param int      $zip_size Archive byte length.
	 * @return array<string,int>|WP_Error
	 */
	private static function locate_central_directory( $fp, $zip_size ) {
		$read_len   = min( $zip_size, 65557 );
		$tail_start = $zip_size - $read_len;

		if ( 0 !== @fseek( $fp, $tail_start, SEEK_SET ) ) {
			return new WP_Error( 'eocd_seek_failed', 'Failed seeking to end of ZIP archive.' );
		}

		$tail = self::fread_exact( $fp, $read_len );
		if ( false === $tail ) {
			return new WP_Error( 'eocd_read_failed', 'Failed reading tail of ZIP archive.' );
		}

		$eocd_rel_pos = false;
		$max_pos      = strlen( $tail ) - 22;
		for ( $pos = $max_pos; $pos >= 0; $pos-- ) {
			if ( "\x50\x4b\x05\x06" === substr( $tail, $pos, 4 ) ) {
				$com_unpack = unpack( 'vlen', substr( $tail, $pos + 20, 2 ) );
				if ( is_array( $com_unpack ) && ( $pos + 22 + (int) $com_unpack['len'] ) === strlen( $tail ) ) {
					$eocd_rel_pos = $pos;
					break;
				}
			}
		}

		if ( false === $eocd_rel_pos ) {
			return new WP_Error( 'invalid_zip_eocd', 'Not a valid or complete ZIP archive (End of Central Directory missing).' );
		}

		$eocd = unpack(
			'Vsig/vdisk/vcddisk/vdiskentries/vtotalentries/Vcdsize/Vcdoffset/vcomlen',
			substr( $tail, $eocd_rel_pos, 22 )
		);

		$total_entries = (int) $eocd['totalentries'];
		$cd_size       = (int) $eocd['cdsize'];
		$cd_offset     = (int) $eocd['cdoffset'];

		$eocd_abs_pos = $tail_start + $eocd_rel_pos;
		if (
			( 0xFFFF === $total_entries || 0xFFFFFFFF === $cd_size || 0xFFFFFFFF === $cd_offset ) &&
			$eocd_abs_pos >= 20
		) {
			if ( 0 === @fseek( $fp, $eocd_abs_pos - 20, SEEK_SET ) ) {
				$loc_raw = self::fread_exact( $fp, 20 );
				if ( false !== $loc_raw && "\x50\x4b\x06\x07" === substr( $loc_raw, 0, 4 ) ) {
					$loc = unpack( 'Vsig/Vdisk/Pzip64off/Vtotaldisks', $loc_raw );
					if ( is_array( $loc ) && 0 === @fseek( $fp, (int) $loc['zip64off'], SEEK_SET ) ) {
						$z64_raw = self::fread_exact( $fp, 56 );
						if ( false !== $z64_raw && "\x50\x4b\x06\x06" === substr( $z64_raw, 0, 4 ) ) {
							$z64 = unpack(
								'Vsig/Psize/vmade/vver/Vdisk/Vcddisk/Pdiskentries/Ptotalentries/Pcdsize/Pcdoffset',
								$z64_raw
							);
							if ( is_array( $z64 ) ) {
								$total_entries = (int) $z64['totalentries'];
								$cd_size       = (int) $z64['cdsize'];
								$cd_offset     = (int) $z64['cdoffset'];
							}
						}
					}
				}
			}
		}

		return array(
			'cd_offset'     => $cd_offset,
			'cd_size'       => $cd_size,
			'total_entries' => $total_entries,
		);
	}

	/**
	 * Parse ZIP64 Extended Information Extra Field (0x0001).
	 */
	private static function parse_zip64_extra( $extra, &$uncomp_size, &$comp_size, &$offset ) {
		$len = strlen( $extra );
		$pos = 0;
		while ( $pos + 4 <= $len ) {
			$hdr  = unpack( 'vid/vsize', substr( $extra, $pos, 4 ) );
			$pos += 4;
			if ( ! is_array( $hdr ) ) {
				break;
			}
			$id   = (int) $hdr['id'];
			$size = (int) $hdr['size'];
			if ( $pos + $size > $len ) {
				break;
			}
			if ( 0x0001 === $id ) {
				$payload = substr( $extra, $pos, $size );
				$cursor  = 0;
				if ( 0xFFFFFFFF === $uncomp_size && $cursor + 8 <= $size ) {
					$u           = unpack( 'Pval', substr( $payload, $cursor, 8 ) );
					$uncomp_size = (int) $u['val'];
					$cursor     += 8;
				}
				if ( 0xFFFFFFFF === $comp_size && $cursor + 8 <= $size ) {
					$u         = unpack( 'Pval', substr( $payload, $cursor, 8 ) );
					$comp_size = (int) $u['val'];
					$cursor   += 8;
				}
				if ( 0xFFFFFFFF === $offset && $cursor + 8 <= $size ) {
					$u       = unpack( 'Pval', substr( $payload, $cursor, 8 ) );
					$offset  = (int) $u['val'];
					$cursor += 8;
				}
				return;
			}
			$pos += $size;
		}
	}

	/**
	 * Read Local File Header at $header_offset to calculate exact start of compressed payload.
	 */
	private static function resolve_entry_data_offset( $fp, $header_offset ) {
		if ( 0 !== @fseek( $fp, $header_offset, SEEK_SET ) ) {
			return new WP_Error( 'lfh_seek_failed', 'Cannot seek to Local File Header.' );
		}
		$lfh = self::fread_exact( $fp, 30 );
		if ( false === $lfh ) {
			return new WP_Error( 'lfh_read_failed', 'Cannot read Local File Header.' );
		}
		$u = unpack( 'Vsig/vver/vflag/vmethod/vmtime/vmdate/Vcrc/Vcomp/Vuncomp/vnamelen/vextralen', $lfh );
		if ( ! is_array( $u ) || 0x04034b50 !== (int) $u['sig'] ) {
			return new WP_Error( 'lfh_sig_invalid', 'Invalid Local File Header signature.' );
		}
		return $header_offset + 30 + (int) $u['namelen'] + (int) $u['extralen'];
	}

	/**
	 * Read exact number of bytes from stream.
	 */
	private static function fread_exact( $fp, $length ) {
		if ( $length <= 0 ) {
			return '';
		}
		$data = '';
		$rem  = $length;
		while ( $rem > 0 && ! feof( $fp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk = fread( $fp, min( 1024 * 1024, $rem ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data .= $chunk;
			$rem  -= strlen( $chunk );
		}
		return ( strlen( $data ) === $length ) ? $data : false;
	}
}
