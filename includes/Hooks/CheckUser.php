<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Hooks;

use MediaWiki\Api\Hook\APIAfterExecuteHook;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\WikiOasisSafety\CheckUser\CheckLog;
use MediaWiki\Hook\ApiBeforeMainHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\SpecialPage\Hook\SpecialPageAfterExecuteHook;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use Throwable;

class CheckUser implements
	SpecialPageBeforeExecuteHook,
	SpecialPageAfterExecuteHook,
	ApiBeforeMainHook,
	APIAfterExecuteHook
{

	private const SURFACES = [ 'CheckUser', 'Investigate', 'InvestigateBlock' ];

	private static ?int $mark = null;

	/** @inheritDoc */
	public function onSpecialPageBeforeExecute( $special, $subPage ) {
		if ( in_array( $special->getName(), self::SURFACES, true ) ) {
			self::takeMark();
		}

		return true;
	}

	/** @inheritDoc */
	public function onSpecialPageAfterExecute( $special, $subPage ): void {
		if ( in_array( $special->getName(), self::SURFACES, true ) ) {
			self::sendWhatThisRequestLogged();
		}
	}

	/** @inheritDoc */
	public function onApiBeforeMain( &$main ) {
		if ( self::isCheckUserApiRequest( $main->getRequest()->getVal( 'list', '' ) ) ) {
			self::takeMark();
		}

		return true;
	}

	/** @inheritDoc */
	public function onAPIAfterExecute( $module ): void {
		if ( self::isCheckUserApiRequest( $module->getRequest()->getVal( 'list', '' ) ) ) {
			self::sendWhatThisRequestLogged();
		}
	}

	private static function isCheckUserApiRequest( $list ): bool {
		return is_string( $list ) && str_contains( $list, 'checkuser' );
	}

	private static function takeMark(): void {
		self::guard( static function () {
			$log = new CheckLog();

			if ( $log->isEnabled() ) {
				self::$mark = $log->highWaterMark();
			}
		} );
	}

	private static function sendWhatThisRequestLogged(): void {
		$mark = self::$mark;

		if ( $mark === null ) {
			return;
		}

		self::$mark = null;

		DeferredUpdates::addCallableUpdate( static function () use ( $mark ) {
			self::guard( static function () use ( $mark ) {
				$status = ( new CheckLog() )->sendSince( $mark );

				if ( !$status->isGood() ) {
					LoggerFactory::getInstance( 'WikiOasisSafety' )->warning(
						'Could not send the CheckUser log to TSPortal: {reason}',
						[ 'reason' => $status->getMessages()[0]->getKey() ]
					);
				}
			} );
		}, DeferredUpdates::POSTSEND );
	}

	private static function guard( callable $work ): void {
		try {
			$work();
		} catch ( Throwable $e ) {
			LoggerFactory::getInstance( 'WikiOasisSafety' )->error(
				'CheckUser reporting failed and was skipped: {message}',
				[ 'message' => $e->getMessage(), 'exception' => $e ]
			);
		}
	}
}
