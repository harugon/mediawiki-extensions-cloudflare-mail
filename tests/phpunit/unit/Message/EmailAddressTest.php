<?php

namespace MediaWiki\Extension\CloudflareMail\Tests\Unit\Message;

use InvalidArgumentException;
use MediaWiki\Extension\CloudflareMail\Message\EmailAddress;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\CloudflareMail\Message\EmailAddress
 * @covers \MediaWiki\Extension\CloudflareMail\Message\MimeHeader
 */
class EmailAddressTest extends TestCase {

	public static function provideHeaderValues() {
		yield 'plain' => [ 'jane@example.com', 'jane@example.com', null ];
		yield 'quoted name' => [ '"Jane \"JD\" Doe" <jane@example.com>', 'jane@example.com', 'Jane "JD" Doe' ];
		yield 'bare name' => [ 'Jane <jane@example.com>', 'jane@example.com', 'Jane' ];
		yield 'encoded-word' => [ '=?UTF-8?Q?=E5=B1=B1=E7=94=B0?= <taro@example.jp>', 'taro@example.jp', '山田' ];
		yield 'no name' => [ '<jane@example.com>', 'jane@example.com', null ];
	}

	/**
	 * @dataProvider provideHeaderValues
	 */
	public function testFromHeaderValue( string $value, string $address, ?string $name ) {
		$parsed = EmailAddress::fromHeaderValue( $value );
		$this->assertSame( $address, $parsed->address );
		$this->assertSame( $name, $parsed->name );
	}

	public function testToApiValue() {
		$this->assertSame( 'a@example.com', ( new EmailAddress( 'a@example.com' ) )->toApiValue() );
		$this->assertSame( 'a@example.com', ( new EmailAddress( 'a@example.com', '' ) )->toApiValue() );
		$this->assertSame(
			[ 'address' => 'a@example.com', 'name' => 'A B' ],
			( new EmailAddress( 'a@example.com', "A\r\nB" ) )->toApiValue()
		);
	}

	public function testRejectsInvalidAddress() {
		$this->expectException( InvalidArgumentException::class );
		new EmailAddress( "a@example.com\r\nBcc: x@example.com" );
	}
}
