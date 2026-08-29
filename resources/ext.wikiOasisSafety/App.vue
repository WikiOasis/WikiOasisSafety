<template>
	<cdx-dialog
		v-model:open="open"
		:title="title"
		:use-close-button="true"
		:close-button-label="closeLabel"
		class="wikioasis-safety-dialog"
	>
		<safety-wizard
			:key="instance"
			:wizard="wizard"
			:data="answers"
			@close="open = false"
			@submit="onSubmit"
		></safety-wizard>

		<cdx-message
			v-if="result.status !== 'idle'"
			:type="resultMessage.type"
			:allow-user-dismiss="false"
		>
			{{ resultMessage.text }}
		</cdx-message>

		<cdx-message
			v-if="fileResultMessage"
			:type="fileResultMessage.type"
			:allow-user-dismiss="false"
		>
			{{ fileResultMessage.text }}
		</cdx-message>
	</cdx-dialog>
</template>

<script>
const { computed, reactive, ref } = require( 'vue' );
const { CdxDialog, CdxMessage } = require( '@wikimedia/codex' );
const SafetyWizard = require( './SafetyWizard.vue' );
const { answersFor } = require( './prefill.js' );
const portal = require( './portal.js' );
const { submitMessage, fileMessage } = require( './submitMessage.js' );

// @vue/component
module.exports = exports = {
	name: 'App',
	components: { CdxDialog, CdxMessage, SafetyWizard },
	props: {
		wizard: { type: Object, default: null }
	},
	setup( props ) {

		const open = ref( false );

		const instance = ref( 0 );

		const answers = reactive( {} );

		const result = reactive(
			{ status: 'idle', reference: null, anonymous: false, temporary: false } );

		const files = reactive( { stored: 0, refused: [] } );

		/**
		 * @param {Object} context
		 */
		function openWizard( context ) {
			Object.keys( answers ).forEach( ( key ) => {
				delete answers[ key ];
			} );
			Object.assign( answers, answersFor(
				context,
				props.wizard && props.wizard.fieldRoles
			) );
			result.status = 'idle';
			result.reference = null;
			files.stored = 0;
			files.refused = [];
			instance.value += 1;
			open.value = true;
		}

		function onSubmit( data ) {
			result.status = 'sending';

			const attachments = portal.attachmentsIn( props.wizard, data );

			portal.submit( 'report', data, {
				attachments: attachments
			} ).then( ( sent ) => {
				result.status = sent.queued ? 'queued' : 'sent';
				result.reference = sent.reference;
				result.anonymous = sent.anonymous;
				result.temporary = sent.temporary;

				if ( !attachments.length ) {
					return;
				}

				portal.upload( sent.reference, attachments, sent.anonymous ).then( ( outcome ) => {
					files.stored = outcome.stored;
					files.refused = outcome.refused;
				} );
			}, ( code, error ) => {
				result.status = code === 'ratelimited' ? 'throttled' : 'failed';
				mw.log.error( 'WikiOasisSafety: could not submit the report', code, error );
			} );
		}

		return {
			open: open,
			instance: instance,
			answers: answers,
			result: result,
			resultMessage: computed( () => submitMessage( result ) ),
			fileResultMessage: computed( () => fileMessage( files ) ),
			openWizard: openWizard,
			onSubmit: onSubmit,
			title: mw.msg( 'wikioasissafety-title' ),
			closeLabel: mw.msg( 'wikioasissafety-close' )
		};
	}
};
</script>

<style lang="less">
.wikioasis-safety-dialog {
	max-width: 40rem;
}
</style>
