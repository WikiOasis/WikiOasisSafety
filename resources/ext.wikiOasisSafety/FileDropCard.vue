<template>
	<div class="wikioasis-safety-drop">
		<div
			class="wikioasis-safety-drop__zone"
			:class="{
				'wikioasis-safety-drop__zone--active': isDragging,
				'wikioasis-safety-drop__zone--disabled': field.disabled,
				'wikioasis-safety-drop__zone--error': !!error
			}"
			@click="openPicker"
			@dragenter.prevent="onDragEnter"
			@dragover.prevent="onDragOver"
			@dragleave.prevent="onDragLeave"
			@drop.prevent="onDrop"
		>
			<cdx-icon :icon="icons.cdxIconUpload" class="wikioasis-safety-drop__icon"></cdx-icon>
			<p class="wikioasis-safety-drop__headline">
				{{ field.dropText || defaultDropText }}
			</p>
			<cdx-button
				type="button"
				:disabled="field.disabled || atLimit"
				@click.stop="openPicker"
			>
				{{ field.buttonLabel || defaultButtonLabel }}
			</cdx-button>
			<p v-if="constraintText" class="wikioasis-safety-drop__constraints">
				{{ constraintText }}
			</p>

			<input
				ref="input"
				type="file"
				class="wikioasis-safety-drop__input"
				tabindex="-1"
				:accept="field.accept || undefined"
				:multiple="field.multiple !== false"
				:disabled="field.disabled"
				@change="onPick"
			>
		</div>

		<ul v-if="files.length" class="wikioasis-safety-drop__list">
			<li
				v-for="( file, index ) in files"
				:key="key( file )"
				class="wikioasis-safety-drop__file"
			>
				<img
					v-if="field.showThumbnails !== false && thumbnail( file )"
					:src="thumbnail( file )"
					:alt="previewAlt( file )"
					class="wikioasis-safety-drop__thumb"
				>
				<cdx-icon
					v-else
					:icon="iconFor( file )"
					class="wikioasis-safety-drop__file-icon"
				></cdx-icon>
				<span class="wikioasis-safety-drop__file-text">
					<span class="wikioasis-safety-drop__file-name">{{ file.name }}</span>
					<span class="wikioasis-safety-drop__file-meta">{{ size( file ) }}</span>
				</span>
				<cdx-button
					type="button"
					weight="quiet"
					action="destructive"
					:aria-label="removeLabel( file )"
					:disabled="field.disabled"
					@click="remove( index )"
				>
					<cdx-icon :icon="icons.cdxIconTrash"></cdx-icon>
				</cdx-button>
			</li>
		</ul>

		<cdx-message v-if="files.length" type="notice" inline>
			{{ uploadNoticeText }}
		</cdx-message>

		<cdx-message
			v-for="( rejection, i ) in rejections"
			:key="i"
			type="error"
			inline
		>
			{{ rejection }}
		</cdx-message>
	</div>
</template>

<script>
const { computed, ref } = require( 'vue' );
const { CdxButton, CdxIcon, CdxMessage } = require( '@wikimedia/codex' );
const icons = require( './icons.json' );
const { fileKey, formatSize, constraintSummary, matchesAccept } = require( './fileHelpers.js' );
const fileStore = require( './fileStore.js' );

const thumbnails = new Map();

