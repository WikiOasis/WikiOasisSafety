<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Portal;

use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;
use StatusValue;

class PortalClient {

	private string $baseUrl;
	private Hmac $hmac;
	private int $timeout;

	public function __construct( ?string $baseUrl = null, ?Hmac $hmac = null, ?int $timeout = null ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();

		$this->baseUrl = rtrim( $baseUrl ?? (string)$config->get( 'WikiOasisSafetyPortalUrl' ), '/' );
		$this->timeout = $timeout ?? (int)$config->get( 'WikiOasisSafetyPortalTimeout' );
		$this->hmac = $hmac ?? new Hmac(
			(string)$config->get( 'WikiOasisSafetyPortalSecret' ),
			300,
			WikiMap::getCurrentWikiId()
		);
	}

	public function isConfigured(): bool {
		return $this->baseUrl !== '' && $this->hmac->isConfigured();
	}

	/**
	 * @param array<string, mixed> $submission
	 * @return StatusValue
	 */
	public function submit( array $submission ): StatusValue {
		return $this->deliver( '/api/wiki/v1/submissions', $submission );
	}

	/**
	 * @param array<string, mixed> $file
	 * @return StatusValue
	 */
	public function attach( string $reference, array $file ): StatusValue {
		if ( !$this->isConfigured() ) {
			return StatusValue::newFatal( 'wikioasissafety-portal-unconfigured' );
		}

		return $this->post(
			'/api/wiki/v1/cases/' . rawurlencode( $reference ) . '/attachments',
			$file
		);
	}

	/**
	 * @param array<string, mixed> $comment
	 */
	public function comment( string $reference, array $comment ): StatusValue {
		return $this->deliver( '/api/wiki/v1/cases/' . rawurlencode( $reference ) . '/comments', $comment );
	}

	/**
	 * @param array<string, mixed> $appeal
	 */
	public function appeal( array $appeal ): StatusValue {
		return $this->deliver( '/api/wiki/v1/appeals', $appeal );
	}

	/**
	 * @param array{wiki: string, targets: string, checks: list<array<string, mixed>>} $batch
	 */
	public function checks( array $batch ): StatusValue {
		return $this->deliver( '/api/wiki/v1/checkuser-checks', $batch );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function deliver( string $path, array $payload ): StatusValue {
		if ( !$this->isConfigured() ) {
			return StatusValue::newFatal( 'wikioasissafety-portal-unconfigured' );
		}

		$status = $this->post( $path, $payload );

		if ( $status->isGood() ) {
			return $status;
		}

		if ( $status->hasMessage( 'wikioasissafety-portal-refused' ) ) {
			return $status;
		}

		( new Outbox() )->enqueue( $path, $payload );

		return StatusValue::newGood( [ 'reference' => null, 'queued' => true ] );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function confirmRemoval( string $reference, string $oldName, string $newName ): ?array {
		if ( !$this->isConfigured() ) {
			return null;
		}

		$path = '/api/wiki/v1/removals/' . rawurlencode( $reference );
		$query = '?' . http_build_query( [ 'username' => $oldName, 'newname' => $newName ] );

		$request = MediaWikiServices::getInstance()->getHttpRequestFactory()->create(
			$this->baseUrl . $path . $query,
			[ 'method' => 'GET', 'timeout' => $this->timeout ],
			__METHOD__
		);

		foreach ( $this->hmac->headers( 'GET', $path, '' ) as $header => $value ) {
			$request->setHeader( $header, $value );
		}
		$request->setHeader( 'Accept', 'application/json' );

		if ( !$request->execute()->isOK() ) {
			return null;
		}

		$decoded = json_decode( $request->getContent(), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param string|null $asWiki
	 */
	public function post( string $path, array $payload, ?string $asWiki = null ): StatusValue {
		$body = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( $body === false ) {
			return StatusValue::newFatal( 'wikioasissafety-portal-badpayload' );
		}

		$request = MediaWikiServices::getInstance()->getHttpRequestFactory()->create(
			$this->baseUrl . $path,
			[
				'method' => 'POST',
				'timeout' => $this->timeout,
				'postData' => $body,
			],
			__METHOD__
		);

		$headers = $this->hmac->headers( 'POST', $path, $body );

		if ( $asWiki !== null && $asWiki !== '' ) {
			$headers[ Hmac::HEADER_WIKI ] = $asWiki;
		}

		foreach ( $headers as $header => $value ) {
			$request->setHeader( $header, $value );
		}
		$request->setHeader( 'Content-Type', 'application/json' );
		$request->setHeader( 'Accept', 'application/json' );

		$status = $request->execute();
		if ( !$status->isOK() ) {
			return StatusValue::newFatal( 'wikioasissafety-portal-unreachable' );
		}

		$code = $request->getStatus();
		$decoded = json_decode( $request->getContent(), true );

		if ( $code >= 500 || !is_array( $decoded ) ) {
			return StatusValue::newFatal( 'wikioasissafety-portal-unreachable' );
		}

		if ( $code >= 400 ) {
			wfLogWarning( 'WikiOasisSafety: the portal refused a request: '
				. ( $decoded['message'] ?? 'no reason given' ) );

			return StatusValue::newFatal( 'wikioasissafety-portal-refused' );
		}

		return StatusValue::newGood( [
			'reference' => $decoded['reference'] ?? null,
			'duplicate' => !empty( $decoded['duplicate'] ),
			'queued' => false,
			'stored' => !empty( $decoded['stored'] ),
			'reason' => $decoded['reason'] ?? null,
		] );
	}
}
