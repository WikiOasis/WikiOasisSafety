<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety;

use MediaWiki\User\User;

final class Throttle {

	public const REPORT = 'wikioasissafety-report';

	public const REPORT_ANONYMOUS = 'wikioasissafety-report-anon';

	/**
	 * @param User $user
	 * @return bool should this be refused?
	 */
	public static function refusesReport( User $user ): bool {
		return $user->pingLimiter(
			Accounts::isNamed( $user ) ? self::REPORT : self::REPORT_ANONYMOUS
		);
	}
}
