<template>
	<cdx-dialog
		v-model:open="isOpen"
		:title="dialogTitle"
		:use-close-button="true"
		:close-button-label="closeLabel"
		class="wikioasis-safety-dialog wikioasis-safety-reports"
	>
		<div v-if="!selected" class="wikioasis-safety-reports__list">
			<p class="wikioasis-safety-paragraph">{{ introText }}</p>

			<cdx-message v-if="!reports.length" type="notice">
				{{ emptyText }}
			</cdx-message>

			<ul v-else class="wikioasis-safety-reports__items">
				<li v-for="report in reports" :key="report.id">
					<button
						type="button"
						class="wikioasis-safety-reports__item"
						@click="open( report )"
					>
						<span class="wikioasis-safety-reports__item-head">
							<span class="wikioasis-safety-reports__subject">
								{{ report.subject }}
							</span>
							<cdx-info-chip :status="statusOf( report ).status">
								{{ statusOf( report ).text }}
							</cdx-info-chip>
						</span>
						<span class="wikioasis-safety-reports__meta">
							{{ listMeta( report ) }}
						</span>
					</button>
				</li>
			</ul>
		</div>

		<div v-else class="wikioasis-safety-reports__detail">
			<cdx-button
				weight="quiet"
				type="button"
				class="wikioasis-safety-reports__back"
				@click="selected = null"
			>
				<cdx-icon :icon="backIcon"></cdx-icon>
				{{ backText }}
			</cdx-button>

			<div class="wikioasis-safety-reports__summary">
				<div class="wikioasis-safety-reports__item-head">
					<h3 class="wikioasis-safety-reports__subject">{{ selected.subject }}</h3>
					<cdx-info-chip :status="statusOf( selected ).status">
						{{ statusOf( selected ).text }}
					</cdx-info-chip>
				</div>
				<dl class="wikioasis-safety-reports__facts">
					<div>
						<dt>{{ referenceLabel }}</dt>
						<dd>{{ selected.id }}</dd>
					</div>
					<div>
						<dt>{{ filedLabel }}</dt>
						<dd>{{ formatDate( selected.filed ) }}</dd>
					</div>
					<div v-if="categoryNames.length">
						<dt>{{ categoryLabel }}</dt>
						<dd>{{ categoryNames.join( ', ' ) }}</dd>
					</div>
					<div v-if="selected.about && selected.about.length">
						<dt>{{ aboutLabel }}</dt>
						<dd>{{ selected.about.join( ', ' ) }}</dd>
					</div>

					<template v-if="appeal && appeal.action">
						<div>
							<dt>{{ appealAgainstLabel }}</dt>
							<dd class="wikioasis-safety-reports__appeal">
								<span>{{ appealActionText }}</span>
								<cdx-info-chip :status="appeal.action.in_force ? 'warning' : 'success'">
									{{ appeal.action.in_force ? appealInForceLabel : appealLiftedLabel }}
								</cdx-info-chip>
							</dd>
						</div>
						<div v-if="appeal.action.reason">
							<dt>{{ appealReasonLabel }}</dt>
							<dd>{{ appeal.action.reason }}</dd>
						</div>
						<div v-if="appeal.action.where">
							<dt>{{ appealAppliesLabel }}</dt>
							<dd>{{ appeal.action.where }}</dd>
						</div>
					</template>

					<div v-if="appeal">
						<dt>{{ appealOutcomeLabel }}</dt>
						<dd>{{ appealOutcomeText }}</dd>
					</div>
				</dl>

				<cdx-message
					v-if="appeal && !appeal.action"
					type="notice"
					:inline="false"
				>
					{{ appealUnmatchedText }}
				</cdx-message>
				<p v-if="selected.summary" class="wikioasis-safety-paragraph">
					{{ selected.summary }}
				</p>
			</div>

			<section class="wikioasis-safety-reports__thread">
				<h4 class="wikioasis-safety-reports__thread-title">{{ commentsLabel }}</h4>

				<p v-if="!selected.comments.length" class="wikioasis-safety-paragraph">
					{{ noCommentsText }}
				</p>

				<ol v-else class="wikioasis-safety-reports__comments">
					<li
						v-for="( comment, index ) in selected.comments"
						:key="index"
						class="wikioasis-safety-comment"
						:class="commentClass( comment )"
					>
						<div class="wikioasis-safety-comment__head">
							<span class="wikioasis-safety-comment__author">
								{{ comment.name || authorLabel( comment ) }}
							</span>
							<span class="wikioasis-safety-comment__date">
								{{ formatDate( comment.date ) }}
							</span>
						</div>
						<p class="wikioasis-safety-comment__text">{{ comment.text }}</p>
					</li>
				</ol>
			</section>

			<form class="wikioasis-safety-reports__reply" @submit.prevent="addComment">
				<cdx-field :status="replyStatus" :messages="replyMessages">
					<template #label>{{ addLabel }}</template>
					<template #description>{{ addDescriptionText }}</template>
					<cdx-text-area
						v-model="reply"
						:rows="3"
						:placeholder="addPlaceholderText"
						:status="replyStatus"
					></cdx-text-area>
				</cdx-field>
				<div class="wikioasis-safety-reports__reply-actions">
					<cdx-button action="progressive" weight="primary" type="submit">
						{{ addButtonText }}
					</cdx-button>
				</div>
				<cdx-message v-if="failed" type="error" :inline="false">
					{{ failedText }}
				</cdx-message>
				<cdx-message v-else-if="sending" type="notice" :inline="false">
					{{ sendingText }}
				</cdx-message>
				<cdx-message v-else-if="added && queued" type="warning" :inline="false">
					{{ queuedText }}
				</cdx-message>
				<cdx-message v-else-if="added" type="success" :inline="false">
					{{ addedText }}
				</cdx-message>
			</form>
		</div>
	</cdx-dialog>
