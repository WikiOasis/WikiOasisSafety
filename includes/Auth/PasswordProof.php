<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Auth;

use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\WebRequest;
use Throwable;

final class PasswordProof {

	public const MATCHED = 'matched';

	public const MISMATCHED = 'mismatched';

	public const UNAVAILABLE = 'unavailable';

	private const SESSION_KEY = 'wikioasissafety-proved';

	private const GOOD_FOR = 1200;

	/**
	 * @param string $username The account being logged into
	 * @param \MediaWiki\Auth\AuthenticationRequest[] $reqs The attempt
	 * @return string One of MATCHED, MISMATCHED or UNAVAILABLE
	 */
	public static function check( string $username, array $reqs ): string {
		$passwords = self::passwordsIn( $reqs );

		if ( $passwords === [] ) {
			return self::MISMATCHED;
		}

		$unavailable = false;

		foreach ( array_slice( $passwords, 0, 2 ) as $password ) {
			$verdict = self::verdict( $username, $password );

			if ( $verdict === self::MATCHED ) {
				return self::MATCHED;
			}

			$unavailable = $unavailable || $verdict === self::UNAVAILABLE;
		}

		return $unavailable ? self::UNAVAILABLE : self::MISMATCHED;
	}

	/**
	 * @param \MediaWiki\Auth\AuthenticationRequest[] $reqs
	 */
	public static function suppliedIn( array $reqs ): bool {
		return self::passwordsIn( $reqs ) !== [];
	}

	/**
	 * @param \MediaWiki\Auth\AuthenticationRequest[] $reqs
	 * @return list<string>
	 */
	private static function passwordsIn( array $reqs ): array {
		$passwords = [];

		foreach ( $reqs as $request ) {
			if ( !$request instanceof PasswordAuthenticationRequest ) {
				continue;
			}

			$password = (string)( $request->password ?? '' );

			if ( $password !== '' && !in_array( $password, $passwords, true ) ) {
				$passwords[] = $password;
			}
		}

		return $passwords;
	}

	public static function record( WebRequest $request, string $username ): void {
		$canonical = Accounts::canonicalName( $username );

		if ( $canonical === null ) {
			return;
		}

		$session = $request->getSession();
		$session->set( self::SESSION_KEY, [ 'name' => $canonical, 'at' => time() ] );
		$session->persist();
	}

	public static function proves( WebRequest $request, string $username ): bool {
		$canonical = Accounts::canonicalName( $username );

		if ( $canonical === null ) {
			return false;
		}

		$proof = $request->getSession()->get( self::SESSION_KEY );

		if ( !is_array( $proof ) ) {
			return false;
		}

		$at = $proof['at'] ?? null;

		return ( $proof['name'] ?? null ) === $canonical
			&& is_int( $at )
			&& $at <= time()
			&& time() - $at <= self::GOOD_FOR;
	}

	/**
	 * @return string One of MATCHED, MISMATCHED or UNAVAILABLE.
	 */
	private static function verdict( string $username, string $password ): string {
		$canonical = Accounts::canonicalName( $username );

		if ( $canonical === null ) {
			return self::UNAVAILABLE;
		}

		try {
			return self::centrally( $canonical, $password )
				?? self::locally( $canonical, $password );
		} catch ( Throwable $e ) {
			return self::UNAVAILABLE;
		}
	}

	private static function centrally( string $username, string $password ): ?string {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			return null;
		}

		$central = CentralAuthUser::getInstanceByName( $username );

		if ( !$central->exists() ) {
			return null;
		}

		$result = (array)$central->authenticate( $password );

		if ( in_array( CentralAuthUser::AUTHENTICATE_NO_USER, $result, true ) ) {
			return self::UNAVAILABLE;
		}

		return in_array( CentralAuthUser::AUTHENTICATE_BAD_PASSWORD, $result, true )
			? self::MISMATCHED
			: self::MATCHED;
	}

	private static function locally( string $username, string $password ): string {
		$services = MediaWikiServices::getInstance();

		$hash = $services->getConnectionProvider()->getReplicaDatabase()
			->newSelectQueryBuilder()
			->select( 'user_password' )
			->from( 'user' )
			->where( [ 'user_name' => $username ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( !is_string( $hash ) || $hash === '' ) {
			return self::UNAVAILABLE;
		}

		return $services->getPasswordFactory()->newFromCiphertext( $hash )->verify( $password )
			? self::MATCHED
			: self::MISMATCHED;
	}
}
