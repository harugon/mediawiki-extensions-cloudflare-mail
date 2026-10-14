<?php

namespace MediaWiki\Extension\CloudflareMail;

use InvalidArgumentException;
use MailAddress;
use MediaWiki\Extension\CloudflareMail\Api\CloudflareApiException;
use MediaWiki\Extension\CloudflareMail\Api\CloudflareEmailClient;
use MediaWiki\Extension\CloudflareMail\Message\EmailAddress;
use MediaWiki\Extension\CloudflareMail\Message\HeaderFilter;
use MediaWiki\Extension\CloudflareMail\Message\MimeBodyParser;
use MediaWiki\Extension\CloudflareMail\Message\MimeHeader;
use MediaWiki\Extension\CloudflareMail\Message\OutgoingEmail;
use Psr\Log\LoggerInterface;

/**
 * Sends mail prepared by MediaWiki's UserMailer through Cloudflare Email Service.
 */
class CloudflareMailer {

	/** RFC 5322 line length limit, also enforced by Cloudflare. */
	private const MAX_SUBJECT_LENGTH = 998;

	public function __construct(
		private readonly CloudflareEmailClient $client,
		private readonly MimeBodyParser $bodyParser,
		private readonly HeaderFilter $headerFilter,
		private readonly LoggerInterface $logger,
		private readonly bool $useRealName = false
	) {
	}

	public function isConfigured(): bool {
		return $this->client->isConfigured();
	}

	/**
	 * Send a message in the form passed to the AlternateUserMailer hook.
	 *
	 * Each recipient gets a separate request so that addresses are never
	 * disclosed to other recipients, mirroring what UserMailer does for
	 * multiple recipients ("undisclosed-recipients").
	 *
	 * @param array<string,string> $headers
	 * @param MailAddress[] $to
	 * @param MailAddress $from
	 * @param string $subject
	 * @param string $body MIME-encoded body
	 * @return DeliveryReport
	 */
	public function send(
		array $headers,
		array $to,
		MailAddress $from,
		string $subject,
		string $body
	): DeliveryReport {
		try {
			$messageBody = $this->bodyParser->parse( $headers, $body );
			$sender = $this->toEmailAddress( $from );
			$replyToHeader = MimeHeader::get( $headers, 'Reply-To' );
			$replyTo = $replyToHeader !== null && trim( $replyToHeader ) !== ''
				? EmailAddress::fromHeaderValue( $replyToHeader )
				: null;
		} catch ( InvalidArgumentException $e ) {
			return new DeliveryReport( 0, [ $e->getMessage() ] );
		}

		$subject = mb_substr( MimeHeader::decode( $subject ), 0, self::MAX_SUBJECT_LENGTH );
		$customHeaders = $this->headerFilter->filter( $headers );

		$accepted = 0;
		$errors = [];
		foreach ( $to as $recipient ) {
			if ( $recipient->address === '' ) {
				continue;
			}
			try {
				$email = new OutgoingEmail(
					$sender,
					[ $this->toEmailAddress( $recipient ) ],
					$subject,
					$messageBody,
					$replyTo,
					$customHeaders
				);
				$result = $this->client->send( $email );
			} catch ( InvalidArgumentException | CloudflareApiException $e ) {
				$this->logger->error( 'Sending email to {to} via Cloudflare failed: {error}', [
					'to' => $recipient->address,
					'error' => $e->getMessage(),
					'exception' => $e,
				] );
				$errors[] = $e->getMessage();
				continue;
			}

			if ( $result->permanentBounces ) {
				$this->logger->warning( 'Cloudflare reported a permanent bounce for {to}', [
					'to' => $recipient->address,
					'messageId' => $result->messageId,
				] );
				$errors[] = 'Permanent bounce: ' . implode( ', ', $result->permanentBounces );
				continue;
			}
			if ( $result->suppressedRecipients ) {
				$this->logger->warning( 'Cloudflare dropped suppressed recipient {to}', [
					'to' => $recipient->address,
					'messageId' => $result->messageId,
				] );
				continue;
			}

			$accepted++;
			$this->logger->info( 'Sent email to {to} via Cloudflare ({status}), message ID {messageId}', [
				'to' => $recipient->address,
				'status' => $result->queued ? 'queued' : 'delivered',
				'messageId' => $result->messageId,
				'subject' => $subject,
			] );
		}

		return new DeliveryReport( $accepted, $errors );
	}

	private function toEmailAddress( MailAddress $address ): EmailAddress {
		$name = ( $this->useRealName && $address->realName !== '' ) ? $address->realName : $address->name;
		return new EmailAddress( $address->address, $name );
	}
}
