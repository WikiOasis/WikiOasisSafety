<?php

$wgWikiOasisSafetyWikis = [];

$wgWikiOasisSafetyWizard = [];

$wgWikiOasisSafetySteps = [

	'triage' => [
		'title-message' => 'wikioasissafety-flow-triage-title',
		'description-message' => 'wikioasissafety-flow-triage-description',

		'branches' => [
			[
				'when' => [ 'field' => 'help', 'op' => 'equals', 'value' => 'unacceptable' ],
				'goTo' => 'guidance',
			],
		],

		'fields' => [
			[
				'type' => 'radio',
				'name' => 'help',
				'label-message' => 'wikioasissafety-flow-help-label',
				'required' => true,
				'options' => [
					[
						'value' => 'unacceptable',
						'label-message' => 'wikioasissafety-flow-help-unacceptable',
						'description-message' => 'wikioasissafety-flow-help-unacceptable-description',
					],
				],
			],

			[
				'type' => 'radio',
				'name' => 'report',
				'label-message' => 'wikioasissafety-flow-report-label',
				'required' => true,
				'options' => [
					[
						'value' => 'threat-of-physical-harm',
						'label-message' => 'wikioasissafety-flow-report-threat',
						'description-message' => 'wikioasissafety-flow-report-threat-description',
					],
					[
						'value' => 'a-licensing-issue',
						'label-message' => 'wikioasissafety-flow-report-licensing',
						'description-message' => 'wikioasissafety-flow-report-licensing-description',
					],
					[
						'value' => 'a-child-protection-issue',
						'label-message' => 'wikioasissafety-flow-report-child',
						'description-message' => 'wikioasissafety-flow-report-child-description',
					],
					[
						'value' => 'harassment',
						'label-message' => 'wikioasissafety-flow-report-harassment',
						'description-message' => 'wikioasissafety-flow-report-harassment-description',
					],
					[
						'value' => 'underage-user',
						'label-message' => 'wikioasissafety-flow-report-underage',
						'description-message' => 'wikioasissafety-flow-report-underage-description',
					],
					[
						'value' => 'something-else',
						'label-message' => 'wikioasissafety-flow-report-other',
						'followUp' => [
							'type' => 'text',
							'name' => 'report-other',
							'label-message' => 'wikioasissafety-flow-report-other-followup',
						],
					],
				],
			],
		],
	],

	'report-subject' => [
		'title-message' => 'wikioasissafety-flow-subject-title',
		'fields' => [
			[
				'type' => 'checkboxGroup',
				'name' => 'concerns',
				'label-message' => 'wikioasissafety-flow-concerns-label',
				'required' => true,
				'options' => [
					[
						'value' => 'pages',
						'label-message' => 'wikioasissafety-flow-concerns-pages',
						'description-message' => 'wikioasissafety-flow-concerns-pages-description',
					],
					[
						'value' => 'users',
						'label-message' => 'wikioasissafety-flow-concerns-users',
						'description-message' => 'wikioasissafety-flow-concerns-users-description',
					],
					[
						'value' => 'wikis',
						'label-message' => 'wikioasissafety-flow-concerns-wikis',
						'description-message' => 'wikioasissafety-flow-concerns-wikis-description',
					],
				],
			],

			[
				'type' => 'chipInput',
				'name' => 'pages',
				'label-message' => 'wikioasissafety-flow-pages-label',
				'description-message' => 'wikioasissafety-flow-pages-description',
				'visibleWhen' => [ 'field' => 'concerns', 'op' => 'contains', 'value' => 'pages' ],
				'required' => true,
				'search' => 'pages',
				'searchNamespaces' => [ 0, 1, 2, 3, 4, 5 ],
			],

			[
				'type' => 'chipInput',
				'name' => 'users',
				'label-message' => 'wikioasissafety-flow-users-label',
				'description-message' => 'wikioasissafety-flow-users-description',
				'visibleWhen' => [ 'field' => 'concerns', 'op' => 'contains', 'value' => 'users' ],
				'required' => true,
				'search' => 'users',
			],

			[
				'type' => 'chipInput',
				'name' => 'wikis',
				'label-message' => 'wikioasissafety-flow-wikis-label',
				'description-message' => 'wikioasissafety-flow-wikis-description',
				'visibleWhen' => [ 'field' => 'concerns', 'op' => 'contains', 'value' => 'wikis' ],
				'required' => true,
				'search' => 'list',
				'searchOptions' => $wgWikiOasisSafetyWikis,
			],
		],
	],

	'report-details' => [
		'title-message' => 'wikioasissafety-flow-details-title',
		'fields' => [
			[
				'type' => 'textarea',
				'name' => 'details',
				'label-message' => 'wikioasissafety-flow-details-label',
				'description-message' => 'wikioasissafety-flow-details-description',
				'required' => true,
				'rows' => 6,
			],

			[
				'type' => 'radio',
				'name' => 'threat-immediacy',
				'label-message' => 'wikioasissafety-flow-threat-immediacy-label',
				'description-message' => 'wikioasissafety-flow-threat-immediacy-description',
				'visibleWhen' => [
					'field' => 'report', 'op' => 'equals', 'value' => 'threat-of-physical-harm',
				],
				'optional' => true,
				'options' => [
					[
						'value' => 'right-now',
						'label-message' => 'wikioasissafety-flow-threat-immediacy-now',
					],
					[
						'value' => 'threatened',
						'label-message' => 'wikioasissafety-flow-threat-immediacy-threatened',
					],
					[
						'value' => 'already-happened',
						'label-message' => 'wikioasissafety-flow-threat-immediacy-happened',
					],
				],
			],
			[
				'type' => 'radio',
				'name' => 'threat-authorities',
				'label-message' => 'wikioasissafety-flow-threat-authorities-label',
				'description-message' => 'wikioasissafety-flow-threat-authorities-description',
				'visibleWhen' => [
					'field' => 'report', 'op' => 'equals', 'value' => 'threat-of-physical-harm',
				],
				'optional' => true,
				'options' => [
					[ 'value' => 'yes', 'label-message' => 'wikioasissafety-flow-yes' ],
					[ 'value' => 'no', 'label-message' => 'wikioasissafety-flow-no' ],
					[ 'value' => 'unsure', 'label-message' => 'wikioasissafety-flow-unsure' ],
				],
			],
			[
				'type' => 'textarea',
				'name' => 'threat-where',
				'label-message' => 'wikioasissafety-flow-threat-where-label',
				'description-message' => 'wikioasissafety-flow-threat-where-description',
				'visibleWhen' => [
					'field' => 'report', 'op' => 'equals', 'value' => 'threat-of-physical-harm',
				],
				'optional' => true,
				'rows' => 3,
			],

			[
				'type' => 'radio',
				'name' => 'licensing-standing',
				'label-message' => 'wikioasissafety-flow-licensing-standing-label',
				'visibleWhen' => [
					'field' => 'report', 'op' => 'equals', 'value' => 'a-licensing-issue',
				],
				'optional' => true,
				'options' => [
					[
						'value' => 'rights-holder',
						'label-message' => 'wikioasissafety-flow-licensing-standing-holder',
					],
					[
						'value' => 'acting-for-holder',
						'label-message' => 'wikioasissafety-flow-licensing-standing-agent',
					],
					[
						'value' => 'neither',
						'label-message' => 'wikioasissafety-flow-licensing-standing-neither',
					],
				],
			],
			[
				'type' => 'textarea',
				'name' => 'licensing-source',
				'label-message' => 'wikioasissafety-flow-licensing-source-label',
				'description-message' => 'wikioasissafety-flow-licensing-source-description',
				'visibleWhen' => [
					'field' => 'report', 'op' => 'equals', 'value' => 'a-licensing-issue',
				],
				'optional' => true,
				'rows' => 3,
			],

			[
				'type' => 'message',
				'name' => 'child-notice',
				'messageType' => 'warning',
				'text-message' => 'wikioasissafety-flow-child-notice',
				'visibleWhen' => [
					'field' => 'report', 'op' => 'equals', 'value' => 'a-child-protection-issue',
				],
			],
			[
				'type' => 'radio',
				'name' => 'child-nature',
				'label-message' => 'wikioasissafety-flow-child-nature-label',
				'visibleWhen' => [
					'field' => 'report', 'op' => 'equals', 'value' => 'a-child-protection-issue',
				],
				'optional' => true,
				'options' => [
					[
						'value' => 'sexual-content',
						'label-message' => 'wikioasissafety-flow-child-nature-content',
					],
					[
						'value' => 'unwanted-contact',
						'label-message' => 'wikioasissafety-flow-child-nature-contact',
					],
					[
						'value' => 'personal-information',
						'label-message' => 'wikioasissafety-flow-child-nature-personal',
					],
					[
						'value' => 'other-risk',
						'label-message' => 'wikioasissafety-flow-child-nature-other',
					],
				],
			],
			[
				'type' => 'radio',
				'name' => 'child-authorities',
				'label-message' => 'wikioasissafety-flow-child-authorities-label',
				'description-message' => 'wikioasissafety-flow-child-authorities-description',
				'visibleWhen' => [
					'field' => 'report', 'op' => 'equals', 'value' => 'a-child-protection-issue',
				],
				'optional' => true,
				'options' => [
					[ 'value' => 'yes', 'label-message' => 'wikioasissafety-flow-yes' ],
					[ 'value' => 'no', 'label-message' => 'wikioasissafety-flow-no' ],
					[ 'value' => 'unsure', 'label-message' => 'wikioasissafety-flow-unsure' ],
				],
			],

			[
				'type' => 'radio',
				'name' => 'harassment-target',
				'label-message' => 'wikioasissafety-flow-harassment-target-label',
				'visibleWhen' => [ 'field' => 'report', 'op' => 'equals', 'value' => 'harassment' ],
				'optional' => true,
				'options' => [
					[ 'value' => 'me', 'label-message' => 'wikioasissafety-flow-harassment-target-me' ],
					[
						'value' => 'somebody-else',
						'label-message' => 'wikioasissafety-flow-harassment-target-other',
					],
					[
						'value' => 'several-people',
						'label-message' => 'wikioasissafety-flow-harassment-target-several',
					],
				],
			],
			[
				'type' => 'checkboxGroup',
				'name' => 'harassment-where',
				'label-message' => 'wikioasissafety-flow-harassment-where-label',
				'description-message' => 'wikioasissafety-flow-harassment-where-description',
				'visibleWhen' => [ 'field' => 'report', 'op' => 'equals', 'value' => 'harassment' ],
				'optional' => true,
				'options' => [
					[
						'value' => 'this-wiki',
						'label-message' => 'wikioasissafety-flow-harassment-where-thiswiki',
					],
					[
						'value' => 'another-wiki',
						'label-message' => 'wikioasissafety-flow-harassment-where-otherwiki',
					],
					[
						'value' => 'off-wiki',
						'label-message' => 'wikioasissafety-flow-harassment-where-offwiki',
					],
				],
			],
			[
				'type' => 'radio',
				'name' => 'harassment-ongoing',
				'label-message' => 'wikioasissafety-flow-harassment-ongoing-label',
				'visibleWhen' => [ 'field' => 'report', 'op' => 'equals', 'value' => 'harassment' ],
				'optional' => true,
				'options' => [
					[
						'value' => 'ongoing',
						'label-message' => 'wikioasissafety-flow-harassment-ongoing-yes',
					],
					[
						'value' => 'stopped',
						'label-message' => 'wikioasissafety-flow-harassment-ongoing-stopped',
					],
				],
			],

			[
				'type' => 'message',
				'name' => 'underage-notice',
				'text-message' => 'wikioasissafety-flow-underage-notice',
				'visibleWhen' => [ 'field' => 'report', 'op' => 'equals', 'value' => 'underage-user' ],
			],
			[
				'type' => 'radio',
				'name' => 'underage-basis',
				'label-message' => 'wikioasissafety-flow-underage-basis-label',
				'visibleWhen' => [ 'field' => 'report', 'op' => 'equals', 'value' => 'underage-user' ],
				'required' => true,
				'options' => [
					[
						'value' => 'said-on-wiki',
						'label-message' => 'wikioasissafety-flow-underage-basis-onwiki',
					],
					[
						'value' => 'said-elsewhere',
						'label-message' => 'wikioasissafety-flow-underage-basis-elsewhere',
					],
					[
						'value' => 'somebody-said-so',
						'label-message' => 'wikioasissafety-flow-underage-basis-someone',
					],
					[
						'value' => 'other',
						'label-message' => 'wikioasissafety-flow-underage-basis-other',
						'followUp' => [
							'type' => 'text',
							'name' => 'underage-basis-other',
							'label-message' => 'wikioasissafety-flow-underage-basis-other-followup',
							'required' => true,
						],
					],
				],
			],
			[
				'type' => 'textarea',
				'name' => 'underage-where',
				'label-message' => 'wikioasissafety-flow-underage-where-label',
				'description-message' => 'wikioasissafety-flow-underage-where-description',
				'visibleWhen' => [ 'field' => 'report', 'op' => 'equals', 'value' => 'underage-user' ],
				'optional' => true,
				'rows' => 3,
			],

			[
				'type' => 'fileUpload',
				'name' => 'attachments',
				'label-message' => 'wikioasissafety-flow-attachments-label',
				'optional' => true,
				'accept' => 'image/*,.pdf,.txt',
				'maxFiles' => 10,
				'maxSizeMb' => 25,
			],
		],
	],

	'report-final' => [
		'title-message' => 'wikioasissafety-flow-final-title',
		'isFinal' => true,
		'fields' => [
			[
				'type' => 'checkbox',
				'name' => 'threat-to-life',
				'label-message' => 'wikioasissafety-flow-threattolife-label',
				'optional' => true,
			],

			[
				'type' => 'toggle',
				'name' => 'anonymous',
				'label-message' => 'wikioasissafety-flow-anonymous-label',
				'description-message' => 'wikioasissafety-flow-anonymous-description',
			],
		],
	],

	'guidance' => [
		'title-message' => 'wikioasissafety-flow-guidance-title',
		'nextLabel-message' => 'wikioasissafety-flow-guidance-next',
		'backLabel-message' => 'wikioasissafety-flow-guidance-back',
		'fields' => [
			[
				'type' => 'card',
				'name' => 'guidance-sockpuppetry',
				'label-message' => 'wikioasissafety-flow-guidance-sockpuppetry',
				'description-message' => 'wikioasissafety-flow-guidance-sockpuppetry-description',
			],
			[
				'type' => 'card',
				'name' => 'guidance-spam',
				'label-message' => 'wikioasissafety-flow-guidance-spam',
				'description-message' => 'wikioasissafety-flow-guidance-spam-description',
			],
			[
				'type' => 'card',
				'name' => 'guidance-vandalism',
				'label-message' => 'wikioasissafety-flow-guidance-vandalism',
				'description-message' => 'wikioasissafety-flow-guidance-vandalism-description',
			],
		],
	],
];

