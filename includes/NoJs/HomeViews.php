<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\NoJs;

use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;

class HomeViews {

	private IContextSource $context;

	public function __construct( IContextSource $context ) {
		$this->context = $context;
	}

	public static function url( string $subPage = '' ): string {
		$title = SpecialPage::getTitleFor( 'SafetyHome', $subPage ?: null );
		return $title->getLocalURL();
	}

	private function msg( string $key, ...$params ): string {
		return $this->context->msg( $key, ...$params )->text();
	}

	private function date( ?string $iso ): string {
		if ( !$iso ) {
			return '';
		}
		$timestamp = strtotime( $iso );
		if ( $timestamp === false ) {
			return '';
		}
		return $this->context->getLanguage()->userDate(
			wfTimestamp( TS_MW, $timestamp ),
			$this->context->getUser()
		);
	}

	/**
	 * @param array $available
	 * @return string HTML
	 */
	public function cards( array $available ): string {
		$user = $this->context->getUser();
		$named = Accounts::isNamed( $user );
		$noAccount = $user->isRegistered() && !$named
			? 'wikioasissafety-home-temporary'
			: 'wikioasissafety-home-login';
		$login = SpecialPage::getTitleFor( 'Userlogin' )->getLocalURL( [
			'returnto' => SpecialPage::getTitleFor( 'SafetyHome' )->getPrefixedText(),
		] );

		$cards = [
			[ 'report', 'half', SpecialPage::getTitleFor( 'SafetyReport' )->getLocalURL(),
				!empty( $available['report'] ), false ],
			[ 'data', 'half', self::url( 'data' ), !empty( $available['data'] ), true ],
			[ 'reports', 'third', self::url( 'reports' ), true, true ],
			[ 'account', 'third', self::url( 'account' ), true, true ],
			[ 'contact', 'third', self::url( 'contact' ), !empty( $available['contact'] ), false ],
		];

		$html = '';
		foreach ( $cards as [ $id, $row, $href, $configured, $needsAccount ] ) {
			$note = '';
			$classes = [ 'wikioasis-safety-nojs-card', 'wikioasis-safety-nojs-card--' . $row ];

			if ( !$configured ) {
				$note = $this->msg( 'wikioasissafety-home-unconfigured' );
				$classes[] = 'wikioasis-safety-nojs-card--disabled';
				$href = null;
			} elseif ( $needsAccount && !$named ) {
				$note = $this->msg( $noAccount );
				$href = $login;
			}

			$body = Html::element( 'span', [ 'class' => 'wikioasis-safety-nojs-card__title' ],
					$this->msg( 'wikioasissafety-home-' . $id ) ) .
				Html::element( 'span', [ 'class' => 'wikioasis-safety-nojs-card__description' ],
					$this->msg( 'wikioasissafety-home-' . $id . '-description' ) ) .
				( $note !== ''
					? Html::element( 'span', [ 'class' => 'wikioasis-safety-nojs-card__note' ], $note )
					: '' );
			$html .= Html::rawElement( 'li', [ 'class' => 'wikioasis-safety-nojs-cell' ],
				$href === null
					? Html::rawElement( 'span', [ 'class' => implode( ' ', $classes ) ], $body )
					: Html::rawElement( 'a', [ 'class' => implode( ' ', $classes ), 'href' => $href ], $body )
			);
		}

		return Html::rawElement( 'p', [ 'class' => 'wikioasis-safety-nojs-intro' ],
				htmlspecialchars( $this->msg( 'wikioasissafety-home-intro' ) ) ) .
			Html::rawElement( 'ul', [ 'class' => 'wikioasis-safety-nojs-grid' ], $html );
	}

