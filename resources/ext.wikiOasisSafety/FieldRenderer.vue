<template>
	<component
		:is="field.level || 'h3'"
		v-if="field.type === 'heading'"
		class="wikioasis-safety-heading"
	>
		{{ field.text }}
	</component>

	<p v-else-if="field.type === 'paragraph'" class="wikioasis-safety-paragraph">
		{{ field.text }}
	</p>

	<cdx-message
		v-else-if="field.type === 'message'"
		:type="field.messageType || 'notice'"
		:inline="field.inline"
		:allow-user-dismiss="field.allowUserDismiss"
	>
		{{ field.text }}
	</cdx-message>

	<cdx-accordion v-else-if="field.type === 'accordion'" v-model:open="accordionOpen">
		<template #title>{{ field.label }}</template>
		<template v-if="field.description" #description>{{ field.description }}</template>
		<p class="wikioasis-safety-paragraph">{{ field.text }}</p>
	</cdx-accordion>

	<cdx-card
		v-else-if="field.type === 'card'"
		:url="field.url || undefined"
		:icon="icon( field.startIcon )"
	>
		<template #title>{{ field.label }}</template>
		<template v-if="field.description" #description>{{ field.description }}</template>
		<template v-if="field.supportingText" #supporting-text>
			{{ field.supportingText }}
		</template>
	</cdx-card>

	<cdx-info-chip
		v-else-if="field.type === 'infoChip'"
		:status="field.status || 'notice'"
		:icon="icon( field.startIcon )"
	>
		{{ field.text }}
	</cdx-info-chip>

	<cdx-toggle-switch
		v-else-if="field.type === 'toggle'"
		v-model="model"
		:align-switch="field.alignSwitch"
		:disabled="field.disabled"
	>
		{{ field.label }}
		<template v-if="field.description" #description>{{ field.description }}</template>
	</cdx-toggle-switch>

	<div v-else-if="field.type === 'checkbox'">
		<cdx-checkbox v-model="model" :disabled="field.disabled" :status="status">
			{{ field.label }}
			<template v-if="field.description" #description>{{ field.description }}</template>
		</cdx-checkbox>
		<cdx-message v-if="selfError" type="error" inline>{{ selfError }}</cdx-message>
	</div>

	<cdx-field
		v-else
		:is-fieldset="isFieldset"
		:optional="field.optional"
		:disabled="field.disabled"
		:status="status"
		:messages="messages"
	>
		<template #label>{{ field.label }}</template>
		<template v-if="field.description" #description>{{ field.description }}</template>
		<template v-if="field.helpText" #help-text>{{ field.helpText }}</template>

		<cdx-text-input
			v-if="field.type === 'text'"
			v-model="model"
			:input-type="field.inputType || 'text'"
			:placeholder="field.placeholder"
			:clearable="field.clearable"
			:start-icon="icon( field.startIcon )"
			:disabled="field.disabled"
			:status="status"
			:maxlength="field.maxLength || undefined"
		></cdx-text-input>

		<cdx-text-area
			v-else-if="field.type === 'textarea'"
			v-model="model"
			:placeholder="field.placeholder"
			:autosize="field.autosize"
			:rows="field.rows || undefined"
			:disabled="field.disabled"
			:status="status"
			:maxlength="field.maxLength || undefined"
		></cdx-text-area>

		<cdx-select
			v-else-if="field.type === 'select'"
			v-model:selected="model"
			:menu-items="menuItems"
			:default-label="field.defaultLabel || undefined"
			:disabled="field.disabled"
			:status="status"
		></cdx-select>

		<cdx-combobox
			v-else-if="field.type === 'combobox'"
			v-model:selected="model"
			:menu-items="menuItems"
			:placeholder="field.placeholder"
			:disabled="field.disabled"
			:status="status"
		></cdx-combobox>

		<cdx-lookup
			v-else-if="field.type === 'lookup'"
			v-model:selected="model"
			:menu-items="lookupResults"
			:placeholder="field.placeholder"
			:disabled="field.disabled"
			:status="status"
			@input="onLookupInput"
		></cdx-lookup>

		<file-drop-card
			v-else-if="field.type === 'fileUpload'"
			:field="field"
			:data="data"
			:error="selfError"
		></file-drop-card>

		<!--
			A chip input that suggests as you type. Anything typed is still
			accepted, suggestion or not: see search.js.
		-->
		<cdx-multiselect-lookup
			v-else-if="field.type === 'chipInput' && searchable"
			v-model:input-chips="lookupChips"
			v-model:selected="lookupSelected"
			v-model:input-value="searchQuery"
			:menu-items="suggestions"
			:placeholder="field.placeholder"
			:separate-input="field.separateInput"
			:disabled="field.disabled"
			:status="status"
			@input="onSearchInput"
			@keydown.enter="onSearchEnter"
		></cdx-multiselect-lookup>

		<cdx-chip-input
			v-else-if="field.type === 'chipInput'"
			v-model:input-chips="chips"
			:placeholder="field.placeholder"
			:separate-input="field.separateInput"
			:disabled="field.disabled"
			:status="status"
		></cdx-chip-input>

		<template v-else-if="field.type === 'radio'">
			<cdx-radio
				v-for="( option, i ) in field.options"
				:key="option.value + '-' + i"
				v-model="model"
				:input-value="option.value"
				:name="field.name"
				:inline="field.inline"
				:disabled="field.disabled"
			>
				{{ option.label }}
				<template v-if="option.description" #description>
					{{ option.description }}
				</template>
				<!--
					Codex nests a follow-up control in the radio's own slot, so it
					stays indented under the option and is announced with it.
				-->
				<template v-if="showFollowUp( option )" #custom-input>
					<option-follow-up
						:field="field"
						:option="option"
						:data="data"
						:error="followUpError( option )"
					></option-follow-up>
				</template>
			</cdx-radio>
		</template>

		<template v-else-if="field.type === 'checkboxGroup'">
			<cdx-checkbox
				v-for="( option, i ) in field.options"
				:key="option.value + '-' + i"
				v-model="multiModel"
				:input-value="option.value"
				:inline="field.inline"
				:disabled="field.disabled"
			>
				{{ option.label }}
				<template v-if="option.description" #description>
					{{ option.description }}
				</template>
				<template v-if="showFollowUp( option )" #custom-input>
					<option-follow-up
						:field="field"
						:option="option"
						:data="data"
						:error="followUpError( option )"
					></option-follow-up>
				</template>
			</cdx-checkbox>
		</template>
	</cdx-field>
