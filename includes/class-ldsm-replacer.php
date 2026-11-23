<?php
/**
 * Byte-Exact Serialization-Safe & JSON-Safe Search-and-Replace Engine — LibertyDev Site Migrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LDSM_Replacer {

	/**
	 * Maximum recursion depth for nested serialized/JSON payloads.
	 */
	const MAX_DEPTH = 24;

	/**
	 * Associative replacement map for single-pass strtr() (old => new).
	 *
	 * @var array<string,string>
	 */
	private $replace_map = array();

	/**
	 * Plain unescaped map used inside decoded JSON strings.
	 *
	 * @var array<string,string>
	 */
	private $decoded_json_map = array();

	/**
	 * Fast substring needles to skip strings that cannot possibly match.
	 *
	 * @var array<int,string>
	 */
	private $fast_needles = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $raw_pairs  Associative map of (search => replace).
	 * @param array<string,string> $json_pairs Optional clean pairs without slash-escaped duplicates.
	 */
	public function __construct( array $raw_pairs, array $json_pairs = array() ) {
		$filtered = array();
		foreach ( $raw_pairs as $search => $replace ) {
			$search  = (string) $search;
			$replace = (string) $replace;
			if ( '' === $search || $search === $replace ) {
				continue;
			}
			$filtered[ $search ] = $replace;

			if ( 0 === strpos( $replace, $search ) ) {
				$filtered[ $replace ] = $replace;
			}
		}

		uksort(
			$filtered,
			function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		$this->replace_map = $filtered;

		if ( ! empty( $json_pairs ) ) {
			$j_filtered = array();
			foreach ( $json_pairs as $s => $r ) {
				$s = (string) $s;
				$r = (string) $r;
				if ( '' === $s || $s === $r ) {
					continue;
				}
				$j_filtered[ $s ] = $r;
				if ( 0 === strpos( $r, $s ) ) {
					$j_filtered[ $r ] = $r;
				}
			}
			uksort(
				$j_filtered,
				function ( $a, $b ) {
					return strlen( $b ) <=> strlen( $a );
				}
			);
			$this->decoded_json_map = $j_filtered;
		} else {
			$this->decoded_json_map = $this->replace_map;
		}

		foreach ( $this->replace_map as $k => $v ) {
			if ( $k !== $v ) {
				$this->fast_needles[] = $k;
			}
		}
	}

	/**
	 * Check if this replacer has any active replacement rules.
	 *
	 * @return bool
	 */
	public function has_rules() {
		return ! empty( $this->fast_needles );
	}

	/**
	 * Build comprehensive URL, path (including relocated plugins/themes/uploads), and custom replacement maps.
	 *
	 * @param array $manifest        Source site manifest.
	 * @param array $dest_info       Destination site info.
	 * @param array $custom_replaces Optional custom replace rules.
	 * @return LDSM_Replacer
	 */
	public static function from_migration_context( array $manifest, array $dest_info, array $custom_replaces = array() ) {
		$raw_map   = array();
		$json_map  = array();
		$url_pairs = array();

		foreach ( array( 'uploads_url', 'plugins_url', 'content_url', 'site_url', 'home_url' ) as $url_key ) {
			if ( ! empty( $manifest[ $url_key ] ) && ! empty( $dest_info[ $url_key ] ) ) {
				$url_pairs[] = array( $manifest[ $url_key ], $dest_info[ $url_key ] );
			}
		}

		foreach ( $url_pairs as $pair ) {
			self::register_url_variants( (string) $pair[0], (string) $pair[1], $raw_map, $json_map );
		}

		$path_pairs = array();
		foreach ( array( 'uploads_path', 'plugins_path', 'mu_plugins_path', 'themes_path', 'languages_path', 'abspath' ) as $path_key ) {
			if ( ! empty( $manifest[ $path_key ] ) && ! empty( $dest_info[ $path_key ] ) ) {
				$path_pairs[] = array( $manifest[ $path_key ], $dest_info[ $path_key ] );
			}
		}

		foreach ( $path_pairs as $pp ) {
			self::register_path_variants( (string) $pp[0], (string) $pp[1], $raw_map, $json_map );
		}

		foreach ( $custom_replaces as $rule ) {
			if ( is_array( $rule ) && isset( $rule['search'], $rule['replace'] ) ) {
				$s = (string) $rule['search'];
				$r = (string) $rule['replace'];
				if ( '' !== $s && $s !== $r ) {
					$raw_map[ $s ]  = $r;
					$json_map[ $s ] = $r;
				}
			}
		}

		return new self( $raw_map, $json_map );
	}

	/**
	 * Register HTTP, HTTPS, protocol-relative, JSON-escaped, and URL-encoded variants of a URL pair.
	 */
	private static function register_url_variants( $old_url, $new_url, array &$raw_map, array &$json_map ) {
		$old_clean = rtrim( trim( $old_url ), '/' );
		$new_clean = rtrim( trim( $new_url ), '/' );

		if ( '' === $old_clean || '' === $new_clean || $old_clean === $new_clean ) {
			return;
		}

		$old_no_scheme = preg_replace( '#^https?://#i', '', $old_clean );
		$new_no_scheme = preg_replace( '#^https?://#i', '', $new_clean );
		$new_scheme    = ( 0 === stripos( $new_clean, 'https://' ) ) ? 'https' : 'http';

		if ( '' === $old_no_scheme || '' === $new_no_scheme ) {
			return;
		}

		$old_https = 'https://' . $old_no_scheme;
		$old_http  = 'http://' . $old_no_scheme;
		$new_full  = $new_scheme . '://' . $new_no_scheme;

		$raw_map[ $old_https ]  = $new_full;
		$raw_map[ $old_http ]   = $new_full;
		$json_map[ $old_https ] = $new_full;
		$json_map[ $old_http ]  = $new_full;

		if ( $old_no_scheme !== $new_no_scheme ) {
			$raw_map[ '//' . $old_no_scheme ]  = '//' . $new_no_scheme;
			$json_map[ '//' . $old_no_scheme ] = '//' . $new_no_scheme;
		}

		$esc_new_full                                     = str_replace( '/', '\\/', $new_full );
		$raw_map[ str_replace( '/', '\\/', $old_https ) ] = $esc_new_full;
		$raw_map[ str_replace( '/', '\\/', $old_http ) ]  = $esc_new_full;
		if ( $old_no_scheme !== $new_no_scheme ) {
			$raw_map[ '\\/\\/' . str_replace( '/', '\\/', $old_no_scheme ) ] = '\\/\\/' . str_replace( '/', '\\/', $new_no_scheme );
		}

		$enc_new_full                           = rawurlencode( $new_full );
		$raw_map[ rawurlencode( $old_https ) ]  = $enc_new_full;
		$raw_map[ rawurlencode( $old_http ) ]   = $enc_new_full;
		$json_map[ rawurlencode( $old_https ) ] = $enc_new_full;
		$json_map[ rawurlencode( $old_http ) ]  = $enc_new_full;
	}

	/**
	 * Register forward-slash, Windows-backslash, and JSON-escaped filesystem path variants.
	 */
	private static function register_path_variants( $old_path, $new_path, array &$raw_map, array &$json_map ) {
		$old_fwd = LDSM_Storage::normalize_path( $old_path );
		$new_fwd = LDSM_Storage::normalize_path( $new_path );

		if ( strlen( $old_fwd ) < 4 || '' === $new_fwd || $old_fwd === $new_fwd ) {
			return;
		}

		$raw_map[ $old_fwd ]  = $new_fwd;
		$json_map[ $old_fwd ] = $new_fwd;

		$raw_map[ str_replace( '/', '\\/', $old_fwd ) ] = str_replace( '/', '\\/', $new_fwd );

		$old_win = str_replace( '/', '\\', $old_fwd );
		if ( $old_win !== $old_fwd ) {
			$raw_map[ $old_win ]                              = $new_fwd;
			$json_map[ $old_win ]                             = $new_fwd;
			$raw_map[ str_replace( '\\', '\\\\', $old_win ) ] = $new_fwd;
		}
	}

	/**
	 * Transform any database string value safely (serialized PHP, JSON, or plain text/HTML).
	 */
	public function transform( $value ) {
		if ( ! is_string( $value ) || '' === $value || empty( $this->fast_needles ) ) {
			return $value;
		}

		if ( ! $this->might_contain_match( $value ) ) {
			return $value;
		}

		return $this->transform_value( $value, 0, false );
	}

	/**
	 * Quick check whether $text contains any active search needle.
	 */
	private function might_contain_match( $text ) {
		foreach ( $this->fast_needles as $needle ) {
			if ( false !== strpos( $text, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursive value transformer supporting serialized PHP, JSON objects/arrays, and plain strings.
	 */
	private function transform_value( $str, $depth = 0, $inside_json = false ) {
		if ( '' === $str || $depth > self::MAX_DEPTH ) {
			return $str;
		}

		if ( self::looks_serialized( $str ) ) {
			$pos    = 0;
			$len    = strlen( $str );
			$ok     = true;
			$parsed = $this->parse_serialized_node( $str, $pos, $len, $depth + 1, false, $ok );
			if ( $ok && $pos === $len ) {
				return $parsed;
			}
			return $str;
		}

		if ( self::looks_like_json_container( $str ) ) {
			$had_escaped_slashes = ( false !== strpos( $str, '\\/' ) );
			$decoded             = json_decode( $str, false, 512 );
			if ( JSON_ERROR_NONE === json_last_error() && ( is_object( $decoded ) || is_array( $decoded ) ) ) {
				$mutated = $this->walk_decoded_json( $decoded, $depth + 1 );
				$flags   = JSON_UNESCAPED_UNICODE;
				if ( defined( 'JSON_PRESERVE_ZERO_FRACTION' ) ) {
					$flags |= JSON_PRESERVE_ZERO_FRACTION;
				}
				if ( ! $had_escaped_slashes ) {
					$flags |= JSON_UNESCAPED_SLASHES;
				}
				$reencoded = wp_json_encode( $mutated, $flags );
				if ( is_string( $reencoded ) && '' !== $reencoded ) {
					return $reencoded;
				}
			}
		}

		return strtr( $str, $inside_json ? $this->decoded_json_map : $this->replace_map );
	}

	/**
	 * Recursively walk a json_decode(..., false) structure preserving stdClass ({}) vs array ([]).
	 */
	private function walk_decoded_json( $node, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return $node;
		}

		if ( is_string( $node ) ) {
			return $this->transform_value( $node, $depth, true );
		}

		if ( is_array( $node ) ) {
			foreach ( $node as $idx => $item ) {
				$node[ $idx ] = $this->walk_decoded_json( $item, $depth + 1 );
			}
			return $node;
		}

		if ( is_object( $node ) ) {
			$vars = get_object_vars( $node );
			if ( empty( $vars ) ) {
				return $node;
			}
			$rebuilt = new stdClass();
			foreach ( $vars as $k => $v ) {
				$new_k             = is_string( $k ) ? strtr( $k, $this->decoded_json_map ) : $k;
				$rebuilt->{$new_k} = $this->walk_decoded_json( $v, $depth + 1 );
			}
			return $rebuilt;
		}

		return $node;
	}

	/**
	 * Byte-exact recursive parser & re-emitter for PHP's serialize() grammar.
	 */
	private function parse_serialized_node( $str, &$pos, $len, $depth, $preserve_exact, &$ok ) {
		if ( ! $ok || $pos >= $len || $depth > self::MAX_DEPTH + 16 ) {
			$ok = false;
			return '';
		}

		$type = $str[ $pos ];

		if ( 'N' === $type ) {
			if ( $pos + 2 <= $len && ';' === $str[ $pos + 1 ] ) {
				$pos += 2;
				return 'N;';
			}
			$ok = false;
			return '';
		}

		if ( 'b' === $type || 'i' === $type || 'd' === $type || 'r' === $type || 'R' === $type ) {
			if ( $pos + 3 > $len || ':' !== $str[ $pos + 1 ] ) {
				$ok = false;
				return '';
			}
			$semi = strpos( $str, ';', $pos + 2 );
			if ( false === $semi ) {
				$ok = false;
				return '';
			}
			$token = substr( $str, $pos, $semi - $pos + 1 );
			$pos   = $semi + 1;
			return $token;
		}

		if ( 's' === $type || 'E' === $type ) {
			if ( $pos + 6 > $len || ':' !== $str[ $pos + 1 ] ) {
				$ok = false;
				return '';
			}
			$colon = strpos( $str, ':', $pos + 2 );
			if ( false === $colon ) {
				$ok = false;
				return '';
			}
			$len_str = substr( $str, $pos + 2, $colon - ( $pos + 2 ) );
			if ( '' === $len_str || ! ctype_digit( $len_str ) ) {
				$ok = false;
				return '';
			}
			$byte_len   = (int) $len_str;
			$quote_open = $colon + 1;
			$quote_end  = $quote_open + 1 + $byte_len;

			if ( $quote_end + 2 > $len || '"' !== $str[ $quote_open ] || '";' !== substr( $str, $quote_end, 2 ) ) {
				$ok = false;
				return '';
			}

			$raw_bytes = substr( $str, $quote_open + 1, $byte_len );
			$pos       = $quote_end + 2;

			if ( 'E' === $type || $preserve_exact ) {
				return $type . ':' . $byte_len . ':"' . $raw_bytes . '";';
			}

			$new_bytes = $this->transform_value( $raw_bytes, $depth );
			return 's:' . strlen( $new_bytes ) . ':"' . $new_bytes . '";';
		}

		if ( 'a' === $type ) {
			if ( $pos + 5 > $len || ':' !== $str[ $pos + 1 ] ) {
				$ok = false;
				return '';
			}
			$colon = strpos( $str, ':', $pos + 2 );
			if ( false === $colon ) {
				$ok = false;
				return '';
			}
			$count_str = substr( $str, $pos + 2, $colon - ( $pos + 2 ) );
			if ( '' === $count_str || ! ctype_digit( $count_str ) ) {
				$ok = false;
				return '';
			}
			$count = (int) $count_str;
			$brace = $colon + 1;
			if ( $brace >= $len || '{' !== $str[ $brace ] ) {
				$ok = false;
				return '';
			}
			$pos = $brace + 1;

			$body = '';
			for ( $i = 0; $i < $count; $i++ ) {
				$k = $this->parse_serialized_node( $str, $pos, $len, $depth + 1, false, $ok );
				if ( ! $ok ) {
					return '';
				}
				$v = $this->parse_serialized_node( $str, $pos, $len, $depth + 1, false, $ok );
				if ( ! $ok ) {
					return '';
				}
				$body .= $k . $v;
			}

			if ( $pos >= $len || '}' !== $str[ $pos ] ) {
				$ok = false;
				return '';
			}
			$pos += 1;
			return 'a:' . $count . ':{' . $body . '}';
		}

		if ( 'O' === $type ) {
			if ( $pos + 8 > $len || ':' !== $str[ $pos + 1 ] ) {
				$ok = false;
				return '';
			}
			$colon1 = strpos( $str, ':', $pos + 2 );
			if ( false === $colon1 ) {
				$ok = false;
				return '';
			}
			$clen_str = substr( $str, $pos + 2, $colon1 - ( $pos + 2 ) );
			if ( '' === $clen_str || ! ctype_digit( $clen_str ) ) {
				$ok = false;
				return '';
			}
			$class_len  = (int) $clen_str;
			$cquote_op  = $colon1 + 1;
			$cquote_end = $cquote_op + 1 + $class_len;

			if ( $cquote_end + 4 > $len || '"' !== $str[ $cquote_op ] || '":' !== substr( $str, $cquote_end, 2 ) ) {
				$ok = false;
				return '';
			}
			$class_name = substr( $str, $cquote_op + 1, $class_len );

			$pcount_start = $cquote_end + 2;
			$colon2       = strpos( $str, ':', $pcount_start );
			if ( false === $colon2 ) {
				$ok = false;
				return '';
			}
			$pcount_str = substr( $str, $pcount_start, $colon2 - $pcount_start );
			if ( '' === $pcount_str || ! ctype_digit( $pcount_str ) ) {
				$ok = false;
				return '';
			}
			$prop_count = (int) $pcount_str;
			$brace      = $colon2 + 1;
			if ( $brace >= $len || '{' !== $str[ $brace ] ) {
				$ok = false;
				return '';
			}
			$pos = $brace + 1;

			$body = '';
			for ( $i = 0; $i < $prop_count; $i++ ) {
				$pk = $this->parse_serialized_node( $str, $pos, $len, $depth + 1, true, $ok );
				if ( ! $ok ) {
					return '';
				}
				$pv = $this->parse_serialized_node( $str, $pos, $len, $depth + 1, false, $ok );
				if ( ! $ok ) {
					return '';
				}
				$body .= $pk . $pv;
			}

			if ( $pos >= $len || '}' !== $str[ $pos ] ) {
				$ok = false;
				return '';
			}
			$pos += 1;
			return 'O:' . $class_len . ':"' . $class_name . '":' . $prop_count . ':{' . $body . '}';
		}

		if ( 'C' === $type ) {
			if ( $pos + 8 > $len || ':' !== $str[ $pos + 1 ] ) {
				$ok = false;
				return '';
			}
			$colon1 = strpos( $str, ':', $pos + 2 );
			if ( false === $colon1 ) {
				$ok = false;
				return '';
			}
			$clen_str = substr( $str, $pos + 2, $colon1 - ( $pos + 2 ) );
			if ( '' === $clen_str || ! ctype_digit( $clen_str ) ) {
				$ok = false;
				return '';
			}
			$class_len  = (int) $clen_str;
			$cquote_op  = $colon1 + 1;
			$cquote_end = $cquote_op + 1 + $class_len;

			if ( $cquote_end + 4 > $len || '"' !== $str[ $cquote_op ] || '":' !== substr( $str, $cquote_end, 2 ) ) {
				$ok = false;
				return '';
			}
			$class_name = substr( $str, $cquote_op + 1, $class_len );

			$plen_start = $cquote_end + 2;
			$colon2     = strpos( $str, ':', $plen_start );
			if ( false === $colon2 ) {
				$ok = false;
				return '';
			}
			$plen_str = substr( $str, $plen_start, $colon2 - $plen_start );
			if ( '' === $plen_str || ! ctype_digit( $plen_str ) ) {
				$ok = false;
				return '';
			}
			$payload_len = (int) $plen_str;
			$brace_open  = $colon2 + 1;
			$brace_close = $brace_open + 1 + $payload_len;

			if ( $brace_close >= $len || '{' !== $str[ $brace_open ] || '}' !== $str[ $brace_close ] ) {
				$ok = false;
				return '';
			}

			$payload     = substr( $str, $brace_open + 1, $payload_len );
			$pos         = $brace_close + 1;
			$new_payload = $this->transform_value( $payload, $depth + 1 );

			return 'C:' . $class_len . ':"' . $class_name . '":' . strlen( $new_payload ) . ':{' . $new_payload . '}';
		}

		$ok = false;
		return '';
	}

	/**
	 * Fast check if a string resembles a PHP serialized value.
	 */
	private static function looks_serialized( $str ) {
		$len = strlen( $str );
		if ( $len < 2 ) {
			return false;
		}
		if ( 'N;' === $str ) {
			return true;
		}
		if ( $len < 4 || ':' !== $str[1] ) {
			return false;
		}
		$first = $str[0];
		$last  = $str[ $len - 1 ];

		if ( ( 'a' === $first || 'O' === $first || 'C' === $first ) && '}' === $last ) {
			return true;
		}
		if ( ( 's' === $first || 'E' === $first || 'i' === $first || 'd' === $first || 'b' === $first ) && ';' === $last ) {
			return true;
		}
		return false;
	}

	/**
	 * Fast check if a string looks like a JSON object or array container.
	 */
	private static function looks_like_json_container( $str ) {
		$len = strlen( $str );
		if ( $len < 2 ) {
			return false;
		}
		$first = $str[0];
		$last  = $str[ $len - 1 ];
		return ( '{' === $first && '}' === $last ) || ( '[' === $first && ']' === $last );
	}
}
