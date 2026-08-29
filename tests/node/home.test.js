'use strict';

const assert = require( 'assert' );
const fs = require( 'fs' );
const path = require( 'path' );
const {
	Vue, apiCalls, cache, config, load, messages, modules, setApiResponder,
	test, run, text, buttonLabelled, click
} = require( './harness.js' );

async function flush() {
	await Promise.resolve();
	await Promise.resolve();
	await Vue.nextTick();
}

function calls( action ) {
	return apiCalls.filter( ( params ) => params.action === action );
}

/**
 * @param {string} key
 * @return {string}
 */
function msg( key ) {
	const text = messages[ key ];
	assert.ok( text, 'message is defined in en.json: ' + key );
	return text;
}

global.$ = function ( target ) {
	if ( typeof target === 'function' ) {
		target();
	}
};

const REGISTERED = {
	isNamed: true,
	userName: 'Example',
	loginUrl: '/wiki/Special:UserLogin'
};

function records() {
	return JSON.parse( fs.readFileSync(
		path.join( __dirname, 'records.fixture.json' ), 'utf8' ) );
}

/**
 * @param {Object} [context]
 * @param {?Object} [held]
 */
function startHome( context, held ) {
	document.body.innerHTML = '<div id="wikioasis-safety-home"></div>';
	config.wgWikiOasisSafetyHome = context || REGISTERED;
	config.wgWikiOasisSafetyRecords = held === undefined ? records() : held;
	delete cache[ 'home.js' ];
	load( 'home.js' );
}

function grid() {
	return document.getElementById( 'wikioasis-safety-home' );
}

function cards() {
	return Array.from( grid().querySelectorAll( '.wikioasis-safety-home__card' ) );
}

function titleOf( element ) {
	return text( element.querySelector( '.cdx-card__text__title' ) );
}

/**
 * @param {string} label
 * @return {Element}
 */
function card( label ) {
	const found = cards().find( ( element ) => titleOf( element ) === label );
	assert.ok( found, 'a card titled "' + label + '" is rendered; got: ' +
		cards().map( titleOf ).join( ' | ' ) );
	return found.closest( '.wikioasis-safety-home__button' ) || found;
}

function dialog() {
	return document.querySelector( '.wikioasis-safety-dialog' );
}

test( 'the grid is two cards over three, in that order', async () => {
	startHome();
	await Vue.nextTick();

	const cells = Array.from( grid().querySelectorAll( '.wikioasis-safety-home__cell' ) );
	assert.strictEqual( cells.length, 5, 'five routes are offered' );
	cards().forEach( ( element ) => assert.ok(
		element.classList.contains( 'cdx-card' ),
		'each route is a Codex card' ) );
	const rows = cells.map( ( cell ) => (
		cell.classList.contains( 'wikioasis-safety-home__cell--half' ) ? 'top' : 'bottom'
	) );
	assert.deepStrictEqual( rows, [ 'top', 'top', 'bottom', 'bottom', 'bottom' ],
		'two cards in the top row, three in the bottom' );

	assert.deepStrictEqual(
		cards().map( titleOf ),
		[ 'report', 'data', 'reports', 'account', 'contact' ]
			.map( ( id ) => msg( 'wikioasissafety-home-' + id ) )
	);
} );

test( 'every card is reachable from the keyboard', async () => {
	startHome();
	await Vue.nextTick();

	cards().forEach( ( element ) => {
		const activator = element.closest( '.wikioasis-safety-home__button' ) || element;
		assert.ok( [ 'BUTTON', 'A' ].includes( activator.tagName ),
			'a card is activated by a button or a link, not a clickable div: ' +
			activator.tagName );
	} );
} );

test( 'a card that needs an account offers a way to get one', async () => {
	startHome( { isNamed: false, userName: null, loginUrl: '/wiki/Special:UserLogin' } );
	await Vue.nextTick();

	const mine = card( msg( 'wikioasissafety-home-reports' ) );
	assert.strictEqual( mine.tagName, 'A', 'it links rather than opening nothing' );
	assert.strictEqual( mine.getAttribute( 'href' ), '/wiki/Special:UserLogin' );
	assert.ok( text( mine ).includes( msg( 'wikioasissafety-home-login' ) ), 'and says why' );

	assert.strictEqual( card( msg( 'wikioasissafety-home-report' ) ).tagName, 'BUTTON' );
} );

