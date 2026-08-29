'use strict';

const shared = {
	label: '',
	description: '',
	helpText: '',
	required: false,
	optional: false,
	disabled: false,
	visibleWhen: null
};

module.exports = exports = {
	text: {
		collectsData: true,
		defaults: Object.assign( {}, shared, {
			placeholder: '', inputType: 'text', startIcon: '', clearable: false, maxLength: null
		} )
	},
	textarea: {
		collectsData: true,
		defaults: Object.assign( {}, shared, {
			placeholder: '', autosize: true, rows: 4, maxLength: null
		} )
	},
	select: {
		collectsData: true,
		defaults: Object.assign( {}, shared, { defaultLabel: '', options: [] } )
	},
	radio: {
		collectsData: true,
		defaults: Object.assign( {}, shared, { inline: false, options: [] } )
	},
	checkboxGroup: {
		collectsData: true,
		defaults: Object.assign( {}, shared, { inline: false, options: [] } )
	},
	checkbox: {
		collectsData: true,
		defaults: Object.assign( {}, shared, {} )
	},
	toggle: {
		collectsData: true,
		defaults: Object.assign( {}, shared, { alignSwitch: true } )
	},
	combobox: {
		collectsData: true,
		defaults: Object.assign( {}, shared, { placeholder: '', options: [] } )
	},
	lookup: {
		collectsData: true,
		defaults: Object.assign( {}, shared, { placeholder: '', options: [] } )
	},
	chipInput: {
		collectsData: true,
		defaults: Object.assign( {}, shared, {
			placeholder: '',
			separateInput: false,
			search: '',
			searchNamespaces: [],
			searchOptions: []
		} )
	},
	fileUpload: {
		collectsData: true,
		defaults: Object.assign( {}, shared, {
			dropText: '',
			buttonLabel: '',
			accept: '',
			multiple: true,
			maxFiles: 10,
			maxSizeMb: 10,
			showThumbnails: true
		} )
	},
	heading: {
		collectsData: false,
		defaults: { text: '', level: 'h3', visibleWhen: null }
	},
	paragraph: {
		collectsData: false,
		defaults: { text: '', visibleWhen: null }
	},
	message: {
		collectsData: false,
		defaults: {
			text: '', messageType: 'notice', inline: false, allowUserDismiss: false, visibleWhen: null
		}
	},
	accordion: {
		collectsData: false,
		defaults: { label: '', description: '', text: '', open: false, visibleWhen: null }
	},
	card: {
		collectsData: false,
		defaults: {
			label: '', description: '', supportingText: '', startIcon: '', url: '', visibleWhen: null
		}
	},
	infoChip: {
		collectsData: false,
		defaults: { text: '', status: 'notice', startIcon: '', visibleWhen: null }
	}
};
