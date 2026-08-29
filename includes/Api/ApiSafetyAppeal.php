<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\Extension\WikiOasisSafety\Store\SafetyStore;
use Wikimedia\ParamValidator\ParamValidator;

/*
 * API route for appeals, supports unauthenticated access as this is primarily for disabled accounts.
 */

class ApiSafetyAppeal extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();

		if ( !$this->getConfig()->get( 'WikiOasisSafetyEnabled' ) ) {
			$this->dieWithError( 'apierror-wikioasissafety-disabled', 'disabled' );
		}

		if ( $this->getUser()->pingLimiter( 'wikioasissafety-appeal' ) ) {
			$this->dieWithError( 'apierror-ratelimited', 'ratelimited' );
		}

		$name = trim( (string)$params['username'] );
		$body = trim( (string)$params['text'] );

		if ( $name === '' || $body === '' ) {
			$this->dieWithError( 'apierror-wikioasissafety-appealincomplete', 'incomplete' );
		}

		$store = new SafetyStore();
		$suspension = $store->suspension( $name );

		$reference = $params['reference'] !== null && $params['reference'] !== ''
			? (string)$params['reference']
			: ( $suspension['reference'] ?? null );

		$status = ( new PortalClient() )->appeal( [
			'username' => $name,
			'email' => $params['email'] !== null && $params['email'] !== '' ? (string)$params['email'] : null,
			'sanction_reference' => $reference,
			'body' => $body,
			'wiki' => \MediaWiki\WikiMap\WikiMap::getCurrentWikiId(),
			'authenticated' => false,
		] );

		if ( !$status->isGood() ) {
			$this->dieStatus( $status );
		}

		$value = $status->getValue();

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'ok' => true,
			'reference' => $value['reference'],
			'queued' => $value['queued'],
			'duplicate' => $value['duplicate'] ?? false,
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'username' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true ],
			'text' => [ ParamValidator::PARAM_TYPE => 'text', ParamValidator::PARAM_REQUIRED => true ],
			'email' => [ ParamValidator::PARAM_TYPE => 'string' ],
			'reference' => [ ParamValidator::PARAM_TYPE => 'string' ],
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

	/** @inheritDoc */
	public function needsToken() {
		return false;
	}
}
