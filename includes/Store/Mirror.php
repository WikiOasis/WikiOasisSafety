<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Store;

use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\Extension\WikiOasisSafety\Notifications\Notifier;
use MediaWiki\Logger\LoggerFactory;
use Wikimedia\Rdbms\IDatabase;

class Mirror {

	private IDatabase $db;
	private Notifier $notifier;

	public function __construct( ?IDatabase $db = null, ?Notifier $notifier = null ) {
		$this->db = $db ?? SafetyDatabase::primary();
		$this->notifier = $notifier ?? new Notifier();
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{reference: string, created: bool}
	 */
	public function upsertCase( array $payload ): array {
		$reference = (string)( $payload['reference'] ?? '' );
		if ( $reference === '' || empty( $payload['central_id'] ) ) {
			throw new \InvalidArgumentException( 'A case needs a reference and an account' );
		}

		$existing = $this->db->newSelectQueryBuilder()
			->select( [ 'wosc_id', 'wosc_status' ] )
			->from( 'wos_case' )
			->where( [ 'wosc_reference' => $reference ] )
			->caller( __METHOD__ )
			->fetchRow();

		$row = [
			'wosc_reference' => $reference,
			'wosc_type' => (string)( $payload['type'] ?? 'report' ),
			'wosc_central_id' => (int)$payload['central_id'],
			'wosc_user_name' => self::name( $payload['username'] ?? '' ),
			'wosc_subject' => mb_substr( (string)( $payload['subject'] ?? '' ), 0, 255 ),
			'wosc_summary' => $payload['summary'] ?? null,
			'wosc_status' => (string)( $payload['status'] ?? 'received' ),
			'wosc_about' => json_encode( $payload['about'] ?? [] ),
			'wosc_category' => isset( $payload['categories'] )
				? json_encode( $payload['categories'] )
				: null,
			'wosc_appeal' => isset( $payload['appeal'] ) && $payload['appeal'] !== null
				? json_encode( $payload['appeal'] )
				: null,
			'wosc_filed' => $this->timestamp( $payload['filed'] ?? null ),
			'wosc_updated' => $this->timestamp( $payload['updated'] ?? null ),
		];

		if ( $existing ) {
			$this->db->newUpdateQueryBuilder()
				->update( 'wos_case' )
				->set( $row )
				->where( [ 'wosc_id' => (int)$existing->wosc_id ] )
				->caller( __METHOD__ )
				->execute();
		} else {
			$this->db->newInsertQueryBuilder()
				->insertInto( 'wos_case' )
				->row( $row )
				->caller( __METHOD__ )
				->execute();
		}

		$statusChanged = $existing && (string)$existing->wosc_status !== $row['wosc_status'];
		if ( !empty( $payload['notify'] ) && ( !$existing || $statusChanged ) ) {
			$this->notifier->caseUpdated(
				(int)$payload['central_id'],
				$reference,
				$row['wosc_status'],
				$existing === false || $existing === null
			);
		}

		return [ 'reference' => $reference, 'created' => !$existing ];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param bool $local
	 * @return array{reference: string, created: bool}
	 */
	public function addComment( array $payload, bool $local = false ): array {
		$reference = (string)( $payload['reference'] ?? '' );
		$portalId = $local ? null : (int)( $payload['comment_id'] ?? 0 );

		$caseId = $this->db->newSelectQueryBuilder()
			->select( 'wosc_id' )
			->from( 'wos_case' )
			->where( [ 'wosc_reference' => $reference ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( $caseId === false ) {
			throw new \RuntimeException( "This wiki has no case $reference to comment on" );
		}

		$row = [
			'wosm_case' => (int)$caseId,
			'wosm_portal_id' => $portalId,
			'wosm_author' => (string)( $payload['author'] ?? 'staff' ),
			'wosm_author_name' => $payload['author_label'] ?? null,
			'wosm_text' => (string)( $payload['body'] ?? '' ),
			'wosm_date' => $this->timestamp( $payload['date'] ?? null ),
		];

		if ( $local ) {
			$this->db->newInsertQueryBuilder()
				->insertInto( 'wos_comment' )
				->row( $row )
				->caller( __METHOD__ )
				->execute();

			$created = true;
		} else {
			$this->db->newDeleteQueryBuilder()
				->deleteFrom( 'wos_comment' )
				->where( [
					'wosm_case' => (int)$caseId,
					'wosm_portal_id' => null,
					'wosm_author' => $row['wosm_author'],
					'wosm_text' => $row['wosm_text'],
				] )
				->caller( __METHOD__ )
				->execute();

			$this->db->newInsertQueryBuilder()
				->insertInto( 'wos_comment' )
				->row( $row )
				->onDuplicateKeyUpdate()
				->uniqueIndexFields( [ 'wosm_case', 'wosm_portal_id' ] )
				->set( [ 'wosm_text' => $row['wosm_text'], 'wosm_date' => $row['wosm_date'] ] )
				->caller( __METHOD__ )
				->execute();

			$created = $this->db->affectedRows() === 1;
		}

		$this->db->newUpdateQueryBuilder()
			->update( 'wos_case' )
			->set( [ 'wosc_updated' => $row['wosm_date'] ] )
			->where( [ 'wosc_id' => (int)$caseId ] )
			->caller( __METHOD__ )
			->execute();

		if ( !empty( $payload['notify'] ) && $created && !empty( $payload['central_id'] ) ) {
			$this->notifier->commentAdded(
				(int)$payload['central_id'],
				$reference,
				(string)( $payload['author_label'] ?? '' )
			);
		}

		return [ 'reference' => $reference, 'created' => $created ];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{reference: string}
	 */
	public function upsertAction( array $payload ): array {
		$reference = (string)( $payload['reference'] ?? '' );
		if ( $reference === '' ) {
			throw new \InvalidArgumentException( 'An action needs a reference.' );
		}

		$name = self::name( $payload['username'] ?? '' );

		$row = [
			'wosa_reference' => $reference,
			'wosa_central_id' => $this->centralIdFor( $payload, $name ),
			'wosa_user_name' => $name,
			'wosa_type' => (string)( $payload['type'] ?? '' ),
			'wosa_label' => (string)( $payload['label'] ?? $payload['type'] ?? '' ),
			'wosa_scope' => $payload['scope'] ?? null,
			'wosa_reason' => (string)( $payload['reason'] ?? '' ),
			'wosa_issued' => $this->timestamp( $payload['issued'] ?? null ),
			'wosa_expires' => isset( $payload['expires'] ) && $payload['expires'] !== null
				? $this->timestamp( $payload['expires'] )
				: null,
			'wosa_active' => !empty( $payload['active'] ) ? 1 : 0,
			'wosa_appealable' => !empty( $payload['appealable'] ) ? 1 : 0,
		];

		$existing = $this->db->newSelectQueryBuilder()
			->select( [ 'wosa_id', 'wosa_active' ] )
			->from( 'wos_action' )
			->where( [ 'wosa_reference' => $reference ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $existing ) {
			$this->db->newUpdateQueryBuilder()
				->update( 'wos_action' )
				->set( $row )
				->where( [ 'wosa_id' => (int)$existing->wosa_id ] )
				->caller( __METHOD__ )
				->execute();
		} else {
			$this->db->newInsertQueryBuilder()
				->insertInto( 'wos_action' )
				->row( $row )
				->caller( __METHOD__ )
				->execute();
		}

		$this->setStanding( [
			'username' => $row['wosa_user_name'],
			'central_id' => $row['wosa_central_id'],
			'standing' => (string)( $payload['standing'] ?? 'restricted' ),
			'banned' => !empty( $payload['banned'] ),
			'reference' => $reference,
			'reason' => $row['wosa_reason'],
			'expires' => $payload['expires'] ?? null,
			'appealable' => !empty( $payload['appealable'] ),
		] );

		$lifted = $existing && (bool)$existing->wosa_active && !$row['wosa_active'];

		if ( !empty( $payload['notify'] ) && ( !$existing || $lifted ) ) {
			if ( $row['wosa_central_id'] === 0 ) {
				LoggerFactory::getInstance( 'WikiOasisSafety' )->info(
					'No notification sent for {reference}: no central id for {username}',
					[
						'reference' => $reference,
						'username' => $row['wosa_user_name'],
						'type' => $row['wosa_type'],
					]
				);
			}

			$this->notifier->actionTaken(
				$row['wosa_central_id'],
				$reference,
				$row['wosa_label'],
				$lifted
			);
		}

		return [ 'reference' => $reference ];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function centralIdFor( array $payload, string $name ): int {
		$given = (int)( $payload['central_id'] ?? 0 );

		if ( $given !== 0 || $name === '' ) {
			return $given;
		}

		return Accounts::centralIdForName( $name ) ?? 0;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function setStanding( array $payload ): void {
		$name = self::name( $payload['username'] ?? '' );
		if ( $name === '' ) {
			return;
		}

		$banned = !empty( $payload['banned'] );

		$row = [
			'woss_user_name' => $name,
			'woss_central_id' => !empty( $payload['central_id'] ) ? (int)$payload['central_id'] : null,
			'woss_standing' => (string)( $payload['standing'] ?? 'good' ),
			'woss_banned' => $banned ? 1 : 0,
			'woss_reference' => $banned ? ( $payload['reference'] ?? null ) : null,
			'woss_reason' => $banned ? ( $payload['reason'] ?? null ) : null,
			'woss_expires' => $banned && !empty( $payload['expires'] )
				? $this->timestamp( $payload['expires'] )
				: null,
			'woss_appealable' => !empty( $payload['appealable'] ) ? 1 : 0,
			'woss_updated' => wfTimestampNow(),
		];

		$this->db->newInsertQueryBuilder()
			->insertInto( 'wos_standing' )
			->row( $row )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'woss_user_name' ] )
			->set( array_diff_key( $row, [ 'woss_user_name' => true ] ) )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param mixed $name
	 */
	private static function name( $name ): string {
		$raw = (string)$name;

		return Accounts::canonicalName( $raw ) ?? $raw;
	}

	private function timestamp( ?string $iso ): string {
		if ( $iso === null || $iso === '' ) {
			return wfTimestampNow();
		}
		$ts = wfTimestamp( TS_MW, $iso );

		return $ts !== false ? $ts : wfTimestampNow();
	}
}
