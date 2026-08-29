<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\WikiOasisSafety\Auth\CentralLock;
use MediaWiki\Extension\WikiOasisSafety\Enforcement\WikiActions;
use MediaWiki\Extension\WikiOasisSafety\Pii\RemovePII;
use MediaWiki\Extension\WikiOasisSafety\Portal\Hmac;
use MediaWiki\Extension\WikiOasisSafety\Portal\SignedParameters;
use MediaWiki\Extension\WikiOasisSafety\Store\Mirror;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

class ApiSafetyEnforce extends ApiBase {

	public function execute() {
		$this->authenticate();

		$params = $this->extractRequestParams();
		$mirror = new Mirror();

		$name = (string)$params['username'];
		$reference = (string)$params['reference'];
		$wantsCentral = (bool)$params['centralauth'];

		if ( $name === '' && !in_array( $params['task'], [ 'delete-wiki', 'undelete-wiki' ], true ) ) {
			$this->dieWithError( [ 'apierror-missingparam', 'username' ], 'missingparam' );
		}

		$extra = [];

		switch ( $params['task'] ) {
			case 'lock':
				$mirror->setStanding( [
					'username' => $name,
					'central_id' => (int)$params['centralid'],
					'standing' => 'suspended',
					'banned' => true,
					'reference' => $reference,
					'reason' => (string)$params['reason'],
					'expires' => $params['expiry'] !== 'never' ? $params['expiry'] : null,
					'appealable' => true,
				] );

				$extra = $this->central( $wantsCentral, static fn () => CentralLock::lock( $name ) );
				$done = 'locked';
				break;

			case 'unlock':
				$mirror->setStanding( [
					'username' => $name,
					'central_id' => (int)$params['centralid'],
					'standing' => 'good',
					'banned' => false,
				] );

				$extra = $this->central( $wantsCentral, static fn () => CentralLock::unlock( $name ) );
				$done = 'unlocked';
				break;

			case 'warn':
			case 'note':
				$done = 'recorded';
				break;

			case 'block':
			case 'unblock':
				$actions = new WikiActions();
				$extra = $params['task'] === 'block'
					? $actions->block(
						$reference,
						$name,
						(int)$params['centralid'],
						(string)$params['reason'],
						$params['expiry'] !== 'never' ? (string)$params['expiry'] : null,
						$this->wikis( $params )
					)
					: $actions->unblock(
						$reference, $name, (int)$params['centralid'], (string)$params['reason'], $this->wikis( $params )
					);
				$done = $params['task'] === 'block' ? 'block-queued' : 'unblock-queued';
				break;

			case 'delete-wiki':
			case 'undelete-wiki':
				$actions = new WikiActions();
				$extra = $params['task'] === 'delete-wiki'
					? $actions->deleteWiki( $reference, $this->wikis( $params ) )
					: $actions->undeleteWiki( $reference, $this->wikis( $params ) );
				$done = $params['task'] === 'delete-wiki' ? 'deleted' : 'undeleted';
				break;

			case 'rename':
				$extra = ( new RemovePII() )->rename( $reference, $name, (string)$params['newname'] );
				$done = 'rename-queued';
				break;

			case 'renamestatus':
				$extra = ( new RemovePII() )->renameStatus( (string)$params['newname'] );
				$done = 'checked';
				break;

			case 'removepii':
				$extra = ( new RemovePII() )->scrub(
					$reference,
					$name,
					(string)$params['newname'],
					$params['wikis'] !== null && $params['wikis'] !== ''
						? explode( '|', (string)$params['wikis'] )
						: null
				);
				$done = 'scrub-queued';
				break;

			default:
				$this->dieWithError( 'apierror-wikioasissafety-unsupportedtask', 'unsupportedtask' );
		}

		if ( isset( $extra['error'] ) && $extra['error'] !== '' ) {
			$notReady = !empty( $extra['retry'] );

			$this->dieWithError(
				[
					$notReady
						? 'apierror-wikioasissafety-tasknotready'
						: 'apierror-wikioasissafety-taskfailed',
					wfEscapeWikiText( (string)$extra['error'] ),
				],
				$notReady ? 'tasknotready' : 'taskfailed'
			);
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'ok' => true,
			'task' => $params['task'],
			'reference' => $reference,
			'result' => $done,
		] + $extra );
	}

	/**
	 * @param array<string, mixed> $params
	 * @return list<string>
	 */
	private function wikis( array $params ): array {
		$raw = (string)( $params['wikis'] ?? '' );

		return $raw === '' ? [] : array_values( array_filter( explode( '|', $raw ) ) );
	}

	/**
	 * @param callable(): array{state: string, error: ?string} $act
	 * @return array<string, mixed>
	 */
	private function central( bool $requested, callable $act ): array {
		if ( !$requested ) {
			return [ 'centralauth' => CentralLock::NOT_REQUESTED ];
		}

		$result = $act();

		return array_filter( [
			'centralauth' => $result['state'],
			'centralauth_error' => $result['error'],
		], static fn ( $value ) => $value !== null );
	}

	/** @see ApiSafetySync::authenticate() */
	private function authenticate(): void {
		$config = $this->getConfig();
		$secret = (string)$config->get( 'WikiOasisSafetyPortalSecret' );

		if ( $secret === '' ) {
			$this->dieWithError( 'apierror-wikioasissafety-nosecret', 'nosecret' );
		}

		$request = $this->getRequest();
		$hmac = new Hmac( $secret, (int)$config->get( 'WikiOasisSafetyPortalTolerance' ) );
		$nonce = (string)$request->getHeader( Hmac::HEADER_NONCE );
		$body = file_get_contents( 'php://input' );

		$ok = $hmac->verify(
			'POST',
			$request->getRequestURL(),
			(string)$request->getHeader( Hmac::HEADER_TIMESTAMP ),
			$nonce,
			$body === false ? '' : $body,
			(string)$request->getHeader( Hmac::HEADER_SIGNATURE )
		);

		if ( !$ok ) {
			$this->dieWithError( 'apierror-wikioasissafety-badsignature', 'badsignature' );
		}

		$unsigned = SignedParameters::unsigned(
			$body === false ? '' : $body,
			$request->getQueryValues(),
			array_keys( $this->getAllowedParams() )
		);

		if ( $unsigned !== [] ) {
			$this->dieWithError(
				[
					'apierror-wikioasissafety-unsignedparam',
					wfEscapeWikiText( implode( ', ', $unsigned ) ),
				],
				'unsignedparam'
			);
		}

		$cache = MediaWikiServices::getInstance()->getMainObjectStash();
		$key = $cache->makeGlobalKey( 'wikioasissafety-nonce', hash( 'sha256', $nonce ) );

		if ( !$cache->add( $key, 1, (int)$config->get( 'WikiOasisSafetyPortalTolerance' ) * 2 ) ) {
			$this->dieWithError( 'apierror-wikioasissafety-replayed', 'replayed' );
		}
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'task' => [
				ParamValidator::PARAM_TYPE => [
					'lock', 'unlock', 'warn', 'note',
					'block', 'unblock',
					'delete-wiki', 'undelete-wiki',
					'rename', 'renamestatus', 'removepii',
				],
				ParamValidator::PARAM_REQUIRED => true,
			],
			'reference' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true ],
			'username' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
			'centralid' => [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_DEFAULT => 0 ],
			'reason' => [ ParamValidator::PARAM_TYPE => 'text', ParamValidator::PARAM_DEFAULT => '' ],
			'expiry' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => 'never' ],

			'centralauth' => [ ParamValidator::PARAM_TYPE => 'boolean', ParamValidator::PARAM_DEFAULT => false ],

			'newname' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],

			'wikis' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
		];
	}

	/** @inheritDoc */
	public function mustBePosted() {
		return true;
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return true;
	}

	/** @inheritDoc */
	public function needsToken() {
		return false;
	}

	/** @inheritDoc */
	public function isInternal() {
		return true;
	}
}
