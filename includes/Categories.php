<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety;

use MediaWiki\Config\Config;
use MessageLocalizer;

class Categories {

	/**
	 * @param Config $config
	 * @param string $flow
	 * @param ?MessageLocalizer $localizer
	 * @return array<string, array{id: string, label: string, group: ?string, description: ?string}>
	 */
	public static function vocabulary(
		Config $config,
		string $flow = '',
		?MessageLocalizer $localizer = null
	): array {
		$name = 'WikiOasisSafety' . $flow . 'Categories';
		$declared = $config->get( $name );

		if ( !is_array( $declared ) ) {
			wfLogWarning( "WikiOasisSafety: \$wg$name is not an array; ignoring" );
			return [];
		}

		$vocabulary = [];

		foreach ( $declared as $id => $category ) {
			$id = self::normalise( (string)$id );
			if ( $id === '' ) {
				continue;
			}

			if ( is_string( $category ) ) {
				$category = [ 'label' => $category ];
			}

			if ( !is_array( $category ) ) {
				wfLogWarning( "WikiOasisSafety: \$wg{$name}['$id'] is not an array or a string; ignoring" );
				continue;
			}

			$category = Wording::localise( $category, $localizer );

			$vocabulary[$id] = [
				'id' => $id,
				'label' => (string)( $category['label'] ?? $id ),
				'group' => isset( $category['group'] ) ? (string)$category['group'] : null,
				'description' => isset( $category['description'] )
					? (string)$category['description']
					: null,
			];
		}

		return $vocabulary;
	}

	/**
	 * @param array $flow
	 * @param array<string, mixed> $answers
	 * @return list<array{id: string, label: string, group: ?string, field: string}>
	 */
	public static function resolve( array $flow, array $answers ): array {
		$vocabulary = $flow['categories'] ?? [];
		if ( !$vocabulary ) {
			return [];
		}

		$found = [];

		foreach ( $flow['steps'] ?? [] as $step ) {
			foreach ( $step['fields'] ?? [] as $field ) {
				if ( !is_array( $field ) ) {
					continue;
				}
				foreach ( self::withFollowUps( $field ) as $candidate ) {
					foreach ( self::fromField( $candidate, $answers, $vocabulary ) as $id => $via ) {
						$found[$id] ??= $via;
					}
				}
			}
		}

		$categories = [];
		foreach ( $found as $id => $via ) {
			$categories[] = [
				'id' => $id,
				'label' => $vocabulary[$id]['label'],
				'group' => $vocabulary[$id]['group'],
				'field' => $via,
			];
		}

		return $categories;
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
	 * @param array $field
	 * @param array<string, mixed> $answers
	 * @param array<string, array> $vocabulary
	 * @return array<string, string>
	 */
	private static function fromField( array $field, array $answers, array $vocabulary ): array {
		$name = (string)( $field['name'] ?? '' );
		if ( $name === '' || !array_key_exists( $name, $answers ) ) {
			return [];
		}

		$answer = $answers[$name];
		if ( !self::isAnswered( $answer ) ) {
			return [];
		}

		$found = [];
		$options = self::optionsByValue( $field );

		foreach ( is_array( $answer ) ? $answer : [ $answer ] as $value ) {
			if ( !is_scalar( $value ) ) {
				continue;
			}

			$value = self::normalise( (string)$value );
			if ( $value === '' ) {
				continue;
			}

			$option = $options[$value] ?? null;

			$id = isset( $option['category'] ) ? self::normalise( (string)$option['category'] ) : '';

			if ( $id === '' && isset( $vocabulary[$value] ) ) {
				$id = $value;
			}

			if ( $id !== '' && isset( $vocabulary[$id] ) ) {
				$found[$id] ??= $name;
			}
		}

		if ( isset( $field['category'] ) ) {
			$id = self::normalise( (string)$field['category'] );
			if ( isset( $vocabulary[$id] ) ) {
				$found[$id] ??= $name;
			}
		}

		return $found;
	}

	/**
	 * @param array $field
	 * @return array<string, array>
	 */
	private static function optionsByValue( array $field ): array {
		$options = [];

		foreach ( $field['options'] ?? [] as $option ) {
			if ( !is_array( $option ) || !isset( $option['value'] ) || !is_scalar( $option['value'] ) ) {
				continue;
			}
			$options[ self::normalise( (string)$option['value'] ) ] = $option;
		}

		return $options;
	}

	/**
	 * @param mixed $answer
	 */
	private static function isAnswered( $answer ): bool {
		return !( $answer === null || $answer === false || $answer === '' || $answer === [] );
	}

	public static function normalise( string $id ): string {
		return strtolower( trim( $id ) );
	}
}
