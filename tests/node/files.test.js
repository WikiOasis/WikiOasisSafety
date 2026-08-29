'use strict';

const assert = require( 'assert' );
const { load, test, run } = require( './harness.js' );

const { fileKey } = load( 'fileHelpers.js' );
const fileStore = load( 'fileStore.js' );
const portal = load( 'portal.js' );

function fakeFile( name, size, type, lastModified ) {
	return { name: name, size: size, type: type, lastModified: lastModified };
}

function flowWithFileField( fieldName ) {
	return {
		steps: [ {
			id: 'evidence',
			fields: [ { type: 'fileUpload', name: fieldName } ]
		} ]
	};
}

test( 'the same file is one key whichever name the modified time has', () => {
	assert.strictEqual(
		fileKey( { name: 'a.png', size: 12, lastModified: 1690000000000 } ),
		fileKey( { name: 'a.png', size: 12, modified: 1690000000000 } ),
		'the two metadata shapes have to agree, or the blob is never found'
	);
} );

test( 'a real modified time does not collapse to zero', () => {
	assert.notStrictEqual(
		fileKey( { name: 'a.png', size: 12, modified: 1690000000000 } ),
		fileKey( { name: 'a.png', size: 12, modified: 0 } )
	);
} );

test( 'a file survives the trip from the drop zone to the upload', () => {
	fileStore.clear();

	const file = fakeFile( '835441.png', 4096, 'image/png', 1690000000000 );

	const meta = fileStore.remember( file );
	const answers = { evidence: [ meta ] };

	const attachments = portal.attachmentsIn( flowWithFileField( 'evidence' ), answers );

	assert.strictEqual( attachments.length, 1 );
	assert.strictEqual( attachments[ 0 ].modified, 1690000000000,
		'the payload keeps the portal\'s own field name' );
	assert.strictEqual( attachments[ 0 ].lastModified, undefined,
		'and does not also carry the answers\' name, so the lookup has to cope' );

	assert.strictEqual(
		fileStore.blobFor( attachments[ 0 ] ),
		file,
		'the blob was not found from the payload shape, so nothing would be uploaded'
	);
} );

test( 'a file the store never had is not found', () => {
	fileStore.clear();

	assert.strictEqual(
		fileStore.blobFor( { name: 'never-attached.png', size: 1, modified: 1 } ),
		null
	);
} );

test( 'removing a file forgets its bytes', () => {
	fileStore.clear();

	const file = fakeFile( 'withdrawn.png', 10, 'image/png', 1690000000001 );
	const meta = fileStore.remember( file );

	assert.strictEqual( fileStore.count(), 1 );

	fileStore.forget( meta );

	assert.strictEqual( fileStore.blobFor( meta ), null );
	assert.strictEqual( fileStore.count(), 0 );
} );

test( 'nothing is kept once a submission has been handed over', () => {
	fileStore.clear();

	fileStore.remember( fakeFile( 'one.png', 10, 'image/png', 1 ) );
	fileStore.remember( fakeFile( 'two.png', 10, 'image/png', 2 ) );

	assert.strictEqual( fileStore.count(), 2 );

	fileStore.clear();

	assert.strictEqual( fileStore.count(), 0 );
} );

test( 'a flow with no file field has nothing to send', () => {
	const attachments = portal.attachmentsIn(
		{ steps: [ { id: 'only', fields: [ { type: 'text', name: 'what' } ] } ] },
		{ what: 'they keep following me' }
	);

	assert.deepStrictEqual( attachments, [] );
} );

run();