test( 'a temporary account can report, but is told why it cannot follow one', async () => {
	startHome(
		{ isNamed: false, isTemp: true, userName: null, loginUrl: '/wiki/Special:UserLogin' },
		null
	);
	await Vue.nextTick();

	const mine = card( msg( 'wikioasissafety-home-reports' ) );
	assert.strictEqual( mine.tagName, 'A', 'the route to an account is offered' );
	assert.ok( text( mine ).includes( msg( 'wikioasissafety-home-temporary' ) ) );
	assert.ok( !text( mine ).includes( msg( 'wikioasissafety-home-login' ) ),
		'and not the message written for somebody logged out' );

	assert.ok( text( card( msg( 'wikioasissafety-home-account' ) ) )
		.includes( msg( 'wikioasissafety-home-temporary' ) ), 'the same for standing' );

	assert.strictEqual( card( msg( 'wikioasissafety-home-report' ) ).tagName, 'BUTTON' );
} );

test( 'a route the wiki has not configured says so rather than failing when used', async () => {
	const flow = cache[ 'flow.contact.json' ];
	cache[ 'flow.contact.json' ] = { exports: { version: 1, steps: [] } };
	try {
		startHome();
		await Vue.nextTick();

		const contact = card( msg( 'wikioasissafety-home-contact' ) );
		assert.ok( contact.disabled, 'the card cannot be used' );
		assert.ok( text( contact ).includes( msg( 'wikioasissafety-home-unconfigured' ) ) );

		await click( card( msg( 'wikioasissafety-home-reports' ) ) );
		assert.ok( dialog(), 'the other cards still work' );
	} finally {
		cache[ 'flow.contact.json' ] = flow;
	}
} );

test( 'the first card opens the existing report wizard', async () => {
	startHome();
	await Vue.nextTick();

	await click( card( msg( 'wikioasissafety-home-report' ) ) );
	assert.ok( text( dialog() ).includes( 'What would you like to do?' ),
		'the report flow, unchanged' );
} );

test( 'manage my data opens on the deletion step and offers no copy', async () => {
	startHome();
	await Vue.nextTick();

	await click( card( msg( 'wikioasissafety-home-data' ) ) );
	const open = dialog();

	assert.ok( text( open ).includes( 'Deleting your data' ),
		'one step, reached without choosing anything' );

	assert.ok( !text( open ).includes( 'Send me a copy of my data' ) );
	assert.strictEqual( open.querySelectorAll( 'input[type="radio"]' ).length, 0,
		'nothing to choose between' );
	assert.ok( text( open ).includes( 'safety@wikioasis.org' ),
		'and where to write instead' );
} );

test( 'contact offers a question, an appeal and something else', async () => {
	startHome();
	await Vue.nextTick();

	await click( card( msg( 'wikioasissafety-home-contact' ) ) );
	const open = dialog();
	assert.ok( text( open ).includes( 'A general question' ) );
	assert.ok( text( open ).includes( 'Appealing a sanction' ) );
	assert.ok( text( open ).includes( 'Something else' ) );
} );

test( 'appealing asks which sanction, from the reader\'s own record', async () => {
	startHome();
	await Vue.nextTick();

	await click( card( msg( 'wikioasissafety-home-contact' ) ) );
	const open = dialog();
	const appeal = Array.from( open.querySelectorAll( 'input[type="radio"]' ) )
		.find( ( input ) => input.value === 'appeal' );
	appeal.checked = true;
	appeal.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
	await Vue.nextTick();
	await click( buttonLabelled( open, 'Next' ) );

	const step = text( dialog() );
	assert.ok( step.includes( 'Which sanction?' ), 'the appeal branch asks which one' );
	assert.ok( step.includes( 'Choose a sanction' ), 'and offers a choice' );

	const select = dialog().querySelector( '.cdx-select-vue' );
	assert.ok( select, 'the choice is a select over the reader\'s own sanctions' );
} );

