<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety;

use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MessageLocalizer;

class Wording {

	public const SUFFIX = '-message';

	/**
	 * @param array $node
	 * @param ?MessageLocalizer $localizer
	 * @return array
	 */
	public static function localise( array $node, ?MessageLocalizer $localizer ): array {
		$resolved = [];
		foreach ( $node as $key => $value ) {
			$target = self::targetOf( (string)$key );
			if ( $target === null ) {
				continue;
			}
			$specifier = self::asSpecifier( $value, (string)$key );
			if ( $specifier ) {
				$resolved[$target] = self::text( $specifier, $node[$target] ?? null, $localizer );
			}
		}

		if ( !$resolved ) {
			return $node;
		}

		$localised = [];
		foreach ( $node as $key => $value ) {
			$target = self::targetOf( (string)$key ) ?? (string)$key;
			if ( array_key_exists( $target, $resolved ) ) {
				// A literal beside a message: the message wins, and takes
				// whichever of the two positions came first.
				$localised[$target] = $resolved[$target];
				continue;
			}
			$localised[$key] = $value;
		}

		return $localised;
	}

	/**
	 * @param string $key
	 * @return ?string
	 */
	private static function targetOf( string $key ): ?string {
		if ( $key === self::SUFFIX || !str_ends_with( $key, self::SUFFIX ) ) {
			return null;
		}
		return substr( $key, 0, -strlen( self::SUFFIX ) );
	}

	/**
	 * @param mixed $value
	 * @param string $key
	 * @return ?list<string>
	 */
	private static function asSpecifier( $value, string $key ): ?array {
		if ( is_string( $value ) && trim( $value ) !== '' ) {
			return [ trim( $value ) ];
		}

		if ( is_array( $value ) && $value ) {
			$specifier = [];
			foreach ( $value as $part ) {
				if ( !is_scalar( $part ) ) {
					wfLogWarning( "WikiOasisSafety: '$key' has a parameter that is not a "
						. 'string or a number; ignoring the whole message' );
					return null;
				}
				$specifier[] = (string)$part;
			}
			return trim( $specifier[0] ) !== '' ? $specifier : null;
		}

		wfLogWarning( "WikiOasisSafety: '$key' should be a message key, or a list of a "
			. 'message key and its parameters; ignoring' );
		return null;
	}

	/**
	 * @param list<string> $specifier
	 * @param mixed $literal
	 * @param ?MessageLocalizer $localizer
	 * @return string
	 */
	private static function text( array $specifier, $literal, ?MessageLocalizer $localizer ): string {
		$fallback = is_string( $literal ) ? $literal : '';
		$key = array_shift( $specifier );

		if ( !$localizer ) {
			return $fallback !== '' ? $fallback : $key;
		}

		$message = $localizer->msg( $key, ...$specifier );
		if ( !$message->exists() ) {
			wfLogWarning( "WikiOasisSafety: the wizard names a message '$key' that does "
				. 'not exist' );
			return $fallback !== '' ? $fallback : $message->text();
		}

		return $message->text();
	}

	/**
	 * @return MessageLocalizer
	 */
	public static function inContentLanguage(): MessageLocalizer {
		return new class implements MessageLocalizer {
			/** @inheritDoc */
			public function msg( $key, ...$params ): Message {
				return wfMessage( $key, ...$params )->inLanguage(
					MediaWikiServices::getInstance()->getContentLanguage()
				);
			}
		};
	}
}
