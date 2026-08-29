<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Enforcement;

use MediaWiki\Extension\WikiOasisSafety\Auth\CentralLock;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use Throwable;

class WikiActions {

	/**
	 * @param list<string> $wikis
	 * @return array<string, mixed>
	 */
	public function block(
		string $reference, string $username, int $centralId, string $reason, ?string $expiry, array $wikis
	): array {
		if ( $wikis === [] ) {
			return [ 'wikis' => [], 'error' => 'A block has to target at least one wiki' ];
		}

		return $this->queue( 'block', $reference, $username, $centralId, $reason, $expiry, $wikis );
	}

	/**
	 * @param list<string> $wikis
	 * @return array<string, mixed>
	 */
	public function unblock(
		string $reference, string $username, int $centralId, string $reason, array $wikis
	): array {
		return $this->queue( 'unblock', $reference, $username, $centralId, $reason, null, $wikis );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function deleteWiki( string $reference, array $wikis ): array {
		if ( $wikis === [] ) {
			return [ 'wikis' => [], 'error' => 'No wiki targeted.' ];
		}

		return $this->setDeleted( $reference, $wikis, true );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function undeleteWiki( string $reference, array $wikis ): array {
		if ( $wikis === [] ) {
			return [ 'wikis' => [], 'error' => 'No wiki targeted' ];
		}

		return $this->setDeleted( $reference, $wikis, false );
	}

	/**
	 * @param list<string> $wikis
	 * @return array<string, mixed>
	 */
	private function queue(
		string $task, string $reference, string $username, int $centralId,
		string $reason, ?string $expiry, array $wikis
	): array {
		$factory = MediaWikiServices::getInstance()->getJobQueueGroupFactory();
		$queued = [];
		$refused = [];

		$notifyWiki = $this->centralWikiId();

		foreach ( $wikis as $wiki ) {
			try {
				$factory->makeJobQueueGroup( $wiki )->push( new BlockJob( [
					'task' => $task,
					'reference' => $reference,
					'username' => $username,
					'central_id' => $centralId,
					'reason' => $reason,
					'expiry' => $expiry ?? 'infinity',
					'notify_wiki' => $notifyWiki,
				] ) );

				$queued[] = $wiki;
			} catch ( Throwable $e ) {
				$refused[ $wiki ] = $e->getMessage();
			}
		}

		return array_filter( [
			'wikis' => $queued,
			'refused' => $refused ?: null,
		], static fn ( $value ) => $value !== null );
	}

	private function centralWikiId(): ?string {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CreateWiki' ) ) {
			return null;
		}

		$services = MediaWikiServices::getInstance();

		foreach ( [ 'CreateWiki.DatabaseUtils', 'CreateWikiDatabaseUtils' ] as $name ) {
			if ( $services->hasService( $name ) ) {
				return $services->getService( $name )->getCentralWikiID();
			}
		}

		return null;
	}

	/**
	 * @param list<string> $wikis
	 * @return array<string, mixed>
	 */
	private function setDeleted( string $reference, array $wikis, bool $deleted ): array {
		$factory = $this->remoteWikiFactory();

		if ( $factory === null ) {
			return [
				'wikis' => [],
				'error' => 'CreateWiki is not available on this wiki',
			];
		}

		$done = [];
		$failed = [];

		foreach ( $wikis as $wiki ) {
			try {
				$remote = $factory->newInstance( $wiki );

				if ( $deleted ) {
					if ( $remote->isDeleted() ) {
						$done[] = $wiki;
						continue;
					}

					$remote->delete();
				} else {
					if ( !$remote->isDeleted() ) {
						$done[] = $wiki;
						continue;
					}

					$remote->undelete();
				}

				$remote->commit();

				$this->log( $wiki, $deleted, $reference );

				$done[] = $wiki;
			} catch ( Throwable $e ) {
				$failed[ $wiki ] = $e->getMessage();
			}
		}

		return array_filter( [
			'wikis' => $done,
			'failed' => $failed ?: null,
			'retry' => $failed !== [] ? true : null,
			'error' => $failed !== []
				? 'Could not change: ' . implode( ', ', array_keys( $failed ) )
				: null,
		], static fn ( $value ) => $value !== null );
	}

	/**
	 * @return object|null
	 */
	private function remoteWikiFactory() {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CreateWiki' ) ) {
			return null;
		}

		$services = MediaWikiServices::getInstance();

		foreach ( [ 'CreateWiki.RemoteWikiFactory', 'RemoteWikiFactory' ] as $name ) {
			if ( $services->hasService( $name ) ) {
				return $services->getService( $name );
			}
		}

		return null;
	}

	private function log( string $wiki, bool $deleted, string $reference ): void {
		try {
			$performer = User::newSystemUser( CentralLock::ACTOR, [ 'steal' => true ] );
			if ( !$performer ) {
				return;
			}

			$services = MediaWikiServices::getInstance();
			$title = $services->getTitleFactory()->newFromText( 'Special:CreateWiki/' . $wiki );

			$entry = new \ManualLogEntry( 'managewiki', $deleted ? 'delete' : 'undelete' );
			$entry->setPerformer( $performer );
			$entry->setTarget( $title );
			$entry->setComment( CentralLock::LOG_REASON );
			$entry->setParameters( [ '4::wiki' => $wiki, '5::reference' => $reference ] );

			$id = $entry->insert();
			$entry->publish( $id );
		} catch ( Throwable $e ) {
			wfLogWarning( 'WikiOasisSafety: could not log a wiki deletion: ' . $e->getMessage() );
		}
	}
}
