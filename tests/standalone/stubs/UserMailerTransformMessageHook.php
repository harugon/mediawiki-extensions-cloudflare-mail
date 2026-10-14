<?php

namespace MediaWiki\Hook;

interface UserMailerTransformMessageHook {
	public function onUserMailerTransformMessage( $to, $from, &$subject, &$headers, &$body, &$error );
}