$wgWikiOasisSafetyExclusiveFields = [
	[ 'help', 'report' ],
];

$wgWikiOasisSafetyFieldRoles = [
	'concerns' => 'concerns',
	'pages' => 'pages',
	'users' => 'users',
	'wikis' => 'wikis',
	'details' => 'details',
];

$wgWikiOasisSafetyCategories = [
	'threat-of-physical-harm' => [
		'label-message' => 'wikioasissafety-category-threat-of-physical-harm',
		'description-message' => 'wikioasissafety-category-threat-of-physical-harm-description',
		'group' => 'harm',
	],
	'a-child-protection-issue' => [
		'label-message' => 'wikioasissafety-category-a-child-protection-issue',
		'group' => 'harm',
	],
	'harassment' => [
		'label-message' => 'wikioasissafety-category-harassment',
		'group' => 'conduct',
	],
	'a-licensing-issue' => [
		'label-message' => 'wikioasissafety-category-a-licensing-issue',
		'group' => 'legal',
	],
	'underage-user' => [
		'label-message' => 'wikioasissafety-category-underage-user',
		'group' => 'eligibility',
	],
	'something-else' => [
		'label-message' => 'wikioasissafety-category-something-else',
	],
	'unacceptable' => [
		'label-message' => 'wikioasissafety-category-unacceptable',
		'group' => 'conduct',
	],
];

