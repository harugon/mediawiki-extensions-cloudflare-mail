<?php

namespace MediaWiki\Extension\CloudflareMail\Tests\Unit\Message;

use MediaWiki\Extension\CloudflareMail\Message\HeaderFilter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\CloudflareMail\Message\HeaderFilter
 */
class HeaderFilterTest extends TestCase {

	public function testDropsPlatformControlledAndAddressHeaders() {
		$filtered = ( new HeaderFilter() )->filter( [
			'From' => 'Wiki <wiki@example.org>',
			'Return-Path' => 'wiki@example.org',
			'Reply-To' => 'user@example.org',
			'Date' => 'Mon, 1 Jan 2026 00:00:00 +0000',
			'Message-ID' => '<abc@example.org>',
			'X-Mailer' => 'MediaWiki mailer',
			'List-Unsubscribe' => '<https://wiki.example.org/wiki/Special:Preferences>',
			'MIME-Version' => '1.0',
			'Content-Type' => 'text/plain; charset=UTF-8',
			'Content-Transfer-Encoding' => '8bit',
			'Subject' => 'Hi',
		] );

		$this->assertSame( [
			'X-Mailer' => 'MediaWiki mailer',
			'List-Unsubscribe' => '<https://wiki.example.org/wiki/Special:Preferences>',
		], $filtered );
	}

	public function testCanonicalisesNamesAndUnfoldsValues() {
		$filtered = ( new HeaderFilter() )->filter( [
			'in-reply-to' => "<a@example.org>",
			'references' => "<a@example.org>\r\n <b@example.org>",
		] );

		$this->assertSame( [
			'In-Reply-To' => '<a@example.org>',
			'References' => '<a@example.org> <b@example.org>',
		], $filtered );
	}

	public function testRejectsInvalidNamesAndOversizedValues() {
		$filtered = ( new HeaderFilter() )->filter( [
			'X-Bad Name' => 'v',
			'X-' . str_repeat( 'a', 100 ) => 'v',
			'X-Too-Long' => str_repeat( 'v', 2049 ),
			'X-Empty' => '',
			'X-Injected' => "a\nb",
			'X-Ok' => 'fine',
		] );

		$this->assertSame( [ 'X-Ok' => 'fine' ], $filtered );
	}

	public function testEnforcesTotalSize() {
		$headers = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$headers["X-H$i"] = str_repeat( 'v', 2000 );
		}
		$filtered = ( new HeaderFilter() )->filter( $headers );

		$this->assertCount( 8, $filtered );
	}
}
