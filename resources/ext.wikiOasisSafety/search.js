'use strict';

const DEFAULT_NAMESPACES = [ 0, 1, 2, 3, 4, 5 ];

const LIMIT = 10;

let api = null;

function getApi() {
	if ( !api ) {
		api = new mw.Api();
	}
	return api;
}

/**
 * @param {string} query
 * @param {Object} field
 * @return {Promise<string[]>}
 */
function searchPages( query, field ) {
	const namespaces = Array.isArray( field.searchNamespaces ) && field.searchNamespaces.length ?
		field.searchNamespaces :
		DEFAULT_NAMESPACES;
	return getApi().get( {
		action: 'query',
		list: 'prefixsearch',
		pssearch: query,
		pslimit: LIMIT,
		psnamespace: namespaces.join( '|' ),
		formatversion: 2
	} ).then( ( data ) => (
		( ( data.query && data.query.prefixsearch ) || [] ).map( ( page ) => page.title )
	) );
}

/**
 * @param {string} query
 * @return {Promise<string[]>}
 */
function searchUsers( query ) {
	return getApi().get( {
		action: 'query',
		list: 'allusers',
		auprefix: query,
		aulimit: LIMIT,
		formatversion: 2
	} ).then( ( data ) => (
		( ( data.query && data.query.allusers ) || [] ).map( ( user ) => user.name )
	) );
}

/**
 * @param {string} query
 * @param {Object} field
 * @return {Promise<string[]>}
 */
function searchList( query, field ) {
	const needle = query.toLowerCase();
	const matches = ( field.searchOptions || [] )
		.map( String )
		.filter( ( option ) => option.toLowerCase().includes( needle ) )
		.slice( 0, LIMIT );
	return Promise.resolve( matches );
}

const SOURCES = {
	pages: searchPages,
	users: searchUsers,
	list: searchList
};

/**
 * @param {Object} field
 * @param {string} query
 * @return {Promise<string[]>}
 */
function suggest( field, query ) {
	const source = SOURCES[ field.search ];
	const trimmed = String( query || '' ).trim();
	if ( !source || !trimmed ) {
		return Promise.resolve( [] );
	}
	return source( trimmed, field ).catch( ( error ) => {
		mw.log.warn( 'WikiOasisSafety: suggestions unavailable', error );
		return [];
	} );
}

function isSearchable( field ) {
	return !!SOURCES[ field.search ];
}

module.exports = exports = {
	DEFAULT_NAMESPACES: DEFAULT_NAMESPACES,
	suggest: suggest,
	isSearchable: isSearchable
};
