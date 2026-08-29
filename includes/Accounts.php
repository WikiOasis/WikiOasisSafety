<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentity;

final class Accounts {

	public static function centralId( UserIdentity $user ): ?int {
		$central = MediaWikiServices::getInstance()
			->getCentralIdLookup()
			->centralIdFromLocalUser( $user );

		return $central !== 0 ? $central : null;
	}

	public static function centralIdForName( string $name ): ?int {
		$canonical = self::canonicalName( $name );

		if ( $canonical === null ) {
			return null;
		}

		$central = MediaWikiServices::getInstance()
			->getCentralIdLookup()
			->centralIdFromName( $canonical, CentralIdLookup::AUDIENCE_RAW );

		return $central !== 0 ? $central : null;
	}

	public static function canonicalName( string $name ): ?string {
		$canonical = MediaWikiServices::getInstance()->getUserNameUtils()
			->getCanonical( $name );

		return $canonical !== false ? $canonical : null;
	}

	public static function isNamed( UserIdentity $user ): bool {
		return MediaWikiServices::getInstance()->getUserIdentityUtils()->isNamed( $user );
	}

	/**
	 * @return array{central_id: ?int, username: string}
	 */
	public static function identity( UserIdentity $user ): array {
		return [
			'central_id' => self::centralId( $user ),
			'username' => $user->getName(),
		];
	}
}
