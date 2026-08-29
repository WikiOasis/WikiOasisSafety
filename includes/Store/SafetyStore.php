<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Store;

use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IReadableDatabase;

class SafetyStore {

	private IReadableDatabase $db;

	public function __construct( ?IReadableDatabase $db = null ) {
		$this->db = $db ?? SafetyDatabase::replica();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function reportsFor( UserIdentity $user ): array {
		if ( !Accounts::isNamed( $user ) ) {
			return [];
		}

		$rows = $this->db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'wos_case' )
			->where( $this->identifies( 'wosc_central_id', 'wosc_user_name', $user ) )
			->orderBy( 'wosc_filed', 'DESC' )
			->limit( 100 )
			->caller( __METHOD__ )
			->fetchResultSet();

		$reports = [];
		$ids = [];
		foreach ( $rows as $row ) {
			$reports[ (int)$row->wosc_id ] = $this->caseFromRow( $row );
			$ids[] = (int)$row->wosc_id;
		}

		if ( $ids === [] ) {
			return [];
		}

		$comments = $this->db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'wos_comment' )
			->where( [ 'wosm_case' => $ids ] )
			->orderBy( 'wosm_date' )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $comments as $comment ) {
			$reports[ (int)$comment->wosm_case ]['comments'][] = [
				'author' => (string)$comment->wosm_author,
				'name' => $comment->wosm_author_name !== null ? (string)$comment->wosm_author_name : null,
				'date' => wfTimestamp( TS_ISO_8601, $comment->wosm_date ),
				'text' => (string)$comment->wosm_text,
			];
		}

		return array_values( $reports );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function reportFor( UserIdentity $user, string $reference ): ?array {
		foreach ( $this->reportsFor( $user ) as $report ) {
			if ( ( $report['id'] ?? '' ) === $reference ) {
				return $report;
			}
		}
		return null;
	}

	/**
	 * @return array{standing: string, registered: ?string, actions: array}
	 */
	public function accountFor( UserIdentity $user ): array {
		$registered = MediaWikiServices::getInstance()->getUserFactory()
			->newFromUserIdentity( $user )->getRegistration();

		$standing = Accounts::isNamed( $user )
			? $this->db->newSelectQueryBuilder()
				->select( 'woss_standing' )
				->from( 'wos_standing' )
				->where( [ 'woss_user_name' => $user->getName() ] )
				->caller( __METHOD__ )
				->fetchField()
			: false;

		return [
			'standing' => $standing !== false ? (string)$standing : 'good',
			'registered' => $registered ? wfTimestamp( TS_ISO_8601, $registered ) : null,
			'actions' => $this->actionsFor( $user ),
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function actionsFor( UserIdentity $user ): array {
		if ( !Accounts::isNamed( $user ) ) {
			return [];
		}

		$rows = $this->db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'wos_action' )
			->where( $this->identifies( 'wosa_central_id', 'wosa_user_name', $user ) )
			->orderBy( 'wosa_issued', 'DESC' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$actions = [];
		foreach ( $rows as $row ) {
			$expires = $row->wosa_expires !== null ? wfTimestamp( TS_ISO_8601, $row->wosa_expires ) : null;

			$active = (bool)$row->wosa_active
				&& ( $row->wosa_expires === null || wfTimestamp( TS_MW, $row->wosa_expires ) > wfTimestampNow() );

			$actions[] = [
				'id' => (string)$row->wosa_reference,
				'type' => (string)$row->wosa_label,
				'scope' => $row->wosa_scope !== null ? (string)$row->wosa_scope : null,
				'issued' => wfTimestamp( TS_ISO_8601, $row->wosa_issued ),
				'expires' => $expires,
				'active' => $active,
				'reason' => (string)$row->wosa_reason,
				'appealable' => (bool)$row->wosa_appealable && $active,
			];
		}

		return $actions;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function appealableFor( UserIdentity $user ): array {
		return array_values( array_filter(
			$this->actionsFor( $user ),
			static fn ( array $action ): bool => !empty( $action['appealable'] )
		) );
	}

	/**
	 * @return array{banned: bool, reference: ?string, reason: ?string, expires: ?string, appealable: bool}|null
	 */
	public function suspension( string $userName ): ?array {
		$canonical = Accounts::canonicalName( $userName );

		if ( $canonical === null ) {
			return null;
		}

		$row = $this->db->newSelectQueryBuilder()
			->select( [ 'woss_banned', 'woss_reference', 'woss_reason', 'woss_expires', 'woss_appealable' ] )
			->from( 'wos_standing' )
			->where( [ 'woss_user_name' => $canonical ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row || !$row->woss_banned ) {
			return null;
		}

		if ( $row->woss_expires !== null && wfTimestamp( TS_MW, $row->woss_expires ) <= wfTimestampNow() ) {
			return null;
		}

		return [
			'banned' => true,
			'reference' => $row->woss_reference !== null ? (string)$row->woss_reference : null,
			'reason' => $row->woss_reason !== null ? (string)$row->woss_reason : null,
			'expires' => $row->woss_expires !== null ? wfTimestamp( TS_ISO_8601, $row->woss_expires ) : null,
			'appealable' => (bool)$row->woss_appealable,
		];
	}

	public function hasAnonymousReports( UserIdentity $user ): bool {
		return false;
	}

	/** @return array<string, mixed> */
	private function caseFromRow( \stdClass $row ): array {
		$about = $row->wosc_about !== null ? json_decode( (string)$row->wosc_about, true ) : [];
		$categories = isset( $row->wosc_category ) && $row->wosc_category !== null
			? json_decode( (string)$row->wosc_category, true )
			: [];
		$appeal = isset( $row->wosc_appeal ) && $row->wosc_appeal !== null
			? json_decode( (string)$row->wosc_appeal, true )
			: null;

		return [
			'id' => (string)$row->wosc_reference,
			'type' => (string)$row->wosc_type,
			'subject' => (string)$row->wosc_subject,
			'filed' => wfTimestamp( TS_ISO_8601, $row->wosc_filed ),
			'updated' => wfTimestamp( TS_ISO_8601, $row->wosc_updated ),
			'status' => (string)$row->wosc_status,
			'anonymous' => false,
			'about' => is_array( $about ) ? $about : [],
			'categories' => is_array( $categories ) ? $categories : [],
			'appeal' => is_array( $appeal ) ? $appeal : null,
			'summary' => $row->wosc_summary !== null ? (string)$row->wosc_summary : null,
			'comments' => [],
		];
	}

	private function centralId( UserIdentity $user ): int {
		return Accounts::centralId( $user ) ?? 0;
	}

	/**
	 * @return array<int, mixed>
	 */
	private function identifies( string $idColumn, string $nameColumn, UserIdentity $user ): array {
		$central = $this->centralId( $user );

		if ( $central === 0 ) {
			return [ $nameColumn => $user->getName() ];
		}

		return [
			$this->db->orExpr( [
				$idColumn => $central,
				$nameColumn => $user->getName(),
			] ),
		];
	}
}
