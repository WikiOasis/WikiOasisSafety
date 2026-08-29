'use strict';

const assert = require( 'assert' );
const {
	Vue, apiCalls, config, dom, load, resolveFlow, setApiResponder, test, run, text,
	buttonLabelled, click, createApp
} = require( './harness.js' );

const wizardModel = load( 'wizard.js' );
const anonymity = load( 'anonymity.js' );
const prefill = load( 'prefill.js' );
const SafetyWizard = load( 'SafetyWizard.vue' );

/** @return {Object} */
function renamedUsersField() {
	const steps = {};
	load( 'flow.json' ).steps.forEach( ( step ) => {
		const copy = JSON.parse( JSON.stringify( step ) );
		copy.fields.forEach( ( field ) => {
			if ( field.name === 'users' ) {
				field.name = 'accounts-involved';
			}
		} );
		steps[ step.id ] = copy;
	} );
	return steps;
}

async function press( element, key ) {
	element.dispatchEvent( new dom.window.KeyboardEvent( 'keydown', { key: key, bubbles: true } ) );
	await Vue.nextTick();
	await Vue.nextTick();
}

async function settle() {
	await new Promise( ( resolve ) => setTimeout( resolve, 200 ) );
	await Vue.nextTick();
	await Vue.nextTick();
}

/**
 * @param {Object} [answers]
 * @param {Object} [options]
 * @param {Function} [options.flow]
 * @param {string} [options.startStep]
 */
function mountWizard( answers, options ) {
	options = options || {};
	let wizard = wizardModel.normalizeWizard( load( 'flow.json' ) );
	prefill.fillCardLinks( wizard, config.wgWikiOasisSafetyNoticeboardUrl );
	if ( options.flow ) {
		wizard = options.flow( wizard );
	}
	const closed = { count: 0 };
	const submitted = [];
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	createApp( SafetyWizard, {
		wizard: wizard,
		data: Vue.reactive( answers || {} ),
		startStep: options.startStep || '',
		onClose: () => {
			closed.count += 1;
		},
		onSubmit: ( data ) => submitted.push( data )
	} ).mount( host );
	return { host: host, closed: closed, submitted: submitted, wizard: wizard };
}

test( 'the first step renders both triage questions', async () => {
	const { host } = mountWizard();
	const rendered = text( host );
	assert.ok( rendered.includes( 'What would you like to do?' ), 'step title' );
	assert.ok( rendered.includes( 'Unacceptable user behavior' ), 'help option' );
	assert.ok( rendered.includes( 'Threat of physical harm' ), 'report option' );
	assert.strictEqual( host.querySelectorAll( 'input[type="radio"]' ).length, 7 );
} );

test( 'the guidance branch renders cards linking to the noticeboard and exits', async () => {
	const answers = Vue.reactive( { help: 'unacceptable' } );
	const { host, closed } = mountWizard( answers );
	await click( buttonLabelled( host, 'Next' ) );

	assert.ok( text( host ).includes( 'Unacceptable user behaviour' ), 'guidance step reached' );
	const links = Array.from( host.querySelectorAll( 'a' ) ).map( ( a ) => a.getAttribute( 'href' ) );
	assert.deepStrictEqual( links, [
		'/wiki/Project:Counter-vandalism_unit',
		'/wiki/Project:Counter-vandalism_unit',
		'/wiki/Project:Counter-vandalism_unit'
	], 'every guidance card links to the noticeboard' );

	await click( buttonLabelled( host, 'Exit' ) );
	assert.strictEqual( closed.count, 1, 'Exit closes the wizard' );
	assert.ok( !text( host ).includes( 'Thank you' ), 'no false confirmation' );
} );

test( 'the report branch walks to submission and collects the answers', async () => {
	const answers = Vue.reactive( { report: 'harassment' } );
	const { host, submitted } = mountWizard( answers );

	await click( buttonLabelled( host, 'Next' ) );
	assert.ok( text( host ).includes( 'Reporting to Trust & Safety' ), 'step 2' );

	assert.ok( !text( host ).includes( 'Which users does this report concern?' ) );
	answers.concerns = [ 'users' ];
	await Vue.nextTick();
	assert.ok( text( host ).includes( 'Which users does this report concern?' ),
		'the users chip input appears when "Users" is checked' );

	answers.users = [ 'Halcyon Reed' ];
	answers.details = 'Reverting my edits and following me to other pages.';
	await Vue.nextTick();

	await click( buttonLabelled( host, 'Next' ) );
	assert.ok( text( host ).includes( 'Tell us what happened' ), 'step 3' );
	assert.ok( text( host ).includes( 'Drag and drop files here' ), 'file drop card renders' );
	assert.ok( text( host ).includes( 'image files, PDF' ), 'accept list is described' );

	await click( buttonLabelled( host, 'Next' ) );
	assert.ok( text( host ).includes( 'Submit anonymously' ), 'final step' );

	await click( buttonLabelled( host, 'Submit report' ) );
	assert.strictEqual( submitted.length, 1, 'the report is submitted once' );
	assert.deepStrictEqual( submitted[ 0 ].concerns, [ 'users' ] );
	assert.ok( text( host ).includes( 'Thank you' ), 'confirmation is shown' );
	assert.ok( text( host ).includes( 'reach out to you' ),
		'and says what happens next' );
} );

