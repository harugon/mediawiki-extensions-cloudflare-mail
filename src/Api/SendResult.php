<?php

namespace MediaWiki\Extension\CloudflareMail\Api;

/**
 * The `result` object returned by the send endpoint.
 */
final class SendResult {

	/**
	 * @param string|null $messageId
	 * @param string[] $delivered Delivered immediately
	 * @param string[] $queued Accepted, delivery will be retried later
	 * @param string[] $permanentBounces Rejected by the receiving server
	 * @param string[] $suppressedRecipients Dropped because they are on the suppression list
	 */
	public function __construct(
		public readonly ?string $messageId,
		public readonly array $delivered = [],
		public readonly array $queued = [],
		public readonly array $permanentBounces = [],
		public readonly array $suppressedRecipients = []
	) {
	}

	/**
	 * @param array<string,mixed> $result
	 */
	public static function fromApiResult( array $result ): self {
		$list = static fn ( string $key ): array => array_values( array_filter(
			(array)( $result[$key] ?? [] ),
			'is_string'
		) );

		return new self(
			isset( $result['message_id'] ) ? (string)$result['message_id'] : null,
			$list( 'delivered' ),
			$list( 'queued' ),
			$list( 'permanent_bounces' ),
			$list( 'suppressed_recipients' )
		);
	}
}