	/**
	 * @param array $reports
	 * @return string HTML.
	 */
	public function reportsList( array $reports ): string {
		$html = Html::rawElement( 'p', [],
			htmlspecialchars( $this->msg( 'wikioasissafety-home-reports-intro' ) ) );

		if ( !$reports ) {
			$html .= Html::noticeBox(
				htmlspecialchars( $this->msg( 'wikioasissafety-home-reports-empty' ) ), ''
			);
		} else {
			$items = '';
			foreach ( $reports as $report ) {
				$items .= Html::rawElement( 'li', [],
					Html::rawElement( 'a', [
						'class' => 'wikioasis-safety-nojs-report',
						'href' => self::url( 'reports/' . $report['id'] ),
					],
						Html::element( 'span', [ 'class' => 'wikioasis-safety-nojs-report__subject' ],
							$report['subject'] ?? '' ) .
						Html::element( 'span', [ 'class' => 'wikioasis-safety-nojs-status' ],
							$this->statusText( $report['status'] ?? '' ) ) .
						Html::element( 'span', [ 'class' => 'wikioasis-safety-nojs-meta' ],
							$this->msg( 'wikioasissafety-home-reports-meta',
								$report['id'] ?? '',
								$this->date( $report['filed'] ?? null ),
								$this->date( $report['updated'] ?? null ) ) )
					) );
			}
			$html .= Html::rawElement( 'ul',
				[ 'class' => 'wikioasis-safety-nojs-reports' ], $items );
		}

		return $html;
	}

	/**
	 * @param array $appeal
	 * @return array<string, string>
	 */
	private function appealFacts( array $appeal ): array {
		$rows = [];
		$action = $appeal['action'] ?? null;

		if ( is_array( $action ) ) {
			$rows['wikioasissafety-home-reports-appeal-against'] = $this->msg(
				'wikioasissafety-home-reports-appeal-action',
				(string)( $action['reference'] ?? '' ),
				(string)( $action['label'] ?? '' )
			) . ' · ' . $this->msg( empty( $action['in_force'] )
				? 'wikioasissafety-home-reports-appeal-lifted'
				: 'wikioasissafety-home-reports-appeal-inforce' );

			$rows['wikioasissafety-home-reports-appeal-reason'] = (string)( $action['reason'] ?? '' );
			$rows['wikioasissafety-home-reports-appeal-applies'] = (string)( $action['where'] ?? '' );
		}

		$rows['wikioasissafety-home-reports-appeal-outcome'] = $this->appealOutcome(
			$appeal['outcome'] ?? null
		);

		return $rows;
	}

	private function appealOutcome( ?string $outcome ): string {
		if ( $outcome === null || $outcome === '' ) {
			return $this->msg( 'wikioasissafety-home-reports-appeal-undecided' );
		}

		$key = self::APPEAL_OUTCOMES[$outcome] ?? null;

		return $key !== null ? $this->msg( $key ) : $outcome;
	}

	private const APPEAL_OUTCOMES = [
		'granted' => 'wikioasissafety-home-reports-appeal-outcome-granted',
		'partly-granted' => 'wikioasissafety-home-reports-appeal-outcome-partly-granted',
		'declined' => 'wikioasissafety-home-reports-appeal-outcome-declined',
		'withdrawn' => 'wikioasissafety-home-reports-appeal-outcome-withdrawn',
		'invalid' => 'wikioasissafety-home-reports-appeal-outcome-invalid',
	];

