'use strict';

const fileStore = require( './fileStore.js' );

/** @return {mw.Api} */
function api() {
	return new mw.Api();
}

/**
 * @param {string} flow
 * @param {Object} answers
 * @param {Object} [options]
 * @param {boolean} [options.anonymous]
 * @param {Array<Object>} [options.attachments]
 * @return {jQuery.Promise}
 */
function submit( flow, answers, options ) {
	options = options || {};

	return api().postWithToken( 'csrf', {
		action: 'wikioasissafetysubmit',
		format: 'json',
		formatversion: 2,
		flow: flow,
		answers: JSON.stringify( answers ),
		attachments: JSON.stringify( options.attachments || [] ),
		anonymous: options.anonymous ? 1 : undefined
	} ).then( ( response ) => {
		const result = response.wikioasissafetysubmit || {};

		return {
			reference: result.reference || null,
			queued: !!result.queued,
			anonymous: !!result.anonymous,
			temporary: !!result.temporary
		};
	} );
}

/**
 * @param {string} reference
 * @param {Array<Object>} attachments
 * @param {boolean} [anonymous]
 * @return {jQuery.Promise}
 */
function upload( reference, attachments, anonymous ) {
	const files = ( attachments || [] )
		.map( ( meta ) => ( { meta: meta, blob: fileStore.blobFor( meta ) } ) );

	const result = { stored: 0, refused: [] };

	if ( !files.length ) {
		return $.Deferred().resolve( result ).promise();
	}

	if ( !reference ) {
		result.refused = files.map( ( file ) => ( {
			name: file.meta.name,
			reason: mw.msg( 'wikioasissafety-file-uploadfailed' )
		} ) );
		fileStore.clear();

		return $.Deferred().resolve( result ).promise();
	}

	return files.reduce(
		( chain, file ) => chain.then( () => sendOne( reference, file, result, anonymous ) ),
		$.Deferred().resolve().promise()
	).then( () => {
		fileStore.clear();

		return result;
	} );
}

/**
 * @param {string} reference
 * @param {{ meta: Object, blob: ?File }} file
 * @param {{ stored: number, refused: Array }} result
 * @param {boolean} [anonymous]
 * @return {jQuery.Promise}
 */
function sendOne( reference, file, result, anonymous ) {
	if ( !file.blob ) {
		result.refused.push( {
			name: file.meta.name,
			reason: mw.msg( 'wikioasissafety-file-lost' )
		} );

		return $.Deferred().resolve().promise();
	}

	return api().postWithToken( 'csrf', {
		action: 'wikioasissafetyupload',
		format: 'json',
		formatversion: 2,
		reference: reference,
		anonymous: anonymous ? 1 : undefined,
		file: file.blob
	}, {
		contentType: 'multipart/form-data'
	} ).then( ( response ) => {
		const outcome = response.wikioasissafetyupload || {};

		if ( outcome.stored ) {
			result.stored += 1;
		} else {
			result.refused.push( { name: file.meta.name, reason: outcome.reason || null } );
		}
	}, ( code, error ) => {
		mw.log.error( 'WikiOasisSafety: could not upload a file', code, error );

		result.refused.push( {
			name: file.meta.name,
			reason: apiReason( code, error )
		} );
	} );
}

/**
 * @param {string} code
 * @param {Object} [error]
 * @return {string}
 */
function apiReason( code, error ) {
	const info = error && (
		( error.error && error.error.info ) ||
		( error.errors && error.errors[ 0 ] &&
			( error.errors[ 0 ][ '*' ] || error.errors[ 0 ].text || error.errors[ 0 ].html ) )
	);

	if ( info ) {
		return String( info ).replace( /<[^>]*>/g, '' ).trim();
	}

	return code && code !== 'http' && code !== 'error'
		? mw.msg( 'wikioasissafety-file-uploaderror', code )
		: mw.msg( 'wikioasissafety-file-uploadfailed' );
}

/**
 * @param {string} reference
 * @param {string} text
 * @return {jQuery.Promise}
 */
function comment( reference, text ) {
	return api().postWithToken( 'csrf', {
		action: 'wikioasissafetycomment',
		format: 'json',
		formatversion: 2,
		reference: reference,
		text: text
	} ).then( ( response ) => ( {
		queued: !!( response.wikioasissafetycomment || {} ).queued
	} ) );
}

/**
 * @param {?Object} wizard
 * @param {Object} answers
 * @return {Array<Object>}
 */
function attachmentsIn( wizard, answers ) {
	if ( !wizard || !wizard.steps ) {
		return [];
	}

	const files = [];
	wizard.steps.forEach( ( step ) => {
		( step.fields || [] ).forEach( ( field ) => {
			if ( field.type !== 'fileUpload' ) {
				return;
			}
			( answers[ field.name ] || [] ).forEach( ( file ) => {
				files.push( {
					name: file.name,
					size: file.size,
					type: file.type,
					modified: file.lastModified
				} );
			} );
		} );
	} );

	return files;
}

module.exports = exports = {
	submit: submit,
	upload: upload,
	comment: comment,
	attachmentsIn: attachmentsIn
};