test( 'asking to be anonymous changes what the reporter is promised', async () => {
	const answers = Vue.reactive( { report: 'harassment' } );
	const { host, submitted } = mountWizard( answers, { startStep: 'report-final' } );

	assert.ok( text( host ).includes( 'Submit anonymously' ),
		'a logged-in reporter is offered the choice' );

	answers.anonymous = true;
	await Vue.nextTick();
	await click( buttonLabelled( host, 'Submit report' ) );

	assert.strictEqual( submitted[ 0 ].anonymous, true, 'the answer is submitted' );
	assert.ok( text( host ).includes( 'Nothing identifying you' ),
		'and the confirmation says so' );
	assert.ok( !text( host ).includes( 'reach out to you' ),
		'rather than promising to come back to them' );
} );

test( 'a logged-out reporter is not offered a choice they do not have', async () => {
	config.wgUserId = null;
	try {
		const answers = Vue.reactive( { report: 'harassment' } );
		const { host, submitted } = mountWizard( answers, {
			flow: anonymity.asOffered,
			startStep: 'report-final'
		} );

		assert.ok( !text( host ).includes( 'Submit anonymously' ),
			'the control is gone' );
		assert.ok( text( host ).includes( 'threat to' ), 'the rest of the step is intact' );

		await click( buttonLabelled( host, 'Submit report' ) );

		assert.strictEqual( submitted.length, 1, 'and the step still submits' );
		assert.strictEqual( submitted[ 0 ].anonymous, undefined,
			'with no answer to a question that was never asked' );
		assert.ok( text( host ).includes( 'Nothing identifying you' ),
			'the confirmation is the anonymous one all the same' );
	} finally {
		config.wgUserId = 7;
	}
} );

test( 'back retraces the path a branch took, not the step order', async () => {
	const answers = Vue.reactive( { help: 'unacceptable' } );
	const { host } = mountWizard( answers );
	await click( buttonLabelled( host, 'Next' ) );
	assert.ok( text( host ).includes( 'Unacceptable user behaviour' ) );
	await click( buttonLabelled( host, 'Go back' ) );
	assert.ok( text( host ).includes( 'What would you like to do?' ),
		'back from the branch lands on the step it branched from' );
} );

test( 'a nested follow-up appears under its own option only', async () => {
	const answers = Vue.reactive( {} );
	const { host } = mountWizard( answers );
	assert.ok( !text( host ).includes( 'What are you reporting?' ), 'hidden until chosen' );
	answers.report = 'something-else';
	await Vue.nextTick();
	assert.ok( text( host ).includes( 'What are you reporting?' ), 'shown once chosen' );
	answers.report = 'harassment';
	await Vue.nextTick();
	assert.ok( !text( host ).includes( 'What are you reporting?' ), 'hidden again' );
} );

test( 'prefilled answers reach the fields they belong to', async () => {
	const answers = Vue.reactive( prefill.answersFor( {
		mode: 'comment',
		user: 'Example',
		page: 'Talk:Example',
		commentUrl: 'https://wiki.example/wiki/Talk:Example#c-Example-2026'
	}, load( 'flow.json' ).fieldRoles ) );
	answers.report = 'harassment';
	const { host } = mountWizard( answers );
	await click( buttonLabelled( host, 'Next' ) );
	const rendered = text( host );
	assert.ok( rendered.includes( 'Example' ), 'the reported user is shown as a chip' );
	assert.ok( rendered.includes( 'Talk:Example' ), 'the page is shown as a chip' );
	const checked = Array.from( host.querySelectorAll( 'input[type="checkbox"]' ) )
		.filter( ( input ) => input.checked ).length;
	assert.strictEqual( checked, 2, 'both "Pages" and "Users" are checked' );

	await click( buttonLabelled( host, 'Next' ) );
	assert.ok(
		host.querySelector( 'textarea' ).value.includes( '#c-Example-2026' ),
		'the comment permalink leads the description'
	);
} );

