'use strict';

const assert = require( 'assert' );
const {
	Vue, cache, config, dom, hooks, load, test, run, text, buttonLabelled, click
} = require( './harness.js' );

function jq( target ) {
	const nodes = typeof target === 'string' ?
		Array.from( document.querySelectorAll( target ) ) :
		[ target ];
	return {
		on: function ( type, selector, handler ) {
			nodes.forEach( ( node ) => {
				node.addEventListener( type, ( event ) => {
					const match = event.target.closest( selector );
					if ( !match ) {
						return;
					}
					if ( event.which === undefined ) {
						event.which = ( event.button || 0 ) + 1;
					}
					handler.call( match, event );
				} );
			} );
			return this;
		},
		addClass: function ( name ) {
			nodes.forEach( ( node ) => node.classList.add( name ) );
			return this;
		}
	};
}

global.$ = function ( target ) {
	if ( typeof target === 'function' ) {
		target();
		return;
	}
	return jq( target );
};

function startInit() {
	Object.keys( hooks ).forEach( ( name ) => {
		hooks[ name ].length = 0;
	} );
	delete cache[ 'init.js' ];
	load( 'init.js' );
}

function dialog() {
	return document.querySelector( '.wikioasis-safety-dialog' );
}

/**
 * @param {Element} root
 * @param {string} value
 */
async function chooseReportType( root, value ) {
	const option = Array.from( root.querySelectorAll( 'input[type="radio"]' ) )
		.find( ( input ) => input.value === value );
	assert.ok( option, 'the triage step offers "' + value + '"' );
	await click( option );
}

test( 'Special:SafetyReport renders the wizard prefilled from the url', async () => {
	document.body.innerHTML = '<div id="wikioasis-safety-wizard"></div>';
	config.wgWikiOasisSafetyPrefill = { user: 'Example', page: 'User:Example' };
	startInit();
	await Vue.nextTick();

	const host = document.getElementById( 'wikioasis-safety-wizard' );
	assert.ok( text( host ).includes( 'What would you like to do?' ),
		'the wizard is the page, not a dialog' );
	assert.strictEqual( dialog(), null, 'no dialog on the wizard\'s own page' );

	await chooseReportType( host, 'harassment' );
	await click( buttonLabelled( host, 'Next' ) );
	assert.ok( text( host ).includes( 'Example' ), 'the reported user came through the url' );
} );

test( 'a sidebar link opens the dialog instead of navigating', async () => {
	document.body.innerHTML =
		'<a class="wikioasis-safety-link" ' +
		'href="/wiki/Special:SafetyReport?user=Example&page=User%3AExample">Report this user</a>' +
		'<div id="wikioasis-safety-app"></div>';
	config.wgWikiOasisSafetyPrefill = null;
	startInit();
	await Vue.nextTick();

	assert.strictEqual( dialog(), null, 'nothing is shown until the link is used' );

	const link = document.querySelector( 'a.wikioasis-safety-link' );
	const event = new dom.window.MouseEvent( 'click', { bubbles: true, cancelable: true, button: 0 } );
	link.dispatchEvent( event );
	await Vue.nextTick();
	await Vue.nextTick();

	assert.ok( event.defaultPrevented, 'the navigation is cancelled' );
	assert.ok( dialog(), 'the dialog opened' );
	assert.ok( text( dialog() ).includes( 'What would you like to do?' ), 'at the first step' );

	await chooseReportType( dialog(), 'harassment' );
	await click( buttonLabelled( dialog(), 'Next' ) );
	assert.ok( text( dialog() ).includes( 'Example' ),
		'the user named in the link is the subject of the report' );
} );

test( 'a modified click is left alone, so the page can be opened in a tab', async () => {
	document.body.innerHTML =
		'<a class="wikioasis-safety-link" href="/wiki/Special:SafetyReport">Get help</a>' +
		'<div id="wikioasis-safety-app"></div>';
	startInit();
	await Vue.nextTick();

	const link = document.querySelector( 'a.wikioasis-safety-link' );
	const event = new dom.window.MouseEvent( 'click', {
		bubbles: true, cancelable: true, button: 0, metaKey: true
	} );
	link.dispatchEvent( event );
	await Vue.nextTick();

	assert.ok( !event.defaultPrevented, 'the browser keeps the click' );
	assert.strictEqual( dialog(), null, 'and no dialog opens over the page being left' );
} );

test( 'reporting a comment brings the comment and its author with it', async () => {
	document.body.innerHTML = '<div id="wikioasis-safety-app"></div>';
	startInit();
	await Vue.nextTick();

	const menuItem = {
		getData: () => ( {
			id: 'wikioasissafety',
			'thread-id': 'c-Example-20260821',
			author: 'Example'
		} ),
		$element: jq( document.createElement( 'div' ) )
	};
	mw.hook( 'discussionToolsOverflowMenuOnChoose' ).fire( 'wikioasissafety', menuItem );
	await Vue.nextTick();
	await Vue.nextTick();

	assert.ok( dialog(), 'the dialog opened from the comment menu' );
	await chooseReportType( dialog(), 'harassment' );
	await click( buttonLabelled( dialog(), 'Next' ) );
	const rendered = text( dialog() );
	assert.ok( rendered.includes( 'Example' ), 'the comment author is the subject' );
	assert.ok( rendered.includes( 'Talk:Example' ), 'the talk page it happened on is included' );

	await click( buttonLabelled( dialog(), 'Next' ) );
	const description = document.querySelector( '.wikioasis-safety-dialog textarea' );
	assert.ok(
		description.value.includes( '/wiki/Talk:Example#c-Example-20260821' ),
		'and a permanent link to the comment itself: ' + description.value
	);
} );

test( 'another extension\'s overflow menu item is not ours to handle', async () => {
	document.body.innerHTML = '<div id="wikioasis-safety-app"></div>';
	startInit();
	await Vue.nextTick();

	mw.hook( 'discussionToolsOverflowMenuOnChoose' ).fire( 'edit', {
		getData: () => ( { id: 'edit' } ),
		$element: jq( document.createElement( 'div' ) )
	} );
	await Vue.nextTick();
	assert.strictEqual( dialog(), null, 'no dialog for someone else\'s menu item' );
} );

test( 'our overflow menu item is tagged so it can be found again', async () => {
	document.body.innerHTML = '<div id="wikioasis-safety-app"></div>';
	startInit();
	const element = document.createElement( 'div' );
	mw.hook( 'discussionToolsOverflowMenuOnAddItem' ).fire( 'wikioasissafety', {
		$element: jq( element )
	} );
	assert.ok( element.classList.contains( 'wikioasis-safety-thread-link' ) );

	const other = document.createElement( 'div' );
	mw.hook( 'discussionToolsOverflowMenuOnAddItem' ).fire( 'edit', { $element: jq( other ) } );
	assert.strictEqual( other.className, '', 'and nobody else\'s item is touched' );
} );

run();
