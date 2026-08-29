<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety;

use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\ResourceLoader\Context;
use MessageLocalizer;

class WizardDefinition {

	private const CHROME_KEYS = [
		'title', 'description', 'submitLabel', 'nextLabel', 'backLabel'
	];

	public const FLOWS = [ '', 'Data', 'Contact' ];

	/**
	 * @param string $flowName
	 * @return string
	 */
	public static function suffixFor( string $flowName ): string {
		return match ( $flowName ) {
			'data' => 'Data',
			'contact' => 'Contact',
			default => '',
		};
	}

	/**
	 * @param string $flow
	 * @param string $key
	 * @return string
	 */
	private static function setting( string $flow, string $key ): string {
		if ( !in_array( $flow, self::FLOWS, true ) ) {
			throw new InvalidArgumentException( "WikiOasisSafety: unknown flow '$flow'" );
		}
		return 'WikiOasisSafety' . $flow . $key;
	}

	/**
	 * @param Config $config
	 * @param string $flow
	 * @param ?MessageLocalizer $localizer
	 * @return array
	 */
	public static function resolve(
		Config $config,
		string $flow = '',
		?MessageLocalizer $localizer = null
	): array {
		$name = self::setting( $flow, 'Wizard' );
		$chrome = Wording::localise( self::asArray( $config->get( $name ), $name ), $localizer );

		$resolved = [
			'version' => 1,
			'title' => '',
			'description' => '',
			'submitLabel' => '',
			'nextLabel' => '',
			'backLabel' => '',
		];
		foreach ( self::CHROME_KEYS as $key ) {
			if ( array_key_exists( $key, $chrome ) ) {
				$resolved[$key] = $chrome[$key];
			}
		}

		$name = self::setting( $flow, 'Steps' );
		$resolved['steps'] = self::buildSteps(
			self::asArray( $config->get( $name ), $name ),
			$localizer
		);

		$name = self::setting( $flow, 'ExclusiveFields' );
		$resolved['exclusiveFields'] = self::buildExclusiveFields(
			self::asArray( $config->get( $name ), $name )
		);

		$name = self::setting( $flow, 'FieldRoles' );
		$resolved['fieldRoles'] = self::asArray( $config->get( $name ), $name );

		$resolved['categories'] = Categories::vocabulary( $config, $flow, $localizer );

		return $resolved;
	}

	/** Is there a flow to offer at all? */
	public static function hasSteps( Config $config, string $flow = '' ): bool {
		$name = self::setting( $flow, 'Steps' );
		return (bool)self::asArray( $config->get( $name ), $name );
	}

	/**
	 * @param array $steps
	 * @param ?MessageLocalizer $localizer
	 * @return array
	 */
	private static function buildSteps( array $steps, ?MessageLocalizer $localizer ): array {
		$built = [];
		foreach ( $steps as $id => $step ) {
			$id = (string)$id;
			if ( $step === false || $step === null ) {
				continue;
			}
			if ( !is_array( $step ) ) {
				wfLogWarning(
					"WikiOasisSafety: \$wgWikiOasisSafetySteps['$id'] is not an array or false; ignoring"
				);
				continue;
			}
			$step = Wording::localise( $step, $localizer );
			$step['id'] = $id;
			$step['fields'] = self::buildFields( $id, $step['fields'] ?? [], $localizer );
			$built[] = $step;
		}
		return $built;
	}

	/**
	 * @param string $stepId
	 * @param mixed $fields
	 * @param ?MessageLocalizer $localizer
	 * @return array
	 */
	private static function buildFields(
		string $stepId,
		$fields,
		?MessageLocalizer $localizer
	): array {
		if ( !is_array( $fields ) ) {
			wfLogWarning( "WikiOasisSafety: step '$stepId' has a non-array 'fields'; ignoring" );
			return [];
		}
		$built = [];
		foreach ( $fields as $field ) {
			if ( !is_array( $field ) || !isset( $field['type'] ) ) {
				wfLogWarning( "WikiOasisSafety: step '$stepId' has a field with no type; ignoring" );
				continue;
			}
			if ( !isset( $field['name'] ) || $field['name'] === '' ) {
				wfLogWarning(
					"WikiOasisSafety: step '$stepId' has a '{$field['type']}' field with no name; ignoring"
				);
				continue;
			}

			$field['id'] = $field['id'] ?? ( $stepId . '-' . $field['name'] );
			$built[] = self::localiseField( $field, $localizer );
		}
		return $built;
	}

	/**
	 * @param array $field
	 * @param ?MessageLocalizer $localizer
	 * @return array
	 */
	private static function localiseField( array $field, ?MessageLocalizer $localizer ): array {
		$field = Wording::localise( $field, $localizer );

		if ( array_key_exists( 'anonymous', $field ) || Anonymity::isControl( $field ) ) {
			$field['anonymous'] = Anonymity::isControl( $field );
		}

		if ( !isset( $field['options'] ) || !is_array( $field['options'] ) ) {
			return $field;
		}

		foreach ( $field['options'] as $index => $option ) {
			if ( !is_array( $option ) ) {
				continue;
			}
			$option = Wording::localise( $option, $localizer );

			if ( isset( $option['followUp'] ) && is_array( $option['followUp'] ) ) {
				$option['followUp'] = self::localiseField( $option['followUp'], $localizer );
			}

			$field['options'][$index] = $option;
		}

		return $field;
	}

	/**
	 * @param array $groups
	 * @return array
	 */
	private static function buildExclusiveFields( array $groups ): array {
		$clean = [];
		foreach ( $groups as $group ) {
			if ( !is_array( $group ) ) {
				wfLogWarning(
					'WikiOasisSafety: $wgWikiOasisSafetyExclusiveFields expects a list of lists'
				);
				continue;
			}
			$names = array_values( array_unique( array_map( 'strval', $group ) ) );
			if ( count( $names ) > 1 ) {
				$clean[] = $names;
			}
		}
		return $clean;
	}

	/**
	 * @param mixed $value
	 * @param string $name
	 * @return array
	 */
	private static function asArray( $value, string $name ): array {
		if ( $value === null || $value === false || $value === [] ) {
			return [];
		}
		if ( !is_array( $value ) ) {
			wfLogWarning( "WikiOasisSafety: \$wg$name is not an array; ignoring" );
			return [];
		}
		return $value;
	}

	/**
	 * @param Context $context
	 * @param Config $config
	 * @param mixed $param
	 * @return array
	 */
	public static function provideFlow( Context $context, Config $config, $param = null ): array {
		return self::resolve( $config, (string)( $param ?? '' ), $context );
	}

	/**
	 * @param Context $context
	 * @param Config $config
	 * @param mixed $param
	 * @return string
	 */
	public static function provideFlowVersion( Context $context, Config $config, $param = null ): string {
		return md5( (string)json_encode(
			self::resolve( $config, (string)( $param ?? '' ), $context )
		) );
	}
}
