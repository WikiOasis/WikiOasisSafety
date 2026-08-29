<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\WikiOasisSafety\Portal\Hmac;
use MediaWiki\Extension\WikiOasisSafety\Portal\SignedParameters;
use MediaWiki\Extension\WikiOasisSafety\Store\Mirror;
use MediaWiki\MediaWikiServices;
use Throwable;
use Wikimedia\ParamValidator\ParamValidator;

class ApiSafetySync extends ApiBase {

	private Mirror $mirror;

	public function __construct( ApiMain $main, string $name, ?Mirror $mirror = null ) {
		parent::__construct( $main, $name );
		$this->mirror = $mirror ?? new Mirror();
	}

	public function execute() {
		$this->authenticate();

		$params = $this->extractRequestParams();
		$payload = json_decode( (string)$params['payload'], true );

		if ( !is_array( $payload ) ) {
			$this->dieWithError( 'apierror-wikioasissafety-badpayload', 'badpayload' );
		}

		try {
			$result = match ( $params['event'] ) {
				'case.upsert' => $this->mirror->upsertCase( $payload ),
				'comment.add' => $this->mirror->addComment( $payload ),
				'sanction.upsert' => $this->mirror->upsertAction( $payload ),
				'notify' => $this->standing( $payload ),
				default => $this->dieWithError( 'apierror-wikioasissafety-badevent', 'badevent' ),
			};
		} catch ( Throwable $e ) {
			$this->dieWithError(
				[ 'apierror-wikioasissafety-syncfailed', wfEscapeWikiText( $e->getMessage() ) ],
				'syncfailed'
			);
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [ 'ok' => true ] + $result );
	}

	/** @param array<string, mixed> $payload */
	private function standing( array $payload ): array {
		$this->mirror->setStanding( $payload );

		return [ 'standing' => $payload['standing'] ?? 'good' ];
	}

	private function authenticate(): void {
		$config = $this->getConfig();
		$secret = (string)$config->get( 'WikiOasisSafetyPortalSecret' );

		if ( $secret === '' ) {
			$this->dieWithError( 'apierror-wikioasissafety-nosecret', 'nosecret' );
		}

		$request = $this->getRequest();
		$hmac = new Hmac( $secret, (int)$config->get( 'WikiOasisSafetyPortalTolerance' ) );

		$nonce = (string)$request->getHeader( Hmac::HEADER_NONCE );
		$body = $this->rawBody();

		$ok = $hmac->verify(
			'POST',
			$request->getRequestURL(),
			(string)$request->getHeader( Hmac::HEADER_TIMESTAMP ),
			$nonce,
			$body,
			(string)$request->getHeader( Hmac::HEADER_SIGNATURE )
		);

		if ( !$ok ) {
			$this->dieWithError( 'apierror-wikioasissafety-badsignature', 'badsignature' );
		}

		$unsigned = SignedParameters::unsigned(
			$body,
			$request->getQueryValues(),
			array_keys( $this->getAllowedParams() )
		);

		if ( $unsigned !== [] ) {
			$this->dieWithError(
				[
					'apierror-wikioasissafety-unsignedparam',
					wfEscapeWikiText( implode( ', ', $unsigned ) ),
				],
				'unsignedparam'
			);
		}

		$cache = MediaWikiServices::getInstance()->getMainObjectStash();
		$key = $cache->makeGlobalKey( 'wikioasissafety-nonce', hash( 'sha256', $nonce ) );

		if ( !$cache->add( $key, 1, (int)$config->get( 'WikiOasisSafetyPortalTolerance' ) * 2 ) ) {
			$this->dieWithError( 'apierror-wikioasissafety-replayed', 'replayed' );
		}
	}

	private function rawBody(): string {
		$body = file_get_contents( 'php://input' );

		return $body === false ? '' : $body;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'event' => [
				ParamValidator::PARAM_TYPE => [ 'case.upsert', 'comment.add', 'sanction.upsert', 'notify' ],
				ParamValidator::PARAM_REQUIRED => true,
			],
			'payload' => [
				ParamValidator::PARAM_TYPE => 'text',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/** @inheritDoc */
	public function mustBePosted() {
		return true;
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function needsToken() {
		return false;
	}

	/** @inheritDoc */
	public function isInternal() {
		return true;
	}
}
