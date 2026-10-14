<?php

namespace MediaWiki\Extension\CloudflareMail;

use MailAddress;
use MediaWiki\Config\Config;
use MediaWiki\Hook\AlternateUserMailerHook;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use RuntimeException;

/**
 * Sends wiki email through the Cloudflare Email Service REST API.
 *
 * @see https://developers.cloudflare.com/email-service/api/send-emails/rest-api/
 */
class Hooks implements AlternateUserMailerHook {

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly Config $config
	) {
	}

	/**
	 * @inheritDoc
	 * @return bool false: the mail was sent, so core must not send it again
	 * @throws RuntimeException if the mail could not be sent. This never falls
	 *   back to PHP mail() or $wgSMTP, which would reveal the server's IP address.
	 */
	public function onAlternateUserMailer( $headers, $to, $from, $subject, $body ) {
		$accountId = $this->config->get( 'CloudflareMailAccountId' );
		$apiToken = $this->config->get( 'CloudflareMailApiToken' );
		if ( !$accountId || !$apiToken ) {
			$this->fail( 'Set $wgCloudflareMailAccountId and $wgCloudflareMailApiToken' );
		}
		if ( str_starts_with( $headers['Content-Type'] ?? '', 'multipart/' ) ) {
			$this->fail( 'HTML email is not supported, set $wgAllowHTMLEmail = false' );
		}

		$payload = [
			'from' => $this->formatAddress( $from ),
			'subject' => $subject,
			'text' => $body,
		];
		if ( isset( $headers['Reply-To'] ) ) {
			// "Name <user@example.com>" -> "user@example.com"
			preg_match( '/<([^<>]+)>\s*$/', $headers['Reply-To'], $m );
			$payload['reply_to'] = $m[1] ?? $headers['Reply-To'];
		}

		$url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $accountId ) .
			'/email/sending/send';

		// One request per recipient so recipients do not see each other's address.
		foreach ( is_array( $to ) ? $to : [ $to ] as $recipient ) {
			$request = $this->httpRequestFactory->create( $url, [
				'method' => 'POST',
				'postData' => json_encode( [ 'to' => $this->formatAddress( $recipient ) ] + $payload ),
				'timeout' => 10,
			], __METHOD__ );
			$request->setHeader( 'Authorization', "Bearer $apiToken" );
			$request->setHeader( 'Content-Type', 'application/json' );

			$status = $request->execute();
			$response = json_decode( $request->getContent(), true );
			if ( !$status->isOK() || ( $response['success'] ?? false ) !== true ) {
				$errors = array_column( $response['errors'] ?? [], 'message' );
				$this->fail( "Sending to {$recipient->address} failed (HTTP {$request->getStatus()}): " .
					( $errors ? implode( '; ', $errors ) : $request->getContent() ) );
			}
		}

		return false;
	}

	private function formatAddress( MailAddress $address ): string|array {
		return $address->name === ''
			? $address->address
			: [ 'address' => $address->address, 'name' => $address->name ];
	}

	/**
	 * @return never
	 */
	private function fail( string $message ): never {
		LoggerFactory::getInstance( 'CloudflareMail' )->error( $message );
		throw new RuntimeException( "CloudflareMail: $message" );
	}
}
