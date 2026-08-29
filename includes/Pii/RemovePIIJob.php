<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Pii;

use GenericParameterJob;
use Job;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use MWCryptRand;
use Throwable;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

class RemovePIIJob extends Job implements GenericParameterJob {

	private string $reference;
	private string $oldName;
	private string $newName;

	public function __construct( array $params ) {
		parent::__construct( 'WikiOasisSafetyRemovePII', $params );

		$this->reference = (string)( $params['reference'] ?? '' );
		$this->oldName = (string)( $params['oldname'] ?? '' );
		$this->newName = (string)( $params['newname'] ?? '' );

		$this->removeDuplicates = true;
	}

	/** @inheritDoc */
	public function run(): bool {
		try {
			$this->erase();
		} catch ( Throwable $e ) {
			$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );
			$this->report( false, $e->getMessage() );

			return false;
		}

		$this->report( true, null );

		return true;
	}

	private function erase(): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			throw new \RuntimeException( 'CentralAuth is not installed on this wiki.' );
		}

		$services = MediaWikiServices::getInstance();
		$userFactory = $services->getUserFactory();
		$lbFactory = $services->getDBLoadBalancerFactory();

		$newCentral = CentralAuthUser::getInstanceByName( $this->newName );
		$newCentral->invalidateCache();

		$newCentral->setPassword( MWCryptRand::generateHex( 32 ), true );

		foreach ( (array)$newCentral->getGlobalGroups() as $group ) {
			$newCentral->removeFromGlobalGroups( $group );
		}

		$oldUser = $userFactory->newFromName( $this->oldName );
		$newUser = $userFactory->newFromName( $this->newName );

		if ( !$oldUser || !$newUser ) {
			throw new \RuntimeException( 'The account names are not usable.' );
		}

		$dbw = $lbFactory->getMainLB()->getMaintenanceConnectionRef( DB_PRIMARY );
		$titleFactory = $services->getTitleFactory();
		$renameLogTitle = $titleFactory->newFromText( 'CentralAuth', NS_SPECIAL )
			->getSubpage( $newUser->getName() );

		$oldTitleKey = $oldUser->getTitleKey();
		$renameLogKey = $renameLogTitle->getDBkey();

		$this->deleteRenameLog( $dbw, $oldTitleKey, $renameLogKey );

		$userId = $newUser->getId();

		if ( $userId ) {
			$actorId = $newUser->getActorId( $dbw );

			$context = [
				'userId' => $userId,
				'actorId' => $actorId,
				'oldName' => $oldUser->getName(),
				'newName' => $newUser->getName(),
				'oldTitleKey' => $oldTitleKey,
				'renameLogKey' => $renameLogKey,
			];

			$this->apply( $dbw, $lbFactory, PiiTables::deletions( $context ), delete: true );
			$this->apply( $dbw, $lbFactory, PiiTables::updates( $context ), delete: false );

			$this->deleteUserPages( $oldUser, $dbw );

			$latest = $newUser->getInstanceForUpdate();

			if ( $latest !== null ) {
				if ( $latest->getEmail() ) {
					$latest->invalidateEmail();
				}
				if ( $latest->getRealName() ) {
					$latest->setRealName( '' );
				}
				$latest->saveSettings();
			}
		}

		$newCentral->adminLock();
		$newCentral->invalidateCache();

		$this->verify( $dbw, $oldTitleKey, $renameLogKey );
	}

	private function deleteRenameLog( IDatabase $dbw, string $oldTitleKey, string $renameLogKey ): void {
		foreach ( [ 'logging' => 'log', 'recentchanges' => 'rc' ] as $table => $prefix ) {
			if ( !$dbw->tableExists( $table, __METHOD__ ) ) {
				continue;
			}

			$typeColumn = $prefix === 'log' ? 'log_type' : 'rc_log_type';
			$actionColumn = $prefix === 'log' ? 'log_action' : 'rc_log_action';
			$titleColumn = $prefix === 'log' ? 'log_title' : 'rc_title';

			$dbw->newDeleteQueryBuilder()
				->deleteFrom( $table )
				->where( [
					$typeColumn => 'gblrename',
					$actionColumn => 'rename',
					$titleColumn => $renameLogKey,
				] )
				->caller( __METHOD__ )
				->execute();

			$dbw->newDeleteQueryBuilder()
				->deleteFrom( $table )
				->where( [
					$typeColumn => 'renameuser',
					$actionColumn => 'renameuser',
					$titleColumn => $oldTitleKey,
				] )
				->caller( __METHOD__ )
				->execute();
		}
	}

	private function verify( IDatabase $dbw, string $oldTitleKey, string $renameLogKey ): void {
		$survivors = [];

		foreach ( [
			[ 'gblrename', 'rename', $renameLogKey ],
			[ 'renameuser', 'renameuser', $oldTitleKey ],
		] as [ $type, $action, $title ] ) {
			$found = $dbw->newSelectQueryBuilder()
				->select( 'log_id' )
				->from( 'logging' )
				->where( [ 'log_type' => $type, 'log_action' => $action, 'log_title' => $title ] )
				->caller( __METHOD__ )
				->fetchField();

			if ( $found !== false ) {
				$survivors[] = "$type/$action";
			}
		}

		if ( $survivors !== [] ) {
			throw new \RuntimeException(
				'The rename is still logged on this wiki (' . implode( ', ', $survivors ) . '), '
				. 'which maps the new name back to the old one. This erasure is incomplete.'
			);
		}
	}

	/**
	 * @param array<string, list<array<string, mixed>>> $tables
	 */
	private function apply( IDatabase $dbw, $lbFactory, array $tables, bool $delete ): void {
		foreach ( $tables as $table => $operations ) {
			if ( !$dbw->tableExists( $table, __METHOD__ ) ) {
				continue;
			}

			foreach ( $operations as $operation ) {
				if ( empty( $operation['where'] ) ) {
					continue;
				}

				try {
					if ( $delete ) {
						$dbw->newDeleteQueryBuilder()
							->deleteFrom( $table )
							->where( $operation['where'] )
							->caller( __METHOD__ )
							->execute();
					} else {
						$dbw->newUpdateQueryBuilder()
							->update( $table )
							->set( $operation['fields'] )
							->where( $operation['where'] )
							->caller( __METHOD__ )
							->execute();
					}
				} catch ( DBQueryError $e ) {
					throw new \RuntimeException( "$table: " . $e->getMessage(), 0, $e );
				}

				$lbFactory->waitForReplication();
			}
		}
	}

	private function deleteUserPages( User $oldUser, IDatabase $dbw ): void {
		$services = MediaWikiServices::getInstance();
		$actor = User::newSystemUser( 'Trust and Safety', [ 'steal' => true ] )
			?? User::newSystemUser( 'MediaWiki default', [ 'steal' => true ] );

		if ( !$actor ) {
			throw new \RuntimeException( 'No system account is available to delete with.' );
		}

		$services->getUserGroupManager()->addUserToGroup( $actor, 'bot', null, true );

		$namespaces = PiiTables::userNamespaces();
		$key = $oldUser->getUserPage()->getDBkey();

		$titleFactory = $services->getTitleFactory();
		$wikiPageFactory = $services->getWikiPageFactory();
		$deletePageFactory = $services->getDeletePageFactory();

		$rows = $dbw->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title' ] )
			->from( 'page' )
			->where( [
				'page_namespace' => $namespaces,
				$this->titleMatches( $dbw, 'page_title', $key ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $rows as $row ) {
			$title = $titleFactory->newFromRow( $row );

			$status = $deletePageFactory
				->newDeletePage( $wikiPageFactory->newFromTitle( $title ), $actor )
				->setSuppress( true )
				->forceImmediate( true )
				->deleteUnsafe( $this->reference );

			if ( !$status->isOK() ) {
				throw new \RuntimeException( 'Could not delete ' . $title->getPrefixedText() );
			}
		}

		foreach ( [
			[ 'archive', 'ar_namespace', 'ar_title' ],
			[ 'logging', 'log_namespace', 'log_title' ],
			[ 'recentchanges', 'rc_namespace', 'rc_title' ],
		] as [ $table, $namespaceColumn, $titleColumn ] ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( $table )
				->where( [
					$namespaceColumn => $namespaces,
					$this->titleMatches( $dbw, $titleColumn, $key ),
				] )
				->caller( __METHOD__ )
				->execute();
		}
	}

	private function titleMatches( IDatabase $dbw, string $column, string $key ) {
		return $dbw->orExpr( [
			$dbw->expr( $column, IExpression::LIKE, new LikeValue( $key . '/', $dbw->anyString() ) ),
			$column => $key,
		] );
	}

	private function report( bool $ok, ?string $error ): void {
		if ( $this->reference === '' ) {
			return;
		}

		try {
			$this->client()->post(
				'/api/wiki/v1/removals/' . rawurlencode( $this->reference ) . '/progress',
				[
					'wiki' => WikiMap::getCurrentWikiId(),
					'ok' => $ok,
					'error' => $error,
				]
			);
		} catch ( Throwable $e ) {
			wfLogWarning( 'WikiOasisSafety: could not report an erasure to the portal: ' . $e->getMessage() );
		}
	}

	private function client(): PortalClient {
		return new PortalClient();
	}
}