test( 'the two triage questions are alternatives, and switching forgets the other',
	async () => {
		const answers = Vue.reactive( {} );
		const { host } = mountWizard( answers );

		answers.report = 'something-else';
		await Vue.nextTick();
		const followUp = host.querySelector( 'input[type="text"]' );
		followUp.value = 'Off-wiki coordination';
		followUp.dispatchEvent( new dom.window.Event( 'input', { bubbles: true } ) );
		await Vue.nextTick();
		assert.strictEqual( answers[ 'report-other' ], 'Off-wiki coordination',
			'the follow-up was recorded' );

		const guidance = Array.from( host.querySelectorAll( 'input[type="radio"]' ) )
			.find( ( input ) => input.value === 'unacceptable' );
		await click( guidance );

		assert.strictEqual( answers.help, 'unacceptable', 'the new answer stands' );
		assert.ok( !( 'report' in answers ), 'the report question was forgotten' );
		assert.ok( !( 'report-other' in answers ),
			'and so was the text hanging off its option' );
		assert.strictEqual(
			host.querySelectorAll( 'input[type="radio"]:checked' ).length, 1,
			'exactly one route is selected in the interface'
		);

		const harassment = Array.from( host.querySelectorAll( 'input[type="radio"]' ) )
			.find( ( input ) => input.value === 'harassment' );
		await click( harassment );
		assert.strictEqual( answers.report, 'harassment', 'the report route is taken' );
		assert.ok( !( 'help' in answers ), 'and the guidance route is forgotten' );
	} );

test( 'the page and user fields suggest as you type, and take what is typed anyway',
	async () => {
		setApiResponder( ( params ) => {
			if ( params.list === 'prefixsearch' ) {
				return { query: { prefixsearch: [
					{ title: 'Talk:Sandbox' }, { title: 'Talk:Sandbox/Archive 1' }
				] } };
			}
			return { query: { allusers: [ { name: 'Example' }, { name: 'Example2' } ] } };
		} );
		apiCalls.length = 0;

		const answers = Vue.reactive( { report: 'harassment', concerns: [ 'pages', 'users' ] } );
		const { host } = mountWizard( answers );
		await click( buttonLabelled( host, 'Next' ) );

		const inputs = Array.from( host.querySelectorAll( '.cdx-multiselect-lookup input' ) );
		assert.strictEqual( inputs.length, 2, 'the page and user fields are searchable' );
		const [ pages, users ] = inputs;

		pages.value = 'Talk:Sand';
		pages.dispatchEvent( new dom.window.Event( 'input', { bubbles: true } ) );
		await settle();
		const options = Array.from( host.querySelectorAll( '.cdx-menu-item' ) )
			.map( ( item ) => item.textContent.replace( /\s+/g, ' ' ).trim() );
		assert.ok( options.some( ( o ) => o.includes( 'Talk:Sandbox/Archive 1' ) ),
			'a suggestion from the wiki: ' + JSON.stringify( options ) );
		assert.ok( options[ 0 ].startsWith( 'Talk:Sand' ),
			'and what was typed, first: ' + JSON.stringify( options ) );
		assert.deepStrictEqual(
			apiCalls.map( ( call ) => call.list ), [ 'prefixsearch' ],
			'one request, after the typing settled'
		);
		assert.strictEqual( apiCalls[ 0 ].psnamespace, '0|1|2|3|4|5',
			'over the namespaces the flow configured' );

		const suggestion = Array.from( host.querySelectorAll( '.cdx-menu-item' ) )
			.find( ( item ) => item.textContent.includes( 'Archive 1' ) );
		suggestion.dispatchEvent( new dom.window.MouseEvent( 'mousedown', {
			bubbles: true, button: 0
		} ) );
		await click( suggestion );
		assert.deepStrictEqual( answers.pages, [ 'Talk:Sandbox/Archive 1' ],
			'the suggestion became the answer' );

		users.value = '127.0.0.1';
		users.dispatchEvent( new dom.window.Event( 'input', { bubbles: true } ) );
		await settle();
		await press( users, 'Enter' );
		assert.deepStrictEqual( answers.users, [ '127.0.0.1' ],
			'Enter added what was typed, suggestion or not' );

		await click( buttonLabelled( host, 'Next' ) );
		await click( buttonLabelled( host, 'Back' ) );
		assert.ok( text( host ).includes( '127.0.0.1' ), 'the chip is still there' );
	} );

test( 'a field with nothing to search is still a plain chip input', async () => {
	setApiResponder( () => ( {} ) );
	apiCalls.length = 0;
	const answers = Vue.reactive( { report: 'harassment', concerns: [ 'wikis' ] } );
	const { host } = mountWizard( answers );
	await click( buttonLabelled( host, 'Next' ) );

	const input = host.querySelector( '.cdx-multiselect-lookup input' );
	input.value = 'test1.example';
	input.dispatchEvent( new dom.window.Event( 'input', { bubbles: true } ) );
	await settle();
	assert.deepStrictEqual( apiCalls, [], 'a list is searched without asking the wiki' );
	await press( input, 'Enter' );
	assert.deepStrictEqual( answers.wikis, [ 'test1.example' ], 'and free text still works' );
} );

