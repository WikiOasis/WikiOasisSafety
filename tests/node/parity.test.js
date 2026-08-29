'use strict';

const assert = require( 'assert' );
const path = require( 'path' );
const childProcess = require( 'child_process' );
const { load, test, run, resolveFlow } = require( './harness.js' );

const {
	resolveNext, evaluateCondition, isEmptyValue, normalizeWizard,
	blockingFields, SUBMIT
} = load( './wizard.js' );
const FIELD_TYPES = load( './fieldTypes.js' );

/**
 * @param {Object} wizard
 * @param {Object} answers
 * @return {Array<Object>}
 */
function walk( wizard, answers ) {
	const seen = [];
	const result = [];
	let step = wizard.steps[ 0 ];

	while ( step && !seen.includes( step.id ) ) {
		seen.push( step.id );

		const visible = step.fields.filter(
			( field ) => evaluateCondition( field.visibleWhen, answers )
		);

		result.push( {
			step: step.id,
			fields: visible.map( ( field ) => field.name ),
			blocking: blockingFields( wizard, step, answers ),
			guidance: !step.fields.some(
				( field ) => FIELD_TYPES[ field.type ].collectsData
			)
		} );

		const next = resolveNext( wizard, step, answers );
		if ( next === SUBMIT ) {
			result.push( { step: SUBMIT } );
			break;
		}
		step = wizard.steps.find( ( candidate ) => candidate.id === next );
	}
	return result;
}

function walkInPhp( answers, flow, overrides ) {
	const result = childProcess.spawnSync( 'php', [
		path.join( __dirname, '..', 'php', 'walkFlow.php' ),
		JSON.stringify( answers ),
		flow || '',
		overrides ? JSON.stringify( overrides ) : ''
	], { encoding: 'utf8' } );
	assert.strictEqual( result.status, 0,
		'the PHP walk ran: ' + ( result.stderr || result.stdout ) );
	return JSON.parse( result.stdout );
}

const CASES = [
	[ '', 'report: nothing answered yet', {} ],
	[ '', 'report: the guidance branch', { help: 'unacceptable' } ],
	[ '', 'report: the Trust & Safety branch', { report: 'harassment' } ],
	[ '', 'report: an answer that matches no branch falls through', { report: 'other' } ],
	[ '', 'report: a licensing complaint is asked about licensing', {
		report: 'a-licensing-issue'
	} ],
	[ '', 'report: an age report has to say what the basis is', {
		report: 'underage-user'
	} ],
	[ '', 'report: an age report on "something else" has to say what', {
		report: 'underage-user', 'underage-basis': 'other'
	} ],
	[ 'Data', 'data: nothing answered yet', {} ],
	[ 'Data', 'data: deletion, unconfirmed', {} ],
	[ 'Data', 'data: deletion, confirmed', { 'erase-confirm': true } ],
	[ 'Contact', 'contact: nothing answered yet', {} ],
	[ 'Contact', 'contact: a general question', { 'contact-reason': 'general' } ],
	[ 'Contact', 'contact: an appeal', { 'contact-reason': 'appeal' } ],
	[ 'Contact', 'contact: something else falls through to the message step', {
		'contact-reason': 'other'
	} ]
];

const FLOW_FILES = { '': './flow.json', Data: './flow.data.json', Contact: './flow.contact.json' };

CASES.forEach( ( [ flow, name, answers ] ) => {
	test( name + ' walks the same in both implementations', () => {
		const wizard = load( FLOW_FILES[ flow ] );
		assert.deepStrictEqual(
			walk( wizard, answers ),
			walkInPhp( answers, flow ),
			'wizard.js and FlowNavigator.php disagree about this flow. They are a ' +
			'port of one another; change both.'
		);
	} );
} );

test( 'the two agree about what counts as an empty answer', () => {
	const values = [ '', 'x', 0, '0', false, true, [], [ 'a' ], null ];
	const answers = {};
	values.forEach( ( value, i ) => {
		answers[ 'v' + i ] = value;
	} );

	const result = childProcess.spawnSync( 'php', [ '-r', `
		require "${ path.join( __dirname, '..', 'php', 'bootstrap.php' ) }";
		$data = json_decode( $argv[1], true );
		$out = [];
		foreach ( $data as $key => $value ) {
			$out[ $key ] = MediaWiki\\Extension\\WikiOasisSafety\\NoJs\\FlowNavigator::isEmptyValue( $value );
		}
		echo json_encode( $out );
	`, JSON.stringify( answers ) ], { encoding: 'utf8' } );
	assert.strictEqual( result.status, 0, result.stderr || result.stdout );

	const inPhp = JSON.parse( result.stdout );
	const inJs = {};
	Object.keys( answers ).forEach( ( key ) => {
		inJs[ key ] = isEmptyValue( answers[ key ] );
	} );
	assert.deepStrictEqual( inJs, inPhp,
		'isEmptyValue disagrees between wizard.js and FlowNavigator.php' );
} );