	/**
	 * @param array $report
	 * @return string HTML
	 */
	public function report( array $report ): string {
		$rows = [
			'wikioasissafety-home-reports-reference' => $report['id'] ?? '',
			'wikioasissafety-home-reports-filed' => $this->date( $report['filed'] ?? null ),
			'wikioasissafety-home-reports-about' => implode( ', ', $report['about'] ?? [] ),
		];

		$appeal = $report['appeal'] ?? null;
		if ( is_array( $appeal ) ) {
			$rows += $this->appealFacts( $appeal );
		}

		$facts = '';
		foreach ( $rows as $key => $value ) {
			if ( $value === '' ) {
				continue;
			}
			$facts .= Html::rawElement( 'div', [],
				Html::element( 'dt', [], $this->msg( $key ) ) .
				Html::element( 'dd', [], $value ) );
		}

		$html = Html::rawElement( 'a',
				[ 'class' => 'wikioasis-safety-nojs-back', 'href' => self::url( 'reports' ) ],
				htmlspecialchars( $this->msg( 'wikioasissafety-home-reports-back' ) ) ) .
			Html::element( 'h2', [], $report['subject'] ?? '' ) .
			Html::element( 'p', [ 'class' => 'wikioasis-safety-nojs-status' ],
				$this->statusText( $report['status'] ?? '' ) ) .
			Html::rawElement( 'dl', [ 'class' => 'wikioasis-safety-nojs-facts' ], $facts );

		if ( is_array( $appeal ) && ( $appeal['action'] ?? null ) === null ) {
			$html .= Html::element( 'p', [ 'class' => 'wikioasis-safety-nojs-note' ],
				$this->msg( 'wikioasissafety-home-reports-appeal-unmatched' ) );
		}

		if ( $report['summary'] ?? '' ) {
			$html .= Html::element( 'p', [], $report['summary'] );
		}

		$html .= Html::element( 'h3', [], $this->msg( 'wikioasissafety-home-reports-comments' ) );
		$comments = $report['comments'] ?? [];
		if ( !$comments ) {
			$html .= Html::element( 'p', [],
				$this->msg( 'wikioasissafety-home-reports-nocomments' ) );
			return $html;
		}

		$items = '';
		foreach ( $comments as $comment ) {
			// Anything not written by the reader is Trust & Safety, including the
			// automatic updates, which arrive without a name attached.
			$mine = ( $comment['author'] ?? '' ) === 'you';
			$items .= Html::rawElement( 'li', [
				'class' => 'wikioasis-safety-nojs-comment' .
					( $mine ? '' : ' wikioasis-safety-nojs-comment--staff' ),
			],
				Html::element( 'span', [ 'class' => 'wikioasis-safety-nojs-comment__author' ],
					$comment['name'] ?: $this->msg( $mine
						? 'wikioasissafety-home-reports-you'
						: 'wikioasissafety-home-reports-staff' ) ) .
				Html::element( 'span', [ 'class' => 'wikioasis-safety-nojs-meta' ],
					$this->date( $comment['date'] ?? null ) ) .
				Html::element( 'p', [], $comment['text'] ?? '' ) );
		}
		return $html . Html::rawElement( 'ol',
			[ 'class' => 'wikioasis-safety-nojs-comments' ], $items );
	}

	/**
	 * @param array $report
	 */
	public function showCommentForm( array $report ): void {
		$form = HTMLForm::factory( 'codex', [
			'wocomment' => [
				'type' => 'textarea',
				'rows' => 3,
				'label' => $this->msg( 'wikioasissafety-home-reports-add' ),
				'help' => $this->msg( 'wikioasissafety-home-reports-add-description' ),
				'placeholder' => $this->msg( 'wikioasissafety-home-reports-add-placeholder' ),
				'required' => true,
			],
		], $this->context );

		$form->setAction( self::url( 'reports/' . ( $report['id'] ?? '' ) ) )
			->setWrapperLegend( $this->msg( 'wikioasissafety-home-reports-add' ) )
			->setSubmitTextMsg( 'wikioasissafety-home-reports-add-button' )
			->setSubmitCallback( function ( array $data ) use ( $report ) {
				$out = $this->context->getOutput();
				$user = $this->context->getUser();

				$status = ( new PortalClient() )->comment(
					(string)( $report['id'] ?? '' ),
					Accounts::identity( $user ) + [ 'body' => trim( (string)$data['wocomment'] ) ]
				);

				if ( !$status->isGood() ) {
					$out->addHTML( Html::errorBox( htmlspecialchars(
						$this->msg( 'wikioasissafety-submit-failed' ) ) ) );
					return true;
				}

				$queued = !empty( $status->getValue()['queued'] );

				$out->addHTML( $queued
					? Html::warningBox( htmlspecialchars(
						$this->msg( 'wikioasissafety-comment-queued' ) ) )
					: Html::successBox( htmlspecialchars(
						$this->msg( 'wikioasissafety-home-reports-added' ) ) ) );

				return true;
			} )
			->show();
	}

