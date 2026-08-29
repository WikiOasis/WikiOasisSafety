'use strict';

/**
 * @param {{ anonymous: ?boolean, temporary: ?boolean }} result
 * @return {string}
 */
function sentFollow( result ) {
	if ( result.anonymous ) {
		return ' ' + mw.msg( 'wikioasissafety-submit-sent-anonymous' );
	}
	if ( result.temporary ) {
		return ' ' + mw.msg( 'wikioasissafety-submit-sent-temp' );
	}

	return '';
}

/**
 * @param {{ status: string, reference: ?string, anonymous: ?boolean,
 *   temporary: ?boolean }} result
 * @return {{ type: string, text: string }}
 */
function submitMessage( result ) {
	switch ( result.status ) {
		case 'sending':
			return { type: 'notice', text: mw.msg( 'wikioasissafety-submit-sending' ) };
		case 'sent':
			return {
				type: 'success',
				text: mw.msg( 'wikioasissafety-submit-sent', result.reference || '' ) +
					sentFollow( result )
			};
		case 'queued':
			return { type: 'warning', text: mw.msg( 'wikioasissafety-submit-queued' ) };
		case 'throttled':
			return { type: 'error', text: mw.msg( 'wikioasissafety-submit-throttled' ) };
		default:
			return { type: 'error', text: mw.msg( 'wikioasissafety-submit-failed' ) };
	}
}

/**
 * @param {?{ stored: number, refused: Array<{ name: string, reason: ?string }> }} files
 * @return {?{ type: string, text: string }}
 */
function fileMessage( files ) {
	if ( !files || ( !files.stored && !( files.refused || [] ).length ) ) {
		return null;
	}

	const refused = files.refused || [];

	if ( !refused.length ) {
		return {
			type: 'success',
			text: mw.msg( 'wikioasissafety-file-uploaded', files.stored )
		};
	}

	const listed = refused
		.map( ( file ) => ( file.reason ? file.name + ' (' + file.reason + ')' : file.name ) )
		.join( ', ' );

	return {
		type: 'warning',
		text: mw.msg( 'wikioasissafety-file-notsent', refused.length, listed )
	};
}

module.exports = exports = {
	submitMessage: submitMessage,
	fileMessage: fileMessage
};
