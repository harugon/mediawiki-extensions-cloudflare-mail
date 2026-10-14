<?php

namespace MediaWiki\Extension\CloudflareMail\Api;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use MediaWiki\Extension\CloudflareMail\Message\OutgoingEmail;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Minimal client for the Cloudflare Email Service REST API.
 *
 * Cloudflare does not ship an official PHP SDK, so this talks to
 * `POST /accounts/{account_id}/email/sending/send` directly.
 *
 * @see https://developers.cloudflare.com/email-service/api/send-emails/rest-api/
 */
class CloudflareEmailClient {

	/** Upper bound for a server-requested Retry-After delay, in seconds. */
	private const MAX_RETRY_DELAY = 10.0;

	private readonly Closure $sleep;

	/**
	 * @param ClientInterface $http
	 * @param string $accountId
	 * @param string $apiToken
	 * @param string $baseUrl
	 * @param int $maxRetries Retries after a network error, HTTP 429 or HTTP 5xx
	 * @param LoggerInterface $logger
	 * @param Closure|null $sleep fn( float $seconds ): void, injectable for tests
	 */
	public function __construct(
		private readonly ClientInterface $http,
		private readonly string $accountId,
		#[\SensitiveParameter]
		private readonly string $apiToken,
		private readonly string $baseUrl = 'https://api.cloudflare.com/client/v4',
		private readonly int $maxRetries = 2,
		private readonly LoggerInterface $logger = new NullLogger(),
		?Closure $sleep = null
	) {
		$this->sleep = $sleep ?? static function ( float $seconds ): void {
			usleep( (int)( $seconds * 1_000_000 ) );
		};
	}

	public function isConfigured(): bool {
		return $this->accountId !== '' && $this->apiToken !== '';
	}

	/**
	 * @throws CloudflareApiException
	 */
	public function send( OutgoingEmail $email ): SendResult {
		$url = rtrim( $this->baseUrl, '/' ) . '/accounts/' . rawurlencode( $this->accountId ) .
			'/email/sending/send';
		$payload = $email->toApiPayload();

		for ( $attempt = 0; ; $attempt++ ) {
			$retryAfter = null;
			try {
				$response = $this->http->request( 'POST', $url, [
					'headers' => [
						'Authorization' => 'Bearer ' . $this->apiToken,
						'Accept' => 'application/json',
					],
					'json' => $payload,
					'http_errors' => false,
				] );
				$result = $this->handleResponse( $response );
				if ( $result instanceof SendResult ) {
					return $result;
				}
				$error = $result;
				$retryAfter = $this->parseRetryAfter( $response );
			} catch ( GuzzleException $e ) {
				$error = new CloudflareApiException(
					'Could not connect to the Cloudflare API: ' . $e->getMessage(),
					null,
					[],
					$e
				);
			}

			if ( !$error->isRetryable() || $attempt >= $this->maxRetries ) {
				throw $error;
			}

			$delay = $retryAfter ?? 0.5 * 2 ** $attempt;
			$this->logger->warning( 'Retrying Cloudflare email request in {delay}s: {error}', [
				'delay' => $delay,
				'error' => $error->getMessage(),
				'attempt' => $attempt + 1,
			] );
			( $this->sleep )( $delay );
		}
	}

	private function handleResponse( ResponseInterface $response ): SendResult|CloudflareApiException {
		$status = $response->getStatusCode();
		$data = json_decode( (string)$response->getBody(), true );

		if ( !is_array( $data ) ) {
			return CloudflareApiException::fromResponse( $status, [
				[ 'code' => 0, 'message' => 'Invalid JSON response' ],
			] );
		}

		if ( $status >= 200 && $status < 300 && ( $data['success'] ?? false ) === true ) {
			return SendResult::fromApiResult( is_array( $data['result'] ?? null ) ? $data['result'] : [] );
		}

		$errors = [];
		foreach ( (array)( $data['errors'] ?? [] ) as $error ) {
			if ( is_array( $error ) ) {
				$errors[] = [
					'code' => (int)( $error['code'] ?? 0 ),
					'message' => (string)( $error['message'] ?? '' ),
				];
			}
		}
		return CloudflareApiException::fromResponse( $status, $errors );
	}

	private function parseRetryAfter( ResponseInterface $response ): ?float {
		$header = $response->getHeaderLine( 'Retry-After' );
		if ( $header === '' || !is_numeric( $header ) ) {
			return null;
		}
		return min( max( (float)$header, 0.0 ), self::MAX_RETRY_DELAY );
	}
}
