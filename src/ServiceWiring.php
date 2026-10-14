<?php

use MediaWiki\Extension\CloudflareMail\Api\CloudflareEmailClient;
use MediaWiki\Extension\CloudflareMail\CloudflareMailer;
use MediaWiki\Extension\CloudflareMail\Message\HeaderFilter;
use MediaWiki\Extension\CloudflareMail\Message\MimeBodyParser;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'CloudflareMail.Client' => static function ( MediaWikiServices $services ): CloudflareEmailClient {
		$config = $services->getMainConfig();
		$timeout = (float)$config->get( 'CloudflareMailTimeout' );

		return new CloudflareEmailClient(
			$services->getHttpRequestFactory()->createGuzzleClient( [
				'timeout' => $timeout,
				'connect_timeout' => min( $timeout, 5.0 ),
			] ),
			(string)$config->get( 'CloudflareMailAccountId' ),
			(string)$config->get( 'CloudflareMailApiToken' ),
			(string)$config->get( 'CloudflareMailApiBaseUrl' ),
			(int)$config->get( 'CloudflareMailMaxRetries' ),
			LoggerFactory::getInstance( 'CloudflareMail' )
		);
	},

	'CloudflareMail.Mailer' => static function ( MediaWikiServices $services ): CloudflareMailer {
		return new CloudflareMailer(
			$services->getService( 'CloudflareMail.Client' ),
			new MimeBodyParser(),
			new HeaderFilter(),
			LoggerFactory::getInstance( 'CloudflareMail' ),
			(bool)$services->getMainConfig()->get( MainConfigNames::EnotifUseRealName )
		);
	},
];