$wgWikiOasisSafetyDataWizard = [
	'title-message' => 'wikioasissafety-flow-data-title',
	'submitLabel-message' => 'wikioasissafety-flow-data-submit',
];

$wgWikiOasisSafetyDataSteps = [
	'erase' => [
		'title-message' => 'wikioasissafety-flow-data-erase-title',
		'description-message' => 'wikioasissafety-flow-data-erase-description',
		'isFinal' => true,
		'fields' => [
			[
				'type' => 'message',
				'name' => 'copy-elsewhere',
				'text-message' => 'wikioasissafety-flow-data-copy-elsewhere',
			],
			[
				'type' => 'textarea',
				'name' => 'erase-details',
				'label-message' => 'wikioasissafety-flow-data-erase-details-label',
				'description-message' => 'wikioasissafety-flow-data-erase-details-description',
				'optional' => true,
				'rows' => 4,
			],
			[
				'type' => 'checkbox',
				'name' => 'erase-confirm',
				'label-message' => 'wikioasissafety-flow-data-erase-confirm-label',
				'required' => true,
				'category' => 'erasure',
			],
		],
	],
];

$wgWikiOasisSafetyDataExclusiveFields = [];
$wgWikiOasisSafetyDataFieldRoles = [];

$wgWikiOasisSafetyDataCategories = [
	'erasure' => [
		'label-message' => 'wikioasissafety-category-data-erasure',
		'group' => 'data',
	],
	'rectification' => [
		'label-message' => 'wikioasissafety-category-data-rectification',
		'group' => 'data',
	],
	'other' => [
		'label-message' => 'wikioasissafety-category-data-other',
		'group' => 'data',
	],
];