test( 'the account dialog can hand someone straight to an appeal', async () => {
	startHome();
	await Vue.nextTick();

	await click( card( msg( 'wikioasissafety-home-account' ) ) );
	await click( buttonLabelled( dialog(), msg( 'wikioasissafety-home-account-appeal-button' ) ) );

	const open = text( dialog() );
	assert.ok( open.includes( 'Appealing a sanction' ),
		'the contact wizard opens on its appeal branch, not on "what is this about?"' );
	assert.ok( open.includes( 'Which sanction?' ),
		'and asks the one question the button did not already answer' );
	assert.ok( !open.includes( 'A general question' ),
		'the reason it was opened for is not asked again' );
} );

test( 'the list is exactly what the server sent, and nothing else', async () => {
	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );

	const rendered = Array.from(
		dialog().querySelectorAll( '.wikioasis-safety-reports__subject' )
	).map( text );

	assert.deepStrictEqual( rendered, records().reports.map( ( report ) => report.subject ) );
} );

test( 'a reader the wiki holds nothing about is told so, not shown someone else', async () => {
	startHome( REGISTERED, null );
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );

	assert.ok( text( dialog() ).includes( msg( 'wikioasissafety-home-reports-empty' ) ),
		'an empty list, rather than invented records' );
} );

test( 'opening a report shows what Trust & Safety has said, and who said it', async () => {
	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );

	await click( dialog().querySelector( '.wikioasis-safety-reports__item' ) );
	const open = dialog();
	assert.ok( text( open ).includes( 'TS-2026-0481' ), 'the reference is shown' );
	assert.ok( text( open ).includes( 'M. Okonjo (Trust & Safety)' ),
		'a staff comment is attributed to the person who wrote it' );

	const staff = open.querySelectorAll( '.wikioasis-safety-comment--staff' );
	assert.ok( staff.length >= 1, 'staff replies are marked apart from the reader\'s own' );
} );

test( 'an automatic update is attributed to Trust & Safety, not to the reader', async () => {
	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );
	await openReport( 'TS-2026-0644' );

	const comment = dialog().querySelector( '.wikioasis-safety-comment' );
	assert.ok( comment, 'the fixture still has an unsigned staff comment' );
	assert.strictEqual(
		text( comment.querySelector( '.wikioasis-safety-comment__author' ) ),
		msg( 'wikioasissafety-home-reports-staff' ),
		'an unsigned staff comment is attributed to Trust & Safety' );
	assert.ok( comment.classList.contains( 'wikioasis-safety-comment--staff' ),
		'and is marked apart from the reader\'s own comments' );
} );

/**
 * @param {string} reference
 * @return {Promise<void>}
 */
async function openReport( reference ) {
	const items = Array.from( dialog().querySelectorAll( '.wikioasis-safety-reports__item' ) );
	const wanted = items.find( ( item ) => text( item ).includes( reference ) );
	assert.ok( wanted, 'the fixture still has ' + reference );
	await click( wanted );
}

test( 'an appeal says which action it is against, and what happened to it', async () => {
	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );
	await openReport( 'TS-2026-0601' );

	const open = text( dialog() );

	assert.ok( open.includes( 'AC-2026-0119' ), 'the action is named by its reference' );
	assert.ok( open.includes( 'Persistent addition of promotional links.' ),
		'and by the reason that was given for it' );

	assert.ok( open.includes( msg( 'wikioasissafety-home-reports-appeal-lifted' ) ),
		'a lifted action does not still read as being in force' );

	assert.ok( open.includes( msg( 'wikioasissafety-home-reports-appeal-outcome-granted' ) ),
		'the decision is stated, not left to be inferred from the status word' );
} );

test( 'an appeal nobody has decided says so rather than leaving a blank', async () => {
	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );
	await openReport( 'TS-2026-0644' );

	assert.ok( text( dialog() ).includes( msg( 'wikioasissafety-home-reports-appeal-undecided' ) ),
		'an undecided appeal says it is undecided' );
} );

test( 'an appeal matched to no action asks the reader which one they meant', async () => {
	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );
	await openReport( 'TS-2026-0644' );

	assert.ok( text( dialog() ).includes( msg( 'wikioasissafety-home-reports-appeal-unmatched' ) ),
		'an unplaced appeal says so' );
} );

test( 'a report is not dressed up as an appeal', async () => {
	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );
	await openReport( 'TS-2026-0481' );

	assert.ok( !text( dialog() ).includes( msg( 'wikioasissafety-home-reports-appeal-outcome' ) ),
		'a report has no appeal facts on it' );
} );

