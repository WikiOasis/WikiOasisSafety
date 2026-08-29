<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Portal;

/**
 * This must match the portal implementation
 */
class Hmac {

	public const HEADER_TIMESTAMP = 'X-TSPortal-Timestamp';
	public const HEADER_NONCE = 'X-TSPortal-Nonce';
	public const HEADER_SIGNATURE = 'X-TSPortal-Signature';
	public const HEADER_WIKI = 'X-TSPortal-Wiki';

	private string $secret;
	private int $tolerance;
	private string $wikiId;

	/**
	 * @param string $secret
	 * @param int $tolerance
	 * @param string $wikiId
	 */
	public function __construct( string $secret, int $tolerance = 300, string $wikiId = '' ) {
		$this->secret = $secret;
		$this->tolerance = $tolerance;
		$this->wikiId = $wikiId;
	}

	public function isConfigured(): bool {
		return $this->secret !== '';
	}

	public function canonical(
		string $method, string $path, string $timestamp, string $nonce, string $body
	): string {
		$parsed = parse_url( $path, PHP_URL_PATH );
		$path = '/' . trim( is_string( $parsed ) ? $parsed : $path, '/' );

		return implode( "\n", [
			strtoupper( $method ),
			$path,
			$timestamp,
			$nonce,
			hash( 'sha256', $body ),
		] );
	}

	public function sign(
		string $method, string $path, string $timestamp, string $nonce, string $body
	): string {
		return hash_hmac(
			'sha256',
			$this->canonical( $method, $path, $timestamp, $nonce, $body ),
			$this->secret
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function headers( string $method, string $path, string $body ): array {
		$timestamp = (string)time();
		$nonce = bin2hex( random_bytes( 12 ) );

		return [
			self::HEADER_TIMESTAMP => $timestamp,
			self::HEADER_NONCE => $nonce,
			self::HEADER_SIGNATURE => $this->sign( $method, $path, $timestamp, $nonce, $body ),
			self::HEADER_WIKI => $this->wikiId,
		];
	}

	public function verify(
		string $method, string $path, string $timestamp, string $nonce, string $body, string $signature
	): bool {
		if ( !$this->isConfigured() || $timestamp === '' || $nonce === '' || $signature === '' ) {
			return false;
		}
		if ( abs( time() - (int)$timestamp ) > $this->tolerance ) {
			return false;
		}

		return hash_equals( $this->sign( $method, $path, $timestamp, $nonce, $body ), $signature );
	}
}
