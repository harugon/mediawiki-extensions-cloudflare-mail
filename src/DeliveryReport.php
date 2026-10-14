<?php

namespace MediaWiki\Extension\CloudflareMail;

/**
 * Outcome of handing one MediaWiki mail to Cloudflare.
 */
final class DeliveryReport {

	/**
	 * @param int $accepted Number of recipients Cloudflare accepted
	 * @param string[] $errors
	 */
	public function __construct(
		public readonly int $accepted,
		public readonly array $errors = []
	) {
	}

	public function isOk(): bool {
		return !$this->errors;
	}
}
