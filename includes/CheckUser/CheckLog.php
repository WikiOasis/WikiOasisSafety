<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\CheckUser;

use MediaWiki\Config\Config;
use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use StatusValue;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

class CheckLog {

	public const TARGETS_ACCOUNTS = 'accounts';

	public const TARGETS_ALL = 'all';

	public const TARGETS_NONE = 'none';

	public const TARGET_MODES = [ self::TARGETS_ACCOUNTS, self::TARGETS_ALL, self::TARGETS_NONE ];

	private Config $config;
	private IReadableDatabase $db;
	private string $wiki;

	public function __construct(
		?Config $config = null,
		?IReadableDatabase $db = null,
		?string $wiki = null
	) {
		$services = MediaWikiServices::getInstance();

		$this->config = $config ?? $services->getMainConfig();
		$this->db = $db ?? $services->getConnectionProvider()->getReplicaDatabase();
		$this->wiki = $wiki ?? WikiMap::getCurrentWikiId();
	}

	public function isAvailable(): bool {
		return $this->db instanceof IMaintainableDatabase
			? $this->db->tableExists( 'cu_log', __METHOD__ )
			: true;
	}

	public function isEnabled(): bool {
		return (bool)$this->config->get( 'WikiOasisSafetyCheckUserTracking' );
	}

	/**
	 * @param int $after the last id already delivered.
	 * @param int $limit
	 * @return list<array<string, mixed>>
	 */
	public function since( int $after, int $limit = 500 ): array {
		$modern = $this->hasActorColumn();

		$builder = $this->db->newSelectQueryBuilder()
			->from( 'cu_log' )
			->where( $this->db->expr( 'cul_id', '>', $after ) )
			->orderBy( 'cul_id' )
			->limit( $limit )
			->caller( __METHOD__ );

		$builder->fields( [
			'cul_id',
			'cul_timestamp',
			'cul_type',
			'cul_target_id',
			'cul_target_text',
		] );

		if ( $modern ) {
			$builder->join( 'actor', null, 'actor_id = cul_actor' );
			$builder->fields( [ 'checker_name' => 'actor_name', 'checker_id' => 'actor_user' ] );

			$comment = MediaWikiServices::getInstance()->getCommentStore()->getJoin( 'cul_reason' );
			$builder->tables( $comment['tables'] );
			$builder->fields( $comment['fields'] );
			$builder->joinConds( $comment['joins'] );
		} else {
			$builder->fields( [
				'checker_name' => 'cul_user_text',
				'checker_id' => 'cul_user',
				'cul_reason',
			] );
		}

		$checks = [];

		foreach ( $builder->fetchResultSet() as $row ) {
			$checks[] = $this->present( $row, $modern );
		}

		return $checks;
	}

	public function highWaterMark(): int {
		if ( !$this->isEnabled() || !$this->isAvailable() ) {
			return 0;
		}

		$max = $this->db->newSelectQueryBuilder()
			->select( 'MAX(cul_id)' )
			->from( 'cu_log' )
			->caller( __METHOD__ )
			->fetchField();

		return $max === false || $max === null ? 0 : (int)$max;
	}

	/**
	 * @param int $after Exclusive.
	 * @return StatusValue Good with [ 'sent' => int, 'queued' => bool ]
	 */
	public function sendSince( int $after, ?PortalClient $client = null ): StatusValue {
		if ( !$this->isEnabled() ) {
			return StatusValue::newGood( [ 'sent' => 0, 'queued' => false ] );
		}

		if ( !$this->isAvailable() ) {
			return StatusValue::newFatal( 'wikioasissafety-checkuser-nolog' );
		}

		$checks = $this->since( $after, $this->batchSize() );

		if ( $checks === [] ) {
			return StatusValue::newGood( [ 'sent' => 0, 'queued' => false ] );
		}

		$client ??= new PortalClient();

		$status = $client->checks( [
			'wiki' => $this->wiki,
			'targets' => $this->targetMode(),
			'checks' => $checks,
		] );

		if ( !$status->isGood() ) {
			return $status;
		}

		return StatusValue::newGood( [
			'sent' => count( $checks ),
			'queued' => (bool)( $status->getValue()['queued'] ?? false ),
		] );
	}

	public function batchSize(): int {
		$configured = (int)$this->config->get( 'WikiOasisSafetyCheckUserBatch' );
		return max( 1, min( 1000, $configured ?: 500 ) );
	}

	/**
	 * @param \stdClass $row
	 * @param bool $modern
	 * @return array<string, mixed>
	 */
	private function present( \stdClass $row, bool $modern ): array {
		$name = (string)( $row->checker_name ?? '' );
		$localId = (int)( $row->checker_id ?? 0 );

		$reason = $modern
			? MediaWikiServices::getInstance()->getCommentStore()
				->getComment( 'cul_reason', $row )->text
			: (string)( $row->cul_reason ?? '' );

		return [
			'log_id' => (int)$row->cul_id,
			'wiki' => $this->wiki,
			'checked_at' => wfTimestamp( TS_ISO_8601, $row->cul_timestamp ),
			'checker' => [
				'username' => $name,
				'central_id' => $localId > 0
					? Accounts::centralId( UserIdentityValue::newRegistered( $localId, $name ) )
					: null,
			],
			'type' => (string)$row->cul_type,
			'target' => $this->target( $row ),
			'reason' => $reason,
			'reason_given' => trim( $reason ) !== '',
		];
	}

	/**
	 * @param \stdClass $row
	 * @return array{kind: string, name: ?string, fingerprint: ?string}
	 */
	private function target( \stdClass $row ): array {
		$text = (string)( $row->cul_target_text ?? '' );
		$kind = $this->targetKind( (int)( $row->cul_target_id ?? 0 ), $text );
		$mode = $this->targetMode();

		if ( $mode === self::TARGETS_NONE ) {
			return [ 'kind' => $kind, 'name' => null, 'fingerprint' => null ];
		}

		if ( $mode === self::TARGETS_ALL || $kind === 'account' ) {
			return [
				'kind' => $kind,
				'name' => $text !== '' ? $text : null,
				'fingerprint' => $this->fingerprint( $text ),
			];
		}

		return [ 'kind' => $kind, 'name' => null, 'fingerprint' => $this->fingerprint( $text ) ];
	}

	private function targetKind( int $targetId, string $text ): string {
		if ( $targetId > 0 ) {
			return 'account';
		}

		if ( $text === '' ) {
			return 'unknown';
		}

		if ( IPUtils::isValidRange( $text ) ) {
			return 'range';
		}

		if ( IPUtils::isIPAddress( $text ) ) {
			return 'ip';
		}

		return 'name';
	}

	private function fingerprint( string $text ): ?string {
		if ( $text === '' ) {
			return null;
		}

		$secret = (string)$this->config->get( 'WikiOasisSafetyPortalSecret' );
		if ( $secret === '' ) {
			return null;
		}

		return substr( hash_hmac( 'sha256', 'checkuser-target|' . strtolower( $text ), $secret ), 0, 16 );
	}

	private function targetMode(): string {
		$mode = (string)$this->config->get( 'WikiOasisSafetyCheckUserTargets' );

		return in_array( $mode, self::TARGET_MODES, true ) ? $mode : self::TARGETS_ACCOUNTS;
	}

	private function hasActorColumn(): bool {
		if ( $this->db instanceof IMaintainableDatabase ) {
			return $this->db->fieldExists( 'cu_log', 'cul_actor', __METHOD__ );
		}

		return true;
	}
}
