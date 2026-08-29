<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Hooks;

use MediaWiki\Extension\WikiOasisSafety\Store\SafetyDatabase;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * @codeCoverageIgnore Schema updates are exercised by running the updater
 */
class Schema implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__, 2 ) . '/sql';
		$db = $updater->getDB();
		$type = $db->getType();

		foreach ( SafetyDatabase::LOCAL_TABLES as $table ) {
			$updater->addExtensionTable( $table, "$dir/$type/tables-generated.sql" );
		}

		if ( !$this->shouldTouchSharedTables( $db->getDBname() ) ) {
			return;
		}

		foreach ( SafetyDatabase::SHARED_TABLES as $table ) {
			$updater->addExtensionTable( $table, "$dir/$type/tables-generated.sql" );
		}
	}

	private function shouldTouchSharedTables( string $updatingDatabase ): bool {
		$shared = SafetyDatabase::sharedDatabaseName();

		if ( $shared === null ) {
			return true;
		}

		return $shared === $updatingDatabase;
	}
}
