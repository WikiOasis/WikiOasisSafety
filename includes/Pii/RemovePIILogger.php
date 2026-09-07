<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Pii;

use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserLogger;

class RemovePIILogger extends GlobalRenameUserLogger {

	public function log( $oldName, $newName, $options ) {
		wfDebugLog( 'WikiOasisSafety', "PII rename: $oldName -> $newName" );
	}
}
