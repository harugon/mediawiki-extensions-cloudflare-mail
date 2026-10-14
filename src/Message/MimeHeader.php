<?php

namespace MediaWiki\Extension\CloudflareMail\Message;

/**
 * Helpers for working with RFC 5322 / RFC 2047 header values.
 */
final class MimeHeader {

	/**
	 * Look up a header case-insensitively.
	 *
	 * @param array<string,string> $headers
	 * @param string $name
	 * @return string|null
	 */
	public static function get( array $headers, string $name ): ?string {
		foreach ( $headers as $key => $value ) {
			if ( strcasecmp( (string)$key, $name ) === 0 ) {
				return (string)$value;
			}
		}
		return null;
	}

	/**
	 * Undo header folding (RFC 5322 section 2.2.3).
	 */
	public static function unfold( string $value ): string {
		return preg_replace( '/\r?\n[ \t]+/', ' ', $value );
	}

	/**
	 * Decode RFC 2047 encoded-words (`=?UTF-8?Q?...?=`) into a UTF-8 string.
	 */
	public static function decode( string $value ): string {
		$value = self::unfold( $value );
		if ( !str_contains( $value, '=?' ) ) {
			return $value;
		}
		$decoded = iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' );
		return $decoded === false ? $value : $decoded;
	}

	/**
	 * Split a header value into its main value and its parameters,
	 * e.g. `multipart/alternative; boundary="abc"`.
	 *
	 * @param string $value
	 * @return array{0:string,1:array<string,string>} Lower-cased value and parameters
	 */
	public static function parseParameterized( string $value ): array {
		$value = self::unfold( $value );
		$parts = explode( ';', $value, 2 );
		$main = strtolower( trim( $parts[0] ) );
		$params = [];
		if ( isset( $parts[1] ) ) {
			preg_match_all(
				'/([!#$%&\'*+\-.^_`|~0-9A-Za-z]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;\s]*)/',
				$parts[1],
				$matches,
				PREG_SET_ORDER
			);
			foreach ( $matches as [ , $key, $val ] ) {
				if ( str_starts_with( $val, '"' ) ) {
					$val = stripslashes( substr( $val, 1, -1 ) );
				}
				$params[strtolower( $key )] = $val;
			}
		}
		return [ $main, $params ];
	}
}
