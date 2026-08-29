<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Hooks;

use MediaWiki\Extension\WikiOasisSafety\SafetyLinks;
use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Output\Hook\BeforePageDisplayHook;

class Main implements BeforePageDisplayHook, SidebarBeforeOutputHook {
	public static function onRegistration(): void {
		if ( !class_exists( \MediaWiki\Extension\Notifications\Model\Event::class ) ) {
			return;
		}

		global $wgHooks;

		$wgHooks['BeforeCreateEchoEvent'][] =
			'MediaWiki\\Extension\\WikiOasisSafety\\Hooks\\EchoNotifications::onBeforeCreateEchoEvent';
		$wgHooks['UserGetDefaultOptions'][] =
			'MediaWiki\\Extension\\WikiOasisSafety\\Hooks\\EchoNotifications::onUserGetDefaultOptions';
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( SafetyLinks::shouldShowLinks( $out->getContext() ) ) {
			$out->addHTML( '<div id="wikioasis-safety-app"></div>' );
		}
	}

	/** @inheritDoc */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		if ( !SafetyLinks::shouldShowLinks( $skin->getContext() ) ) {
			return;
		}
		SafetyLinks::addModules( $skin->getOutput() );

		$sidebar['TOOLBOX']['wikioasissafety-help'] = SafetyLinks::getHelpLink( $skin->getContext() );

		$title = $skin->getTitle();
		$target = $title ? SafetyLinks::getTargetUser( $title ) : null;
		if ( $target !== null ) {
			$sidebar['TOOLBOX']['wikioasissafety-reportuser'] =
				SafetyLinks::getReportUserLink( $skin->getContext(), $target );
		}
	}
}