// @vue/component
module.exports = exports = {
	name: 'FileDropCard',
	components: { CdxButton, CdxIcon, CdxMessage },
	props: {
		field: { type: Object, required: true },
		data: { type: Object, required: true },
		error: { type: String, default: null }
	},
	setup( props ) {
		const input = ref( null );
		const isDragging = ref( false );
		const rejections = ref( [] );
		let dragDepth = 0;

		const files = computed( () => {
			const value = props.data[ props.field.name ];
			return Array.isArray( value ) ? value : [];
		} );

		const maxFiles = computed( () => {
			const limit = Number( props.field.maxFiles );
			return isFinite( limit ) && limit > 0 ? limit : null;
		} );

		const maxBytes = computed( () => {
			const limit = Number( props.field.maxSizeMb );
			return isFinite( limit ) && limit > 0 ? limit * 1024 * 1024 : null;
		} );

		const atLimit = computed(
			() => !!maxFiles.value && files.value.length >= maxFiles.value
		);

		const constraintText = computed( () => constraintSummary( props.field ) );

		function openPicker() {
			if ( !props.field.disabled && !atLimit.value && input.value ) {
				input.value.click();
			}
		}

		function onDragEnter() {
			dragDepth += 1;
			isDragging.value = !props.field.disabled;
		}

		function onDragOver() {
			isDragging.value = !props.field.disabled;
		}

		function onDragLeave() {
			dragDepth = Math.max( 0, dragDepth - 1 );
			if ( dragDepth === 0 ) {
				isDragging.value = false;
			}
		}

		function accept( incoming ) {
			const problems = [];
			const kept = files.value.slice();
			const single = props.field.multiple === false;

			incoming.forEach( ( file ) => {
				if ( single && kept.length ) {
					kept.length = 0;
				}
				if ( maxFiles.value && kept.length >= maxFiles.value ) {
					problems.push( mw.msg(
						'wikioasissafety-file-toomany', file.name, maxFiles.value
					) );
					return;
				}
				if ( !matchesAccept( file, props.field.accept ) ) {
					problems.push( mw.msg( 'wikioasissafety-file-badtype', file.name ) );
					return;
				}
				if ( maxBytes.value && file.size > maxBytes.value ) {
					problems.push( mw.msg(
						'wikioasissafety-file-toobig',
						file.name,
						formatSize( file.size ),
						props.field.maxSizeMb
					) );
					return;
				}
				if ( kept.some( ( existing ) => fileKey( existing ) === fileKey( file ) ) ) {
					problems.push( mw.msg( 'wikioasissafety-file-duplicate', file.name ) );
					return;
				}

				const entry = fileStore.remember( file );
				if (
					props.field.showThumbnails !== false &&
					( file.type || '' ).startsWith( 'image/' ) &&
					typeof URL !== 'undefined' &&
					typeof URL.createObjectURL === 'function'
				) {
					try {
						thumbnails.set( fileKey( entry ), URL.createObjectURL( file ) );
					} catch ( e ) {
					}
				}
				kept.push( entry );
			} );

			props.data[ props.field.name ] = kept;
			rejections.value = problems;
		}

		function onDrop( event ) {
			dragDepth = 0;
			isDragging.value = false;
			if ( props.field.disabled ) {
				return;
			}
			accept( Array.prototype.slice.call(
				( event.dataTransfer && event.dataTransfer.files ) || []
			) );
		}

		function onPick( event ) {
			accept( Array.prototype.slice.call( event.target.files || [] ) );
			event.target.value = '';
		}

		function remove( index ) {
			const next = files.value.slice();
			const removed = next.splice( index, 1 )[ 0 ];
			const id = fileKey( removed );
			if ( thumbnails.has( id ) ) {
				URL.revokeObjectURL( thumbnails.get( id ) );
				thumbnails.delete( id );
			}
			fileStore.forget( removed );
			props.data[ props.field.name ] = next;
			rejections.value = [];
		}

		function iconFor( file ) {
			if ( ( file.type || '' ).startsWith( 'image/' ) ) {
				return icons.cdxIconImage;
			}
			return ( file.type || '' ) === 'application/pdf' ?
				icons.cdxIconArticle :
				icons.cdxIconAttachment;
		}

		return {
			icons: icons,
			input: input,
			isDragging: isDragging,
			rejections: rejections,
			files: files,
			atLimit: atLimit,
			constraintText: constraintText,
			openPicker: openPicker,
			onDragEnter: onDragEnter,
			onDragOver: onDragOver,
			onDragLeave: onDragLeave,
			onDrop: onDrop,
			onPick: onPick,
			remove: remove,
			iconFor: iconFor,
			key: fileKey,
			size: ( file ) => formatSize( file.size ),
			thumbnail: ( file ) => thumbnails.get( fileKey( file ) ) || null,
			removeLabel: ( file ) => mw.msg( 'wikioasissafety-file-remove', file.name ),
			previewAlt: ( file ) => mw.msg( 'wikioasissafety-file-preview', file.name ),
			defaultDropText: mw.msg( 'wikioasissafety-file-drop' ),
			defaultButtonLabel: mw.msg( 'wikioasissafety-file-choose' ),
			uploadNoticeText: mw.msg( 'wikioasissafety-file-willupload' )
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.wikioasis-safety-drop {
	display: flex;
	flex-direction: column;
	gap: @spacing-75;

	&__zone {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: @spacing-75;
		border: @border-width-base dashed @border-color-base;
		border-radius: @border-radius-base;
		background-color: @background-color-interactive-subtle;
		padding: @spacing-200 @spacing-100;
		text-align: center;
		cursor: pointer;
		transition-property: background-color, border-color;
		transition-duration: @transition-duration-base;

		&:hover {
			border-color: @border-color-progressive;
			background-color: @background-color-progressive-subtle;
		}

		&--active {
			border-color: @border-color-progressive;
			border-style: solid;
			background-color: @background-color-progressive-subtle;
		}

		&--error {
			border-color: @border-color-error;
		}

		&--disabled,
		&--disabled:hover {
			border-color: @border-color-base;
			background-color: @background-color-disabled-subtle;
			cursor: default;
		}
	}

	&__icon {
		color: @color-subtle;
	}

	&__headline {
		margin: 0;
		font-weight: @font-weight-bold;
		color: @color-base;
	}

	&__constraints {
		margin: 0;
		color: @color-subtle;
		font-size: @font-size-small;
	}

	&__input {
		position: absolute;
		width: 1px;
		height: 1px;
		margin: -1px;
		padding: 0;
		overflow: hidden;
		border: 0;
		clip-path: inset( 50% );
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: @spacing-50;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__file {
		display: flex;
		align-items: center;
		gap: @spacing-50;
		border: @border-width-base solid @border-color-subtle;
		border-radius: @border-radius-base;
		background-color: @background-color-base;
		padding: @spacing-50 @spacing-75;
	}

	&__thumb {
		flex: none;
		width: 2.5rem;
		height: 2.5rem;
		border-radius: @border-radius-base;
		object-fit: cover;
	}

	&__file-icon {
		flex: none;
		color: @color-subtle;
	}

	&__file-text {
		flex: 1;
		min-width: 0;
		display: flex;
		flex-direction: column;
	}

	&__file-name {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		color: @color-base;
	}

	&__file-meta {
		color: @color-subtle;
		font-size: @font-size-small;
	}
}
</style>
