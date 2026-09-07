<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Notifications;

use GenericParameterJob;
use Job;

class NotifyActionTakenJob extends Job implements GenericParameterJob {

	public function __construct( array $params ) {
		parent::__construct( 'WikiOasisSafetyNotifyAction', $params );
	}

	/** @inheritDoc */
	public function run(): bool {
		( new Notifier() )->actionTaken(
			(int)$this->params['central_id'],
			(string)$this->params['reference'],
			(string)$this->params['label'],
			(bool)( $this->params['lifted'] ?? false )
		);

		return true;
	}
}
