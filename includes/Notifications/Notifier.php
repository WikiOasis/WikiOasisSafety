<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Notifications;

use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

class Notifier {

	public const CASE_UPDATE = 'wikioasissafety-case-update';
	public const COMMENT = 'wikioasissafety-comment';
	public const ACTION = 'wikioasissafety-action';

	public static function available(): bool {
		return class_exists( Event::class );
	}

	public function caseUpdated( int $centralId, string $reference, string $status, bool $isNew ): void {
		$this->fire( self::CASE_UPDATE, $centralId, [
			'reference' => $reference,
			'status' => $status,
			'isNew' => $isNew,
		] );
	}

	public function commentAdded( int $centralId, string $reference, string $author ): void {
		$this->fire( self::COMMENT, $centralId, [
			'reference' => $reference,
			'author' => $author,
		] );
	}

	public function actionTaken( int $centralId, string $reference, string $label, bool $lifted ): void {
		$this->fire( self::ACTION, $centralId, [
			'reference' => $reference,
			'label' => $label,
			'lifted' => $lifted,
		] );
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function fire( string $type, int $centralId, array $extra ): void {
		if ( !self::available() || $centralId === 0 ) {
			return;
		}

		$user = MediaWikiServices::getInstance()->getCentralIdLookup()
			->localUserFromCentralId( $centralId );

		if ( $user === null ) {
			return;
		}

		Event::create( [
			'type' => $type,
			'agent' => $user,
			'title' => SpecialPage::getTitleFor( 'SafetyHome' ),
			'extra' => $extra + [ 'notifyAgent' => true ],
		] );
	}
}
