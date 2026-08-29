'use strict';

const FIELD_TYPES = require( './fieldTypes.js' );

const SCHEMA_VERSION = 1;
const SUBMIT = '__submit__';

let idCounter = 0;

function uid( prefix ) {
	idCounter += 1;
	return prefix + '-' + Date.now().toString( 36 ) + idCounter.toString( 36 );
}

function clone( value ) {
	return JSON.parse( JSON.stringify( value ) );
}

function slugify( text, fallback ) {
	const slug = String( text || '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-|-$/g, '' )
		.slice( 0, 40 );
	return slug || fallback;
}

const STEP_DEFAULTS = {
	title: '',
	description: '',
	visibleWhen: null,
	nextLabel: '',
	backLabel: '',
	showBack: true,
	nextAction: 'progressive',
	skip: null,
	branches: [],
	isFinal: false
};

function isEmptyValue( value ) {
	if ( Array.isArray( value ) ) {
		return value.length === 0;
	}
	return value === null || value === undefined || value === '' || value === false;
}

function evaluateCondition( condition, data ) {
	if ( !condition || condition.op === 'always' || !condition.field ) {
		return true;
	}
	const value = data[ condition.field ];
	switch ( condition.op ) {
		case 'isFilled':
			return !isEmptyValue( value );
		case 'isEmpty':
			return isEmptyValue( value );
		case 'notEquals':
			return String( value === undefined || value === null ? '' : value ) !==
				String( condition.value === undefined || condition.value === null ? '' : condition.value );
		case 'contains':
			if ( Array.isArray( value ) ) {
				return value.map( String ).includes( String( condition.value ) );
			}
			return String( value === undefined || value === null ? '' : value )
				.includes( String( condition.value === undefined || condition.value === null ? '' : condition.value ) );
		case 'equals':
		default:
			return String( value === undefined || value === null ? '' : value ) ===
				String( condition.value === undefined || condition.value === null ? '' : condition.value );
	}
}

function isStepVisible( step, data ) {
	return evaluateCondition( step.visibleWhen, data );
}

/**
 * @param {Object} wizard
 * @param {Object} step
 * @param {Object} data
 * @return {string}
 */
function resolveNext( wizard, step, data ) {
	if ( step.isFinal ) {
		return SUBMIT;
	}
	const branches = step.branches || [];
	for ( let i = 0; i < branches.length; i++ ) {
		const branch = branches[ i ];
		const usable = branch.when && ( branch.when.op === 'always' || branch.when.field );
		if ( branch.goTo && usable && evaluateCondition( branch.when, data ) ) {
			if ( branch.goTo === SUBMIT ) {
				return SUBMIT;
			}
			const target = wizard.steps.find( ( s ) => s.id === branch.goTo );
			if ( target ) {
				return target.id;
			}
		}
	}
	const index = wizard.steps.indexOf( step );
	const following = wizard.steps
		.slice( index + 1 )
		.find( ( candidate ) => isStepVisible( candidate, data ) );
	return following ? following.id : SUBMIT;
}

function resolveSkip( wizard, step, data ) {
	if ( !step.skip ) {
		return null;
	}
	if ( step.skip.goTo === SUBMIT ) {
		return SUBMIT;
	}
	const target = step.skip.goTo && wizard.steps.find( ( s ) => s.id === step.skip.goTo );
	return target ? target.id : resolveNext( wizard, step, data );
}

/**
 * @param {?Object} wizard
 * @param {Object} field
 * @return {string[]}
 */
function alternativesTo( wizard, field ) {
	const groups = ( wizard && wizard.exclusiveFields ) || [];
	const found = [];

	groups.forEach( ( group ) => {
		if ( group.includes( field.name ) ) {
			group.forEach( ( name ) => {
				if ( name !== field.name && !found.includes( name ) ) {
					found.push( name );
				}
			} );
		}
	} );
	return found;
}

/**
 * @param {Object} field
 * @param {Object} data
 * @param {?Object} wizard
 * @return {boolean}
 */
function isAnswered( field, data, wizard ) {
	return [ field.name ].concat( alternativesTo( wizard, field ) )
		.some( ( name ) => !isEmptyValue( data[ name ] ) );
}

function fieldErrors( field, data, wizard ) {
	if ( !FIELD_TYPES[ field.type ].collectsData || !field.required ) {
		return null;
	}
	if ( !isAnswered( field, data, wizard ) ) {
		return field.type === 'checkbox' ?
			mw.msg( 'wikioasissafety-error-checkbox-required' ) :
			mw.msg( 'wikioasissafety-error-required' );
	}
	return null;
}

/**
 * @param {Object} field
 * @param {Object} data
 */
function clearField( field, data ) {
	delete data[ field.name ];
	( field.options || [] ).forEach( ( option ) => {
		if ( option.followUp ) {
			delete data[ followUpName( field, option ) ];
		}
	} );
}

function followUpName( field, option ) {
	return ( option.followUp && option.followUp.name ) ||
		slugify( field.name + '-' + option.value, field.id + '-followup' );
}

function isOptionSelected( field, option, data ) {
	const value = data[ field.name ];
	if ( Array.isArray( value ) ) {
		return value.map( String ).includes( String( option.value ) );
	}
	return String( value === undefined || value === null ? '' : value ) === String( option.value );
}

/**
 * @param {Object} field
 * @param {Object} data
 * @param {?Object} [wizard]
 * @return {{ self: ?string, followUps: Object }}
 */
function validateField( field, data, wizard ) {
	const followUps = {};
	( field.options || [] ).forEach( ( option ) => {
		if (
			option.followUp &&
			option.followUp.required &&
			isOptionSelected( field, option, data ) &&
			isEmptyValue( data[ followUpName( field, option ) ] )
		) {
			followUps[ option.value ] = mw.msg( 'wikioasissafety-error-required' );
		}
	} );
	return { self: fieldErrors( field, data, wizard ), followUps: followUps };
}

/**
 * @param {?Object} wizard
 * @param {Object} step
 * @param {Object} data
 * @return {string[]}
 */
function blockingFields( wizard, step, data ) {
	const blocking = [];

	step.fields
		.filter( ( field ) => evaluateCondition( field.visibleWhen, data ) )
		.forEach( ( field ) => {
			const result = validateField( field, data, wizard );
			if ( result.self ) {
				blocking.push( field.name );
			}
			( field.options || [] ).forEach( ( option ) => {
				if ( result.followUps[ option.value ] ) {
					blocking.push( followUpName( field, option ) );
				}
			} );
		} );

	return blocking;
}

function hasErrors( result ) {
	return !!result && ( !!result.self || Object.keys( result.followUps || {} ).length > 0 );
}

/**
 * @param {Object} raw
 * @return {Object}
 */
function normalizeWizard( raw ) {
	if ( !raw || typeof raw !== 'object' ) {
		throw new Error( 'Wizard definition must be an object.' );
	}
	if ( !Array.isArray( raw.steps ) || !raw.steps.length ) {
		throw new Error( 'Wizard definition has no steps.' );
	}
	const steps = raw.steps.map( ( step, i ) => {
		const fields = ( Array.isArray( step.fields ) ? step.fields : [] )
			.filter( ( field ) => {
				if ( !field || !FIELD_TYPES[ field.type ] ) {
					mw.log.warn( 'WikiOasisSafety: dropping field of unknown type: ' +
						( field && field.type ) );
					return false;
				}
				return true;
			} )
			.map( ( field ) => Object.assign(
				clone( FIELD_TYPES[ field.type ].defaults ),
				field,
				{
					id: field.id || uid( 'f' ),
					name: field.name ||
						slugify( field.label || field.text || field.type, uid( 'f' ) )
				}
			) );
		return Object.assign( clone( STEP_DEFAULTS ), step, {
			id: step.id || uid( 's' ),
			title: step.title || '',
			branches: Array.isArray( step.branches ) ? step.branches : [],
			fields: fields
		} );
	} );
	return {
		version: SCHEMA_VERSION,
		title: raw.title || '',
		description: raw.description || '',
		submitLabel: raw.submitLabel || '',
		nextLabel: raw.nextLabel || '',
		backLabel: raw.backLabel || '',
		exclusiveFields: Array.isArray( raw.exclusiveFields ) ?
			raw.exclusiveFields.filter( Array.isArray ) :
			[],
		fieldRoles: raw.fieldRoles && typeof raw.fieldRoles === 'object' ? raw.fieldRoles : {},
		steps: steps
	};
}

module.exports = exports = {
	SCHEMA_VERSION: SCHEMA_VERSION,
	SUBMIT: SUBMIT,
	clone: clone,
	slugify: slugify,
	isEmptyValue: isEmptyValue,
	clearField: clearField,
	evaluateCondition: evaluateCondition,
	isStepVisible: isStepVisible,
	resolveNext: resolveNext,
	resolveSkip: resolveSkip,
	followUpName: followUpName,
	isOptionSelected: isOptionSelected,
	isAnswered: isAnswered,
	validateField: validateField,
	blockingFields: blockingFields,
	hasErrors: hasErrors,
	normalizeWizard: normalizeWizard
};
