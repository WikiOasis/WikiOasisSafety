'use strict';

/**
 * @param {?Object} field
 * @return {boolean}
 */
function isControl( field ) {
	if ( !field || typeof field !== 'object' ) {
		return false;
	}
	if ( field.anonymous !== undefined ) {
		return !!field.anonymous;
	}

	return ( field.type === 'checkbox' || field.type === 'toggle' ) &&
		String( field.name || '' ).trim().toLowerCase() === 'anonymous';
}

/**
 * @return {boolean}
 */
function forced() {
	return !mw.config.get( 'wgUserId' );
}

/**
 * @param {?Object} wizard
 * @param {Object} data
 * @return {boolean}
 */
function requestedIn( wizard, data ) {
	if ( !wizard || !Array.isArray( wizard.steps ) ) {
		return false;
	}

	return wizard.steps.some( ( step ) => ( step.fields || [] ).some(
		( field ) => isControl( field ) && !!data[ field.name ]
	) );
}

/**
 * @param {?Object} wizard
 * @param {Object} data
 * @return {boolean}
 */
function expected( wizard, data ) {
	return forced() || requestedIn( wizard, data );
}

/**
 * @param {?Object} wizard
 * @return {?Object}
 */
function withoutControl( wizard ) {
	if ( !wizard || !Array.isArray( wizard.steps ) ) {
		return wizard;
	}

	const holds = wizard.steps.some(
		( step ) => ( step.fields || [] ).some( isControl )
	);

	if ( !holds ) {
		return wizard;
	}

	return Object.assign( {}, wizard, {
		steps: wizard.steps.map( ( step ) => Object.assign( {}, step, {
			fields: ( step.fields || [] ).filter( ( field ) => !isControl( field ) )
		} ) )
	} );
}

/**
 * @param {?Object} wizard
 * @return {?Object}
 */
function asOffered( wizard ) {
	return forced() ? withoutControl( wizard ) : wizard;
}

module.exports = exports = {
	isControl: isControl,
	forced: forced,
	requestedIn: requestedIn,
	expected: expected,
	withoutControl: withoutControl,
	asOffered: asOffered
};
