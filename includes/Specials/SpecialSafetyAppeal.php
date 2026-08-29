<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Specials;

use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\Extension\WikiOasisSafety\Auth\PasswordProof;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\Extension\WikiOasisSafety\SafetyLinks;
use MediaWiki\Extension\WikiOasisSafety\Store\SafetyStore;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;

class SpecialSafetyAppeal extends SpecialPage {

	public function __construct() {
		parent::__construct( 'SafetyAppeal' );
	}

	/** @inheritDoc */
	public function getDescription() {
		return $this->msg( 'wikioasissafety-appeal-title' );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'other';
	}

	/** @inheritDoc */
	public function isListed() {
		return false;
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$out = $this->getOutput();
		$out->addModuleStyles( SafetyLinks::NOJS_MODULE );

		if ( !$this->getConfig()->get( 'WikiOasisSafetyEnabled' ) ) {
			$out->addWikiMsg( 'wikioasissafety-home-disabled' );
			return;
		}

		$request = $this->getRequest();
		$user = $this->getUser();

		$username = Accounts::isNamed( $user )
			? $user->getName()
			: (string)$request->getText( 'user', '' );

		$reference = (string)$request->getText( 'ref', '' );

		$maySee = Accounts::isNamed( $user )
			|| ( $username !== '' && PasswordProof::proves( $request, $username ) );

		$suspension = $maySee && $username !== ''
			? ( new SafetyStore() )->suspension( $username )
			: null;

		$out->addHTML( $this->intro( $suspension ) );

		$form = HTMLForm::factory( 'codex', $this->fields( $username, $reference, Accounts::isNamed( $user ) ), $this->getContext() )
			->setSubmitTextMsg( 'wikioasissafety-appeal-submit' )
			->setWrapperLegendMsg( 'wikioasissafety-appeal-legend' )
			->setSubmitCallback( [ $this, 'onSubmit' ] );

		$form->show();
	}

	/**
	 * @param array<string, mixed>|null $suspension
	 */
	private function intro( ?array $suspension ): string {
		$html = Html::rawElement( 'div', [ 'class' => 'wikioasis-safety-nojs-intro' ],
			$this->msg( 'wikioasissafety-appeal-intro' )->parseAsBlock() );

		if ( $suspension === null ) {
			return $html;
		}

		$rows = '';
		if ( $suspension['reference'] !== null ) {
			$rows .= Html::element( 'dt', [], $this->msg( 'wikioasissafety-appeal-reference' )->text() )
				. Html::element( 'dd', [], $suspension['reference'] );
		}
		if ( $suspension['reason'] !== null ) {
			$rows .= Html::element( 'dt', [], $this->msg( 'wikioasissafety-appeal-reason' )->text() )
				. Html::element( 'dd', [], $suspension['reason'] );
		}
		$rows .= Html::element( 'dt', [], $this->msg( 'wikioasissafety-appeal-ends' )->text() )
			. Html::element( 'dd', [], $suspension['expires'] !== null
				? $this->getLanguage()->userTimeAndDate( $suspension['expires'], $this->getUser() )
				: $this->msg( 'wikioasissafety-home-account-noexpiry' )->text() );

		return $html . Html::rawElement( 'dl', [ 'class' => 'wikioasis-safety-nojs-facts' ], $rows );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function fields( string $username, string $reference, bool $registered ): array {
		return [
			'username' => [
				'type' => 'text',
				'label-message' => 'wikioasissafety-appeal-username',
				'default' => $username,
				'required' => true,
				'disabled' => $registered,
				'help-message' => 'wikioasissafety-appeal-username-help',
			],
			'email' => [
				'type' => 'email',
				'label-message' => 'wikioasissafety-appeal-email',
				'help-message' => 'wikioasissafety-appeal-email-help',
			],
			'reference' => [
				'type' => 'hidden',
				'default' => $reference,
			],
			'text' => [
				'type' => 'textarea',
				'rows' => 8,
				'label-message' => 'wikioasissafety-appeal-text',
				'help-message' => 'wikioasissafety-appeal-text-help',
				'required' => true,
			],
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return Status|bool
	 */
	public function onSubmit( array $data ) {
		if ( $this->getUser()->pingLimiter( 'wikioasissafety-appeal' ) ) {
			return Status::newFatal( 'actionthrottledtext' );
		}

		$status = ( new PortalClient() )->appeal( [
			'username' => trim( (string)$data['username'] ),
			'email' => trim( (string)$data['email'] ) ?: null,
			'sanction_reference' => trim( (string)$data['reference'] ) ?: null,
			'body' => trim( (string)$data['text'] ),
			'authenticated' => Accounts::isNamed( $this->getUser() ),
		] );

		if ( !$status->isGood() ) {
			return Status::wrap( $status );
		}

		$value = $status->getValue();
		$out = $this->getOutput();

		if ( !empty( $value['duplicate'] ) ) {
			$out->addHTML( Html::successBox(
				$this->msg( 'wikioasissafety-appeal-duplicate', $value['reference'] )->parse() ) );
		} elseif ( !empty( $value['queued'] ) ) {
			$out->addHTML( Html::warningBox(
				$this->msg( 'wikioasissafety-appeal-queued' )->parse() ) );
		} else {
			$out->addHTML( Html::successBox(
				$this->msg( 'wikioasissafety-appeal-sent', (string)$value['reference'] )->parse() ) );
		}

		return true;
	}
}
