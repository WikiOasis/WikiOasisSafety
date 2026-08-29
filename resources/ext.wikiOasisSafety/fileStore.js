'use strict';

const { fileKey } = require( './fileHelpers.js' );

const files = new Map();

/**
 * @param {File} file
 * @return {{ name: string, size: number, type: string, lastModified: number }}
 */
function remember( file ) {
	const meta = {
		name: file.name,
		size: file.size,
		type: file.type,
		lastModified: file.lastModified
	};

	files.set( fileKey( meta ), file );

	return meta;
}

/**
 * @param {{ name: string, size: number, lastModified?: number, modified?: number }} meta
 * @return {?File}
 */
function blobFor( meta ) {
	return files.get( fileKey( meta ) ) || null;
}

/**
 * @param {Object} meta
 */
function forget( meta ) {
	files.delete( fileKey( meta ) );
}

function clear() {
	files.clear();
}

function count() {
	return files.size;
}

module.exports = exports = {
	remember: remember,
	blobFor: blobFor,
	forget: forget,
	clear: clear,
	count: count
};
