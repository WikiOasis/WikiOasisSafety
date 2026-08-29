<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Specials;

use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\Extension\WikiOasisSafety\NoJs\HomeViews;
use MediaWiki\Extension\WikiOasisSafety\NoJs\WizardForm;
use MediaWiki\Extension\WikiOasisSafety\SafetyLinks;
use MediaWiki\Extension\WikiOasisSafety\Store\SafetyStore;
use MediaWiki\Extension\WikiOasisSafety\WizardDefinition;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialSafetyHome extends SpecialPage {

	private const ROUTES = [ 'reports', 'account', 'data', 'contact' ];

	private const DIALOG_ROUTES = [ 'reports', 'account' ];

	public function __construct() {
		parent::__construct( 'SafetyHome' );
	}

	/** @inheritDoc */
	public function getDescription() {
		return $this->msg( 'wikioasissafety-home-title' );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'other';
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$out = $this->getOutput();

		if ( !$this->getConfig()->get( 'WikiOasisSafetyEnabled' ) ) {
			$out->addWikiMsg( 'wikioasissafety-home-disabled' );
			return;
		}

		$out->addModuleStyles( SafetyLinks::NOJS_MODULE );

		$parts = array_values( array_filter( explode( '/', (string)$subPage ), 'strlen' ) );
		$route = $parts[0] ?? '';

		if ( $route === '' || !in_array( $route, self::ROUTES, true ) ) {
			$this->showGrid();
			return;
		}

		$asDialog = in_array( $route, self::DIALOG_ROUTES, true )
			&& Accounts::isNamed( $this->getUser() );

		if ( $asDialog ) {
			$this->mountGrid( [
				'dialog' => $route,
				'reference' => $route === 'reports' ? ( $parts[1] ?? '' ) : '',
			] );
		}

		$backHome = $this->getLinkRenderer()->makeLink(
			$this->getPageTitle(),
			$this->msg( 'wikioasissafety-nojs-backhome' )->text()
		);
		$out->addSubtitle( $asDialog
			? Html::rawElement( 'span', [ 'class' => 'wikioasis-safety-nojs' ], $backHome )
			: $backHome );

		if ( $asDialog ) {
			$out->addHTML( Html::openElement( 'div', [ 'class' => 'wikioasis-safety-nojs' ] ) );
		}

		switch ( $route ) {
			case 'reports':
				$this->showReports( $parts[1] ?? '' );
				break;
			case 'account':
				$this->showAccount();
				break;
			case 'data':
			case 'contact':
				$this->showWizard( $route, $parts[1] ?? '' );
				break;
		}

		if ( $asDialog ) {
			$out->addHTML( Html::closeElement( 'div' ) );
		}
	}

	private function showGrid(): void {
		$this->mountGrid( null );

		$views = new HomeViews( $this->getContext() );
		$this->getOutput()->addHTML( Html::rawElement( 'div',
			[ 'class' => 'wikioasis-safety-nojs' ], $views->cards( $this->availableFlows() ) ) );
	}

	private function availableFlows(): array {
		$config = $this->getConfig();
		return [
			'report' => WizardDefinition::hasSteps( $config ),
			'data' => WizardDefinition::hasSteps( $config, 'Data' ),
			'contact' => WizardDefinition::hasSteps( $config, 'Contact' ),
		];
	}

	/**
	 * @param array|null $open
	 */
	private function mountGrid( ?array $open ): void {
		$out = $this->getOutput();
		$available = $this->availableFlows();

		$out->addModules( SafetyLinks::HOME_MODULE );
		$store = new SafetyStore();
		$user = $this->getUser();

		$out->addJsConfigVars( [
			'wgWikiOasisSafetyNoticeboardUrl' => SafetyLinks::getNoticeboardUrl( $this->getContext() ),

			'wgWikiOasisSafetyRecords' => Accounts::isNamed( $user ) ? [
				'reports' => $store->reportsFor( $user ),
				'account' => $store->accountFor( $user ),
				'hasAnonymous' => $store->hasAnonymousReports( $user ),
			] : null,

			'wgWikiOasisSafetyHome' => [
				'hasReportFlow' => $available['report'],
				'hasDataFlow' => $available['data'],
				'hasContactFlow' => $available['contact'],
				'isNamed' => Accounts::isNamed( $user ),
				'isTemp' => $user->isRegistered() && !Accounts::isNamed( $user ),
				'userName' => Accounts::isNamed( $user ) ? $user->getName() : null,
				'loginUrl' => SpecialPage::getTitleFor( 'Userlogin' )->getLocalURL(
					[ 'returnto' => $this->getPageTitle()->getPrefixedText() ]
				),
				'autoOpen' => $open,
			],
		] );

		$out->addHTML( Html::element( 'div', [ 'id' => 'wikioasis-safety-home' ] ) );
	}

	/**
	 * @param string $id Report reference, or '' for the list
	 */
	private function showReports( string $id ): void {
		$out = $this->getOutput();
		if ( !$this->requireAccount() ) {
			return;
		}
		$views = new HomeViews( $this->getContext() );

		if ( $id === '' ) {
			$out->setPageTitleMsg( $this->msg( 'wikioasissafety-home-reports-title' ) );
			$out->addHTML( $views->reportsList( ( new SafetyStore() )->reportsFor( $this->getUser() ) ) );
			return;
		}

		$report = ( new SafetyStore() )->reportFor( $this->getUser(), $id );
		if ( !$report ) {
			$out->setPageTitleMsg( $this->msg( 'wikioasissafety-home-reports-title' ) );
			$out->addHTML( Html::errorBox(
				$this->msg( 'wikioasissafety-nojs-noreport' )->escaped() ) );
			$out->addHTML( $views->reportsList( ( new SafetyStore() )->reportsFor( $this->getUser() ) ) );
			return;
		}

		$out->setPageTitle( $report['id'] );
		$out->addHTML( $views->report( $report ) );
		$views->showCommentForm( $report );
	}

	private function showAccount(): void {
		if ( !$this->requireAccount() ) {
			return;
		}
		$out = $this->getOutput();
		$out->setPageTitleMsg( $this->msg( 'wikioasissafety-home-account-title' ) );
		$views = new HomeViews( $this->getContext() );
		$store = new SafetyStore();
		$out->addHTML( $views->accountStanding(
			$store->accountFor( $this->getUser() ),
			$store->actionsFor( $this->getUser() ),
			WizardDefinition::hasSteps( $this->getConfig(), 'Contact' )
		) );
	}

	/**
	 * @param string $route
	 * @param string $stepId
	 */
	private function showWizard( string $route, string $stepId ): void {
		$out = $this->getOutput();
		$flowName = $route === 'data' ? 'Data' : 'Contact';

		if ( $route === 'data' && !$this->requireAccount() ) {
			return;
		}

		$out->setPageTitleMsg( $this->msg( 'wikioasissafety-home-' . $route ) );
		$flow = WizardDefinition::resolve( $this->getConfig(), $flowName, $this );

		$form = new WizardForm(
			$flow,
			$this->getContext(),
			SpecialPage::getTitleFor( 'SafetyHome', $route ),
			$route
		);
		$form->show( $stepId );
	}

	/**
	 * @return bool
	 */
	private function requireAccount(): bool {
		$user = $this->getUser();
		if ( Accounts::isNamed( $user ) ) {
			return true;
		}

		$login = SpecialPage::getTitleFor( 'Userlogin' )->getLocalURL( [
			'returnto' => $this->getFullTitle()->getPrefixedText(),
		] );

		$why = $user->isRegistered()
			? 'wikioasissafety-home-temporary'
			: 'wikioasissafety-home-login';
		$this->getOutput()->addHTML( Html::rawElement( 'p', [],
			htmlspecialchars( $this->msg( $why )->text() ) . ' ' .
			Html::element( 'a', [ 'href' => $login ],
				$this->msg( 'wikioasissafety-nojs-login-link' )->text() ) ) );
		return false;
	}
}
