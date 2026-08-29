<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety;

use MediaWiki\Extension\WikiOasisSafety\Store\SafetyStore;
use MediaWiki\Request\WebRequest;
use MediaWiki\User\UserIdentity;

final class CaseOwnership {

	private const SESSION_KEY = 'wikioasissafety-filed';

	private const REMEMBERED = 20;

	public static function remember( WebRequest $request, string $reference ): void {
		if ( $reference === '' ) {
			return;
		}

		$session = $request->getSession();
		$held = self::held( $request );

		if ( !in_array( $reference, $held, true ) ) {
			$held[] = $reference;
		}

		$session->set( self::SESSION_KEY, array_slice( $held, -self::REMEMBERED ) );

		$session->persist();
	}

	/**
	 * @param UserIdentity $user
	 * @param WebRequest $request
	 * @param string $reference
	 */
	public static function heldBy( UserIdentity $user, WebRequest $request, string $reference ): bool {
		if ( $reference === '' ) {
			return false;
		}

		if ( in_array( $reference, self::held( $request ), true ) ) {
			return true;
		}

		return Accounts::isNamed( $user )
			&& ( new SafetyStore() )->reportFor( $user, $reference ) !== null;
	}

	/**
	 * @return list<string>
	 */
	private static function held( WebRequest $request ): array {
		$held = $request->getSession()->get( self::SESSION_KEY );

		return is_array( $held )
			? array_values( array_filter( $held, 'is_string' ) )
			: [];
	}
}
