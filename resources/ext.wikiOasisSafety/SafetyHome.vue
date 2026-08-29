<template>
	<div class="wikioasis-safety-home">
		<p class="wikioasis-safety-home__intro">{{ introText }}</p>

		<cdx-message
			v-if="submission.status !== 'idle'"
			:type="submissionMessage.type"
			:allow-user-dismiss="submission.status !== 'sending'"
			class="wikioasis-safety-home__submission"
		>
			{{ submissionMessage.text }}
		</cdx-message>

		<cdx-message
			v-if="fileMessageText"
			:type="fileMessageText.type"
			class="wikioasis-safety-home__submission"
		>
			{{ fileMessageText.text }}
		</cdx-message>

		<ul class="wikioasis-safety-home__grid">
			<li
				v-for="card in cards"
				:key="card.id"
				class="wikioasis-safety-home__cell"
				:class="'wikioasis-safety-home__cell--' + card.row"
			>
				<cdx-card
					v-if="card.href"
					:url="card.href"
					:icon="card.icon"
					class="wikioasis-safety-home__card"
				>
					<template #title>{{ card.label }}</template>
					<template #description>{{ card.description }}</template>
					<template #supporting-text>{{ card.note }}</template>
				</cdx-card>

				<button
					v-else
					type="button"
					:disabled="card.disabled || undefined"
					class="wikioasis-safety-home__button"
					@click="card.open()"
				>
					<cdx-card :icon="card.icon" class="wikioasis-safety-home__card">
						<template #title>{{ card.label }}</template>
						<template #description>{{ card.description }}</template>
						<template v-if="card.note" #supporting-text>
							{{ card.note }}
						</template>
					</cdx-card>
				</button>
			</li>
		</ul>

		<wizard-dialog
			v-model:open="openDialog.report"
			:wizard="reportWizard"
			:title="reportTitle"
			@submit="onSubmit( 'report', $event )"
		></wizard-dialog>

		<wizard-dialog
			v-model:open="openDialog.data"
			:wizard="dataWizard"
			:title="dataTitle"
			@submit="onSubmit( 'data', $event )"
		></wizard-dialog>

		<wizard-dialog
			v-model:open="openDialog.contact"
			:wizard="contactWizard"
			:title="contactTitle"
			:prefill="contactPrefill"
			:start-step="contactStartStep"
			@submit="onSubmit( 'contact', $event )"
		></wizard-dialog>

		<reports-dialog
			v-model:open="openDialog.reports"
			:reference="reportReference"
		></reports-dialog>

		<account-status-dialog
			v-model:open="openDialog.account"
			:can-appeal="!!contactWizard"
			@appeal="appeal"
		></account-status-dialog>
	</div>
</template>

<script>
const { computed, reactive, ref } = require( 'vue' );
const { CdxCard, CdxMessage } = require( '@wikimedia/codex' );
const icons = require( './icons.json' );
const WizardDialog = require( './WizardDialog.vue' );
const ReportsDialog = require( './ReportsDialog.vue' );
const AccountStatusDialog = require( './AccountStatusDialog.vue' );
const { clone } = require( './wizard.js' );
const { appealableActions } = require( './records.js' );
const portal = require( './portal.js' );
const { submitMessage, fileMessage } = require( './submitMessage.js' );

const APPEAL_STEP = 'appeal';
const APPEAL_REASON = 'appeal';

/**
 * @param {Object} wizard
 * @param {Array<Object>} actions
 * @return {Object}
 */
function withSanctionOptions( wizard, actions ) {
	const filled = clone( wizard );

	filled.steps.forEach( ( step ) => {
		step.fields.forEach( ( field ) => {
			if ( field.optionsFrom !== 'sanctions' ) {
				return;
			}
			field.options = actions.map( ( action ) => ( {
				value: action.id,
				label: action.type,
				description: mw.msg(
					'wikioasissafety-home-appeal-option', action.scope, action.id
				)
			} ) );
			if ( !field.options.length ) {
				field.description = mw.msg( 'wikioasissafety-home-appeal-none' );
			}
		} );
	} );

	if ( !actions.length ) {
		removeRoutesTo( filled, stepsWithSanctionFields( filled ) );
	}

	return filled;
}

/**
 * @param {Object} wizard
 * @return {string[]}
 */
function stepsWithSanctionFields( wizard ) {
	return wizard.steps
		.filter( ( step ) => step.fields.some( ( field ) => field.optionsFrom === 'sanctions' ) )
		.map( ( step ) => step.id );
}