test( 'a step made only of cards is guidance in both, and one with a field is not', () => {
	const wizard = load( './flow.json' );
	const guidance = walk( wizard, { help: 'unacceptable' } );
	const last = guidance[ guidance.length - 2 ];
	assert.strictEqual( last.guidance, true,
		'the guidance branch ends on a step that collects nothing' );
	assert.deepStrictEqual( guidance, walkInPhp( { help: 'unacceptable' }, '' ) );
} );

const OPERATOR_FLOW = {
	WikiOasisSafetySteps: {
		answers: {
			title: 'Answers everything else keys off',
			fields: [
				{ type: 'text', name: 'word' },
				{ type: 'checkboxGroup', name: 'list', options: [
					{ label: 'A', value: 'a' }, { label: 'B', value: 'b' }
				] },
				{ type: 'checkbox', name: 'flag' }
			]
		},
		operators: {
			title: 'One field per operator',
			fields: [
				{ type: 'text', name: 'f-equals', required: true,
				  visibleWhen: { field: 'word', op: 'equals', value: 'yes' } },
				{ type: 'text', name: 'f-notEquals', required: true,
				  visibleWhen: { field: 'word', op: 'notEquals', value: 'yes' } },
				{ type: 'text', name: 'f-contains-string', required: true,
				  visibleWhen: { field: 'word', op: 'contains', value: 'es' } },
				{ type: 'text', name: 'f-contains-list', required: true,
				  visibleWhen: { field: 'list', op: 'contains', value: 'b' } },
				{ type: 'text', name: 'f-isFilled', required: true,
				  visibleWhen: { field: 'word', op: 'isFilled' } },
				{ type: 'text', name: 'f-isEmpty', required: true,
				  visibleWhen: { field: 'word', op: 'isEmpty' } },
				{ type: 'text', name: 'f-isFilled-ignores-value', required: true,
				  visibleWhen: { field: 'word', op: 'isFilled', value: 'zzz' } },
				{ type: 'text', name: 'f-isEmpty-ignores-value', required: true,
				  visibleWhen: { field: 'word', op: 'isEmpty', value: 'zzz' } },
				{ type: 'text', name: 'f-always', required: true,
				  visibleWhen: { op: 'always' } },
				{ type: 'text', name: 'f-flag', required: true,
				  visibleWhen: { field: 'flag', op: 'equals', value: true } }
			]
		},
		flagged: {
			title: 'Only for the flagged',
			visibleWhen: { field: 'flag', op: 'isFilled' },
			fields: [ { type: 'text', name: 'flagged-note' } ]
		}
	}
};

const TYPES_FLOW = {
	WikiOasisSafetySteps: {
		collecting: {
			title: 'One of every type that collects something',
			fields: [
				{ type: 'text', name: 't-text', required: true },
				{ type: 'textarea', name: 't-textarea', required: true },
				{ type: 'select', name: 't-select', required: true, options: [ { label: 'A', value: 'a' } ] },
				{ type: 'radio', name: 't-radio', required: true, options: [ { label: 'A', value: 'a' } ] },
				{ type: 'checkboxGroup', name: 't-group', required: true, options: [ { label: 'A', value: 'a' } ] },
				{ type: 'checkbox', name: 't-checkbox', required: true },
				{ type: 'toggle', name: 't-toggle', required: true },
				{ type: 'combobox', name: 't-combobox', required: true, options: [ { label: 'A', value: 'a' } ] },
				{ type: 'lookup', name: 't-lookup', required: true },
				{ type: 'chipInput', name: 't-chips', required: true },
				{ type: 'fileUpload', name: 't-files', required: true }
			]
		},
		guidance: {
			title: 'Nothing here collects anything',
			fields: [
				{ type: 'heading', name: 'g-heading', text: 'Heading' },
				{ type: 'paragraph', name: 'g-paragraph', text: 'Paragraph' },
				{ type: 'message', name: 'g-message', text: 'Message' },
				{ type: 'accordion', name: 'g-accordion', label: 'More' },
				{ type: 'card', name: 'g-card', label: 'Card' },
				{ type: 'infoChip', name: 'g-chip', text: 'Chip' }
			]
		}
	}
};

