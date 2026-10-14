<?php
/**
 * Send a test email through MediaWiki's regular mail path, which this
 * extension routes to Cloudflare Email Service.
 *
 * Usage:
 *   php maintenance/run.php CloudflareMail:sendTestEmail --to=you@example.com
 *   php maintenance/run.php CloudflareMail:sendTestEmail --to=you@example.com --html
 */

namespace MediaWiki\Extension\CloudflareMail\Maintenance;

use MailAddress;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use UserMailer;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class SendTestEmail extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Send a test email via Cloudflare Email Service' );
		$this->addOption( 'to', 'Recipient address', true, true );
		$this->addOption( 'from', 'Sender address (default: $wgPasswordSender)', false, true );
		$this->addOption( 'subject', 'Subject line', false, true );
		$this->addOption( 'html', 'Send a multipart message with an HTML part' );
		$this->requireExtension( 'CloudflareMail' );
	}

	public function execute() {
		$config = $this->getConfig();
		if ( $config->get( 'CloudflareMailAccountId' ) === '' || $config->get( 'CloudflareMailApiToken' ) === '' ) {
			$this->fatalError(
				'$wgCloudflareMailAccountId and $wgCloudflareMailApiToken must be set.'
			);
		}

		$sitename = $config->get( MainConfigNames::Sitename );
		$from = new MailAddress(
			$this->getOption( 'from', $config->get( MainConfigNames::PasswordSender ) ),
			$sitename
		);
		$to = new MailAddress( $this->getOption( 'to' ) );
		$subject = $this->getOption( 'subject', "[$sitename] CloudflareMail test" );

		$text = "This is a test message from $sitename sent through Cloudflare Email Service.";
		$body = $this->hasOption( 'html' )
			? [ 'text' => $text, 'html' => '<p>' . htmlspecialchars( $text ) . '</p>' ]
			: $text;

		$status = UserMailer::send( $to, $from, $subject, $body );
		if ( !$status->isOK() ) {
			$this->fatalError( 'Sending failed: ' . $status->getWikiText( false, false, 'en' ) );
		}
		$this->output( "Sent test email to {$to->address}.\n" );
	}
}

// @codeCoverageIgnoreStart
$maintClass = SendTestEmail::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
