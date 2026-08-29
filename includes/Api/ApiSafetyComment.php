<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\Extension\WikiOasisSafety\Store\Mirror;
use MediaWiki\Extension\WikiOasisSafety\Store\SafetyStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API route for adding comments to cases that have already been filed
 */
class ApiSafetyComment extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$user = $this->getUser();

		// Must be a named account, temp accounts and anon IPs do not get to access cases after creation
		if ( !Accounts::isNamed( $user ) ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}

		$reference = (string)$params['reference'];
		$store = new SafetyStore();

		if ( $store->reportFor( $user, $reference ) === null ) {
			$this->dieWithError( 'apierror-wikioasissafety-nocase', 'nocase' );
		}

		$body = trim( (string)$params['text'] );
		if ( $body === '' ) {
			$this->dieWithError( 'apierror-wikioasissafety-emptycomment', 'emptycomment' );
		}

		$status = ( new PortalClient() )->comment(
			$reference,
			Accounts::identity( $user ) + [ 'body' => $body ]
		);

		if ( !$status->isGood() ) {
			$this->dieStatus( $status );
		}

		$queued = (bool)( $status->getValue()['queued'] ?? false );

		if ( !$queued ) {
			( new Mirror() )->addComment( [
				'reference' => $reference,
				'author' => 'you',
				'author_label' => null,
				'body' => $body,
				'date' => wfTimestamp( TS_ISO_8601 ),
				'notify' => false,
			], local: true );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'ok' => true,
			'queued' => $queued,
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'reference' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true ],
			'text' => [ ParamValidator::PARAM_TYPE => 'text', ParamValidator::PARAM_REQUIRED => true ],
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
		return 'csrf';
	}
}