/**
 * @param {Object} wizard Modified in place.
 * @param {string[]} stepIds Steps that have become pointless.
 */
function removeRoutesTo( wizard, stepIds ) {
	if ( !stepIds.length ) {
		return;
	}

	const routes = new Map();

	wizard.steps.forEach( ( step ) => {
		( step.branches || [] ).forEach( ( branch ) => {
			const when = branch.when;
			if ( !when || !stepIds.includes( branch.goTo ) ) {
				return;
			}
			if ( when.op !== 'equals' || !when.field ) {
				return;
			}
			if ( !routes.has( when.field ) ) {
				routes.set( when.field, new Set() );
			}
			routes.get( when.field ).add( when.value );
		} );
	} );

	wizard.steps.forEach( ( step ) => {
		step.fields = step.fields.filter( ( field ) => {
			const dead = routes.get( field.name );
			if ( !dead || !Array.isArray( field.options ) ) {
				return true;
			}

			field.options = field.options.filter( ( option ) => !dead.has( option.value ) );

			return field.options.length > 0;
		} );
	} );
}

// @vue/component
module.exports = exports = {
	name: 'SafetyHome',
	components: { CdxCard, CdxMessage, WizardDialog, ReportsDialog, AccountStatusDialog },
	props: {
		reportWizard: { type: Object, default: null },
		dataWizard: { type: Object, default: null },
		rawContactWizard: { type: Object, default: null },
		context: { type: Object, required: true }
	},
	setup( props ) {
		const openDialog = reactive( {
			report: false,
			data: false,
			reports: false,
			account: false,
			contact: false
		} );

		const contactPrefill = ref( null );
		const contactStartStep = ref( '' );
		const reportReference = ref( '' );

		const submission = reactive(
			{ status: 'idle', reference: null, anonymous: false, temporary: false } );
		const files = reactive( { stored: 0, refused: [] } );

		const contactWizard = computed( () => ( props.rawContactWizard ?
			withSanctionOptions(
				props.rawContactWizard,
				props.context.isNamed ? appealableActions() : []
			) :
			null ) );

		function appeal() {
			openDialog.account = false;
			contactPrefill.value = { 'contact-reason': APPEAL_REASON };
			contactStartStep.value = APPEAL_STEP;
			openDialog.contact = true;
		}

		function open( name ) {
			if ( name === 'contact' ) {
				contactPrefill.value = null;
				contactStartStep.value = '';
			}

			if ( name === 'reports' ) {
				reportReference.value = '';
			}
			openDialog[ name ] = true;
		}

		const autoOpen = props.context.autoOpen;
		if ( autoOpen && Object.prototype.hasOwnProperty.call( openDialog, autoOpen.dialog ) ) {
			reportReference.value = autoOpen.reference || '';
			openDialog[ autoOpen.dialog ] = true;
		}

		/**
		 * @param {boolean} configured
		 * @param {boolean} needsAccount
		 * @return {{ disabled: boolean, href: ?string, note: ?string }}
		 */
		function availability( configured, needsAccount ) {
			if ( !configured ) {
				return {
					disabled: true,
					href: null,
					note: mw.msg( 'wikioasissafety-home-unconfigured' )
				};
			}
			if ( needsAccount && !props.context.isNamed ) {
				return {
					disabled: false,
					href: props.context.loginUrl || null,
					note: mw.msg( props.context.isTemp ?
						'wikioasissafety-home-temporary' :
						'wikioasissafety-home-login' )
				};
			}
			return { disabled: false, href: null, note: null };
		}

		function card( id, icon, row, configured, needsAccount, opener ) {
			return Object.assign( {
				id: id,
				row: row,
				icon: icons[ icon ],
				label: mw.msg( 'wikioasissafety-home-' + id ),
				description: mw.msg( 'wikioasissafety-home-' + id + '-description' ),
				open: opener
			}, availability( configured, needsAccount ) );
		}

		const submissionMessage = computed( () => submitMessage( submission ) );
		const fileMessageText = computed( () => fileMessage( files ) );

		const cards = computed( () => [
			card( 'report', 'cdxIconFlag', 'half',
				!!props.reportWizard, false, () => open( 'report' ) ),
			card( 'data', 'cdxIconDatabase', 'half',
				!!props.dataWizard, true, () => open( 'data' ) ),
			card( 'reports', 'cdxIconTray', 'third',
				true, true, () => open( 'reports' ) ),
			card( 'account', 'cdxIconUserAvatar', 'third',
				true, true, () => open( 'account' ) ),
			card( 'contact', 'cdxIconSpeechBubbles', 'third',
				!!contactWizard.value, false, () => open( 'contact' ) )
		] );

		/**
		 * @param {string} which
		 * @param {Object} data
		 */
		function onSubmit( which, data ) {
			submission.status = 'sending';

			const wizard = {
				report: props.reportWizard,
				data: props.dataWizard,
				contact: contactWizard.value
			}[ which ];

			const attachments = portal.attachmentsIn( wizard, data );

			files.stored = 0;
			files.refused = [];

			portal.submit( which, data, {
				attachments: attachments
			} ).then( ( result ) => {
				submission.status = result.queued ? 'queued' : 'sent';
				submission.reference = result.reference;
				submission.anonymous = result.anonymous;
				submission.temporary = result.temporary;

				if ( !attachments.length ) {
					return;
				}

				portal.upload( result.reference, attachments, result.anonymous )
					.then( ( outcome ) => {
						files.stored = outcome.stored;
						files.refused = outcome.refused;
					} );
			}, ( code, error ) => {
				submission.status = code === 'ratelimited' ? 'throttled' : 'failed';
				submission.reference = null;
				mw.log.error( 'WikiOasisSafety: could not submit', code, error );
			} );
		}

		return {
			cards: cards,
			openDialog: openDialog,
			submission: submission,
			contactWizard: contactWizard,
			contactPrefill: contactPrefill,
			contactStartStep: contactStartStep,
			reportReference: reportReference,
			submissionMessage: submissionMessage,
			fileMessageText: fileMessageText,
			appeal: appeal,
			onSubmit: onSubmit,
			introText: mw.msg( 'wikioasissafety-home-intro' ),
			reportTitle: mw.msg( 'wikioasissafety-home-report' ),
			dataTitle: mw.msg( 'wikioasissafety-home-data' ),
			contactTitle: mw.msg( 'wikioasissafety-home-contact' )
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.wikioasis-safety-home {
	&__intro {
		max-width: 44rem;
		color: @color-subtle;
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat( 6, 1fr );
		gap: @spacing-100;
		margin: @spacing-150 0 0;
		padding: 0;
		list-style: none;
	}

	&__cell {
		display: flex;
		min-width: 0;

		&--half {
			grid-column: span 3;
		}

		&--third {
			grid-column: span 2;
		}
	}

	@media ( max-width: 50rem ) {
		&__grid {
			grid-template-columns: repeat( 2, 1fr );
		}

		&__cell--half,
		&__cell--third {
			grid-column: span 1;
		}
	}

	@media ( max-width: 32rem ) {
		&__grid {
			grid-template-columns: 1fr;
		}
	}

	&__button {
		display: block;
		box-sizing: border-box;
		width: 100%;
		min-width: 0;
		margin: 0;
		border: 0;
		padding: 0;
		background: none;
		font: inherit;
		color: inherit;
		text-align: inherit;
		cursor: @cursor-base--hover;

		&:disabled {
			cursor: @cursor-base--disabled;
		}
	}

	&__card {
		box-sizing: border-box;
		height: 100%;
		min-width: 0;
		align-items: flex-start;
	}

	&__card .cdx-card__text {
		min-width: 0;
		overflow-wrap: break-word;
	}

	&__button:focus-visible {
		outline: 0;

		.wikioasis-safety-home__card {
			outline: @outline-base--focus;
			border-color: @border-color-progressive--focus;
			box-shadow: @box-shadow-inset-medium @box-shadow-color-progressive--focus;
		}
	}

	&__button:hover:not( :disabled ) .wikioasis-safety-home__card {
		background-color: @background-color-interactive-subtle;
		border-color: @border-color-interactive;
	}

	&__button:disabled .wikioasis-safety-home__card {
		background-color: @background-color-disabled-subtle;
		border-color: @border-color-disabled;
		color: @color-disabled;

		.cdx-card__text__title,
		.cdx-card__text__description,
		.cdx-card__text__supporting-text,
		.cdx-icon {
			color: @color-disabled;
		}
	}

	&__card .cdx-card__text__supporting-text {
		font-weight: @font-weight-bold;
	}
}
</style>
