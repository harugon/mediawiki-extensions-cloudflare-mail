<?php

namespace MediaWiki\Extension\CloudflareMail\Tests\Unit\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MediaWiki\Extension\CloudflareMail\Api\CloudflareApiException;
use MediaWiki\Extension\CloudflareMail\Api\CloudflareEmailClient;
use MediaWiki\Extension\CloudflareMail\Message\EmailAddress;
use MediaWiki\Extension\CloudflareMail\Message\MessageBody;
use MediaWiki\Extension\CloudflareMail\Message\OutgoingEmail;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\CloudflareMail\Api\CloudflareEmailClient
 * @covers \MediaWiki\Extension\CloudflareMail\Api\CloudflareApiException
 * @covers \MediaWiki\Extension\CloudflareMail\Api\SendResult
 * @covers \MediaWiki\Extension\CloudflareMail\Message\OutgoingEmail
 */
class CloudflareEmailClientTest extends TestCase {

	/** @var array[] */
	private array $history = [];

	/** @var float[] */
	private array $sleeps = [];

	private function newClient( array $responses, int $maxRetries = 2 ): CloudflareEmailClient {
		$stack = HandlerStack::create( new MockHandler( $responses ) );
		$stack->push( Middleware::history( $this->history ) );

		return new CloudflareEmailClient(
			new Client( [ 'handler' => $stack ] ),
			'acc123',
			'secret-token',
			'https://api.example.test/client/v4/',
			$maxRetries,
			sleep: function ( float $seconds ) {
				$this->sleeps[] = $seconds;
			}
		);
	}

	private function newEmail(): OutgoingEmail {
		return new OutgoingEmail(
			new EmailAddress( 'wiki@example.org', 'My Wiki' ),
			[ new EmailAddress( 'jane@example.com' ) ],
			'Hello',
			new MessageBody( 'text body', '<p>html body</p>' ),
			new EmailAddress( 'user@example.org', 'Some User' ),
			[ 'X-Mailer' => 'MediaWiki mailer' ]
		);
	}

	private static function json( int $status, array $data, array $headers = [] ): Response {
		return new Response( $status, $headers + [ 'Content-Type' => 'application/json' ], json_encode( $data ) );
	}

	public function testSendsExpectedRequest() {
		$client = $this->newClient( [
			self::json( 200, [
				'success' => true,
				'errors' => [],
				'messages' => [],
				'result' => [
					'delivered' => [ 'jane@example.com' ],
					'permanent_bounces' => [],
					'queued' => [],
					'message_id' => 'msg-1',
				],
			] ),
		] );

		$result = $client->send( $this->newEmail() );

		$this->assertSame( 'msg-1', $result->messageId );
		$this->assertSame( [ 'jane@example.com' ], $result->delivered );

		$this->assertCount( 1, $this->history );
		/** @var Request $request */
		$request = $this->history[0]['request'];
		$this->assertSame( 'POST', $request->getMethod() );
		$this->assertSame(
			'https://api.example.test/client/v4/accounts/acc123/email/sending/send',
			(string)$request->getUri()
		);
		$this->assertSame( 'Bearer secret-token', $request->getHeaderLine( 'Authorization' ) );
		$this->assertSame( 'application/json', $request->getHeaderLine( 'Content-Type' ) );
		$this->assertSame( [
			'from' => [ 'address' => 'wiki@example.org', 'name' => 'My Wiki' ],
			'to' => [ 'jane@example.com' ],
			'subject' => 'Hello',
			'text' => 'text body',
			'html' => '<p>html body</p>',
			'reply_to' => 'user@example.org',
			'headers' => [ 'X-Mailer' => 'MediaWiki mailer' ],
		], json_decode( (string)$request->getBody(), true ) );
	}

	public function testApiErrorIsNotRetried() {
		$client = $this->newClient( [
			self::json( 403, [
				'success' => false,
				'errors' => [ [ 'code' => 10000, 'message' => 'Authentication error' ] ],
			] ),
		] );

		try {
			$client->send( $this->newEmail() );
			$this->fail( 'Expected exception' );
		} catch ( CloudflareApiException $e ) {
			$this->assertSame( 403, $e->httpStatus );
			$this->assertSame(
				'Cloudflare API request failed (HTTP 403): [10000] Authentication error',
				$e->getMessage()
			);
		}
		$this->assertCount( 1, $this->history );
		$this->assertSame( [], $this->sleeps );
	}

	public function testRetriesRateLimitAndServerErrors() {
		$client = $this->newClient( [
			self::json( 429, [ 'success' => false, 'errors' => [] ], [ 'Retry-After' => '3' ] ),
			self::json( 502, [ 'success' => false, 'errors' => [] ] ),
			self::json( 200, [ 'success' => true, 'result' => [ 'queued' => [ 'jane@example.com' ] ] ] ),
		] );

		$result = $client->send( $this->newEmail() );

		$this->assertSame( [ 'jane@example.com' ], $result->queued );
		$this->assertCount( 3, $this->history );
		$this->assertSame( [ 3.0, 1.0 ], $this->sleeps );
	}

	public function testGivesUpAfterMaxRetries() {
		$client = $this->newClient( [
			new ConnectException( 'timeout', new Request( 'POST', 'x' ) ),
			new ConnectException( 'timeout', new Request( 'POST', 'x' ) ),
		], 1 );

		$this->expectException( CloudflareApiException::class );
		$this->expectExceptionMessage( 'Could not connect to the Cloudflare API: timeout' );
		$client->send( $this->newEmail() );
	}

	public function testInvalidJsonResponse() {
		$client = $this->newClient( [ new Response( 200, [], '<html>' ) ] );

		$this->expectException( CloudflareApiException::class );
		$this->expectExceptionMessage( 'Invalid JSON response' );
		$client->send( $this->newEmail() );
	}

	public function testIsConfigured() {
		$this->assertTrue( $this->newClient( [] )->isConfigured() );
		$this->assertFalse( ( new CloudflareEmailClient( new Client(), '', '' ) )->isConfigured() );
	}
}
