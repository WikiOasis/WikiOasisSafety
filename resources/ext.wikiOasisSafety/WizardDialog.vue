<template>
	<cdx-dialog
		v-model:open="isOpen"
		:title="title"
		:use-close-button="true"
		:close-button-label="closeLabel"
		class="wikioasis-safety-dialog"
	>
		<safety-wizard
			:key="instance"
			:wizard="wizard"
			:data="answers"
			:start-step="startStep"
			@close="isOpen = false"
			@submit="$emit( 'submit', $event )"
		></safety-wizard>
	</cdx-dialog>
</template>

<script>
const { computed, reactive, ref, watch } = require( 'vue' );
const { CdxDialog } = require( '@wikimedia/codex' );
const SafetyWizard = require( './SafetyWizard.vue' );

// @vue/component
module.exports = exports = {
	name: 'WizardDialog',
	components: { CdxDialog, SafetyWizard },
	props: {
		open: { type: Boolean, default: false },
		wizard: { type: Object, default: null },
		title: { type: String, default: '' },
		prefill: { type: Object, default: null },
		startStep: { type: String, default: '' }
	},
	emits: [ 'update:open', 'submit' ],
	setup( props, { emit } ) {
		const instance = ref( 0 );
		const answers = reactive( {} );

		const isOpen = computed( {
			get: () => props.open,
			set: ( value ) => emit( 'update:open', value )
		} );

		watch( () => [ props.open, props.wizard ], ( [ open ] ) => {
			if ( !open ) {
				return;
			}
			Object.keys( answers ).forEach( ( key ) => {
				delete answers[ key ];
			} );
			Object.assign( answers, props.prefill || {} );
			instance.value += 1;
		} );

		return {
			isOpen: isOpen,
			instance: instance,
			answers: answers,
			closeLabel: mw.msg( 'wikioasissafety-close' )
		};
	}
};
</script>
