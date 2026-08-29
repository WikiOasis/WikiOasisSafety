'use strict';

const NO_ACCOUNT = { standing: 'good', actions: [] };

/**
 * @return {?Object}
 */
function fromServer() {
	if ( typeof mw === 'undefined' || !mw.config ) {
		return null;
	}
	return mw.config.get( 'wgWikiOasisSafetyRecords' ) || null;
}

function visibleReports() {
	const records = fromServer();

	return records && Array.isArray( records.reports ) ? records.reports : [];
}

/**
 * @return {boolean}
 */

function account() {
	const records = fromServer();

	return ( records && records.account ) || NO_ACCOUNT;
}

function appealableActions() {
	return ( account().actions || [] )
		.filter( ( action ) => action.appealable && action.active );
}

module.exports = exports = {
	visibleReports: visibleReports,
	account: account,
	appealableActions: appealableActions
};