test( 'information can be added to a report, and empty additions are refused', async () => {
	setApiResponder( ( params ) => ( params.action === 'wikioasissafetycomment' ?
		{ wikioasissafetycomment: { ok: true, queued: false } } :
		{} ) );

	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );
	await click( dialog().querySelector( '.wikioasis-safety-reports__item' ) );

	const before = dialog().querySelectorAll( '.wikioasis-safety-comment' ).length;
	await click( buttonLabelled( dialog(), msg( 'wikioasissafety-home-reports-add-button' ) ) );
	assert.strictEqual(
		dialog().querySelectorAll( '.wikioasis-safety-comment' ).length, before,
		'an empty box adds nothing' );
	assert.ok( text( dialog() ).includes( msg( 'wikioasissafety-error-required' ) ),
		'and says why' );

	const box = dialog().querySelector( 'textarea' );
	box.value = 'It happened again today.';
	box.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
	await Vue.nextTick();
	await click( buttonLabelled( dialog(), msg( 'wikioasissafety-home-reports-add-button' ) ) );

	assert.ok( text( dialog() ).includes( 'It happened again today.' ),
		'the comment is in the thread' );

	const sent = calls( 'wikioasissafetycomment' );
	assert.strictEqual( sent.length, 1, 'exactly one comment was posted' );
	assert.strictEqual( sent[ 0 ].reference, 'TS-2026-0481',
		'against the report that was open' );
	assert.strictEqual( sent[ 0 ].text, 'It happened again today.' );

	await flush();
	assert.ok( text( dialog() ).includes( msg( 'wikioasissafety-home-reports-added' ) ),
		'and confirms it once the wiki has taken it' );

	setApiResponder( () => ( {} ) );
} );

test( 'a comment that could not be sent is taken back out of the thread', async () => {
	setApiResponder( ( params ) => {
		if ( params.action === 'wikioasissafetycomment' ) {
			throw new Error( 'the portal is down' );
		}
		return {};
	} );

	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );
	await click( dialog().querySelector( '.wikioasis-safety-reports__item' ) );

	const before = dialog().querySelectorAll( '.wikioasis-safety-comment' ).length;

	const box = dialog().querySelector( 'textarea' );
	box.value = 'Please look at this.';
	box.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
	await Vue.nextTick();
	await click( buttonLabelled( dialog(), msg( 'wikioasissafety-home-reports-add-button' ) ) );
	await flush();

	assert.strictEqual(
		dialog().querySelectorAll( '.wikioasis-safety-comment' ).length, before,
		'the comment is no longer in the thread' );
	assert.ok( text( dialog() ).includes( msg( 'wikioasissafety-comment-failed' ) ),
		'and the failure is said out loud' );
	assert.strictEqual( dialog().querySelector( 'textarea' ).value, 'Please look at this.',
		'and the text is given back rather than lost' );

	setApiResponder( () => ( {} ) );
} );

test( 'closing a report and reopening the list comes back to the list', async () => {
	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );
	await click( dialog().querySelector( '.wikioasis-safety-reports__item' ) );
	assert.ok( text( dialog() ).includes( 'TS-2026-0481' ) );

	await click( buttonLabelled( dialog(), msg( 'wikioasissafety-home-reports-back' ) ) );
	assert.ok( dialog().querySelector( '.wikioasis-safety-reports__item' ),
		'back goes to the list, not out of the dialog' );
} );

test( 'a notification about a report opens that report, not the list', async () => {
	startHome( Object.assign( {}, REGISTERED, {
		autoOpen: { dialog: 'reports', reference: 'TS-2026-0298' }
	} ) );
	await Vue.nextTick();

	const open = dialog();
	assert.ok( open, 'the dialog is open on arrival, without anything being clicked' );
	assert.ok( text( open ).includes( 'Off-wiki contact after a content dispute' ),
		'the report the notification was about is what is shown' );
	assert.ok( !open.querySelector( '.wikioasis-safety-reports__item' ),
		'and the list is not in front of it' );

	await click( buttonLabelled( open, msg( 'wikioasissafety-home-reports-back' ) ) );
	assert.ok( dialog().querySelector( '.wikioasis-safety-reports__item' ),
		'back reaches the other reports rather than leaving the page' );
} );

