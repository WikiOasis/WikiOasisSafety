<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Specials;

use MediaWiki\Extension\WikiOasisSafety\NoJs\WizardForm;
use MediaWiki\Extension\WikiOasisSafety\SafetyLinks;
use MediaWiki\Extension\WikiOasisSafety\WizardDefinition;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialSafetyReport extends SpecialPage {

	public function __construct() {
		parent::__construct( 'SafetyReport' );
	}

	/** @inheritDoc */
	public function getDescription() {
		return $this->msg( 'wikioasissafety-special-title' );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'other';
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$out = $this->getOutput();
		$request = $this->getRequest();

		if ( !WizardDefinition::hasSteps( $this->getConfig() ) ) {
			$out->addWikiMsg( 'wikioasissafety-noflow' );
			return;
		}

		$prefill = [
			'user' => $request->getText( 'user' ) ?: null,
			'page' => $request->getText( 'page' ) ?: null,
		];

		$out->addModuleStyles( SafetyLinks::NOJS_MODULE );
		$out->addModules( SafetyLinks::MODULE );
		$out->addJsConfigVars( [
			'wgWikiOasisSafetyNoticeboardUrl' => SafetyLinks::getNoticeboardUrl( $this->getContext() ),
			'wgWikiOasisSafetyPrefill' => $prefill,
		] );

		$out->addHTML( Html::element( 'div', [ 'id' => 'wikioasis-safety-wizard' ] ) );
		$out->addHTML( Html::openElement( 'div', [ 'class' => 'wikioasis-safety-nojs' ] ) );
		$form = new WizardForm(
			WizardDefinition::resolve( $this->getConfig(), '', $this ),
			$this->getContext(),
			$this->getPageTitle()
		);
		$form->show( (string)$subPage, $this->prefillAnswers( $prefill ) );
		$out->addHTML( Html::closeElement( 'div' ) );
	}

	/**
	 * @param array $prefill
	 * @return array
	 */
	private function prefillAnswers( array $prefill ): array {
		$flow = WizardDefinition::resolve( $this->getConfig(), '', $this );
		$roles = $flow['fieldRoles'] ?? [];
		$answers = [];
		$concerns = [];

		if ( $prefill['user'] && ( $roles['users'] ?? '' ) ) {
			$answers[$roles['users']] = [ $prefill['user'] ];
			$concerns[] = 'users';
		}
		if ( $prefill['page'] && ( $roles['pages'] ?? '' ) ) {
			$answers[$roles['pages']] = [ $prefill['page'] ];
			$concerns[] = 'pages';
		}
		if ( $concerns && ( $roles['concerns'] ?? '' ) ) {
			$answers[$roles['concerns']] = $concerns;
		}
		return $answers;
	}
}
