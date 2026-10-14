<?php

namespace MediaWiki\Extension\CloudflareMail\Message;

/**
 * Decoded plain-text and/or HTML body of a message.
 */
final class MessageBody {

	public function __construct(
		public readonly ?string $text,
		public readonly ?string $html = null
	) {
	}

	public function isEmpty(): bool {
		return ( $this->text === null || $this->text === '' )
			&& ( $this->html === null || $this->html === '' );
	}
}
