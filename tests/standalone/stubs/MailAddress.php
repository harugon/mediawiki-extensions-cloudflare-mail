<?php

class MailAddress {
	public string $name;
	public string $realName;
	public string $address;

	public function __construct( $address, $name = null, $realName = null ) {
		$this->address = strval( $address );
		$this->name = strval( $name );
		$this->realName = strval( $realName );
	}
}
