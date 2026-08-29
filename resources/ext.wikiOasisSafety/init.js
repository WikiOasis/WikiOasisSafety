'use strict';

const Vue = require( 'vue' );
const App = require( './App.vue' );
const SafetyWizard = require( './SafetyWizard.vue' );
const { normalizeWizard } = require( './wizard.js' );
const { answersFor, fillCardLinks } = require( './prefill.js' );
const anonymity = require( './anonymity.js' );
const definition = require( './flow.json' );
const portal = require( './portal.js' );
const { submitMessage, fileMessage } = require( './submitMessage.js' );

/**
 * @return {Object|null}
 */
function loadFlow() {
	try {
		const wizard = normalizeWizard( definition );
		fillCardLinks( wizard, mw.config.get( 'wgWikiOasisSafetyNoticeboardUrl' ) );
		return anonymity.asOffered( wizard );
	} catch ( e ) {
		mw.log.error( 'WikiOasisSafety: could not load the wizard definition', e );
		return null;
	}
}

/**
 * @param {HTMLElement} host
 * @param {string} status
 * @param {?string} reference
 * @param {boolean} [anonymous]
 * @param {boolean} [temporary]
 */
function showResult( host, status, reference, anonymous, temporary ) {
	const message = submitMessage( {
		status: status,
		reference: reference,
		anonymous: anonymous,
		temporary: temporary
	} );

	let box = host.parentNode.querySelector( '.wikioasis-safety-result' );
	if ( !box ) {
		box = document.createElement( 'div' );
		box.className = 'wikioasis-safety-result';
		box.setAttribute( 'role', 'status' );
		host.parentNode.insertBefore( box, host );
	}

	box.dataset.status = message.type;
	box.textContent = message.text;
}

/**
 * @param {HTMLElement} host
 * @param {{ stored: number, refused: Array }} files
 */
function showFileResult( host, files ) {
	const message = fileMessage( files );

	if ( !message ) {
		return;
	}

	let box = host.parentNode.querySelector( '.wikioasis-safety-file-result' );
	if ( !box ) {
		box = document.createElement( 'div' );
		box.className = 'wikioasis-safety-result wikioasis-safety-file-result';
		box.setAttribute( 'role', 'status' );
		host.parentNode.insertBefore( box, host );
	}

	box.dataset.status = message.type;
	box.textContent = message.text;
}

function currentPageTitle() {
	return String( mw.config.get( 'wgPageName' ) || '' ).replace( /_/g, ' ' );
}

/**
 * @param {string} href
 * @return {Object}
 */
function contextFromHref( href ) {
	const url = new URL( href, window.location.href );
	const user = url.searchParams.get( 'user' );
	return {
		mode: user ? 'user' : 'page',
		user: user || null,
		page: url.searchParams.get( 'page' ) || null
	};
}

$( () => {
	const wizard = loadFlow();
	const inlineHost = document.getElementById( 'wikioasis-safety-wizard' );
	if ( inlineHost ) {
		const prefill = mw.config.get( 'wgWikiOasisSafetyPrefill' ) || {};
		const context = {
			mode: prefill.user ? 'user' : 'page',
			user: prefill.user || null,
			page: prefill.page || null
		};
		const returnUrl = mw.util.getUrl( prefill.page || '' );
		Vue.createMwApp( SafetyWizard, {
			wizard: wizard,
			data: Vue.reactive( answersFor( context, wizard && wizard.fieldRoles ) ),
			onClose: () => {
				window.location.href = returnUrl;
			},
			onSubmit: ( data ) => {
				const attachments = portal.attachmentsIn( wizard, data );

				portal.submit( 'report', data, {
					attachments: attachments
				} ).then( ( sent ) => {
					showResult( inlineHost, sent.queued ? 'queued' : 'sent',
						sent.reference, sent.anonymous, sent.temporary );

					if ( !attachments.length ) {
						return;
					}

					portal.upload( sent.reference, attachments, sent.anonymous )
						.then( ( outcome ) => showFileResult( inlineHost, outcome ) );
				}, ( code, error ) => {
					showResult( inlineHost,
						code === 'ratelimited' ? 'throttled' : 'failed', null );
					mw.log.error( 'WikiOasisSafety: could not submit the report', code, error );
				} );
			}
		} ).mount( inlineHost );
		return;
	}

	const host = document.getElementById( 'wikioasis-safety-app' );
	if ( !host ) {
		return;
	}
	const app = Vue.createMwApp( App, { wizard: wizard } ).mount( host );

	$( document ).on( 'click', 'a.wikioasis-safety-link', function ( event ) {
		if (
			event.button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
		) {
			return;
		}
		event.preventDefault();
		app.openWizard( contextFromHref( this.href ) );
	} );

	mw.hook( 'discussionToolsOverflowMenuOnChoose' ).add( ( id, menuItem ) => {
		if ( id !== 'wikioasissafety' ) {
			return;
		}
		const data = menuItem.getData() || {};
		const threadId = data[ 'thread-id' ];
		app.openWizard( {
			mode: 'comment',
			user: data.author || null,
			page: currentPageTitle(),
			commentUrl: threadId ?
				new URL(
					mw.util.getUrl( mw.config.get( 'wgPageName' ) ) + '#' + threadId,
					window.location.href
				).href :
				null
		} );
	} );

	mw.hook( 'discussionToolsOverflowMenuOnAddItem' ).add( ( id, menuItem ) => {
		if ( id === 'wikioasissafety' ) {
			menuItem.$element.addClass( 'wikioasis-safety-thread-link' );
		}
	} );
} );
