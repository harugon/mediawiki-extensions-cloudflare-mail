<?php
/**
 * Bootstrap for running the unit tests without a MediaWiki installation
 * (`composer phpunit`). Provides minimal stand-ins for the few core classes
 * the extension touches. Inside MediaWiki the real classes are used.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

if ( !class_exists( 'MailAddress' ) ) {
	require_once __DIR__ . '/stubs/MailAddress.php';
}
if ( !interface_exists( 'MediaWiki\Hook\AlternateUserMailerHook' ) ) {
	require_once __DIR__ . '/stubs/AlternateUserMailerHook.php';
}
if ( !interface_exists( 'MediaWiki\Config\Config' ) ) {
	require_once __DIR__ . '/stubs/Config.php';
}
if ( !interface_exists( 'MediaWiki\Hook\UserMailerTransformMessageHook' ) ) {
	require_once __DIR__ . '/stubs/UserMailerTransformMessageHook.php';
}
