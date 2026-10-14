<?php

namespace MediaWiki\Extension\CloudflareMail\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CloudflareMail\CloudflareMailer;
use MediaWiki\Hook\AlternateUserMailerHook;
use MediaWiki\Hook\UserMailerTransformMessageHook;

/**
 * Replaces MediaWiki's default mail transport with Cloudflare Email Service.
 *
 * UserMailer::sendInternal() runs UserMailerTransformMessage and then
 * AlternateUserMailer for every message. AlternateUserMailer would be the
 * natural place to send, but HookContainer only lets handlers return a
 * boolean, so a failed delivery could not be reported to the caller from
 * there. The message is therefore sent from UserMailerTransformMessage,
 * which can abort with an error that ends up in the returned Status, and
 * AlternateUserMailer then tells core to skip its own transport.
 *
 * Other extensions that transform outgoing mail must be loaded before
 * this one, since handlers run in registration order.
 */
class MailHookHandler implements UserMailerTransformMessageHook, AlternateUserMailerHook {

	/** Whether the message currently going through UserMailer was sent by us. */
	private bool $handled = false;

	public function __construct(
		private readonly CloudflareMailer $mailer,
		private readonly Config $config
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onUserMailerTransformMessage( $to, $from, &$subject, &$headers, &$body, &$error ) {
		$this->handled = false;
		if ( !$this->mailer->isConfigured() ) {
			// Not set up yet: leave the mail to $wgSMTP / mail().
			return true;
		}

		$report = $this->mailer->send( $headers, $to, $from, (string)$subject, (string)$body );
		if ( $report->isOk() ) {
			$this->handled = true;
			return true;
		}

		// Only fall back when nothing went out, otherwise the default mailer
		// would deliver duplicates to the recipients Cloudflare accepted.
		if ( $report->accepted === 0 && $this->config->get( 'CloudflareMailFallbackOnError' ) ) {
			return true;
		}

		$error = 'Cloudflare Email Service: ' . implode( '; ', $report->errors );
		return false;
	}

	/**
	 * @inheritDoc
	 * @return bool false if the message was already sent through Cloudflare
	 */
	public function onAlternateUserMailer( $headers, $to, $from, $subject, $body ) {
		$handled = $this->handled;
		$this->handled = false;
		return !$handled;
	}
}
