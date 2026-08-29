<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Auth;

use ManualLogEntry;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use Throwable;

final class CentralLock {

	public const LOCKED = 'locked';

	public const UNLOCKED = 'unlocked';

	public const UNAVAILABLE = 'unavailable';

	public const NO_ACCOUNT = 'no-account';

	public const NOT_REQUESTED = 'not-requested';

	public const FAILED = 'failed';

	public const ACTOR = 'Trust and Safety';

	public const LOG_REASON = 'Trust and Safety enforcement action: direct inquiries to safety@wikioasis.org';

	public static function isAvailable(): bool {
		return ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' );
	}

	/**
	 * @return array{state: string, error: ?string}
	 */
	public static function lock( string $username ): array {
		return self::set( $username, true );
	}

	/**
	 * @return array{state: string, error: ?string}
	 */
	public static function unlock( string $username ): array {
		return self::set( $username, false );
	}

	/** @return array{state: string, error: ?string} */
	private static function set( string $username, bool $locked ): array {
		if ( !self::isAvailable() ) {
			return [ 'state' => self::UNAVAILABLE, 'error' => 'CentralAuth is not installed on this wiki.' ];
		}

		try {
			$central = CentralAuthUser::getInstanceByName( $username );

			if ( !$central->exists() ) {
				return [
					'state' => self::NO_ACCOUNT,
					'error' => "There is no global account named $username.",
				];
			}

			if ( $central->isLocked() === $locked ) {
				return [ 'state' => $locked ? self::LOCKED : self::UNLOCKED, 'error' => null ];
			}

			$status = $locked ? $central->adminLock() : $central->adminUnlock();

			if ( !$status->isGood() ) {
				return [
					'state' => self::FAILED,
					'error' => self::describe( $status ),
				];
			}

			$central->invalidateCache();

			self::log( $username, $locked );

			return [ 'state' => $locked ? self::LOCKED : self::UNLOCKED, 'error' => null ];
		} catch ( Throwable $e ) {
			return [ 'state' => self::FAILED, 'error' => $e->getMessage() ];
		}
	}

	private static function log( string $username, bool $locked ): void {
		try {
			$performer = User::newSystemUser( self::ACTOR, [ 'steal' => true ] );

			if ( !$performer ) {
				return;
			}

			$entry = new ManualLogEntry( 'globalauth', 'setstatus' );
			$entry->setPerformer( $performer );
			$entry->setTarget( SpecialPage::getTitleFor( 'CentralAuth', $username ) );
			$entry->setComment( self::LOG_REASON );
			$entry->setParameters( [
				'added' => $locked ? [ 'locked' ] : [],
				'removed' => $locked ? [] : [ 'locked' ],
			] );

			$id = $entry->insert();
			$entry->publish( $id );
		} catch ( Throwable $e ) {
			wfLogWarning( 'WikiOasisSafety: could not log a central lock: ' . $e->getMessage() );
		}
	}

	private static function describe( $status ): string {
		if ( method_exists( $status, 'getMessages' ) ) {
			$keys = array_map(
				static fn ( $message ) => $message->getKey(),
				$status->getMessages()
			);

			if ( $keys !== [] ) {
				return implode( ', ', $keys );
			}
		}

		return 'CentralAuth refused the change.';
	}
}
