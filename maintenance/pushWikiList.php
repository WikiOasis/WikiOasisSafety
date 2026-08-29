<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Maintenance;

use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class PushWikiList extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Send the portal this farm\'s list of wikis.' );
		$this->addOption( 'dry-run', 'Print what would be sent and send nothing.' );
		$this->requireExtension( 'WikiOasisSafety' );
	}

	public function execute() {
		$client = new PortalClient();

		if ( !$client->isConfigured() ) {
			$this->fatalError(
				'No portal is configured, so there is nowhere to send this. '
				. 'Set $wgWikiOasisSafetyPortalUrl and $wgWikiOasisSafetyPortalSecret.'
			);
		}

		$wikis = $this->collect();

		if ( $wikis === [] ) {
			$this->fatalError(
				'No wikis found'
			);
		}

		$this->output( count( $wikis ) . " wiki(s) found.\n" );

		if ( $this->hasOption( 'dry-run' ) ) {
			foreach ( $wikis as $wiki ) {
				$this->output( sprintf(
					"  %-32s %s%s\n",
					$wiki['dbname'],
					$wiki['sitename'] ?? '',
					$wiki['deleted'] ? ' (deleted)' : ( $wiki['closed'] ? ' (closed)' : '' )
				) );
			}
			return;
		}

		$status = $client->post( '/api/wiki/v1/wikis', [ 'wikis' => $wikis ] );

		if ( !$status->isGood() ) {
			$this->fatalError( 'The portal refused the list, or could not be reached.' );
		}

		$this->output( "Sent.\n" );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function collect(): array {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CreateWiki' ) ) {
			return [];
		}

		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getReplicaDatabase( 'virtual-createwiki' );

		$rows = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cw_wikis' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$wikis = [];

		foreach ( $rows as $row ) {
			$wikis[] = [
				'dbname' => (string)$row->wiki_dbname,
				'sitename' => isset( $row->wiki_sitename ) ? (string)$row->wiki_sitename : null,
				'language' => isset( $row->wiki_language ) ? (string)$row->wiki_language : null,
				'url' => isset( $row->wiki_url ) ? (string)$row->wiki_url : null,
				'closed' => !empty( $row->wiki_closed ),
				'private' => !empty( $row->wiki_private ),
				'deleted' => !empty( $row->wiki_deleted ),
				'locked' => !empty( $row->wiki_locked ),
			];
		}

		return $wikis;
	}
}

$maintClass = PushWikiList::class;
require_once RUN_MAINTENANCE_IF_MAIN;
