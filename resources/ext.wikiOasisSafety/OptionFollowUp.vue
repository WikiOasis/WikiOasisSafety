<template>
	<cdx-field class="wikioasis-safety-followup" :status="status" :messages="messages">
		<template #label>
			<span v-if="option.followUp.label">{{ option.followUp.label }}</span>
			<span v-else class="wikioasis-safety-visually-hidden">{{ fallbackLabel }}</span>
		</template>
		<template v-if="option.followUp.description" #description>
			{{ option.followUp.description }}
		</template>

		<cdx-text-area
			v-if="option.followUp.type === 'textarea'"
			v-model="model"
			:placeholder="option.followUp.placeholder"
			:status="status"
			autosize
		></cdx-text-area>
		<cdx-text-input
			v-else
			v-model="model"
			:input-type="option.followUp.type === 'number' ? 'number' : 'text'"
			:placeholder="option.followUp.placeholder"
			:status="status"
		></cdx-text-input>
	</cdx-field>
</template>

<script>
const { computed } = require( 'vue' );
const { CdxField, CdxTextArea, CdxTextInput } = require( '@wikimedia/codex' );
const { followUpName } = require( './wizard.js' );

// @vue/component
module.exports = exports = {
	name: 'OptionFollowUp',
	components: { CdxField, CdxTextArea, CdxTextInput },
	props: {
		field: { type: Object, required: true },
		option: { type: Object, required: true },
		data: { type: Object, required: true },
		error: { type: String, default: null }
	},
	setup( props ) {
		const key = computed( () => followUpName( props.field, props.option ) );
		const status = computed( () => ( props.error ? 'error' : 'default' ) );
		const messages = computed( () => ( props.error ? { error: props.error } : {} ) );

		const model = computed( {
			get: function () {
				const value = props.data[ key.value ];
				return value === undefined || value === null ? '' : value;
			},
			set: function ( value ) {
				props.data[ key.value ] = value;
			}
		} );

		return {
			status: status,
			messages: messages,
			model: model,
			fallbackLabel: mw.msg( 'wikioasissafety-followup-label' )
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.wikioasis-safety-followup {
	margin-top: @spacing-50;
	margin-bottom: @spacing-75;
}

.wikioasis-safety-visually-hidden {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	border: 0;
	clip-path: inset( 50% );
	white-space: nowrap;
}
</style>
