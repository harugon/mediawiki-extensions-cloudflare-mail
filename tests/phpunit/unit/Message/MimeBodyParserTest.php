<?php

namespace MediaWiki\Extension\CloudflareMail\Tests\Unit\Message;

use Mail_mime;
use MediaWiki\Extension\CloudflareMail\Message\MimeBodyParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\CloudflareMail\Message\MimeBodyParser
 * @covers \MediaWiki\Extension\CloudflareMail\Message\MimeHeader
 * @covers \MediaWiki\Extension\CloudflareMail\Message\MessageBody
 */
class MimeBodyParserTest extends TestCase {

	public function testPlainTextAsSentByUserMailer() {
		$headers = [
			'MIME-Version' => '1.0',
			'Content-type' => 'text/plain; charset=UTF-8',
			'Content-transfer-encoding' => '8bit',
		];
		$body = ( new MimeBodyParser() )->parse( $headers, "こんにちは\r\nline 2" );

		$this->assertSame( "こんにちは\nline 2", $body->text );
		$this->assertNull( $body->html );
	}

	public function testMissingContentTypeDefaultsToText() {
		$body = ( new MimeBodyParser() )->parse( [], 'Hello there' );
		$this->assertSame( 'Hello there', $body->text );
	}

	public function testMultipartFromMailMime() {
		$text = "Plain body with ümlauts and a long line " . str_repeat( 'x', 120 ) . "\nsecond = line";
		$html = '<p>HTML body with <b>bold</b> and 日本語</p>';

		// Same construction as UserMailer::sendInternal()
		$mime = new Mail_mime( [ 'eol' => "\n", 'text_charset' => 'UTF-8', 'html_charset' => 'UTF-8' ] );
		$mime->setTXTBody( $text );
		$mime->setHTMLBody( $html );
		$raw = $mime->get();
		$headers = $mime->headers( [ 'From' => 'wiki@example.org' ] );

		$body = ( new MimeBodyParser() )->parse( $headers, $raw );

		$this->assertSame( $text, $body->text );
		$this->assertSame( $html, $body->html );
	}

	public function testHandwrittenMultipartWithBase64AndCharset() {
		$headers = [ 'Content-Type' => "multipart/alternative;\r\n boundary=\"b1\"" ];
		$raw = "preamble\r\n" .
			"--b1\r\n" .
			"Content-Type: text/plain; charset=ISO-8859-1\r\n" .
			"Content-Transfer-Encoding: quoted-printable\r\n" .
			"\r\n" .
			"Caf=E9\r\n" .
			"--b1\r\n" .
			"Content-Type: text/html; charset=UTF-8\r\n" .
			"Content-Transfer-Encoding: base64\r\n" .
			"\r\n" .
			chunk_split( base64_encode( '<p>Café</p>' ), 8, "\r\n" ) .
			"--b1--\r\n" .
			"epilogue\r\n";

		$body = ( new MimeBodyParser() )->parse( $headers, $raw );

		$this->assertSame( 'Café', $body->text );
		$this->assertSame( '<p>Café</p>', $body->html );
	}

	public function testNestedMultipartAndAttachmentsAreSkipped() {
		$headers = [ 'Content-Type' => 'multipart/mixed; boundary=outer' ];
		$raw = "--outer\n" .
			"Content-Type: multipart/alternative; boundary=inner\n\n" .
			"--inner\nContent-Type: text/plain\n\ntext part\n" .
			"--inner\nContent-Type: text/html\n\n<i>html part</i>\n" .
			"--inner--\n" .
			"--outer\n" .
			"Content-Type: text/plain\nContent-Disposition: attachment; filename=a.txt\n\nattached\n" .
			"--outer--\n";

		$body = ( new MimeBodyParser() )->parse( $headers, $raw );

		$this->assertSame( 'text part', $body->text );
		$this->assertSame( '<i>html part</i>', $body->html );
	}

	public function testMultipartWithoutBoundaryYieldsEmptyBody() {
		$body = ( new MimeBodyParser() )->parse( [ 'Content-Type' => 'multipart/alternative' ], 'x' );
		$this->assertTrue( $body->isEmpty() );
	}
}
