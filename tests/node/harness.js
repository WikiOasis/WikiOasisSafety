'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const assert = require( 'assert' );
const childProcess = require( 'child_process' );

const MODULE_DIR = path.join( __dirname, '..', '..', 'resources', 'ext.wikiOasisSafety' );
const EXTENSION_JSON = path.join( __dirname, '..', '..', 'extension.json' );

const FLOW_FILES = {
	'flow.json': '',
	'flow.data.json': 'Data',
	'flow.contact.json': 'Contact'
};

function modules() {
	return JSON.parse( fs.readFileSync( EXTENSION_JSON, 'utf8' ) ).ResourceModules;
}
const VENDOR = process.env.WIKIOASIS_VENDOR ||
	path.join( process.env.HOME, 'Developer', 'cdxPlayground', 'node_modules' );

const { JSDOM } = require( path.join( VENDOR, 'jsdom' ) );
const dom = new JSDOM( '<!doctype html><html><body><div id="host"></div></body></html>', {
	url: 'https://wiki.example/wiki/Talk:Example',
	pretendToBeVisual: true
} );

global.window = dom.window;
global.document = dom.window.document;
Object.defineProperty( global, 'navigator', { value: dom.window.navigator, configurable: true } );
global.URL = dom.window.URL;
global.Document = dom.window.Document;
global.DocumentFragment = dom.window.DocumentFragment;
global.Element = dom.window.Element;
global.HTMLElement = dom.window.HTMLElement;
global.SVGElement = dom.window.SVGElement;
global.Node = dom.window.Node;
global.getComputedStyle = dom.window.getComputedStyle;
global.requestAnimationFrame = ( fn ) => setTimeout( fn, 0 );
global.matchMedia = () => ( { matches: false, addEventListener() {}, removeEventListener() {} } );
dom.window.matchMedia = global.matchMedia;

const messages = JSON.parse(
	fs.readFileSync( path.join( __dirname, '..', '..', 'i18n', 'en.json' ), 'utf8' )
);
const hooks = {};
const config = {
	wgPageName: 'Talk:Example',
	wgUserLanguage: 'en',
	wgUserId: 7,
	wgWikiOasisSafetyNoticeboardUrl: '/wiki/Project:Counter-vandalism_unit'
};

const apiCalls = [];
let apiResponder = () => ( {} );

function setApiResponder( responder ) {
	apiResponder = responder;
}

global.mw = {
	Api: function () {
		this.get = ( params ) => {
			apiCalls.push( params );
			return Promise.resolve( apiResponder( params ) );
		};

		this.postWithToken = ( token, params ) => {
			apiCalls.push( params );
			try {
				return Promise.resolve( apiResponder( params ) );
			} catch ( e ) {
				return Promise.reject( e );
			}
		};
		this.post = this.postWithToken.bind( this, 'csrf' );
	},
	msg: function ( key ) {
		const params = Array.prototype.slice.call( arguments, 1 );
		let text = messages[ key ];
		assert.ok( text !== undefined, 'message is defined in en.json: ' + key );
		params.forEach( ( value, i ) => {
			text = text.split( '$' + ( i + 1 ) ).join( String( value ) );
		} );
		return text;
	},
	config: { get: ( key ) => config[ key ] },
	log: Object.assign( () => {}, { warn: () => {}, error: () => {} } ),
	util: { getUrl: ( title ) => '/wiki/' + String( title || 'Main_Page' ).replace( / /g, '_' ) },
	hook: ( name ) => {
		hooks[ name ] = hooks[ name ] || [];
		return {
			add: ( fn ) => hooks[ name ].push( fn ),
			fire: function () {
				const args = arguments;
				hooks[ name ].forEach( ( fn ) => fn.apply( null, args ) );
			}
		};
	}
};

const Vue = require( path.join( VENDOR, 'vue', 'dist', 'vue.cjs.js' ) );
const codex = require( path.join( VENDOR, '@wikimedia', 'codex', 'dist', 'codex.cjs' ) );
const codexIcons = require( path.join( VENDOR, '@wikimedia', 'codex-icons', 'dist', 'codex-icons.cjs' ) );

const cache = {};