$wgWikiOasisSafetyContactWizard = [
	'title-message' => 'wikioasissafety-flow-contact-title',
	'submitLabel-message' => 'wikioasissafety-flow-contact-submit',
];

$wgWikiOasisSafetyContactSteps = [

	'contact-reason' => [
		'title-message' => 'wikioasissafety-flow-contact-reason-title',
		'branches' => [
			[
				'when' => [ 'field' => 'contact-reason', 'op' => 'equals', 'value' => 'appeal' ],
				'goTo' => 'appeal',
			],
		],
		'fields' => [
			[
				'type' => 'radio',
				'name' => 'contact-reason',
				'label-message' => 'wikioasissafety-flow-contact-reason-label',
				'required' => true,
				'options' => [
					[
						'value' => 'general',
						'label-message' => 'wikioasissafety-flow-contact-reason-general',
						'description-message' => 'wikioasissafety-flow-contact-reason-general-description',
					],
					[
						'value' => 'appeal',
						'label-message' => 'wikioasissafety-flow-contact-reason-appeal',
						'description-message' => 'wikioasissafety-flow-contact-reason-appeal-description',
					],
					[
						'value' => 'other',
						'label-message' => 'wikioasissafety-flow-contact-reason-other',
						'description-message' => 'wikioasissafety-flow-contact-reason-other-description',
					],
				],
			],
		],
	],

	'contact-message' => [
		'title-message' => 'wikioasissafety-flow-contact-message-title',
		'isFinal' => true,
		'fields' => [
			[
				'type' => 'text',
				'name' => 'contact-subject',
				'label-message' => 'wikioasissafety-flow-contact-subject-label',
				'optional' => true,
				'maxLength' => 255,
			],
			[
				'type' => 'textarea',
				'name' => 'contact-message',
				'label-message' => 'wikioasissafety-flow-contact-message-label',
				'required' => true,
				'rows' => 6,
			],
			[
				'type' => 'fileUpload',
				'name' => 'contact-attachments',
				'label-message' => 'wikioasissafety-flow-contact-attachments-label',
				'optional' => true,
				'accept' => 'image/*,.pdf,.txt',
				'maxFiles' => 10,
				'maxSizeMb' => 25,
			],
		],
	],

	'appeal' => [
		'title-message' => 'wikioasissafety-flow-contact-appeal-title',
		'description-message' => 'wikioasissafety-flow-contact-appeal-description',
		'fields' => [
			[
				'type' => 'select',
				'name' => 'appeal-target',
				'label-message' => 'wikioasissafety-flow-contact-appeal-target-label',
				'defaultLabel-message' => 'wikioasissafety-flow-contact-appeal-target-default',
				'optionsFrom' => 'sanctions',
				'options' => [],
				'optional' => true,
			],
			[
				'type' => 'textarea',
				'name' => 'appeal-grounds',
				'label-message' => 'wikioasissafety-flow-contact-appeal-grounds-label',
				'description-message' => 'wikioasissafety-flow-contact-appeal-grounds-description',
				'required' => true,
				'rows' => 6,
			],
		],
	],
];

$wgWikiOasisSafetyContactExclusiveFields = [];
$wgWikiOasisSafetyContactFieldRoles = [];

$wgWikiOasisSafetyContactCategories = [
	'general' => [
		'label-message' => 'wikioasissafety-category-contact-general',
		'group' => 'contact',
	],
	'appeal' => [
		'label-message' => 'wikioasissafety-category-contact-appeal',
		'group' => 'contact',
	],
	'other' => [
		'label-message' => 'wikioasissafety-category-contact-other',
		'group' => 'contact',
	],
];
