<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Enforcement;

use GenericParameterJob;
use Job;
use MediaWiki\Extension\WikiOasisSafety\Auth\CentralLock;
use MediaWiki\Extension\WikiOasisSafety\Notifications\NotifyActionTakenJob;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;
use Throwable;

class BlockJob extends Job implements GenericParameterJob {

	private const REASON = 'Trust and Safety enforcement action. Direct inquiries to safety@wikioasis.org.';

	public function __construct( array $params ) {
		parent::__construct( 'wikiOasisSafetyBlock', $params );
		$this->removeDuplicates = true;
	}

	/** @inheritDoc */
	public function run(): bool {
		try {
			$this->apply();
		} catch ( Throwable $e ) {
			$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );
			$this->report( false, $e->getMessage() );

			return false;
		}

		$this->report( true, null );

		return true;
	}

	/** @throws RuntimeException */
	private function apply(): void {
		$services = MediaWikiServices::getInstance();

		$target = $services->getUserFactory()->newFromName( (string)$this->params['username'] );
		if ( !$target ) {
			throw new RuntimeException( 'Not a usable account name' );
		}

		$performer = User::newSystemUser( CentralLock::ACTOR, [ 'steal' => true ] );
		if ( !$performer ) {
			throw new RuntimeException( 'No system account is available' );
		}

		$reason = self::REASON;

		$status = $this->params['task'] === 'unblock'
			? $services->getUnblockUserFactory()
				->newUnblockUser( $target, $performer, $reason )
				->unblock()
			: $services->getBlockUserFactory()
				->newBlockUser(
					$target,
					$performer,
					(string)( $this->params['expiry'] ?? 'infinity' ),
					$reason,
					[
						'isCreateAccountBlocked' => true,
						'isEmailBlocked' => true,
						'isUserTalkEditBlocked' => true,
						'isHardBlock' => true,
						'isAutoblocking' => true,
					]
				)
				->placeBlockUnsafe( /* reblock */ true );

		if ( !$status->isOK() ) {
			throw new RuntimeException( $this->describe( $status ) );
		}

		$this->notify();
	}

	private function notify(): void {
		$centralId = (int)( $this->params['central_id'] ?? 0 );
		$notifyWiki = $this->params['notify_wiki'] ?? null;

		if ( $centralId <= 0 || !is_string( $notifyWiki ) || $notifyWiki === '' ) {
			return;
		}

		try {
			MediaWikiServices::getInstance()->getJobQueueGroupFactory()
				->makeJobQueueGroup( $notifyWiki )
				->push( new NotifyActionTakenJob( [
					'central_id' => $centralId,
					'reference' => (string)( $this->params['reference'] ?? '' ),
					'label' => 'Block',
					'lifted' => $this->params['task'] === 'unblock',
				] ) );
		} catch ( Throwable $e ) {
			wfLogWarning( 'WikiOasisSafety: could not queue a block notification: ' . $e->getMessage() );
		}
	}

	private function report( bool $ok, ?string $error ): void {
		$reference = (string)( $this->params['reference'] ?? '' );
		if ( $reference === '' ) {
			return;
		}

		try {
			( new PortalClient() )->post(
				'/api/wiki/v1/actions/' . rawurlencode( $reference ) . '/progress',
				[
					'wiki' => WikiMap::getCurrentWikiId(),
					'ok' => $ok,
					'error' => $error,
				]
			);
		} catch ( Throwable $e ) {
			wfLogWarning( 'WikiOasisSafety: could not report a block to the portal: ' . $e->getMessage() );
		}
	}

	private function describe( $status ): string {
		if ( method_exists( $status, 'getMessages' ) ) {
			$keys = array_map(
				static fn ( $message ) => $message->getKey(),
				$status->getMessages()
			);

			if ( $keys !== [] ) {
				return 'Wiki refused the change: ' . implode( ', ', $keys );
			}
		}

		return 'Wiki refused the change (no reason)';
	}
}
