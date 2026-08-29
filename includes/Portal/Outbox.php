<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Portal;

use MediaWiki\Extension\WikiOasisSafety\Store\SafetyDatabase;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IDatabase;

class Outbox {

	private const MAX_ATTEMPTS = 12;

	private IDatabase $db;

	private const LOCK = 'wikioasissafety-outbox-drain';

	private string $wiki;

	public function __construct( ?IDatabase $db = null, ?string $wiki = null ) {
		$this->db = $db ?? SafetyDatabase::primary();
		$this->wiki = $wiki ?? WikiMap::getCurrentWikiId();
	}

	/** @param array<string, mixed> $payload */
	public function enqueue( string $path, array $payload ): void {
		$this->db->newInsertQueryBuilder()
			->insertInto( 'wos_outbox' )
			->row( [
				'woso_wiki' => $this->wiki,
				'woso_action' => mb_substr( $path, 0, 32 ),
				'woso_payload' => json_encode( [ 'path' => $path, 'body' => $payload ] ),
				'woso_attempts' => 0,
				'woso_created' => wfTimestampNow(),
				'woso_next' => wfTimestampNow(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @return array{sent: int, failed: int, locked: bool}
	 */
	public function drain( int $limit = 100, ?PortalClient $client = null ): array {
		$client ??= new PortalClient();

		if ( !$this->db->lock( self::LOCK, __METHOD__, 0 ) ) {
			return [ 'sent' => 0, 'failed' => 0, 'locked' => true ];
		}

		try {
			return $this->drainLocked( $limit, $client ) + [ 'locked' => false ];
		} finally {
			$this->db->unlock( self::LOCK, __METHOD__ );
		}
	}

	/**
	 * @return array{sent: int, failed: int}
	 */
	private function drainLocked( int $limit, PortalClient $client ): array {
		$rows = $this->db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'wos_outbox' )
			->where( $this->db->expr( 'woso_next', '<=', wfTimestampNow() ) )
			->orderBy( 'woso_id' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$sent = 0;
		$failed = 0;

		foreach ( $rows as $row ) {
			$queued = json_decode( (string)$row->woso_payload, true );
			if ( !is_array( $queued ) || !isset( $queued['path'] ) ) {
				$this->delete( (int)$row->woso_id );
				continue;
			}

			$status = $client->post(
				(string)$queued['path'],
				(array)( $queued['body'] ?? [] ),
				$this->wikiOf( $row )
			);

			if ( $status->isGood() || $status->hasMessage( 'wikioasissafety-portal-refused' ) ) {
				$this->delete( (int)$row->woso_id );
				$sent++;
				continue;
			}

			$this->backOff( $row );
			$failed++;

			if ( $failed >= 3 ) {
				break;
			}
		}

		return [ 'sent' => $sent, 'failed' => $failed ];
	}

	private function wikiOf( \stdClass $row ): string {
		$wiki = (string)( $row->woso_wiki ?? '' );

		return $wiki !== '' ? $wiki : $this->wiki;
	}

	public function counts(): array {
		return [
			'waiting' => (int)$this->db->newSelectQueryBuilder()
				->select( 'COUNT(*)' )->from( 'wos_outbox' )
				->caller( __METHOD__ )->fetchField(),
			'stuck' => (int)$this->db->newSelectQueryBuilder()
				->select( 'COUNT(*)' )->from( 'wos_outbox' )
				->where( [ 'woso_next' => null ] )
				->caller( __METHOD__ )->fetchField(),
		];
	}

	private function delete( int $id ): void {
		$this->db->newDeleteQueryBuilder()
			->deleteFrom( 'wos_outbox' )
			->where( [ 'woso_id' => $id ] )
			->caller( __METHOD__ )
			->execute();
	}

	private function backOff( \stdClass $row ): void {
		$attempts = (int)$row->woso_attempts + 1;
		$next = $attempts >= self::MAX_ATTEMPTS
			? null
			: wfTimestamp( TS_MW, (int)wfTimestamp( TS_UNIX ) + min( 3600, 30 * ( 2 ** min( $attempts, 7 ) ) ) );

		$this->db->newUpdateQueryBuilder()
			->update( 'wos_outbox' )
			->set( [
				'woso_attempts' => $attempts,
				'woso_next' => $next,
				'woso_error' => 'The portal could not be reached.',
			] )
			->where( [ 'woso_id' => (int)$row->woso_id ] )
			->caller( __METHOD__ )
			->execute();
	}
}
