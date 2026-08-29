<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Portal;

final class SignedParameters {

	/**
	 * @param string $body
	 * @param array<string, mixed> $query
	 * @param list<string> $names
	 * @return list<string>
	 */
	public static function unsigned( string $body, array $query, array $names ): array {
		if ( $query === [] ) {
			return [];
		}

		$signed = [];
		parse_str( $body, $signed );

		$unsigned = [];

		foreach ( $names as $name ) {
			if ( !array_key_exists( $name, $query ) ) {
				continue;
			}

			$inBody = $signed[$name] ?? null;

			if ( !is_string( $inBody ) || !is_string( $query[$name] ) || $inBody !== $query[$name] ) {
				$unsigned[] = $name;
			}
		}

		return $unsigned;
	}
}