	/** How a report's state is put to the reader. */
	private function statusText( string $status ): string {
		$keys = [
			'received' => 'wikioasissafety-home-reports-status-received',
			'in-review' => 'wikioasissafety-home-reports-status-inreview',
			'investigating' => 'wikioasissafety-home-reports-status-investigating',
			'action-taken' => 'wikioasissafety-home-reports-status-actiontaken',
			'closed' => 'wikioasissafety-home-reports-status-closed',
			'rejected' => 'wikioasissafety-home-reports-status-rejected',
		];
		return isset( $keys[$status] ) ? $this->msg( $keys[$status] ) : $status;
	}

	/**
	 * @param array $account
	 * @param array $actions
	 * @param bool $canAppeal
	 * @return string HTML
	 */
	public function accountStanding( array $account, array $actions, bool $canAppeal ): string {
		$key = match ( $account['standing'] ?? 'good' ) {
			'restricted' => 'wikioasissafety-home-account-restricted',
			'suspended' => 'wikioasissafety-home-account-suspended',
			default => 'wikioasissafety-home-account-good',
		};
		$body = Html::element( 'strong', [], $this->msg( $key ) ) .
			Html::element( 'p', [], $this->msg( $key . '-detail' ) );

		$html = match ( $account['standing'] ?? 'good' ) {
			'restricted' => Html::warningBox( $body ),
			'suspended' => Html::errorBox( $body ),
			default => Html::successBox( $body ),
		};

		if ( $account['registered'] ?? '' ) {
			$html .= Html::element( 'p', [ 'class' => 'wikioasis-safety-nojs-meta' ],
				$this->msg( 'wikioasissafety-home-account-since',
					$this->date( $account['registered'] ) ) );
		}

		$html .= Html::element( 'h2', [], $this->msg( 'wikioasissafety-home-account-history' ) );

		if ( !$actions ) {
			return $html . Html::element( 'p', [],
				$this->msg( 'wikioasissafety-home-account-noactions' ) );
		}

		$items = '';
		foreach ( $actions as $action ) {
			$active = !empty( $action['active'] );
			$items .= Html::rawElement( 'li', [
				'class' => 'wikioasis-safety-nojs-timeline__entry' .
					( $active ? ' wikioasis-safety-nojs-timeline__entry--active' : '' ),
			],
				Html::element( 'h3', [], $action['type'] ?? '' ) .
				Html::element( 'span', [ 'class' => 'wikioasis-safety-nojs-status' ],
					$this->msg( $active
						? 'wikioasissafety-home-account-active'
						: 'wikioasissafety-home-account-ended' ) ) .
				Html::element( 'p', [ 'class' => 'wikioasis-safety-nojs-meta' ],
					$this->msg( 'wikioasissafety-home-account-issued',
						$this->date( $action['issued'] ?? null ) ) .
					' · ' . $this->expiry( $action ) ) .
				Html::element( 'p', [ 'class' => 'wikioasis-safety-nojs-meta' ],
					$this->msg( 'wikioasissafety-home-account-scope', $action['scope'] ?? '' ) ) .
				Html::element( 'p', [], $action['reason'] ?? '' ) .
				Html::element( 'p', [ 'class' => 'wikioasis-safety-nojs-meta' ],
					$this->msg( 'wikioasissafety-home-account-reference', $action['id'] ?? '' ) )
			);
		}
		$html .= Html::rawElement( 'ol',
			[ 'class' => 'wikioasis-safety-nojs-timeline' ], $items );

		if ( $canAppeal ) {
			$html .= Html::rawElement( 'p', [ 'class' => 'wikioasis-safety-nojs-appeal' ],
				htmlspecialchars( $this->msg( 'wikioasissafety-home-account-appeal' ) ) . ' ' .
				Html::element( 'a', [ 'href' => self::url( 'contact/appeal' ) ],
					$this->msg( 'wikioasissafety-home-account-appeal-button' ) ) );
		}
		return $html;
	}

	/**
	 * @param array $action
	 * @return string
	 */
	private function expiry( array $action ): string {
		if ( empty( $action['expires'] ) ) {
			return $this->msg( 'wikioasissafety-home-account-noexpiry' );
		}
		return $this->msg( !empty( $action['active'] )
			? 'wikioasissafety-home-account-expires'
			: 'wikioasissafety-home-account-expired', $this->date( $action['expires'] ) );
	}
}
