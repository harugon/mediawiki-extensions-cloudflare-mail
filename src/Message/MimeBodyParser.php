<?php

namespace MediaWiki\Extension\CloudflareMail\Message;

use ValueError;

/**
 * Turns the MIME-encoded body that MediaWiki hands to the AlternateUserMailer
 * hook back into plain-text and HTML strings.
 *
 * MediaWiki passes either a single text/plain part (8bit) or, when
 * $wgAllowHTMLEmail is enabled, a multipart/alternative message built by
 * PEAR Mail_mime (quoted-printable encoded). Cloudflare's REST API expects
 * the decoded `text` and `html` fields instead, so this class reverses that.
 */
final class MimeBodyParser {

	private const MAX_DEPTH = 5;

	/**
	 * @param array<string,string> $headers Top-level message headers
	 * @param string $body Raw message body
	 * @return MessageBody
	 */
	public function parse( array $headers, string $body ): MessageBody {
		$text = null;
		$html = null;
		$this->parseEntity( $headers, $body, $text, $html, 0 );
		return new MessageBody( $text, $html );
	}

	/**
	 * @param array<string,string> $headers
	 * @param string $body
	 * @param string|null &$text
	 * @param string|null &$html
	 * @param int $depth
	 */
	private function parseEntity( array $headers, string $body, ?string &$text, ?string &$html, int $depth ): void {
		[ $type, $params ] = MimeHeader::parseParameterized(
			MimeHeader::get( $headers, 'Content-Type' ) ?? 'text/plain'
		);

		if ( str_starts_with( $type, 'multipart/' ) ) {
			$boundary = $params['boundary'] ?? '';
			if ( $boundary === '' || $depth >= self::MAX_DEPTH ) {
				return;
			}
			foreach ( $this->splitMultipart( $body, $boundary ) as $part ) {
				[ $partHeaders, $partBody ] = $this->splitHeaders( $part );
				$this->parseEntity( $partHeaders, $partBody, $text, $html, $depth + 1 );
			}
			return;
		}

		$disposition = MimeHeader::get( $headers, 'Content-Disposition' );
		if ( $disposition !== null && MimeHeader::parseParameterized( $disposition )[0] === 'attachment' ) {
			return;
		}

		if ( $type === 'text/plain' && $text === null ) {
			$text = $this->decodeContent( $headers, $body, $params['charset'] ?? null );
		} elseif ( $type === 'text/html' && $html === null ) {
			$html = $this->decodeContent( $headers, $body, $params['charset'] ?? null );
		}
	}

	/**
	 * @param string $body
	 * @param string $boundary
	 * @return string[] Raw body parts including their headers
	 */
	private function splitMultipart( string $body, string $boundary ): array {
		$delimiter = '--' . preg_quote( $boundary, '/' );
		// Prefix a newline so a delimiter on the very first line is matched as well.
		$body = "\n" . $body;

		// Everything after the closing delimiter is the epilogue.
		$closing = preg_split( "/\\r?\\n{$delimiter}--[ \\t]*(?:\\r?\\n|$)/", $body, 2 );
		$parts = preg_split( "/\\r?\\n{$delimiter}[ \\t]*(?:\\r?\\n|$)/", $closing[0] );

		// The first chunk is the preamble.
		array_shift( $parts );
		return $parts;
	}

	/**
	 * @param string $part
	 * @return array{0:array<string,string>,1:string}
	 */
	private function splitHeaders( string $part ): array {
		$split = preg_split( '/\r?\n\r?\n/', $part, 2 );
		if ( count( $split ) < 2 ) {
			// No blank line at all; treat as headers only (RFC 2046 allows empty bodies).
			return [ $this->parseHeaderBlock( $part ), '' ];
		}
		return [ $this->parseHeaderBlock( $split[0] ), $split[1] ];
	}

	/**
	 * @param string $block
	 * @return array<string,string>
	 */
	private function parseHeaderBlock( string $block ): array {
		$headers = [];
		foreach ( preg_split( '/\r?\n/', MimeHeader::unfold( $block ) ) as $line ) {
			$pos = strpos( $line, ':' );
			if ( $pos === false ) {
				continue;
			}
			$headers[trim( substr( $line, 0, $pos ) )] = trim( substr( $line, $pos + 1 ) );
		}
		return $headers;
	}

	/**
	 * @param array<string,string> $headers
	 * @param string $content
	 * @param string|null $charset
	 * @return string UTF-8 text with "\n" line endings
	 */
	private function decodeContent( array $headers, string $content, ?string $charset ): string {
		$encoding = strtolower( trim( MimeHeader::get( $headers, 'Content-Transfer-Encoding' ) ?? '8bit' ) );
		switch ( $encoding ) {
			case 'quoted-printable':
				$content = quoted_printable_decode( str_replace( "\r\n", "\n", $content ) );
				break;
			case 'base64':
				$content = base64_decode( preg_replace( '/\s+/', '', $content ) ) ?: '';
				break;
		}

		if ( $charset !== null && !in_array( strtolower( $charset ), [ 'utf-8', 'utf8', 'us-ascii' ], true ) ) {
			try {
				$content = mb_convert_encoding( $content, 'UTF-8', $charset );
			} catch ( ValueError ) {
				// Unknown charset: keep the bytes as they are.
			}
		}

		return str_replace( [ "\r\n", "\r" ], "\n", $content );
	}
}
