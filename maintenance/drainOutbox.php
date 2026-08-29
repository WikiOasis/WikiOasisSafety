<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Extension\WikiOasisSafety\Portal\Outbox;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\Extension\WikiOasisSafety\Store\SafetyDatabase;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

class DrainOutbox extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Deliver queued Trust & Safety activity to TSPortal' );
		$this->addOption( 'limit', 'How many to attempt in one pass', false, true );
		$this->addOption( 'retry-stuck', 'Also retry rows that have exhausted their attempts' );
		$this->requireExtension( 'WikiOasisSafety' );
	}

	public function execute() {
		$client = new PortalClient();

		if ( !$client->isConfigured() ) {
			$this->output( "No portal configured: set \$wgWikiOasisSafetyPortalUrl and "
				. "\$wgWikiOasisSafetyPortalSecret.\n" );
			return;
		}

		$outbox = new Outbox();

		if ( $this->hasOption( 'retry-stuck' ) ) {
			SafetyDatabase::primary()->newUpdateQueryBuilder()
				->update( 'wos_outbox' )
				->set( [ 'woso_attempts' => 0, 'woso_next' => wfTimestampNow() ] )
				->where( [ 'woso_next' => null ] )
				->caller( __METHOD__ )
				->execute();
		}

		$result = $outbox->drain( (int)$this->getOption( 'limit', 100 ), $client );

		if ( !empty( $result['locked'] ) ) {
			$this->output( "Another queue drain is ongoing\n" );
			return;
		}

		$counts = $outbox->counts();

		$this->output( sprintf(
			"Sent %d, failed %d. %d waiting, %d abandoned.\n",
			$result['sent'], $result['failed'], $counts['waiting'], $counts['stuck']
		) );
	}
}

$maintClass = DrainOutbox::class;
require_once RUN_MAINTENANCE_IF_MAIN;