</template>

<script>
const { computed, ref, watch } = require( 'vue' );
const {
	CdxButton, CdxDialog, CdxField, CdxIcon, CdxInfoChip, CdxMessage, CdxTextArea
} = require( '@wikimedia/codex' );
const icons = require( './icons.json' );
const { visibleReports } = require( './records.js' );

const APPEAL_OUTCOMES = {
	granted: 'wikioasissafety-home-reports-appeal-outcome-granted',
	'partly-granted': 'wikioasissafety-home-reports-appeal-outcome-partly-granted',
	declined: 'wikioasissafety-home-reports-appeal-outcome-declined',
	withdrawn: 'wikioasissafety-home-reports-appeal-outcome-withdrawn',
	invalid: 'wikioasissafety-home-reports-appeal-outcome-invalid'
};
const portal = require( './portal.js' );
const { date, reportStatus } = require( './format.js' );

// @vue/component
module.exports = exports = {
	name: 'ReportsDialog',
	components: {
		CdxButton, CdxDialog, CdxField, CdxIcon, CdxInfoChip, CdxMessage, CdxTextArea
	},
	props: {
		open: { type: Boolean, default: false },
		reference: { type: String, default: '' }
	},
	emits: [ 'update:open' ],
	setup( props, { emit } ) {
		// A copy, because comments added here are written into it. The sample
		// data is a module singleton; editing it in place would leak one
		// dialog's demo into the next thing that reads it.
		const reports = ref( JSON.parse( JSON.stringify( visibleReports() ) ) );
		const selected = ref( null );
		const reply = ref( '' );
		const added = ref( false );
		const replyStatus = ref( 'default' );

		const sending = ref( false );
		const queued = ref( false );
		const failed = ref( false );

		const isOpen = computed( {
			get: () => props.open,
			set: ( value ) => emit( 'update:open', value )
		} );

		watch( () => props.open, ( open ) => {
			reply.value = '';
			added.value = false;
			replyStatus.value = 'default';
			sending.value = false;
			queued.value = false;
			failed.value = false;

			selected.value = open && props.reference
				? reports.value.find( ( report ) => report.id === props.reference ) || null
				: null;
		}, { immediate: true } );

		function open( report ) {
			selected.value = report;
			reply.value = '';
			added.value = false;
			replyStatus.value = 'default';
			sending.value = false;
			queued.value = false;
			failed.value = false;
		}

		function addComment() {
			const text = reply.value.trim();
			if ( !text ) {
				replyStatus.value = 'error';
				return;
			}

			replyStatus.value = 'default';
			const now = new Date().toISOString();
			const optimistic = { author: 'you', name: null, date: now, text: text };
			const report = selected.value;

			report.comments.push( optimistic );
			report.updated = now;
			reply.value = '';
			added.value = true;
			sending.value = true;
			failed.value = false;

			portal.comment( report.id, text ).then( ( result ) => {
				sending.value = false;
				queued.value = result.queued;
			}, ( code, error ) => {
				sending.value = false;
				failed.value = true;
				added.value = false;

				const at = report.comments.indexOf( optimistic );
				if ( at !== -1 ) {
					report.comments.splice( at, 1 );
				}
				reply.value = text;

				mw.log.error( 'WikiOasisSafety: could not add a comment', code, error );
			} );
		}

		return {
			isOpen: isOpen,
			reports: reports,
			selected: selected,
			reply: reply,
			added: added,
			replyStatus: replyStatus,
			sending: sending,
			queued: queued,
			failed: failed,
			open: open,
			addComment: addComment,
			statusOf: ( report ) => reportStatus( report.status ),
			formatDate: date,

			authorLabel: ( comment ) => mw.msg( comment.author === 'you' ?
				'wikioasissafety-home-reports-you' :
				'wikioasissafety-home-reports-staff' ),
			commentClass: ( comment ) => 'wikioasis-safety-comment--' +
				( comment.author === 'you' ? 'you' : 'staff' ),
			listMeta: ( report ) => mw.msg(
				'wikioasissafety-home-reports-meta',
				report.id,
				date( report.filed ),
				date( report.updated )
			),
			replyMessages: computed( () => ( {
				error: mw.msg( 'wikioasissafety-error-required' )
			} ) ),

			categoryNames: computed( () => ( selected.value && selected.value.categories || [] )
				.map( ( category ) => category.label || category.id )
				.filter( Boolean ) ),

			appeal: computed( () => ( selected.value && selected.value.appeal ) || null ),

			appealActionText: computed( () => {
				const action = selected.value && selected.value.appeal && selected.value.appeal.action;

				return action ? mw.msg(
					'wikioasissafety-home-reports-appeal-action',
					action.reference,
					action.label
				) : '';
			} ),

			appealOutcomeText: computed( () => {
				const outcome = selected.value && selected.value.appeal && selected.value.appeal.outcome;

				if ( !outcome ) {
					return mw.msg( 'wikioasissafety-home-reports-appeal-undecided' );
				}

				return APPEAL_OUTCOMES[ outcome ] ? mw.msg( APPEAL_OUTCOMES[ outcome ] ) : outcome;
			} ),

			appealAgainstLabel: mw.msg( 'wikioasissafety-home-reports-appeal-against' ),
			appealInForceLabel: mw.msg( 'wikioasissafety-home-reports-appeal-inforce' ),
			appealLiftedLabel: mw.msg( 'wikioasissafety-home-reports-appeal-lifted' ),
			appealReasonLabel: mw.msg( 'wikioasissafety-home-reports-appeal-reason' ),
			appealAppliesLabel: mw.msg( 'wikioasissafety-home-reports-appeal-applies' ),
			appealOutcomeLabel: mw.msg( 'wikioasissafety-home-reports-appeal-outcome' ),
			appealUnmatchedText: mw.msg( 'wikioasissafety-home-reports-appeal-unmatched' ),

			dialogTitle: computed( () => ( selected.value ?
				selected.value.id :
				mw.msg( 'wikioasissafety-home-reports-title' ) ) ),
			backIcon: icons.cdxIconArrowPrevious,
			closeLabel: mw.msg( 'wikioasissafety-close' ),
			introText: mw.msg( 'wikioasissafety-home-reports-intro' ),
			emptyText: mw.msg( 'wikioasissafety-home-reports-empty' ),
			backText: mw.msg( 'wikioasissafety-home-reports-back' ),
			referenceLabel: mw.msg( 'wikioasissafety-home-reports-reference' ),
			filedLabel: mw.msg( 'wikioasissafety-home-reports-filed' ),
			aboutLabel: mw.msg( 'wikioasissafety-home-reports-about' ),
			categoryLabel: mw.msg( 'wikioasissafety-home-reports-category' ),
			commentsLabel: mw.msg( 'wikioasissafety-home-reports-comments' ),
			noCommentsText: mw.msg( 'wikioasissafety-home-reports-nocomments' ),
			addLabel: mw.msg( 'wikioasissafety-home-reports-add' ),
			addDescriptionText: mw.msg( 'wikioasissafety-home-reports-add-description' ),
			addPlaceholderText: mw.msg( 'wikioasissafety-home-reports-add-placeholder' ),
			addButtonText: mw.msg( 'wikioasissafety-home-reports-add-button' ),
			addedText: mw.msg( 'wikioasissafety-home-reports-added' ),
			sendingText: mw.msg( 'wikioasissafety-comment-sending' ),
			queuedText: mw.msg( 'wikioasissafety-comment-queued' ),
			failedText: mw.msg( 'wikioasissafety-comment-failed' )
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.wikioasis-safety-reports {
	&__list,
	&__detail {
		display: flex;
		flex-direction: column;
		gap: @spacing-150;
	}

	&__items {
		display: flex;
		flex-direction: column;
		gap: @spacing-50;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__item {
		display: flex;
		flex-direction: column;
		gap: @spacing-25;
		width: 100%;
		border: @border-width-base solid @border-color-base;
		border-radius: @border-radius-base;
		padding: @spacing-75 @spacing-100;
		background-color: @background-color-base;
		font-family: inherit;
		font-size: inherit;
		text-align: left;
		cursor: @cursor-base--hover;
		transition-property: background-color, border-color;
		transition-duration: @transition-duration-base;

		&:hover {
			background-color: @background-color-interactive-subtle;
			border-color: @border-color-interactive;
		}

		&:focus-visible {
			outline: @outline-base--focus;
			box-shadow: @box-shadow-inset-medium @box-shadow-color-progressive--focus;
			border-color: @border-color-progressive--focus;
		}
	}

	&__item-head {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: @spacing-50;
		flex-wrap: wrap;
	}

	&__appeal {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: @spacing-50;
	}

	&__subject {
		margin: 0;
		padding: 0;
		border: 0;
		font-size: @font-size-medium;
		font-weight: @font-weight-bold;
		line-height: @line-height-medium;
		color: @color-base;
	}

	&__meta {
		font-size: @font-size-small;
		color: @color-subtle;
	}

	&__back {
		align-self: flex-start;
	}

	&__summary {
		display: flex;
		flex-direction: column;
		gap: @spacing-50;
	}

	&__facts {
		display: flex;
		flex-wrap: wrap;
		gap: @spacing-25 @spacing-150;
		margin: 0;
		font-size: @font-size-small;

		div {
			display: flex;
			gap: @spacing-25;
		}

		dt {
			color: @color-subtle;
		}

		dd {
			margin: 0;
			color: @color-base;
		}
	}

	&__thread {
		display: flex;
		flex-direction: column;
		gap: @spacing-50;
		border-top: @border-width-base solid @border-color-subtle;
		padding-top: @spacing-100;
	}

	&__thread-title {
		margin: 0;
		padding: 0;
		border: 0;
		font-size: @font-size-medium;
		font-weight: @font-weight-bold;
		color: @color-base;
	}

	&__comments {
		display: flex;
		flex-direction: column;
		gap: @spacing-75;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__reply {
		display: flex;
		flex-direction: column;
		gap: @spacing-75;
		border-top: @border-width-base solid @border-color-subtle;
		padding-top: @spacing-100;
	}

	&__reply-actions {
		display: flex;
		justify-content: flex-end;
	}
}

.wikioasis-safety-comment {
	border-left: @border-width-thick solid @border-color-subtle;
	padding-left: @spacing-75;

	&--staff {
		border-left-color: @border-color-progressive;
		background-color: @background-color-progressive-subtle;
		border-radius: 0 @border-radius-base @border-radius-base 0;
		padding: @spacing-50 @spacing-75;
	}

	&__head {
		display: flex;
		align-items: baseline;
		gap: @spacing-50;
		flex-wrap: wrap;
	}

	&__author {
		font-weight: @font-weight-bold;
		color: @color-base;
	}

	&__date {
		font-size: @font-size-small;
		color: @color-subtle;
	}

	&__text {
		margin: @spacing-25 0 0;
		color: @color-base;
	}
}
</style>
