<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Auth;

use MediaWiki\Auth\AbstractPreAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Extension\WikiOasisSafety\Store\SafetyStore;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use StatusValue;

class SuspendedAccountPreAuthProvider extends AbstractPreAuthenticationProvider {

	private ?SafetyStore $store;

	public function __construct( ?SafetyStore $store = null ) {
		$this->store = $store;
	}

	/**
	 * @param AuthenticationRequest[] $reqs
	 * @return StatusValue
	 */
	public function testForAuthentication( array $reqs ) {
		$username = null;
		foreach ( $reqs as $req ) {
			if ( $req->username !== null && $req->username !== '' ) {
				$username = $req->username;
				break;
			}
		}

		if ( $username === null ) {
			return StatusValue::newGood();
		}

		$store = $this->store ?? new SafetyStore();
		$suspension = $store->suspension( $username );

		if ( $suspension === null ) {
			return StatusValue::newGood();
		}

		$verdict = PasswordProof::check( $username, $reqs );

		if ( $verdict === PasswordProof::MISMATCHED ) {
			return StatusValue::newFatal(
				PasswordProof::suppliedIn( $reqs ) ? 'wrongpassword' : 'wrongpasswordempty'
			);
		}

		$proved = $verdict === PasswordProof::MATCHED;

		if ( $proved && isset( $this->manager ) ) {
			PasswordProof::record( $this->manager->getRequest(), $username );
		}

		return StatusValue::newFatal( $this->message( $username, $suspension, $proved ) );
	}

	/**
	 * @inheritDoc
	 */
	public function testForAccountCreation( $user, $creator, array $reqs ) {
		return StatusValue::newGood();
	}

	/**
	 * @param array{reference: ?string, reason: ?string, expires: ?string, appealable: bool} $suspension
	 * @param bool $proved
	 * @return \MediaWiki\Message\Message
	 */
	private function message( string $username, array $suspension, bool $proved = false ) {
		$appealUrl = SpecialPage::getTitleFor( 'SafetyAppeal' )->getFullURL(
			array_filter( [
				'user' => $username,
				'ref' => $suspension['reference'],
			] )
		);

		$key = 'wikioasissafety-suspended-'
			. ( $suspension['appealable'] ? 'appealable' : 'final' )
			. ( $suspension['expires'] !== null ? '-expiring' : '' );

		return wfMessage(
			$key,
			// $1 is the reference.
			$suspension['reference'] ?? '',
			$appealUrl,
			$suspension['expires'] !== null
				? wfTimestamp( TS_ISO_8601, $suspension['expires'] )
				: '',
			$this->reason( $suspension, $proved )
		);
	}

	/**
	 * @param array{reference: ?string, reason: ?string, expires: ?string, appealable: bool} $suspension
	 */
	private function reason( array $suspension, bool $proved ) {
		if ( !$proved ) {
			return Message::plaintextParam(
				wfMessage( 'wikioasissafety-suspended-reason-withheld' )->text()
			);
		}

		$reason = trim( (string)( $suspension['reason'] ?? '' ) );

		return Message::plaintextParam(
			wfMessage( 'wikioasissafety-suspended-reason' )
				->plaintextParams( $reason !== ''
					? $reason
					: wfMessage( 'wikioasissafety-suspended-noreason' )->text() )
				->text()
		);
	}
}
