<?php

namespace MediaWiki\Config;

interface Config {
	public function get( $name );

	public function has( $name );
}

class HashConfig implements Config {
	public function __construct( private array $settings = [] ) {
	}

	public function get( $name ) {
		return $this->settings[$name];
	}

	public function has( $name ) {
		return array_key_exists( $name, $this->settings );
	}
}
