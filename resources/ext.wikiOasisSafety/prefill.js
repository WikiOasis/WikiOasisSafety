'use strict';

const FALLBACK_ROLES = {
	concerns: 'select-all-that-apply',
	pages: 'add-links-to-the-content',
	users: 'user-report',
	wikis: 'add-links-to-the-content-2',
	details: 'tell-us-what-happened'
};

/**
 * @param {Object} context
 * @param {string} context.mode
 * @param {string} [context.user]
 * @param {string} [context.page]
 * @param {string} [context.commentUrl]
 * @param {Object} [roles]
 * @return {Object}
 */
function answersFor( context, roles ) {
	const FIELDS = Object.assign( {}, FALLBACK_ROLES, roles || {} );
	const answers = {};
	const concerns = [];

	if ( context.user ) {
		concerns.push( 'users' );
		answers[ FIELDS.users ] = [ context.user ];
	}

	if ( context.page && ( context.mode === 'comment' || context.mode === 'page' ) ) {
		concerns.push( 'pages' );
		answers[ FIELDS.pages ] = [ context.page ];
	}
	if ( concerns.length ) {
		answers[ FIELDS.concerns ] = concerns;
	}
	if ( context.commentUrl ) {
		answers[ FIELDS.details ] = context.commentUrl + '\n\n';
	}
	return answers;
}

/**
 * @param {Object} wizard
 * @param {string} noticeboardUrl
 */
function fillCardLinks( wizard, noticeboardUrl ) {
	if ( !noticeboardUrl ) {
		return;
	}
	wizard.steps.forEach( ( step ) => {
		step.fields.forEach( ( field ) => {
			if ( field.type === 'card' && !field.url ) {
				field.url = noticeboardUrl;
			}
		} );
	} );
}

module.exports = exports = {
	FALLBACK_ROLES: FALLBACK_ROLES,
	answersFor: answersFor,
	fillCardLinks: fillCardLinks
};
