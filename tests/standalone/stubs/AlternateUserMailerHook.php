<?php

namespace MediaWiki\Hook;

interface AlternateUserMailerHook {
	public function onAlternateUserMailer( $headers, $to, $from, $subject, $body );
}
