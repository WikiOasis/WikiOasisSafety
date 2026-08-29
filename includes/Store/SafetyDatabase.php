<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Store;

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

class SafetyDatabase {

	public const DOMAIN = 'virtual-wikioasissafety';

	public const SHARED_TABLES = [
		'wos_case', 'wos_comment', 'wos_action', 'wos_standing', 'wos_outbox',
	];

	public const LOCAL_TABLES = [];

	public static function primary(): IDatabase {
		return MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getPrimaryDatabase( self::DOMAIN );
	}

	public static function replica(): IReadableDatabase {
		return MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getReplicaDatabase( self::DOMAIN );
	}

	public static function localPrimary(): IDatabase {
		return MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getPrimaryDatabase();
	}

	public static function isSeparate(): bool {
		$mapping = MediaWikiServices::getInstance()->getMainConfig()
			->get( 'VirtualDomainsMapping' );

		return isset( $mapping[ self::DOMAIN ] );
	}

	public static function sharedDatabaseName(): ?string {
		$mapping = MediaWikiServices::getInstance()->getMainConfig()
			->get( 'VirtualDomainsMapping' );

		$db = $mapping[ self::DOMAIN ]['db'] ?? null;

		return is_string( $db ) && $db !== '' ? $db : null;
	}
}
