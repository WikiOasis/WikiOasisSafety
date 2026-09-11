<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Pii;

use MediaWiki\Extension\CentralAuth\CentralAuthDatabaseManager;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUser;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserDatabaseUpdates;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserStatus;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MWCryptRand;
use Throwable;

class RemovePII {

	private UserFactory $userFactory;
	private PortalClient $portal;

	public function __construct( ?UserFactory $userFactory = null, ?PortalClient $portal = null ) {
		$this->userFactory = $userFactory
			?? MediaWikiServices::getInstance()->getUserFactory();
		$this->portal = $portal ?? new PortalClient();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function rename( string $reference, string $oldName, string $newName ): array {
		if ( !self::isAvailable() ) {
			return [ 'error' => 'CentralAuth is not installed' ];
		}

		$authorised = $this->authorised( $reference, $oldName, $newName );
		if ( $authorised !== null ) {
			return $authorised;
		}

		$oldUser = $this->userFactory->newFromName( $oldName );
		$newUser = $this->userFactory->newFromName( $newName, UserFactory::RIGOR_CREATABLE );

		if ( !$oldUser || !$newUser ) {
			return [ 'error' => 'One of those is not a usable account name.' ];
		}

		$oldCentral = CentralAuthUser::getInstance( $oldUser );

		if ( !$oldCentral->exists() ) {
			return [ 'error' => "There is no global account named $oldName." ];
		}

		if ( $oldCentral->renameInProgress() ) {
			return [ 'queued' => true, 'already' => true ];
		}

		try {
			$services = MediaWikiServices::getInstance();
			$databases = $this->service( 'CentralAuthDatabaseManager' );
			$antiSpoof = $this->service( 'CentralAuthAntiSpoofManager' );

			if ( $databases === null ) {
				return [ 'error' => 'CentralAuth is installed but its database manager service could not be found.' ];
			}

			$rename = new GlobalRenameUser(
				$this->actor(),
				$oldUser,
				$oldCentral,
				$newUser,
				CentralAuthUser::getInstance( $newUser ),
				new GlobalRenameUserStatus( $databases, $newUser->getName() ),
				$services->getJobQueueGroupFactory(),
				new GlobalRenameUserDatabaseUpdates( $databases ),
				new RemovePIILogger( $this->actor() ),
				$antiSpoof
			);

			$status = $rename->rename( [
				'movepages' => false,
				'suppressredirects' => true,
				'reason' => $reference,
				'force' => true,
				'oldname' => $oldName,
				'newname' => $newName,
			] );

			if ( !$status->isGood() ) {
				return [ 'error' => 'CentralAuth refused the rename.' ];
			}

			return [ 'queued' => true ];
		} catch ( Throwable $e ) {
			return [ 'retry' => true, 'error' => $e->getMessage() ];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function renameStatus( string $newName ): array {
		if ( !self::isAvailable() ) {
			return [ 'complete' => false, 'error' => 'CentralAuth is not installed.' ];
		}

		try {
			$databases = $this->service( 'CentralAuthDatabaseManager' );

			if ( $databases === null ) {
				return [ 'complete' => false, 'error' => 'CentralAuth\'s database manager service '
					. 'could not be found.' ];
			}

			$statuses = ( new GlobalRenameUserStatus( $databases, $newName ) )->getStatuses();

			$outstanding = array_keys( array_filter(
				$statuses,
				static fn ( $state ) => $state !== 'done'
			) );

			return [
				'complete' => $outstanding === [],
				'outstanding' => array_values( $outstanding ),
			];
		} catch ( Throwable $e ) {
			return [ 'complete' => false, 'retry' => true, 'error' => $e->getMessage() ];
		}
	}

	/**
	 * @param list<string>|null $wikis
	 * @return array<string, mixed>
	 */
	public function scrub( string $reference, string $oldName, string $newName, ?array $wikis = null ): array {
		if ( !self::isAvailable() ) {
			return [ 'wikis' => [], 'error' => 'CentralAuth is not installed.' ];
		}

		$authorised = $this->authorised( $reference, $oldName, $newName );
		if ( $authorised !== null ) {
			return $authorised;
		}

		$newCentral = CentralAuthUser::getPrimaryInstanceByName( $newName );

		if ( !$newCentral->exists() ) {
			return [
				'wikis' => [],
				'retry' => true,
				'error' => "The rename has not finished yet: there is no global account named $newName.",
			];
		}

		if ( $newCentral->renameInProgress() ) {
			return [ 'wikis' => [], 'retry' => true, 'error' => 'The rename is still running.' ];
		}

		$attached = $newCentral->listAttached();
		$targets = $wikis === null ? $attached : array_values( array_intersect( $wikis, $attached ) );

		// The global account lives in one shared database, so it is erased here, once. Doing it
		// from the per-wiki jobs instead had every attached wiki write to the same globaluser row
		// at the same time, and the losers of that race failed with
		// "Record has changed since last read in table 'globaluser'".
		$failure = $this->eraseGlobalAccount( $newCentral );

		if ( $failure !== null ) {
			return [ 'wikis' => [], 'retry' => true, 'error' => $failure ];
		}

		$factory = MediaWikiServices::getInstance()->getJobQueueGroupFactory();

		foreach ( $targets as $wiki ) {
			$factory->makeJobQueueGroup( $wiki )->push( new RemovePIIJob( [
				'reference' => $reference,
				'oldname' => $oldName,
				'newname' => $newName,
			] ) );
		}

		return [ 'wikis' => $targets ];
	}

	/**
	 * Strip the global account's groups, scramble its password, clear its email and lock it.
	 *
	 * Every step writes to CentralAuth's shared database and every step is safe to repeat,
	 * so the whole task can be retried.
	 *
	 * @return string|null An error to report back, or null if the account was erased.
	 */
	private function eraseGlobalAccount( CentralAuthUser $central ): ?string {
		try {
			$locked = $central->isLocked();
			$groups = $central->getGlobalGroups();

			if ( $groups ) {
				$central->removeFromGlobalGroups( $groups );
			}

			$central->setPassword( MWCryptRand::generateHex( 32 ), true );

			$central->setEmail( '' );
			$central->setEmailAuthenticationTimestamp( null );
			$central->saveSettings();

			// saveSettings() only logs a warning when its CAS check on gu_cas_token fails, so a
			// concurrent write to the globaluser row leaves the address in place and says nothing.
			// Check it, because reporting a finished erasure while the email survives is the one
			// outcome this task must never produce. The lock below is verified for the same reason.
			if ( $central->getEmail() !== '' ) {
				return 'CentralAuth would not clear the global email address.';
			}

			if ( !$locked ) {
				$central->adminLock();
				$central->invalidateCache();

				if ( !$central->isLocked() ) {
					return 'CentralAuth would not lock the global account.';
				}
			}

			$central->invalidateCache();
		} catch ( Throwable $e ) {
			return $e->getMessage();
		}

		return null;
	}

	public static function isAvailable(): bool {
		return ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' );
	}

	/**
	 * @return object|null
	 */
	private function service( string $name ) {
		$services = MediaWikiServices::getInstance();

		foreach ( [ "CentralAuth.$name", $name ] as $candidate ) {
			if ( $services->hasService( $candidate ) ) {
				return $services->getService( $candidate );
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function authorised( string $reference, string $oldName, string $newName ): ?array {
		$confirmation = $this->portal->confirmRemoval( $reference, $oldName, $newName );

		if ( $confirmation === null ) {
			return [
				'retry' => true,
				'error' => 'The portal could not be reached to confirm this erasure.',
			];
		}

		if ( empty( $confirmation['match'] ) ) {
			return [ 'error' => "The portal has no erasure numbered $reference for that account." ];
		}

		return null;
	}

	private function actor(): User {
		return User::newSystemUser( 'Trust and Safety', [ 'steal' => true ] )
			?? User::newSystemUser( 'MediaWiki default', [ 'steal' => true ] );
	}
}
