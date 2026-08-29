<template>
	<cdx-dialog
		v-model:open="isOpen"
		:title="dialogTitle"
		:use-close-button="true"
		:close-button-label="closeLabel"
		class="wikioasis-safety-dialog wikioasis-safety-standing"
	>
		<div class="wikioasis-safety-standing__body">
			<cdx-message :type="standing.messageType" :inline="false">
				<strong>{{ standing.headline }}</strong>
				<p class="wikioasis-safety-paragraph">{{ standing.explanation }}</p>
			</cdx-message>

			<p class="wikioasis-safety-standing__since">{{ sinceText }}</p>

			<section class="wikioasis-safety-standing__timeline-section">
				<h3 class="wikioasis-safety-standing__title">{{ historyLabel }}</h3>

				<p v-if="!actions.length" class="wikioasis-safety-paragraph">
					{{ noActionsText }}
				</p>

				<ol v-else class="wikioasis-safety-timeline">
					<li
						v-for="action in actions"
						:key="action.id"
						class="wikioasis-safety-timeline__entry"
						:class="{
							'wikioasis-safety-timeline__entry--active': action.active
						}"
					>
						<span class="wikioasis-safety-timeline__marker" aria-hidden="true"></span>
						<div class="wikioasis-safety-timeline__content">
							<div class="wikioasis-safety-timeline__head">
								<h4 class="wikioasis-safety-timeline__type">{{ action.type }}</h4>
								<cdx-info-chip :status="action.active ? 'warning' : 'notice'">
									{{ action.active ? activeLabel : endedLabel }}
								</cdx-info-chip>
							</div>
							<p class="wikioasis-safety-timeline__dates">
								{{ issuedText( action ) }} · {{ expiryText( action ) }}
							</p>
							<p class="wikioasis-safety-timeline__scope">
								{{ scopeText( action ) }}
							</p>
							<p class="wikioasis-safety-timeline__reason">{{ action.reason }}</p>
							<p class="wikioasis-safety-timeline__reference">
								{{ referenceText( action ) }}
							</p>
						</div>
					</li>
				</ol>
			</section>

			<footer v-if="canAppeal" class="wikioasis-safety-standing__appeal">
				<p class="wikioasis-safety-paragraph">{{ appealText }}</p>
				<cdx-button action="progressive" type="button" @click="$emit( 'appeal' )">
					{{ appealButtonText }}
				</cdx-button>
			</footer>
		</div>
	</cdx-dialog>
</template>

<script>
const { computed } = require( 'vue' );
const { CdxButton, CdxDialog, CdxInfoChip, CdxMessage } = require( '@wikimedia/codex' );
const { account, appealableActions } = require( './records.js' );
const ACCOUNT = account();
const { date, expiry } = require( './format.js' );

const STANDING = {
	good: {
		messageType: 'success',
		headline: 'wikioasissafety-home-account-good',
		explanation: 'wikioasissafety-home-account-good-detail'
	},
	restricted: {
		messageType: 'warning',
		headline: 'wikioasissafety-home-account-restricted',
		explanation: 'wikioasissafety-home-account-restricted-detail'
	},
	suspended: {
		messageType: 'error',
		headline: 'wikioasissafety-home-account-suspended',
		explanation: 'wikioasissafety-home-account-suspended-detail'
	}
};

// @vue/component
module.exports = exports = {
	name: 'AccountStatusDialog',
	components: { CdxButton, CdxDialog, CdxInfoChip, CdxMessage },
	props: {
		open: { type: Boolean, default: false },
		canAppeal: { type: Boolean, default: false }
	},
	emits: [ 'update:open', 'appeal' ],
	setup( props, { emit } ) {
		const isOpen = computed( {
			get: () => props.open,
			set: ( value ) => emit( 'update:open', value )
		} );

		const standing = computed( () => {
			const chosen = STANDING[ ACCOUNT.standing ] || STANDING.good;
			return {
				messageType: chosen.messageType,
				headline: mw.msg( chosen.headline ),
				explanation: mw.msg( chosen.explanation )
			};
		} );

		const actions = computed( () => ( ACCOUNT.actions || [] ).slice().sort(
			( a, b ) => new Date( b.issued ) - new Date( a.issued )
		) );

		return {
			isOpen: isOpen,
			standing: standing,
			actions: actions,
			issuedText: ( action ) => mw.msg(
				'wikioasissafety-home-account-issued', date( action.issued )
			),
			expiryText: expiry,
			scopeText: ( action ) => mw.msg(
				'wikioasissafety-home-account-scope', action.scope
			),
			referenceText: ( action ) => mw.msg(
				'wikioasissafety-home-account-reference', action.id
			),
			dialogTitle: mw.msg( 'wikioasissafety-home-account-title' ),
			closeLabel: mw.msg( 'wikioasissafety-close' ),
			sinceText: mw.msg(
				'wikioasissafety-home-account-since', date( ACCOUNT.registered )
			),
			historyLabel: mw.msg( 'wikioasissafety-home-account-history' ),
			noActionsText: mw.msg( 'wikioasissafety-home-account-noactions' ),
			activeLabel: mw.msg( 'wikioasissafety-home-account-active' ),
			endedLabel: mw.msg( 'wikioasissafety-home-account-ended' ),
			appealText: appealableActions().length ?
				mw.msg( 'wikioasissafety-home-account-appeal' ) :
				mw.msg( 'wikioasissafety-home-account-appeal-none' ),
			appealButtonText: mw.msg( 'wikioasissafety-home-account-appeal-button' )
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.wikioasis-safety-standing {
	&__body {
		display: flex;
		flex-direction: column;
		gap: @spacing-150;
	}

	&__since {
		margin: 0;
		font-size: @font-size-small;
		color: @color-subtle;
	}

	&__timeline-section {
		display: flex;
		flex-direction: column;
		gap: @spacing-75;
	}

	&__title {
		margin: 0;
		padding: 0;
		border: 0;
		font-size: @font-size-medium;
		font-weight: @font-weight-bold;
		color: @color-base;
	}

	&__appeal {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: @spacing-75;
		flex-wrap: wrap;
		border-top: @border-width-base solid @border-color-subtle;
		padding-top: @spacing-100;
	}
}

.wikioasis-safety-timeline {
	margin: 0;
	padding: 0;
	list-style: none;

	&__entry {
		position: relative;
		padding: 0 0 @spacing-150 @spacing-200;

		&::before {
			content: '';
			position: absolute;
			top: @spacing-25;
			bottom: 0;
			left: 5px;
			width: @border-width-base;
			background-color: @border-color-subtle;
		}

		&:last-child {
			padding-bottom: 0;

			&::before {
				display: none;
			}
		}
	}

	&__marker {
		position: absolute;
		top: @spacing-25;
		left: 0;
		width: 11px;
		height: 11px;
		border: @border-width-thick solid @border-color-subtle;
		border-radius: @border-radius-circle;
		background-color: @background-color-base;
	}

	&__entry--active &__marker {
		border-color: @border-color-warning;
		background-color: @background-color-warning-subtle;
	}

	&__head {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: @spacing-50;
		flex-wrap: wrap;
	}

	&__type {
		margin: 0;
		padding: 0;
		border: 0;
		font-size: @font-size-medium;
		font-weight: @font-weight-bold;
		color: @color-base;
	}

	&__dates,
	&__scope,
	&__reference {
		margin: @spacing-25 0 0;
		font-size: @font-size-small;
		color: @color-subtle;
	}

	&__reason {
		margin: @spacing-50 0 0;
		color: @color-base;
	}
}
</style>
