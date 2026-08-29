<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\Extension\WikiOasisSafety\Portal\Hmac;
use MediaWiki\Extension\WikiOasisSafety\Portal\SignedParameters;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

class ApiSafetyLookup extends ApiBase {

	public function execute() {
		$this->authenticate();

		$params = $this->extractRequestParams();
		$name = (string)$params['username'];

		$canonical = Accounts::canonicalName( $name );

		if ( $canonical === null ) {
			$this->reply( false, null, $name, null );
			return;
		}

		$centralId = Accounts::centralIdForName( $canonical );

		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $canonical );
		$local = $user !== null && $user->isRegistered() ? $user : null;

		$this->reply(
			$centralId !== null || $local !== null,
			$centralId,
			$canonical,
			$local !== null ? $local->getRegistration() : null
		);
	}

	private function reply( bool $exists, ?int $centralId, string $username, ?string $registered ): void {
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'exists' => $exists,
			'central_id' => $centralId,
			'username' => $username,
			'registered_at' => $registered !== null && $registered !== ''
				? wfTimestamp( TS_ISO_8601, $registered )
				: null,
		] );
	}

	/**
	 * @see ApiSafetySync::authenticate()
	 */
	private function authenticate(): void {
		$config = $this->getConfig();
		$secret = (string)$config->get( 'WikiOasisSafetyPortalSecret' );

		if ( $secret === '' ) {
			$this->dieWithError( 'apierror-wikioasissafety-nosecret', 'nosecret' );
		}

		$request = $this->getRequest();
		$hmac = new Hmac( $secret, (int)$config->get( 'WikiOasisSafetyPortalTolerance' ) );

		$nonce = (string)$request->getHeader( Hmac::HEADER_NONCE );
		$body = file_get_contents( 'php://input' );
		$body = $body === false ? '' : $body;

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

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'username' => [
				ParamValidator::PARAM_TYPE => 'string',
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
		return false;
	}

	/** @inheritDoc */
	public function needsToken() {
		return false;
	}

	/** @inheritDoc */
	public function isInternal() {
		return true;
	}
}
