<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\SpecialPage\SpecialPage;

class CasePresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function getIconType() {
		return $this->event->getType() === Notifier::ACTION ? 'alert' : 'chat';
	}

	/** @inheritDoc */
	public function getHeaderMessage() {
		$reference = (string)$this->event->getExtraParam( 'reference', '' );

		switch ( $this->event->getType() ) {
			case Notifier::COMMENT:
				return $this->msg( 'notification-header-wikioasissafety-comment', $reference );

			case Notifier::ACTION:
				return $this->msg(
					$this->event->getExtraParam( 'lifted' )
						? 'notification-header-wikioasissafety-action-lifted'
						: 'notification-header-wikioasissafety-action',
					(string)$this->event->getExtraParam( 'label', '' ),
					$reference
				);

			default:
				return $this->msg(
					$this->event->getExtraParam( 'isNew' )
						? 'notification-header-wikioasissafety-case-new'
						: 'notification-header-wikioasissafety-case-update',
					$reference
				);
		}
	}

	public function getBodyMessage() {
		return $this->msg( 'notification-body-wikioasissafety' );
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		$reference = (string)$this->event->getExtraParam( 'reference', '' );

		$subpage = $this->event->getType() === Notifier::ACTION
			? 'account'
			: ( $reference !== '' ? 'reports/' . $reference : 'reports' );

		return [
			'url' => SpecialPage::getTitleFor( 'SafetyHome', $subpage )->getFullURL(),
			'label' => $this->msg( 'notification-link-wikioasissafety' )->text(),
		];
	}

	/**
	 * @inheritDoc
	 */
	public function canRender() {
		return true;
	}
}
