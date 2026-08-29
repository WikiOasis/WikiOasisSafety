<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\NoJs;

class FlowNavigator {
	public const SUBMIT = '__submit__';

	private const COLLECTS_DATA = [
		'text', 'textarea', 'select', 'radio', 'checkboxGroup', 'checkbox',
		'toggle', 'combobox', 'lookup', 'chipInput', 'fileUpload',
	];

	private array $flow;

	/** @param array $flow */
	public function __construct( array $flow ) {
		$this->flow = $flow;
		$this->flow['steps'] ??= [];
	}

	/** @return array */
	public function steps(): array {
		return $this->flow['steps'];
	}

	public function hasSteps(): bool {
		return (bool)$this->flow['steps'];
	}

	public function step( string $id ): ?array {
		foreach ( $this->flow['steps'] as $step ) {
			if ( ( $step['id'] ?? '' ) === $id ) {
				return $step;
			}
		}
		return null;
	}

	/**
	 * @param string $id
	 * @param array $data
	 * @return array|null
	 */
	public function resolveStep( string $id, array $data ): ?array {
		$wanted = $this->step( $id );
		if ( $wanted && self::isStepVisible( $wanted, $data ) ) {
			return $wanted;
		}
		foreach ( $this->flow['steps'] as $step ) {
			if ( self::isStepVisible( $step, $data ) ) {
				return $step;
			}
		}
		return null;
	}

	public function firstStep(): ?array {
		return $this->flow['steps'][0] ?? null;
	}

	public static function isEmptyValue( $value ): bool {
		if ( is_array( $value ) ) {
			return $value === [];
		}
		return $value === null || $value === '' || $value === false;
	}

	private static function asString( $value ): string {
		if ( $value === null || $value === false ) {
			return '';
		}
		if ( $value === true ) {
			return '1';
		}
		return is_array( $value ) ? implode( ',', $value ) : (string)$value;
	}

	/**
	 * @param array|null $condition
	 * @param array $data
	 * @return bool
	 */
	public static function evaluateCondition( ?array $condition, array $data ): bool {
		if ( !$condition || ( $condition['op'] ?? '' ) === 'always' || empty( $condition['field'] ) ) {
			return true;
		}
		$value = $data[$condition['field']] ?? null;
		$expected = $condition['value'] ?? null;

		switch ( $condition['op'] ?? 'equals' ) {
			case 'isFilled':
				return !self::isEmptyValue( $value );
			case 'isEmpty':
				return self::isEmptyValue( $value );
			case 'notEquals':
				return self::asString( $value ) !== self::asString( $expected );
			case 'contains':
				if ( is_array( $value ) ) {
					return in_array( self::asString( $expected ), array_map(
						[ self::class, 'asString' ], $value
					), true );
				}
				return $expected === null || $expected === '' ||
					str_contains( self::asString( $value ), self::asString( $expected ) );
			case 'equals':
			default:
				return self::asString( $value ) === self::asString( $expected );
		}
	}

	public static function isStepVisible( array $step, array $data ): bool {
		return self::evaluateCondition( $step['visibleWhen'] ?? null, $data );
	}

	public static function visibleFields( array $step, array $data ): array {
		return array_values( array_filter(
			$step['fields'] ?? [],
			static fn ( array $field ): bool =>
				self::evaluateCondition( $field['visibleWhen'] ?? null, $data )
		) );
	}

	/**
	 * @param array $step
	 * @param array $data
	 * @return string A step id, or self::SUBMIT.
	 */
	public function resolveNext( array $step, array $data ): string {
		if ( !empty( $step['isFinal'] ) ) {
			return self::SUBMIT;
		}
		foreach ( $step['branches'] ?? [] as $branch ) {
			$when = $branch['when'] ?? null;

			$usable = $when && ( ( $when['op'] ?? '' ) === 'always' || !empty( $when['field'] ) );

			if ( empty( $branch['goTo'] ) || !$usable ||
				!self::evaluateCondition( $when, $data )
			) {
				continue;
			}
			if ( $branch['goTo'] === self::SUBMIT ) {
				return self::SUBMIT;
			}

			if ( $this->step( $branch['goTo'] ) ) {
				return $branch['goTo'];
			}
		}

		$seen = false;
		foreach ( $this->flow['steps'] as $candidate ) {
			if ( $seen && self::isStepVisible( $candidate, $data ) ) {
				return $candidate['id'];
			}
			if ( ( $candidate['id'] ?? '' ) === ( $step['id'] ?? '' ) ) {
				$seen = true;
			}
		}
		return self::SUBMIT;
	}

