<?php

namespace MediaWiki\Extension\CloudflareMail\Message;

use InvalidArgumentException;

/**
 * A single email ready to be sent through Cloudflare Email Service.
 */
final class OutgoingEmail {

	/** Combined limit for to, cc and bcc enforced by Cloudflare. */
	public const MAX_RECIPIENTS = 50;

	/**
	 * @param EmailAddress $from
	 * @param EmailAddress[] $to
	 * @param string $subject
	 * @param MessageBody $body
	 * @param EmailAddress|null $replyTo
	 * @param array<string,string> $headers Custom headers (must already be filtered)
	 */
	public function __construct(
		public readonly EmailAddress $from,
		public readonly array $to,
		public readonly string $subject,
		public readonly MessageBody $body,
		public readonly ?EmailAddress $replyTo = null,
		public readonly array $headers = []
	) {
		if ( !$to ) {
			throw new InvalidArgumentException( 'At least one recipient is required' );
		}
		if ( count( $to ) > self::MAX_RECIPIENTS ) {
			throw new InvalidArgumentException( 'Too many recipients (max ' . self::MAX_RECIPIENTS . ')' );
		}
		if ( $body->isEmpty() ) {
			throw new InvalidArgumentException( 'Either a text or an HTML body is required' );
		}
	}

	/**
	 * JSON body for `POST /accounts/{account_id}/email/sending/send`.
	 *
	 * @return array<string,mixed>
	 */
	public function toApiPayload(): array {
		$payload = [
			'from' => $this->from->toApiValue(),
			'to' => array_map( static fn ( EmailAddress $a ) => $a->toApiValue(), $this->to ),
			'subject' => $this->subject,
		];
		if ( $this->body->text !== null && $this->body->text !== '' ) {
			$payload['text'] = $this->body->text;
		}
		if ( $this->body->html !== null && $this->body->html !== '' ) {
			$payload['html'] = $this->body->html;
		}
		if ( $this->replyTo ) {
			// The REST API documents reply_to as a plain address only.
			$payload['reply_to'] = $this->replyTo->address;
		}
		if ( $this->headers ) {
			$payload['headers'] = $this->headers;
		}
		return $payload;
	}
}