test( 'a wiki that names its own wikis gets them suggested', async () => {
	const flow = resolveFlow( { WikiOasisSafetyWikis: null } );
	const steps = {};
	flow.steps.forEach( ( step ) => {
		const copy = JSON.parse( JSON.stringify( step ) );
		copy.fields.forEach( ( field ) => {
			if ( field.name === 'wikis' ) {
				field.searchOptions = [ 'meta.example.test', 'test1.example.test' ];
			}
		} );
		steps[ step.id ] = copy;
	} );
	const wizard = wizardModel.normalizeWizard( resolveFlow( { WikiOasisSafetySteps: steps } ) );
	const answers = Vue.reactive( { report: 'harassment', concerns: [ 'wikis' ] } );
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	createApp( SafetyWizard, { wizard: wizard, data: answers } ).mount( host );
	await Vue.nextTick();
	await click( buttonLabelled( host, 'Next' ) );

	const input = host.querySelector( '.cdx-multiselect-lookup input' );
	input.value = 'test1';
	input.dispatchEvent( new dom.window.Event( 'input', { bubbles: true } ) );
	await settle();
	const options = Array.from( host.querySelectorAll( '.cdx-menu-item' ) )
		.map( ( item ) => item.textContent.replace( /\s+/g, ' ' ).trim() );
	assert.ok( options.some( ( o ) => o.includes( 'test1.example.test' ) ),
		'the farm\'s own wiki was suggested: ' + JSON.stringify( options ) );
} );

test( 'a wiki that renames a field says so, and prefilling follows', async () => {
	const flow = resolveFlow( {
		WikiOasisSafetySteps: renamedUsersField(),
		WikiOasisSafetyFieldRoles: {
			concerns: 'concerns',
			pages: 'pages',
			users: 'accounts-involved',
			wikis: 'wikis',
			details: 'details'
		}
	} );
	const wizard = wizardModel.normalizeWizard( flow );
	const answers = Vue.reactive( Object.assign(
		{ report: 'harassment' },
		prefill.answersFor( { mode: 'user', user: 'Example' }, wizard.fieldRoles )
	) );
	assert.deepStrictEqual( answers[ 'accounts-involved' ], [ 'Example' ],
		'the answer went to the field the role names' );

	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	createApp( SafetyWizard, { wizard: wizard, data: answers } ).mount( host );
	await Vue.nextTick();
	await click( buttonLabelled( host, 'Next' ) );
	assert.ok( text( host ).includes( 'Example' ), 'and shows up in the renamed field' );
} );

test( 'the wizard will not advance past a question it has to have answered',
	async () => {
		const answers = Vue.reactive( {} );
		const { host } = mountWizard( answers );

		await click( buttonLabelled( host, 'Next' ) );
		assert.ok( text( host ).includes( 'What would you like to do?' ),
			'the step does not advance with neither answered' );
		assert.ok( text( host ).includes( 'This field is required' ),
			'and says which field is waiting' );

		answers.report = 'harassment';
		await Vue.nextTick();
		await click( buttonLabelled( host, 'Next' ) );
		assert.ok( text( host ).includes( 'Reporting to Trust & Safety' ),
			'answering either alternative answers the pair' );

		await click( buttonLabelled( host, 'Next' ) );
		assert.ok( text( host ).includes( 'Reporting to Trust & Safety' ),
			'"what does this report concern?" has to be answered too' );

		answers.concerns = [ 'users' ];
		answers.users = [ 'Halcyon Reed' ];
		await Vue.nextTick();
		await click( buttonLabelled( host, 'Next' ) );
		assert.ok( text( host ).includes( 'Tell us what happened' ), 'and then it moves on' );

		await click( buttonLabelled( host, 'Next' ) );
		assert.ok( text( host ).includes( 'Tell us what happened' ),
			'the account of what happened is required' );
		answers.details = 'They followed me to three other pages.';
		await Vue.nextTick();
		await click( buttonLabelled( host, 'Next' ) );
		assert.ok( text( host ).includes( 'Submit anonymously' ),
			'with no files attached, which are not required' );
	} );

test( 'a definition with no steps renders a way out instead of nothing', async () => {
	assert.throws( () => wizardModel.normalizeWizard( { steps: [] } ), /no steps/ );
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	createApp( SafetyWizard, { wizard: null, data: Vue.reactive( {} ) } ).mount( host );
	await Vue.nextTick();
	assert.ok( text( host ).includes( 'safety@wikioasis.org' ),
		'the reporter is told where else to go' );
} );

run();