function load( name ) {
	if ( name === 'vue' ) {
		return Vue;
	}
	if ( name === '@wikimedia/codex' ) {
		return codex;
	}
	const file = name.replace( /^\.\//, '' );
	if ( cache[ file ] ) {
		return cache[ file ].exports;
	}
	const full = path.join( MODULE_DIR, file );
	if ( file === 'icons.json' ) {
		const icons = {};
		Object.values( modules() ).forEach( ( module ) => {
			const entry = ( module.packageFiles || [] )
				.find( ( item ) => item && item.name === 'icons.json' );
			( ( entry && entry.callbackParam ) || [] ).forEach( ( icon ) => {
				assert.ok( codexIcons[ icon ],
					'icon exists in @wikimedia/codex-icons: ' + icon );
				icons[ icon ] = codexIcons[ icon ];
			} );
		} );
		cache[ file ] = { exports: icons };
		return icons;
	}
	if ( FLOW_FILES[ file ] !== undefined ) {
		cache[ file ] = { exports: resolveFlow( null, FLOW_FILES[ file ] ) };
		return cache[ file ].exports;
	}
	if ( file.endsWith( '.json' ) ) {
		cache[ file ] = { exports: JSON.parse( fs.readFileSync( full, 'utf8' ) ) };
		return cache[ file ].exports;
	}

	const source = fs.readFileSync( full, 'utf8' );
	const module = { exports: {} };
	cache[ file ] = module;

	if ( file.endsWith( '.vue' ) ) {
		const script = source.match( /<script>([\s\S]*)<\/script>/ )[ 1 ];
		const template = source.match( /<template>([\s\S]*)<\/template>/ )[ 1 ];
		new Function( 'require', 'module', 'exports', script )( load, module, module.exports );
		module.exports.template = template;
	} else {
		new Function( 'require', 'module', 'exports', source )( load, module, module.exports );
	}
	return module.exports;
}

/**
 * @param {Object} [overrides]
 * @param {string} [flow]
 * @return {Object}
 */
function resolveFlow( overrides, flow ) {
	const args = [ path.join( __dirname, '..', 'php', 'resolveFlow.php' ) ];
	if ( overrides || flow ) {
		args.push( overrides ? JSON.stringify( overrides ) : '' );
	}
	if ( flow ) {
		args.push( flow );
	}
	const result = childProcess.spawnSync( 'php', args, { encoding: 'utf8' } );
	assert.strictEqual( result.status, 0,
		'the flow resolved: ' + ( result.stderr || result.stdout ) );
	return JSON.parse( result.stdout );
}

const warnings = [];
const tests = [];

function test( name, fn ) {
	tests.push( [ name, fn ] );
}

function text( element ) {
	return element.textContent.replace( /\s+/g, ' ' ).trim();
}

function buttonLabelled( element, label ) {
	const buttons = Array.from( element.querySelectorAll( 'button' ) );
	const found = buttons.find( ( b ) => b.textContent.trim() === label );
	assert.ok( found, 'a button labelled "' + label + '" is rendered; got: ' +
		buttons.map( ( b ) => b.textContent.trim() ).join( ' | ' ) );
	return found;
}

async function click( element ) {
	element.dispatchEvent( new dom.window.MouseEvent( 'click', { bubbles: true, button: 0 } ) );
	await Vue.nextTick();
	await Vue.nextTick();
}

function createApp( component, props ) {
	const app = Vue.createApp( component, props );
	app.config.warnHandler = ( msg ) => warnings.push( msg );
	app.config.errorHandler = ( err, instance, info ) => {
		warnings.push( 'error in ' + info + ': ' + ( err && err.stack ? err.stack : err ) );
	};
	return app;
}

Vue.createMwApp = createApp;

async function run() {
	let failures = 0;
	for ( const [ name, fn ] of tests ) {
		try {
			await fn();
			console.log( '  ok  ' + name );
		} catch ( e ) {
			failures += 1;
			const detail = e instanceof Error ?
				( e.message || e.stack ) :
				'threw a non-error: ' + JSON.stringify( e );
			console.log( ' FAIL ' + name + '\n       ' + detail );
		}
	}
	if ( warnings.length ) {
		failures += 1;
		console.log( ' FAIL Vue reported no warnings\n       ' + warnings.join( '\n       ' ) );
	}
	console.log( failures ? '\n' + failures + ' failing' : '\nall passing' );
	process.exit( failures ? 1 : 0 );
}

module.exports = {
	MODULE_DIR: MODULE_DIR,
	EXTENSION_JSON: EXTENSION_JSON,
	FLOW_FILES: FLOW_FILES,
	modules: modules,
	cache: cache,
	VENDOR: VENDOR,
	dom: dom,
	Vue: Vue,
	config: config,
	hooks: hooks,
	messages: messages,
	load: load,
	resolveFlow: resolveFlow,
	apiCalls: apiCalls,
	setApiResponder: setApiResponder,
	test: test,
	run: run,
	text: text,
	buttonLabelled: buttonLabelled,
	click: click,
	createApp: createApp,
	warnings: warnings
};
