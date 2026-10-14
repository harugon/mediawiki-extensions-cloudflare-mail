<?php

namespace MediaWiki\Extension\CloudflareMail\Api;

use RuntimeException;
use Throwable;

/**
 * Thrown when the Cloudflare API cannot be reached or rejects a request.
 */
final class CloudflareApiException extends RuntimeException {

	/**
	 * @param string $message
	 * @param int|null $httpStatus HTTP status, or null if no response was received
	 * @param array<int,array{code:int,message:string}> $errors Entries of the API's `errors` array
	 * @param Throwable|null $previous
	 */
	public function __construct(
		string $message,
		public readonly ?int $httpStatus = null,
		public readonly array $errors = [],
		?Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	/**
	 * @param int $httpStatus
	 * @param array<int,array{code:int,message:string}> $errors
	 */
	public static function fromResponse( int $httpStatus, array $errors ): self {
		$details = array_map(
			static fn ( array $e ) => '[' . $e['code'] . '] ' . $e['message'],
			$errors
		);
		$message = "Cloudflare API request failed (HTTP $httpStatus)";
		if ( $details ) {
			$message .= ': ' . implode( '; ', $details );
		}
		return new self( $message, $httpStatus, $errors );
	}

	public function isRetryable(): bool {
		return $this->httpStatus === null || $this->httpStatus === 429 || $this->httpStatus >= 500;
	}
}
