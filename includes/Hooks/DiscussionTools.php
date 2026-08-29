<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Hooks;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\DiscussionTools\Hooks\DiscussionToolsAddOverflowMenuItemsHook;
use MediaWiki\Extension\DiscussionTools\OverflowMenuItem;
use MediaWiki\Extension\WikiOasisSafety\SafetyLinks;

/**
 * Adds "Report this comment" to the overflow (ellipsis) menu DiscussionTools
 * renders next to each comment and topic heading
 */
class DiscussionTools implements DiscussionToolsAddOverflowMenuItemsHook {

	/** @inheritDoc */
	public function onDiscussionToolsAddOverflowMenuItems(
		array &$overflowMenuItems,
		array &$resourceLoaderModules,
		array $threadItemData,
		IContextSource $contextSource
	) {
		if ( !SafetyLinks::shouldShowLinks( $contextSource ) ) {
			return;
		}

		if ( ( $threadItemData['type'] ?? null ) !== 'comment' ) {
			return;
		}

		$overflowMenuItems[] = new OverflowMenuItem(
			'wikioasissafety',
			'flag',
			'wikioasissafety-dt-menu-item',
			0,
			[
				'thread-id' => $threadItemData['id'] ?? null,
				'author' => $threadItemData['author'] ?? null,
			]
		);
		$resourceLoaderModules[] = SafetyLinks::MODULE;
		$resourceLoaderModules[] = 'oojs-ui.styles.icons-moderation';
		$contextSource->getOutput()->addJsConfigVars( [
			'wgWikiOasisSafetyNoticeboardUrl' => SafetyLinks::getNoticeboardUrl( $contextSource ),
		] );
	}
}
