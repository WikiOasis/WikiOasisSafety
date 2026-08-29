'use strict';

const Vue = require( 'vue' );
const SafetyHome = require( './SafetyHome.vue' );
const { normalizeWizard } = require( './wizard.js' );
const { fillCardLinks } = require( './prefill.js' );
const anonymity = require( './anonymity.js' );

/**
 * @param {Object} definition
 * @param {string} name
 * @return {Object|null}
 */
function loadFlow( definition, name ) {
	if ( !definition || !Array.isArray( definition.steps ) || !definition.steps.length ) {
		return null;
	}
	try {
		const wizard = normalizeWizard( definition );
		fillCardLinks( wizard, mw.config.get( 'wgWikiOasisSafetyNoticeboardUrl' ) );
		return anonymity.asOffered( wizard );
	} catch ( e ) {
		mw.log.error( 'WikiOasisSafety: could not load the ' + name + ' flow', e );
		return null;
	}
}

$( () => {
	const host = document.getElementById( 'wikioasis-safety-home' );
	if ( !host ) {
		return;
	}
	Vue.createMwApp( SafetyHome, {
		reportWizard: loadFlow( require( './flow.json' ), 'report' ),
		dataWizard: loadFlow( require( './flow.data.json' ), 'data' ),
		rawContactWizard: loadFlow( require( './flow.contact.json' ), 'contact' ),
		context: mw.config.get( 'wgWikiOasisSafetyHome' ) || {}
	} ).mount( host );
} );
