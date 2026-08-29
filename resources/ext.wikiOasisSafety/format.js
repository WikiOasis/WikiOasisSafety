'use strict';


/**
 * @param {?string} iso
 * @return {string}
 */
function date( iso ) {
	if ( !iso ) {
		return '';
	}
	const value = new Date( iso );
	if ( isNaN( value.getTime() ) ) {
		return '';
	}
	return value.toLocaleDateString( mw.config.get( 'wgUserLanguage' ) || undefined, {
		year: 'numeric', month: 'long', day: 'numeric'
	} );
}

/**
 * @param {Object} action
 * @return {string}
 */
function expiry( action ) {
	if ( !action.expires ) {
		return mw.msg( 'wikioasissafety-home-account-noexpiry' );
	}
	return action.active ?
		mw.msg( 'wikioasissafety-home-account-expires', date( action.expires ) ) :
		mw.msg( 'wikioasissafety-home-account-expired', date( action.expires ) );
}

const REPORT_STATUS = {
	received: { label: 'wikioasissafety-home-reports-status-received', status: 'notice' },
	'in-review': { label: 'wikioasissafety-home-reports-status-inreview', status: 'warning' },
	investigating: { label: 'wikioasissafety-home-reports-status-investigating', status: 'warning' },
	'action-taken': { label: 'wikioasissafety-home-reports-status-actiontaken', status: 'success' },
	closed: { label: 'wikioasissafety-home-reports-status-closed', status: 'notice' },
	rejected: { label: 'wikioasissafety-home-reports-status-rejected', status: 'notice' }
};

/**
 * @param {string} state
 * @return {{ text: string, status: string }}
 */
function reportStatus( state ) {
	const known = REPORT_STATUS[ state ];
	return known ?
		{ text: mw.msg( known.label ), status: known.status } :
		{ text: String( state || '' ), status: 'notice' };
}

module.exports = exports = {
	date: date,
	expiry: expiry,
	reportStatus: reportStatus
};
