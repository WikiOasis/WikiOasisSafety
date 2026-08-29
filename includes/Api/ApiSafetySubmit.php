<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\Extension\WikiOasisSafety\Anonymity;
use MediaWiki\Extension\WikiOasisSafety\CaseOwnership;
use MediaWiki\Extension\WikiOasisSafety\Categories;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\Extension\WikiOasisSafety\Throttle;
use MediaWiki\Extension\WikiOasisSafety\Wording;
use MediaWiki\Extension\WikiOasisSafety\WizardDefinition;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ParamValidator\ParamValidator;

class ApiSafetySubmit extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$user = $this->getUser();

		$this->checkUserRightsAny( 'edit' );

		if ( Throttle::refusesReport( $user ) ) {
			$this->dieWithError( 'apierror-ratelimited', 'ratelimited' );
		}

		$answers = json_decode( (string)$params['answers'], true );
		if ( !is_array( $answers ) ) {
			$this->dieWithError( 'apierror-wikioasissafety-badpayload', 'badpayload' );
		}

		$flow = $this->flowFor( (string)$params['flow'] );


		$anonymous = Anonymity::of( $user, $flow, $answers, (bool)$params['anonymous'] );

		$submission = [
			'type' => $this->typeFor( (string)$params['flow'] ),
			'flow' => (string)$params['flow'],
			'wiki' => WikiMap::getCurrentWikiId(),
			'anonymous' => $anonymous,
			'reporter' => $anonymous ? null : Accounts::identity( $user ),
			'answers' => $answers,
			'roles' => $this->fieldRoles( (string)$params['flow'] ),

			'categories' => Categories::resolve( $flow, $answers ),

			'attachments' => json_decode( (string)$params['attachments'], true ) ?: [],
		];

		$status = ( new PortalClient() )->submit( $submission );

		if ( !$status->isGood() ) {
			$this->dieStatus( $status );
		}

		$value = $status->getValue();

		if ( $value['reference'] !== null && $value['reference'] !== '' ) {
			CaseOwnership::remember( $this->getRequest(), (string)$value['reference'] );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'ok' => true,
			'reference' => $value['reference'],
			'queued' => $value['queued'],
			'anonymous' => $anonymous,
			'temporary' => !$anonymous && !Accounts::isNamed( $user ),
		] );
	}

	private function typeFor( string $flow ): string {
		return match ( $flow ) {
			'data' => 'data',
			'contact' => 'contact',
			default => 'report',
		};
	}

	/**
	 * @param string $flow 'report', 'data' or 'contact'.
	 * @return array See WizardDefinition::resolve().
	 */
	private function flowFor( string $flow ): array {
		return WizardDefinition::resolve(
			$this->getConfig(),
			$this->flowSuffix( $flow ),
			Wording::inContentLanguage()
		);
	}

	private function flowSuffix( string $flow ): string {
		return WizardDefinition::suffixFor( $flow );
	}

	/** @return array<string, string> */
	private function fieldRoles( string $flow ): array {
		$roles = $this->getConfig()->get( 'WikiOasisSafety' . $this->flowSuffix( $flow ) . 'FieldRoles' );

		return is_array( $roles ) ? $roles : [];
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'flow' => [
				ParamValidator::PARAM_TYPE => [ 'report', 'data', 'contact' ],
				ParamValidator::PARAM_REQUIRED => true,
			],
			'answers' => [ ParamValidator::PARAM_TYPE => 'text', ParamValidator::PARAM_REQUIRED => true ],
			'attachments' => [ ParamValidator::PARAM_TYPE => 'text', ParamValidator::PARAM_DEFAULT => '[]' ],
			'anonymous' => [ ParamValidator::PARAM_TYPE => 'boolean', ParamValidator::PARAM_DEFAULT => false ],
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
