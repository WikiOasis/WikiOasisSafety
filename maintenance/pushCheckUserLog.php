<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Maintenance;

use MediaWiki\Extension\WikiOasisSafety\CheckUser\CheckLog;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
require_once "$IP/maintenance/Maintenance.php";

class PushCheckUserLog extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Backfill or inspect the CheckUser log sent to TSPortal.'
		);
		$this->addOption(
			'since',
			'Send everything above this cu_log id. Defaults to 0',
			false,
			true
		);
		$this->addOption( 'limit', 'How many checks to send in one batch', false, true );
		$this->addOption(
			'max-batches',
			'Stop after this many batches (default: keep going until the log runs out)',
			false,
			true
		);
		$this->addOption( 'dry-run', 'Read and report one batch. Send nothing.' );
		$this->requireExtension( 'WikiOasisSafety' );
	}

	public function execute() {
		$log = new CheckLog();

		if ( !$log->isEnabled() ) {
			$this->output( "CheckUser tracking is off. Set \$wgWikiOasisSafetyCheckUserTracking "
				. "to true to turn it on.\n" );
			return;
		}

		if ( !$log->isAvailable() ) {
			$this->output( "This wiki has no cu_log table" );
			return;
		}

		$client = new PortalClient();
		if ( !$client->isConfigured() ) {
			$this->fatalError( 'No portal configured: set $wgWikiOasisSafetyPortalUrl and '
				. '$wgWikiOasisSafetyPortalSecret.' );
		}

		$batch = (int)$this->getOption( 'limit', 0 ) ?: $log->batchSize();
		$after = (int)$this->getOption( 'since', 0 );

		if ( $this->hasOption( 'dry-run' ) ) {
			$this->dryRun( $log, $after, $batch );
			return;
		}

		$maxBatches = (int)$this->getOption( 'max-batches', 0 );
		$batches = 0;
		$total = 0;

		while ( true ) {
			$checks = $log->since( $after, $batch );

			if ( $checks === [] ) {
				break;
			}

			$status = $log->sendSince( $after, $client );

			if ( !$status->isGood() ) {
				$this->fatalError( 'The portal would not take the checks: '
					. $status->getMessages()[0]->getKey()
					. ( $total > 0 ? sprintf( ' (%d sent before this)', $total ) : '' ) );
			}

			$sent = $status->getValue()['sent'];
			$total += $sent;
			$batches++;

			$after = (int)$checks[ count( $checks ) - 1 ]['log_id'];

			$this->output( sprintf( "Sent %d, through cu_log id %d.\n", $sent, $after ) );

			if ( $sent < $batch || ( $maxBatches > 0 && $batches >= $maxBatches ) ) {
				break;
			}
		}

		$this->output( $total === 0
			? "Nothing to send.\n"
			: sprintf( "%d check%s sent in %d batch%s.\n",
				$total, $total === 1 ? '' : 's', $batches, $batches === 1 ? '' : 'es' ) );
	}

	private function dryRun( CheckLog $log, int $after, int $limit ): void {
		$checks = $log->since( $after, $limit );

		if ( $checks === [] ) {
			$this->output( "Nothing to send.\n" );
			return;
		}

		foreach ( $checks as $check ) {
			$this->output( sprintf(
				"%-8d %s  %-16s %-12s %-24s %s\n",
				$check['log_id'],
				$check['checked_at'],
				substr( $check['checker']['username'], 0, 16 ),
				$check['type'],
				$check['target']['name'] ?? ( $check['target']['kind'] . ':'
					. ( $check['target']['fingerprint'] ?? '—' ) ),
				$check['reason_given'] ? $check['reason'] : '(no reason given)'
			) );
		}

		$this->output( sprintf(
			"\n%d check%s would be sent. Nothing was sent.\n",
			count( $checks ),
			count( $checks ) === 1 ? '' : 's'
		) );
	}
}

$maintClass = PushCheckUserLog::class;
require_once RUN_MAINTENANCE_IF_MAIN;
