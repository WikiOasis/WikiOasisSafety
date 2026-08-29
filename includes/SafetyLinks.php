<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety;

use MediaWiki\Context\IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserRigorOptions;

class SafetyLinks {

	public const MODULE = 'ext.wikiOasisSafety';

	public const HOME_MODULE = 'ext.wikiOasisSafety.home';

	public const NOJS_MODULE = 'ext.wikiOasisSafety.nojs';

	public const LINK_CLASS = 'wikioasis-safety-link';

	public static function shouldShowLinks( IContextSource $context ): bool {
		if ( !$context->getConfig()->get( 'WikiOasisSafetyEnabled' ) ) {
			return false;
		}

		if ( !WizardDefinition::hasSteps( $context->getConfig() ) ) {
			return false;
		}
		$title = $context->getTitle();
		if ( !$title ) {
			return false;
		}

		return !$title->isSpecial( 'SafetyReport' ) && !$title->isSpecial( 'SafetyHome' );
	}

	public static function getTargetUser( Title $title ): ?string {
		if ( !in_array( $title->getNamespace(), [ NS_USER, NS_USER_TALK ], true ) ) {
			return null;
		}
		$root = $title->getRootText();
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$user = $userFactory->newFromName( $root, UserRigorOptions::RIGOR_VALID );
		return $user ? $user->getName() : null;
	}

	public static function addModules( OutputPage $out ): void {
		$out->addModules( self::MODULE );
		$out->addJsConfigVars( [
			'wgWikiOasisSafetyNoticeboardUrl' => self::getNoticeboardUrl( $out ),
		] );
	}

	public static function getNoticeboardUrl( IContextSource $context ): string {
		$configured = $context->getConfig()->get( 'WikiOasisSafetyNoticeboard' );
		$title = Title::newFromText( (string)$configured );
		return $title ? $title->getLocalURL() : '';
	}

	public static function getHelpLink( IContextSource $context, ?string $icon = 'flag' ): array {
		$link = [
			'class' => self::LINK_CLASS,
			'link-class' => self::LINK_CLASS,
			'text' => $context->msg( 'wikioasissafety-link-label' )->text(),
			'href' => self::getSpecialUrl( $context ),
		];
		if ( $icon !== null ) {
			$link['icon'] = $icon;
		}
		return $link;
	}

	public static function getReportUserLink(
		IContextSource $context,
		string $target,
		?string $icon = 'userAvatar'
	): array {
		$link = [
			'class' => self::LINK_CLASS,
			'link-class' => self::LINK_CLASS,
			'text' => $context->msg( 'wikioasissafety-link-report-user', $target )->text(),
			'href' => self::getSpecialUrl( $context, [ 'user' => $target ] ),
		];
		if ( $icon !== null ) {
			$link['icon'] = $icon;
		}
		return $link;
	}

	private static function getSpecialUrl( IContextSource $context, array $query = [] ): string {
		$title = $context->getTitle();
		if ( $title && !$title->isSpecialPage() ) {
			$query['page'] = $title->getPrefixedText();
		}
		return SpecialPage::getTitleFor( 'SafetyReport' )->getLocalURL( $query );
	}
}
