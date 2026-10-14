<?php

namespace MediaWiki\Extension\CloudflareMail\Message;

/**
 * Selects the headers that may be forwarded to Cloudflare Email Service.
 *
 * Cloudflare rejects the whole request if it contains a header that is not
 * on its allowlist, while MediaWiki always adds headers such as Date,
 * Message-ID and Return-Path. Only allowlisted headers and X-* headers
 * within the documented limits are kept.
 *
 * @see https://developers.cloudflare.com/email-service/reference/headers/
 */
final class HeaderFilter {

	/** Allowlisted non-X headers, keyed by lower-case name. */
	private const ALLOWED = [
		// Threading and reply
		'in-reply-to' => 'In-Reply-To',
		'references' => 'References',
		'thread-index' => 'Thread-Index',
		'thread-topic' => 'Thread-Topic',
		// List management
		'list-unsubscribe' => 'List-Unsubscribe',
		'list-unsubscribe-post' => 'List-Unsubscribe-Post',
		'list-id' => 'List-Id',
		'list-archive' => 'List-Archive',
		'list-help' => 'List-Help',
		'list-owner' => 'List-Owner',
		'list-post' => 'List-Post',
		'list-subscribe' => 'List-Subscribe',
		'precedence' => 'Precedence',
		// Automated message identification
		'auto-submitted' => 'Auto-Submitted',
		// Content and display
		'content-language' => 'Content-Language',
		'keywords' => 'Keywords',
		'comments' => 'Comments',
		'importance' => 'Importance',
		'priority' => 'Priority',
		'sensitivity' => 'Sensitivity',
		'organization' => 'Organization',
		// Delivery and notification
		'require-recipient-valid-since' => 'Require-Recipient-Valid-Since',
		'expires' => 'Expires',
		'reply-by' => 'Reply-By',
		// Modern standards
		'archived-at' => 'Archived-At',
	];

	private const MAX_ALLOWLISTED = 20;
	private const MAX_NAME_BYTES = 100;
	private const MAX_VALUE_BYTES = 2048;
	private const MAX_TOTAL_BYTES = 16384;

	/**
	 * @param array<string,string> $headers Headers as built by MediaWiki
	 * @return array<string,string> Headers that are safe to send
	 */
	public function filter( array $headers ): array {
		$result = [];
		$allowlistedCount = 0;
		$totalBytes = 0;

		foreach ( $headers as $name => $value ) {
			$name = trim( (string)$name );
			$value = trim( MimeHeader::unfold( (string)$value ) );

			$canonical = self::ALLOWED[strtolower( $name )] ?? null;
			$isCustom = $canonical === null && preg_match( '/^X-[A-Za-z0-9\-_]+$/i', $name );
			if ( ( $canonical === null && !$isCustom ) || isset( $result[$canonical ?? $name] ) ) {
				continue;
			}
			if (
				$value === '' ||
				preg_match( '/[\r\n\0]/', $value ) ||
				strlen( $name ) > self::MAX_NAME_BYTES ||
				strlen( $value ) > self::MAX_VALUE_BYTES ||
				$totalBytes + strlen( $name ) + strlen( $value ) > self::MAX_TOTAL_BYTES
			) {
				continue;
			}
			if ( $canonical !== null ) {
				if ( $allowlistedCount >= self::MAX_ALLOWLISTED ) {
					continue;
				}
				$allowlistedCount++;
			}

			$result[$canonical ?? $name] = $value;
			$totalBytes += strlen( $name ) + strlen( $value );
		}

		return $result;
	}
}