test( 'a notification about an action opens the standing dialog', async () => {
	startHome( Object.assign( {}, REGISTERED, {
		autoOpen: { dialog: 'account', reference: '' }
	} ) );
	await Vue.nextTick();

	assert.ok( text( dialog() ).includes( msg( 'wikioasissafety-home-account-restricted' ) ),
		'the standing is answered straight away' );
} );

test( 'a reference the reader cannot see opens the list rather than saying so', async () => {
	startHome( Object.assign( {}, REGISTERED, {
		autoOpen: { dialog: 'reports', reference: 'TS-2026-0530' }
	} ) );
	await Vue.nextTick();

	const open = dialog();
	assert.ok( open.querySelector( '.wikioasis-safety-reports__item' ),
		'the dialog falls back to the list' );
	assert.ok( !text( open ).includes( 'Threats made in an edit summary' ),
		'and still says nothing about the report that was asked for' );
} );

test( 'the reports card goes back to the list once a notification has been read', async () => {
	startHome( Object.assign( {}, REGISTERED, {
		autoOpen: { dialog: 'reports', reference: 'TS-2026-0298' }
	} ) );
	await Vue.nextTick();

	await click( dialog().querySelector( '.cdx-dialog__header__close-button' ) );
	await click( card( msg( 'wikioasissafety-home-reports' ) ) );

	assert.ok( dialog().querySelector( '.wikioasis-safety-reports__item' ),
		'the card opens the list, not the report the notification named' );
} );

test( 'the standing is answered before the timeline is read', async () => {
	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-account' ) ) );

	const open = text( dialog() );
	assert.ok( open.includes( msg( 'wikioasissafety-home-account-restricted' ) ),
		'the answer comes first' );
	assert.ok( open.includes( msg( 'wikioasissafety-home-account-history' ) ),
		'the timeline is under it' );
} );

test( 'every action says when it ends and why it happened', async () => {
	startHome();
	await Vue.nextTick();
	await click( card( msg( 'wikioasissafety-home-account' ) ) );

	const entries = Array.from(
		dialog().querySelectorAll( '.wikioasis-safety-timeline__entry' )
	);
	assert.strictEqual( entries.length, 3, 'expired actions stay in the timeline' );

	assert.ok( text( entries[ 0 ] ).includes( 'Interaction ban' ) );
	assert.ok( text( entries[ 2 ] ).includes( 'Partial block' ) );

	entries.forEach( ( entry ) => {
		assert.ok( text( entry ).includes( 'Issued' ), 'an action says when it started' );
		assert.ok( text( entry ).includes( 'Reference AC-' ), 'and can be referred to' );
	} );

	assert.ok( text( entries[ 0 ] ).includes( 'Ends December 30, 2026' ),
		'an action in force says when it will end' );
	assert.ok( text( entries[ 1 ] ).includes( 'No end date' ),
		'an action that does not expire says so, rather than looking expired' );
	assert.ok( text( entries[ 2 ] ).includes( 'Ended May 18, 2024' ),
		'an action that has run out says when it did' );

	assert.ok( text( entries[ 0 ] ).includes( 'after mediation' ), 'and why it happened' );
} );

test( 'the home module declares every message and icon its components use', () => {
	const module = modules()[ 'ext.wikiOasisSafety.home' ];
	const declared = new Set( module.messages );
	const icons = new Set(
		module.packageFiles.find( ( file ) => file && file.name === 'icons.json' ).callbackParam
	);
	const fs = require( 'fs' );
	const path = require( 'path' );
	const { MODULE_DIR } = require( './harness.js' );

	module.packageFiles.filter( ( file ) => typeof file === 'string' ).forEach( ( file ) => {
		const source = fs.readFileSync( path.join( MODULE_DIR, file ), 'utf8' );

		( source.match( /mw\.msg\(\s*'([a-z0-9-]+)'\s*[,)]/g ) || [] ).forEach( ( call ) => {
			const key = call.match( /'([a-z0-9-]+)'/ )[ 1 ];
			assert.ok( declared.has( key ),
				file + ' uses ' + key + ', which the module does not declare' );
		} );
		( source.match( /icons\.(cdxIcon[A-Za-z]+)/g ) || [] ).forEach( ( use ) => {
			const name = use.slice( 'icons.'.length );
			assert.ok( icons.has( name ),
				file + ' uses ' + name + ', which the module does not list' );
		} );
	} );
} );

run();
