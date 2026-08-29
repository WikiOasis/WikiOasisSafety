<template>
	<div class="wikioasis-safety-wizard">
		<cdx-message v-if="!wizard" type="error">{{ brokenText }}</cdx-message>

		<template v-else>
			<div v-if="submitted" class="wikioasis-safety-wizard__body">
				<cdx-message type="success">{{ submittedText }}</cdx-message>
				<p class="wikioasis-safety-paragraph">{{ submittedDetailsText }}</p>
				<footer class="wikioasis-safety-actions">
					<div class="wikioasis-safety-actions__end">
						<cdx-button
							action="progressive"
							weight="primary"
							type="button"
							@click="$emit( 'close' )"
						>
							{{ closeText }}
						</cdx-button>
					</div>
				</footer>
			</div>

			<cdx-message v-else-if="!currentStep" type="error">{{ brokenText }}</cdx-message>

			<form v-else class="wikioasis-safety-wizard__body" @submit.prevent="next">
				<div class="wikioasis-safety-step-heading">
					<h3
						v-if="currentStep.title"
						class="wikioasis-safety-step-heading__title"
					>
						{{ currentStep.title }}
					</h3>
					<p
						v-if="currentStep.description"
						class="wikioasis-safety-paragraph"
					>
						{{ currentStep.description }}
					</p>
				</div>

				<div
					v-for="field in visibleFields"
					:key="field.id"
					class="wikioasis-safety-field"
				>
					<field-renderer
						:field="field"
						:data="data"
						:errors="errors[ field.id ] || {}"
						:exclusive-with="exclusiveWith( field )"
					></field-renderer>
				</div>

				<cdx-message v-if="showErrorSummary" type="error">
					{{ errorSummaryText }}
				</cdx-message>

				<footer class="wikioasis-safety-actions">
					<cdx-button
						v-if="currentStep.showBack !== false"
						type="button"
						:disabled="!history.length"
						@click="back"
					>
						{{ backLabel }}
					</cdx-button>
					<div class="wikioasis-safety-actions__end">
						<cdx-button
							v-if="currentStep.skip"
							type="button"
							weight="quiet"
							@click="skip"
						>
							{{ currentStep.skip.label || nextLabel }}
						</cdx-button>
						<cdx-button
							:action="currentStep.nextAction || 'progressive'"
							weight="primary"
							type="submit"
						>
							{{ nextLabel }}
						</cdx-button>
					</div>
				</footer>
			</form>
		</template>
	</div>
</template>

<script>
const { computed, ref } = require( 'vue' );
const { CdxButton, CdxMessage } = require( '@wikimedia/codex' );
const FieldRenderer = require( './FieldRenderer.vue' );
const FIELD_TYPES = require( './fieldTypes.js' );
const anonymity = require( './anonymity.js' );
const {
	evaluateCondition, validateField, hasErrors, resolveNext, resolveSkip,
	isStepVisible, SUBMIT
} = require( './wizard.js' );

/**
 * @param {Object|null} wizard
 * @return {Object}
 */
function fieldsByName( wizard ) {
	const byName = {};
	( wizard ? wizard.steps : [] ).forEach( ( step ) => {
		step.fields.forEach( ( field ) => {
			byName[ field.name ] = field;
		} );
	} );
	return byName;
}

