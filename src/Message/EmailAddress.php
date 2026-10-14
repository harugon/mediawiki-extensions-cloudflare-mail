<?php

namespace MediaWiki\Extension\CloudflareMail\Message;

use InvalidArgumentException;

/**
 * An email address with an optional display name.
 */
final class EmailAddress {

	public readonly string $address;
	public readonly ?string $name;

	public function __construct( string $address, ?string $name = null ) {
		$address = trim( $address );
		if ( $address === '' ) {
			throw new InvalidArgumentException( 'Email address must not be empty' );
		}
		if ( preg_match( '/[\r\n<>\s]/', $address ) ) {
			throw new InvalidArgumentException( "Invalid email address: $address" );
		}
		if ( $name !== null ) {
			// Display names are sent as JSON, so line breaks are never meaningful.
			$name = trim( preg_replace( '/[\r\n]+/', ' ', $name ) );
		}

		$this->address = $address;
		$this->name = $name === '' ? null : $name;
	}

	/**
	 * Parse a header value such as `"Jane Doe" <jane@example.com>`,
	 * `=?UTF-8?Q?...?= <jane@example.com>` or `jane@example.com`.
	 */
	public static function fromHeaderValue( string $value ): self {
		$value = trim( MimeHeader::unfold( $value ) );
		if ( preg_match( '/^(.*?)\s*<([^<>]+)>$/s', $value, $m ) ) {
			$name = trim( $m[1] );
			if ( strlen( $name ) >= 2 && $name[0] === '"' && str_ends_with( $name, '"' ) ) {
				$name = stripslashes( substr( $name, 1, -1 ) );
			}
			return new self( $m[2], MimeHeader::decode( $name ) );
		}
		return new self( $value );
	}

	/**
	 * @return string|array{address:string,name:string} Representation used by the REST API
	 */
	public function toApiValue(): string|array {
		if ( $this->name === null ) {
			return $this->address;
		}
		return [ 'address' => $this->address, 'name' => $this->name ];
	}
}
