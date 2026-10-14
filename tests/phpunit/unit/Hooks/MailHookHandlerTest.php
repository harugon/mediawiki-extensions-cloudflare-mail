<?php

namespace MediaWiki\Extension\CloudflareMail\Tests\Unit\Hooks;

use MailAddress;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CloudflareMail\Api\CloudflareApiException;
use MediaWiki\Extension\CloudflareMail\Api\CloudflareEmailClient;
use MediaWiki\Extension\CloudflareMail\Api\SendResult;
use MediaWiki\Extension\CloudflareMail\CloudflareMailer;
use MediaWiki\Extension\CloudflareMail\Hooks\MailHookHandler;
use MediaWiki\Extension\CloudflareMail\Message\HeaderFilter;
use MediaWiki\Extension\CloudflareMail\Message\MimeBodyParser;
use MediaWiki\Extension\CloudflareMail\Message\OutgoingEmail;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \MediaWiki\Extension\CloudflareMail\Hooks\MailHookHandler
 * @covers \MediaWiki\Extension\CloudflareMail\CloudflareMailer
 * @covers \MediaWiki\Extension\CloudflareMail\DeliveryReport
 */
class MailHookHandlerTest extends TestCase {

	private const HEADERS = [
		'From' => '"My Wiki" <wiki@example.org>',
		'Return-Path' => 'wiki@example.org',
		'Reply-To' => '=?UTF-8?Q?=E5=B1=B1=E7=94=B0?= <taro@example.jp>',
		'Date' => 'Wed, 14 Oct 2026 00:00:00 +0000',
		'Message-ID' => '<x@example.org>',
		'X-Mailer' => 'MediaWiki mailer',
		'MIME-Version' => '1.0',
		'Content-type' => 'text/plain; charset=UTF-8',
		'Content-transfer-encoding' => '8bit',
	];

	/** @var OutgoingEmail[] */
	private array $sent = [];

	private function newHandler( array $results, bool $fallback = false, bool $configured = true ): MailHookHandler {
		$client = $this->createMock( CloudflareEmailClient::class );
		$client->method( 'isConfigured' )->willReturn( $configured );
		$client->method( 'send' )->willReturnCallback( function ( OutgoingEmail $email ) use ( &$results ) {
			$this->sent[] = $email;
			$next = array_shift( $results );
			if ( $next instanceof CloudflareApiException ) {
				throw $next;
			}
			return $next;
		} );

		$mailer = new CloudflareMailer(
			$client,
			new MimeBodyParser(),
			new HeaderFilter(),
			new NullLogger(),
			true
		);
		return new MailHookHandler( $mailer, new HashConfig( [ 'CloudflareMailFallbackOnError' => $fallback ] ) );
	}

	/**
	 * Run the two hooks the way UserMailer::sendInternal() does.
	 *
	 * @param MailHookHandler $handler
	 * @param MailAddress[] $to
	 * @return array{0:bool,1:string,2:bool|null} Return value and error of
	 *   UserMailerTransformMessage, return value of AlternateUserMailer (null if not reached)
	 */
	private function runHooks( MailHookHandler $handler, array $to ): array {
		$from = new MailAddress( 'wiki@example.org', 'MyWiki', 'My Wiki' );
		$subject = 'Test subject';
		$headers = self::HEADERS;
		$body = "Body text\nline 2";
		$error = '';

		$transform = $handler->onUserMailerTransformMessage( $to, $from, $subject, $headers, $body, $error );
		if ( $transform === false ) {
			return [ false, $error, null ];
		}
		return [ true, $error, $handler->onAlternateUserMailer( $headers, $to, $from, $subject, $body ) ];
	}

	public function testNotConfiguredLeavesMailToCore() {
		$handler = $this->newHandler( [], false, false );
		$this->assertSame( [ true, '', true ], $this->runHooks( $handler, [ new MailAddress( 'a@example.com' ) ] ) );
		$this->assertSame( [], $this->sent );
	}

	public function testSendsOneRequestPerRecipient() {
		$handler = $this->newHandler( [
			new SendResult( 'm1', [ 'a@example.com' ] ),
			new SendResult( 'm2', [], [ 'b@example.com' ] ),
		] );

		$ret = $this->runHooks( $handler, [
			new MailAddress( 'a@example.com', 'UserA', 'Alice' ),
			new MailAddress( 'b@example.com' ),
		] );

		$this->assertSame( [ true, '', false ], $ret, 'AlternateUserMailer returns false to skip core\'s mailer' );
		$this->assertCount( 2, $this->sent );

		$payload = $this->sent[0]->toApiPayload();
		$this->assertSame( [
			'from' => [ 'address' => 'wiki@example.org', 'name' => 'My Wiki' ],
			'to' => [ [ 'address' => 'a@example.com', 'name' => 'Alice' ] ],
			'subject' => 'Test subject',
			'text' => "Body text\nline 2",
			'reply_to' => 'taro@example.jp',
			'headers' => [ 'X-Mailer' => 'MediaWiki mailer' ],
		], $payload );
		$this->assertSame( [ 'b@example.com' ], $this->sent[1]->toApiPayload()['to'] );
	}

	public function testErrorAbortsWithMessage() {
		$handler = $this->newHandler( [ new CloudflareApiException( 'boom', 500 ) ] );
		$this->assertSame(
			[ false, 'Cloudflare Email Service: boom', null ],
			$this->runHooks( $handler, [ new MailAddress( 'a@example.com' ) ] )
		);
	}

	public function testPermanentBounceIsReported() {
		$handler = $this->newHandler( [ new SendResult( 'm1', [], [], [ 'a@example.com' ] ) ] );
		$this->assertSame(
			[ false, 'Cloudflare Email Service: Permanent bounce: a@example.com', null ],
			$this->runHooks( $handler, [ new MailAddress( 'a@example.com' ) ] )
		);
	}

	public function testFallbackOnlyWhenNothingWasSent() {
		$handler = $this->newHandler( [ new CloudflareApiException( 'boom', 500 ) ], true );
		$this->assertSame(
			[ true, '', true ],
			$this->runHooks( $handler, [ new MailAddress( 'a@example.com' ) ] ),
			'core mailer takes over'
		);

		$handler = $this->newHandler( [
			new SendResult( 'm1', [ 'a@example.com' ] ),
			new CloudflareApiException( 'boom', 500 ),
		], true );
		$this->assertSame(
			[ false, 'Cloudflare Email Service: boom', null ],
			$this->runHooks( $handler, [ new MailAddress( 'a@example.com' ), new MailAddress( 'b@example.com' ) ] )
		);
	}

	public function testHandledFlagDoesNotLeakIntoNextMessage() {
		$handler = $this->newHandler( [ new SendResult( 'm1', [ 'a@example.com' ] ) ] );
		$this->runHooks( $handler, [ new MailAddress( 'a@example.com' ) ] );

		$this->assertTrue(
			$handler->onAlternateUserMailer( [], [], new MailAddress( 'wiki@example.org' ), 's', 'b' ),
			'a message that did not go through our transform handler is left to core'
		);
	}
}
