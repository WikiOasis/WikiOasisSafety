<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Hooks;

use MediaWiki\Extension\WikiOasisSafety\Notifications\CasePresentationModel;
use MediaWiki\Extension\WikiOasisSafety\Notifications\Notifier;

class EchoNotifications {

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public static function onBeforeCreateEchoEvent(
		array &$notifications, array &$notificationCategories, array &$icons
	): void {
		$notificationCategories['wikioasissafety'] = [
			'priority' => 1,
			'tooltip' => 'echo-pref-tooltip-wikioasissafety',
		];

		$shared = [
			'category' => 'wikioasissafety',
			'group' => 'positive',
			'section' => 'alert',
			'presentation-model' => CasePresentationModel::class,
			'user-locators' => [
				[ 'MediaWiki\\Extension\\Notifications\\UserLocator::locateEventAgent' ],
			],
			'canNotifyAgent' => true,
		];

		$notifications[Notifier::CASE_UPDATE] = $shared;
		$notifications[Notifier::COMMENT] = $shared;
		$notifications[Notifier::ACTION] = [ 'group' => 'negative' ] + $shared;
	}

	/**
	 * @param array &$defaults
	 */
	public static function onUserGetDefaultOptions( array &$defaults ): void {
		$defaults['echo-subscriptions-web-wikioasissafety'] = true;
		$defaults['echo-subscriptions-email-wikioasissafety'] = true;
	}
}
