<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety;

use MediaWiki\User\UserIdentity;

final class Anonymity {

	private const CONTROL_TYPES = [ 'checkbox', 'toggle' ];

	private const CONTROL_NAME = 'anonymous';

	/**
	 * @param array $field
	 */
	public static function isControl( array $field ): bool {
		if ( array_key_exists( self::CONTROL_NAME, $field ) ) {
			return (bool)$field[self::CONTROL_NAME];
		}

		return in_array( (string)( $field['type'] ?? '' ), self::CONTROL_TYPES, true )
			&& strtolower( trim( (string)( $field['name'] ?? '' ) ) ) === self::CONTROL_NAME;
	}

	/**
	 * @param array $flow
	 * @return list<string>
	 */
	public static function controlNames( array $flow ): array {
		$names = [];

		foreach ( $flow['steps'] ?? [] as $step ) {
			foreach ( $step['fields'] ?? [] as $field ) {
				if ( !is_array( $field ) ) {
					continue;
				}
				foreach ( self::withFollowUps( $field ) as $candidate ) {
					$name = (string)( $candidate['name'] ?? '' );
					if ( $name !== '' && self::isControl( $candidate ) ) {
						$names[$name] = true;
					}
				}
			}
		}

		return array_keys( $names );
	}

	/**
	 * @param array $flow
	 * @param array<string, mixed>
	 */
	public static function requestedIn( array $flow, array $answers ): bool {
		foreach ( self::controlNames( $flow ) as $name ) {
			if ( array_key_exists( $name, $answers ) && self::ticked( $answers[$name] ) ) {
				return true;
			}
		}

		return false;
	}

	public static function isForced( UserIdentity $user ): bool {
		return !$user->isRegistered();
	}

	/**
	 * @param UserIdentity $user
	 * @param array $flow
	 * @param array<string, mixed> $answers
	 * @param bool $askedByClient
	 */
	public static function of(
		UserIdentity $user,
		array $flow,
		array $answers,
		bool $askedByClient = false
	): bool {
		return self::isForced( $user )
			|| $askedByClient
			|| self::requestedIn( $flow, $answers );
	}

	/**
	 * @param array $flow
	 * @return array
	 */
	public static function withoutControl( array $flow ): array {
		foreach ( $flow['steps'] ?? [] as $s => $step ) {
			$fields = [];

			foreach ( $step['fields'] ?? [] as $field ) {
				if ( is_array( $field ) && self::isControl( $field ) ) {
					continue;
				}
				$fields[] = is_array( $field ) ? self::withoutFollowUpControl( $field ) : $field;
			}

			$flow['steps'][$s]['fields'] = $fields;
		}

		return $flow;
	}

	private static function withoutFollowUpControl( array $field ): array {
		foreach ( $field['options'] ?? [] as $i => $option ) {
			if (
				is_array( $option )
				&& isset( $option['followUp'] )
				&& is_array( $option['followUp'] )
				&& self::isControl( $option['followUp'] )
			) {
				unset( $field['options'][$i]['followUp'] );
			}
		}

		return $field;
	}

	/**
	 * @param array $field
	 * @return list<array>
	 */
	private static function withFollowUps( array $field ): array {
		$fields = [ $field ];

		foreach ( $field['options'] ?? [] as $option ) {
			if ( is_array( $option ) && isset( $option['followUp'] ) && is_array( $option['followUp'] ) ) {
				$fields[] = $option['followUp'];
			}
		}

		return $fields;
	}

	/**
	 * @param mixed $answer
	 */
	private static function ticked( $answer ): bool {
		if ( is_bool( $answer ) ) {
			return $answer;
		}
		if ( is_int( $answer ) || is_float( $answer ) ) {
			return (float)$answer === 1.0;
		}
		if ( is_string( $answer ) ) {
			return in_array( strtolower( trim( $answer ) ), [ '1', 'true', 'on', 'yes' ], true );
		}

		return false;
	}
}