</template>

<script>
const { computed, ref, watch } = require( 'vue' );
const {
	CdxField, CdxTextInput, CdxTextArea, CdxSelect, CdxCombobox, CdxLookup,
	CdxChipInput, CdxMultiselectLookup, CdxRadio, CdxCheckbox, CdxToggleSwitch,
	CdxMessage, CdxAccordion, CdxCard, CdxInfoChip
} = require( '@wikimedia/codex' );
const OptionFollowUp = require( './OptionFollowUp.vue' );
const FileDropCard = require( './FileDropCard.vue' );
const { isOptionSelected, isEmptyValue, clearField } = require( './wizard.js' );
const search = require( './search.js' );
const SEARCH_DEBOUNCE_MS = 150;
const icons = require( './icons.json' );

// @vue/component
module.exports = exports = {
	name: 'FieldRenderer',
	components: {
		CdxField,
		CdxTextInput,
		CdxTextArea,
		CdxSelect,
		CdxCombobox,
		CdxLookup,
		CdxChipInput,
		CdxMultiselectLookup,
		CdxRadio,
		CdxCheckbox,
		CdxToggleSwitch,
		CdxMessage,
		CdxAccordion,
		CdxCard,
		CdxInfoChip,
		OptionFollowUp,
		FileDropCard
	},
	props: {
		field: { type: Object, required: true },
		data: { type: Object, required: true },
		errors: { type: Object, default: () => ( {} ) },
		exclusiveWith: { type: Array, default: () => [] }
	},
	setup( props ) {
		/**
		 * @param {*} value
		 */
		function write( value ) {
			props.data[ props.field.name ] = value;
			if ( !isEmptyValue( value ) ) {
				props.exclusiveWith.forEach( ( other ) => clearField( other, props.data ) );
			}
		}
		const selfError = computed( () => props.errors.self || null );
		const status = computed( () => ( selfError.value ? 'error' : 'default' ) );
		const messages = computed(
			() => ( selfError.value ? { error: selfError.value } : {} )
		);
		const isFieldset = computed(
			() => props.field.type === 'radio' || props.field.type === 'checkboxGroup'
		);

		const menuItems = computed( () => ( props.field.options || [] ).map( ( option ) => ( {
			value: option.value,
			label: option.label,
			description: option.description || undefined
		} ) ) );

		const model = computed( {
			get: function () {
				const value = props.data[ props.field.name ];
				if ( value !== undefined ) {
					return value;
				}
				return props.field.type === 'checkbox' || props.field.type === 'toggle' ?
					false :
					'';
			},
			set: function ( value ) {
				write( value );
			}
		} );

		const multiModel = computed( {
			get: function () {
				const value = props.data[ props.field.name ];
				return Array.isArray( value ) ? value : [];
			},
			set: function ( value ) {
				write( value );
			}
		} );

		const chips = computed( {
			get: function () {
				const value = props.data[ props.field.name ];
				return Array.isArray( value ) ?
					value.map( ( v ) => ( { value: String( v ) } ) ) :
					[];
			},
			set: function ( value ) {
				write( value.map( ( chip ) => chip.value ) );
			}
		} );

		const searchable = computed( () => search.isSearchable( props.field ) );
		const searchQuery = ref( '' );
		const suggestions = ref( [] );

		function toChip( value ) {
			return { value: String( value ), label: String( value ) };
		}

		function answerValues() {
			const value = props.data[ props.field.name ];
			return Array.isArray( value ) ? value.map( String ) : [];
		}

		function sameList( a, b ) {
			return a.length === b.length && a.every( ( item, i ) => item === b[ i ] );
		}

		const lookupChips = ref( answerValues().map( toChip ) );
		const lookupSelected = ref( answerValues() );

		watch( lookupChips, ( items ) => {
			const values = items.map( ( item ) => String( item.value ) );
			if ( !sameList( values, lookupSelected.value.map( String ) ) ) {
				lookupSelected.value = values;
			}
			if ( !sameList( values, answerValues() ) ) {
				write( values );
			}
		} );

		watch( lookupSelected, ( values ) => {
			const chosen = values.map( String );
			if ( !sameList( chosen, lookupChips.value.map( ( item ) => String( item.value ) ) ) ) {
				lookupChips.value = chosen.map( toChip );
			}
		} );

		watch( () => answerValues().join( '\u0001' ), () => {
			const values = answerValues();
			if ( !sameList( values, lookupChips.value.map( ( item ) => String( item.value ) ) ) ) {
				lookupChips.value = values.map( toChip );
			}
		} );

		let searchTimer = null;
		let searchSequence = 0;

		function onSearchInput( value ) {
			const query = String( value || '' );
			const mine = ++searchSequence;
			if ( searchTimer ) {
				clearTimeout( searchTimer );
			}
			if ( !query.trim() ) {
				suggestions.value = [];
				return;
			}
			searchTimer = setTimeout( () => {
				search.suggest( props.field, query ).then( ( matches ) => {
					if ( mine !== searchSequence ) {
						return;
					}
					const already = lookupChips.value.map( ( item ) => String( item.value ) );
					const items = matches
						.filter( ( match ) => !already.includes( match ) )
						.map( ( match ) => ( { value: match, label: match } ) );
					const typed = query.trim();
					if (
						typed &&
						!already.includes( typed ) &&
						!items.some( ( item ) => item.value === typed )
					) {
						items.unshift( {
							value: typed,
							label: typed,
							description: mw.msg( 'wikioasissafety-search-add' )
						} );
					}
					suggestions.value = items;
				} );
			}, SEARCH_DEBOUNCE_MS );
		}

		function onSearchEnter() {
			const typed = String( searchQuery.value || '' ).trim();
			if ( !typed || lookupChips.value.some( ( item ) => String( item.value ) === typed ) ) {
				return;
			}
			lookupChips.value = lookupChips.value.concat( [ toChip( typed ) ] );
			searchQuery.value = '';
			suggestions.value = [];
		}

		const accordionOpen = ref( !!props.field.open );
		watch( () => props.field.open, ( open ) => {
			accordionOpen.value = !!open;
		} );

		const lookupResults = ref( [] );
		function onLookupInput( value ) {
			const query = String( value || '' ).toLowerCase();
			lookupResults.value = !query ?
				[] :
				menuItems.value.filter(
					( item ) => item.label.toLowerCase().includes( query )
				);
		}

		return {
			selfError: selfError,
			status: status,
			messages: messages,
			isFieldset: isFieldset,
			menuItems: menuItems,
			model: model,
			multiModel: multiModel,
			chips: chips,
			accordionOpen: accordionOpen,
			searchable: searchable,
			searchQuery: searchQuery,
			suggestions: suggestions,
			lookupChips: lookupChips,
			lookupSelected: lookupSelected,
			onSearchInput: onSearchInput,
			onSearchEnter: onSearchEnter,
			lookupResults: lookupResults,
			onLookupInput: onLookupInput,
			icon: ( name ) => ( name && icons[ name ] ? icons[ name ] : null ),
			showFollowUp: ( option ) => !!option.followUp &&
				isOptionSelected( props.field, option, props.data ),
			followUpError: ( option ) => ( props.errors.followUps || {} )[ option.value ] || null
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.wikioasis-safety-heading {
	margin: 0;
	font-weight: @font-weight-bold;
	line-height: @line-height-medium;
	color: @color-base;
}

.wikioasis-safety-paragraph {
	margin: 0;
	color: @color-subtle;
	line-height: @line-height-medium;
}
</style>
