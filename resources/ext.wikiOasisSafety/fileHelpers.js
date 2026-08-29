'use strict';

/**
 * @param {{ name: string, size: number, lastModified?: number, modified?: number }} file
 * @return {string}
 */
function fileKey( file ) {
	const modified = file.lastModified !== undefined ? file.lastModified : file.modified;

	return file.name + ':' + file.size + ':' + ( modified || 0 );
}

function formatSize( bytes ) {
	if ( typeof bytes !== 'number' || !isFinite( bytes ) ) {
		return '';
	}
	if ( bytes < 1024 ) {
		return bytes + ' B';
	}
	if ( bytes < 1024 * 1024 ) {
		return Math.round( bytes / 1024 ) + ' KB';
	}
	return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
}

function acceptTokens( accept ) {
	return String( accept || '' )
		.split( ',' )
		.map( ( token ) => token.trim() )
		.filter( Boolean );
}

function describeAccept( accept ) {
	return acceptTokens( accept )
		.map( ( token ) => {
			if ( token.startsWith( '.' ) ) {
				return token.slice( 1 ).toUpperCase();
			}
			if ( token.endsWith( '/*' ) ) {
				return token.split( '/' )[ 0 ] + ' files';
			}
			return ( token.split( '/' )[ 1 ] || token ).toUpperCase();
		} )
		.join( ', ' );
}

function matchesAccept( file, accept ) {
	const tokens = acceptTokens( accept );
	if ( !tokens.length ) {
		return true;
	}
	const type = ( file.type || '' ).toLowerCase();
	const name = ( file.name || '' ).toLowerCase();
	return tokens.some( ( token ) => {
		const candidate = token.toLowerCase();
		if ( candidate.startsWith( '.' ) ) {
			return name.endsWith( candidate );
		}
		if ( candidate.endsWith( '/*' ) ) {
			return type.startsWith( candidate.slice( 0, -1 ) );
		}
		return type === candidate;
	} );
}

function constraintSummary( field ) {
	const parts = [];
	if ( field.accept ) {
		parts.push( describeAccept( field.accept ) );
	}
	if ( Number( field.maxSizeMb ) > 0 ) {
		parts.push( mw.msg( 'wikioasissafety-file-constraint-size', field.maxSizeMb ) );
	}
	if ( Number( field.maxFiles ) > 0 && field.multiple !== false ) {
		parts.push( mw.msg( 'wikioasissafety-file-constraint-count', field.maxFiles ) );
	}
	return parts.join( ' · ' );
}

module.exports = exports = {
	fileKey: fileKey,
	formatSize: formatSize,
	describeAccept: describeAccept,
	matchesAccept: matchesAccept,
	constraintSummary: constraintSummary
};