const FOLLOWUP_FLOW = {
	WikiOasisSafetySteps: {
		pick: {
			title: 'Pick one',
			fields: [ {
				type: 'radio',
				name: 'pick',
				required: true,
				options: [
					{ label: 'Plain', value: 'plain' },
					{ label: 'Other', value: 'Something Else!', followUp: { required: true } },
					{ label: 'Named', value: 'named',
					  followUp: { name: 'explicit-key', required: true } },
					{ label: 'Long', required: true,
					  value: 'a very long option value that runs well past the slug limit',
					  followUp: { required: true } }
				]
			} ]
		}
	}
};

const BRANCH_FLOW = {
	WikiOasisSafetySteps: {
		start: {
			title: 'Start',
			branches: [
				{ when: { field: 'go', op: 'equals', value: 'end' }, goTo: '__submit__' },
				{ when: { field: 'go', op: 'equals', value: 'gone' }, goTo: 'no-such-step' },
				{ when: {}, goTo: 'second' }
			],
			fields: [ { type: 'text', name: 'go' } ]
		},
		second: { title: 'Second', fields: [ { type: 'text', name: 'second-field' } ] }
	}
};

const ALTERNATIVES_FLOW = {
	WikiOasisSafetyExclusiveFields: [ [ 'this-one', 'or-this-one' ] ],
	WikiOasisSafetySteps: {
		pick: {
			title: 'One or the other',
			fields: [
				{
					type: 'radio',
					name: 'this-one',
					required: true,
					options: [ { label: 'A', value: 'a' } ]
				},
				{
					type: 'radio',
					name: 'or-this-one',
					required: true,
					options: [ { label: 'B', value: 'b' } ]
				},
				{ type: 'text', name: 'on-its-own', required: true }
			]
		}
	}
};

const BUILT = [
	[ 'every condition operator', OPERATOR_FLOW, [
		{},
		{ word: 'yes' },
		{ word: 'no' },
		{ word: '', list: [ 'b' ] },
		{ flag: true },
		{ word: 'yes', list: [ 'a', 'b' ], flag: true },
		{ word: 'zzz' }
	] ],
	[ 'every field type', TYPES_FLOW, [ {}, { 't-checkbox': true, 't-chips': [ 'x' ] } ] ],
	[ 'follow-ups', FOLLOWUP_FLOW, [
		{},
		{ pick: 'plain' },
		{ pick: 'Something Else!' },
		{ pick: 'Something Else!', 'pick-something-else': 'because' },
		{ pick: 'named' },
		{ pick: 'a very long option value that runs well past the slug limit' }
	] ],
	[ 'branches', BRANCH_FLOW, [ {}, { go: 'end' }, { go: 'gone' }, { go: 'other' } ] ],
	[ 'required alternatives', ALTERNATIVES_FLOW, [
		{},
		{ 'this-one': 'a' },
		{ 'or-this-one': 'b' },
		{ 'this-one': 'a', 'on-its-own': 'x' }
	] ]
];

BUILT.forEach( ( [ name, overrides, answerSets ] ) => {
	answerSets.forEach( ( answers, i ) => {
		test( name + ' agree, case ' + ( i + 1 ), () => {
			const wizard = normalizeWizard( resolveFlow( overrides ) );
			assert.deepStrictEqual(
				walk( wizard, answers ),
				walkInPhp( answers, '', overrides ),
				'wizard.js and FlowNavigator.php disagree about ' + name +
				' with ' + JSON.stringify( answers ) + '. They are a port of ' +
				'one another; change both.'
			);
		} );
	} );
} );

test( 'inside an exclusivity group, required means one of us', () => {
	const wizard = normalizeWizard( resolveFlow( ALTERNATIVES_FLOW ) );
	const step = wizard.steps[ 0 ];
	const blocked = ( answers ) => blockingFields( wizard, step, answers );

	assert.deepStrictEqual( blocked( {} ),
		[ 'this-one', 'or-this-one', 'on-its-own' ],
		'with nothing answered, the group asks to be answered' );

	assert.deepStrictEqual( blocked( { 'this-one': 'a' } ), [ 'on-its-own' ],
		'answering either alternative answers the group -- otherwise the step ' +
		'could never be completed, since answering one clears the other' );

	assert.deepStrictEqual( blocked( { 'or-this-one': 'b' } ), [ 'on-its-own' ],
		'and it is either, not one particular one' );

	assert.deepStrictEqual( blocked( { 'this-one': 'a', 'on-its-own': 'x' } ), [],
		'a field in no group is answered only by itself' );
} );

run();