// @vue/component
module.exports = exports = {
	name: 'SafetyWizard',
	components: { CdxButton, CdxMessage, FieldRenderer },
	props: {
		wizard: { type: Object, default: null },
		data: { type: Object, required: true },
		startStep: { type: String, default: '' }
	},
	emits: [ 'close', 'submit' ],
	setup( props, { emit } ) {
		const stepId = ref( ( () => {
			if ( !props.wizard ) {
				return null;
			}
			const wanted = props.wizard.steps.find( ( step ) => step.id === props.startStep );
			return wanted ? wanted.id : props.wizard.steps[ 0 ].id;
		} )() );
		const history = ref( [] );
		const submitted = ref( false );
		const errors = ref( {} );
		const showErrorSummary = ref( false );

		const currentStep = computed( () => {
			if ( !props.wizard ) {
				return null;
			}
			const byId = props.wizard.steps.find( ( step ) => step.id === stepId.value );
			if ( byId && isStepVisible( byId, props.data ) ) {
				return byId;
			}
			return props.wizard.steps.find(
				( step ) => isStepVisible( step, props.data )
			) || null;
		} );

		const visibleFields = computed( () => {
			if ( !currentStep.value ) {
				return [];
			}
			return currentStep.value.fields.filter(
				( field ) => evaluateCondition( field.visibleWhen, props.data )
			);
		} );

		const isGuidanceStep = computed( () => !!currentStep.value &&
			!currentStep.value.fields.some(
				( field ) => FIELD_TYPES[ field.type ].collectsData
			) );

		const exclusiveGroups = computed( () => {
			const byName = fieldsByName( props.wizard );
			const groups = {};
			( ( props.wizard && props.wizard.exclusiveFields ) || [] ).forEach( ( group ) => {
				group.forEach( ( name ) => {
					const others = group
						.filter( ( other ) => other !== name )
						.map( ( other ) => byName[ other ] )
						.filter( Boolean );
					groups[ name ] = ( groups[ name ] || [] ).concat( others );
				} );
			} );
			return groups;
		} );

		const nextTarget = computed( () => ( currentStep.value ?
			resolveNext( props.wizard, currentStep.value, props.data ) :
			SUBMIT ) );

		const nextLabel = computed( () => {
			if ( currentStep.value && currentStep.value.nextLabel ) {
				return currentStep.value.nextLabel;
			}
			if ( nextTarget.value !== SUBMIT ) {
				return props.wizard.nextLabel || mw.msg( 'wikioasissafety-next' );
			}
			return isGuidanceStep.value ?
				mw.msg( 'wikioasissafety-close' ) :
				props.wizard.submitLabel || mw.msg( 'wikioasissafety-submit' );
		} );

		const backLabel = computed( () => ( currentStep.value && currentStep.value.backLabel ) ||
			props.wizard.backLabel ||
			mw.msg( 'wikioasissafety-back' ) );

		function validate() {
			const found = {};
			visibleFields.value.forEach( ( field ) => {
				const result = validateField( field, props.data, props.wizard );
				if ( hasErrors( result ) ) {
					found[ field.id ] = result;
				}
			} );
			errors.value = found;
			showErrorSummary.value = Object.keys( found ).length > 0;
			return !showErrorSummary.value;
		}

		function goTo( target ) {
			if ( target === SUBMIT ) {
				if ( isGuidanceStep.value ) {
					emit( 'close' );
					return;
				}
				emit( 'submit', props.data );
				submitted.value = true;
				return;
			}
			history.value = history.value.concat( [ currentStep.value.id ] );
			stepId.value = target;
			errors.value = {};
			showErrorSummary.value = false;
		}

		function next() {
			if ( validate() ) {
				goTo( nextTarget.value );
			}
		}

		function skip() {
			errors.value = {};
			showErrorSummary.value = false;
			goTo( resolveSkip( props.wizard, currentStep.value, props.data ) );
		}

		function back() {
			errors.value = {};
			showErrorSummary.value = false;
			const walked = history.value.slice();
			const previous = walked.pop();
			if ( previous ) {
				history.value = walked;
				stepId.value = previous;
			}
		}

		const submittedDetailsText = computed( () => mw.msg(
			anonymity.expected( props.wizard, props.data ) ?
				'wikioasissafety-submitted-details-anonymous' :
				'wikioasissafety-submitted-details'
		) );

		return {
			stepId: stepId,
			history: history,
			submitted: submitted,
			errors: errors,
			showErrorSummary: showErrorSummary,
			currentStep: currentStep,
			visibleFields: visibleFields,
			nextLabel: nextLabel,
			backLabel: backLabel,
			next: next,
			skip: skip,
			exclusiveWith: ( field ) => exclusiveGroups.value[ field.name ] || [],
			back: back,
			brokenText: mw.msg( 'wikioasissafety-noflow' ),
			closeText: mw.msg( 'wikioasissafety-close' ),
			errorSummaryText: mw.msg( 'wikioasissafety-error-summary' ),
			submittedText: mw.msg( 'wikioasissafety-submitted' ),
			submittedDetailsText: submittedDetailsText
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.wikioasis-safety-wizard {
	display: flex;
	flex-direction: column;
	gap: @spacing-150;

	&__body {
		display: flex;
		flex-direction: column;
		gap: @spacing-200;
	}
}

.wikioasis-safety-step-heading {
	display: flex;
	flex-direction: column;
	gap: @spacing-50;

	&__title {
		margin: 0;
		padding: 0;
		border: 0;
		font-size: @font-size-large;
		font-weight: @font-weight-bold;
		line-height: @line-height-medium;
		color: @color-base;
	}
}

.wikioasis-safety-wizard {
	.cdx-radio + .cdx-radio,
	.cdx-checkbox + .cdx-checkbox {
		margin-top: @spacing-50;
	}

	.cdx-card + .cdx-card {
		margin-top: @spacing-50;
	}
}

.wikioasis-safety-actions {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: @spacing-50;
	border-top: @border-width-base solid @border-color-subtle;
	margin-top: @spacing-50;
	padding-top: @spacing-150;

	&__end {
		display: flex;
		gap: @spacing-50;
		margin-left: auto;
	}
}
</style>