	/**
	 * @param array $step
	 * @return bool
	 */
	public static function isGuidanceStep( array $step ): bool {
		foreach ( $step['fields'] ?? [] as $field ) {
			if ( in_array( $field['type'] ?? '', self::COLLECTS_DATA, true ) ) {
				return false;
			}
		}
		return true;
	}

	public static function collectsData( array $field ): bool {
		return in_array( $field['type'] ?? '', self::COLLECTS_DATA, true );
	}

	public static function slugify( string $text, string $fallback ): string {
		$slug = strtolower( $text );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug ) ?? '';
		$slug = trim( $slug, '-' );
		$slug = substr( $slug, 0, 40 );
		return $slug !== '' ? $slug : $fallback;
	}

	public static function followUpName( array $field, array $option ): string {
		$named = $option['followUp']['name'] ?? '';
		if ( $named !== '' ) {
			return $named;
		}
		return self::slugify(
			( $field['name'] ?? '' ) . '-' . ( $option['value'] ?? '' ),
			( $field['id'] ?? '' ) . '-followup'
		);
	}

	public static function isOptionSelected( array $field, array $option, array $data ): bool {
		$value = $data[$field['name'] ?? ''] ?? null;
		$wanted = self::asString( $option['value'] ?? null );
		if ( is_array( $value ) ) {
			return in_array( $wanted, array_map( [ self::class, 'asString' ], $value ), true );
		}
		return self::asString( $value ) === $wanted;
	}

	/**
	 * @return array<string,string[]>
	 */
	public function exclusiveGroups(): array {
		$groups = [];
		foreach ( $this->flow['exclusiveFields'] ?? [] as $group ) {
			foreach ( $group as $name ) {
				$groups[$name] = array_values( array_filter(
					$group,
					static fn ( $other ): bool => $other !== $name
				) );
			}
		}
		return $groups;
	}

	/**
	 * @param array $field
	 * @param array $data
	 * @return bool
	 */
	public function isAnswered( array $field, array $data ): bool {
		$names = array_merge(
			[ (string)( $field['name'] ?? '' ) ],
			$this->exclusiveGroups()[$field['name'] ?? ''] ?? []
		);
		foreach ( $names as $name ) {
			if ( !self::isEmptyValue( $data[$name] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array $step
	 * @param array $data
	 * @return string[] Field names, and follow-up names, in the order read.
	 */
	public function blockingFields( array $step, array $data ): array {
		$blocking = [];
		foreach ( self::visibleFields( $step, $data ) as $field ) {
			if ( !empty( $field['required'] ) && self::collectsData( $field )
				&& !$this->isAnswered( $field, $data )
			) {
				$blocking[] = (string)$field['name'];
			}
			foreach ( $field['options'] ?? [] as $option ) {
				if ( empty( $option['followUp']['required'] ) ) {
					continue;
				}
				$followUp = self::followUpName( $field, $option );
				if ( self::isOptionSelected( $field, $option, $data )
					&& self::isEmptyValue( $data[$followUp] ?? null )
				) {
					$blocking[] = $followUp;
				}
			}
		}
		return $blocking;
	}

	/**
	 * @param string $name
	 * @param array $data
	 */
	public function applyExclusivity( string $name, array &$data ): void {
		$groups = $this->exclusiveGroups();
		foreach ( $groups[$name] ?? [] as $other ) {
			unset( $data[$other] );
			foreach ( $this->flow['steps'] as $step ) {
				foreach ( $step['fields'] ?? [] as $field ) {
					if ( ( $field['name'] ?? '' ) !== $other ) {
						continue;
					}
					foreach ( $field['options'] ?? [] as $option ) {
						if ( !empty( $option['followUp'] ) ) {
							unset( $data[self::followUpName( $field, $option )] );
						}
					}
				}
			}
		}
	}
}
