<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\Extension\WikiOasisSafety\CaseOwnership;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use Psr\Http\Message\UploadedFileInterface;
use Wikimedia\ParamValidator\ParamValidator;

class ApiSafetyUpload extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$user = $this->getUser();

		$this->checkUserRightsAny( 'edit' );

		$reference = trim( (string)$params['reference'] );
		if ( $reference === '' ) {
			$this->dieWithError( 'apierror-wikioasissafety-badpayload', 'badpayload' );
		}

		if ( !CaseOwnership::heldBy( $user, $this->getRequest(), $reference ) ) {
			$this->dieWithError( 'apierror-wikioasissafety-nocase', 'nocase' );
		}

		$upload = $params['file'];

		if ( !$upload instanceof UploadedFileInterface ) {
			$this->dieWithError( 'apierror-wikioasissafety-nofile', 'nofile' );
		}

		$error = $upload->getError();

		if ( $error !== UPLOAD_ERR_OK ) {
			return $this->refuse( match ( $error ) {
				UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->msg(
					'wikioasissafety-file-toobig-wiki',
					$this->getLanguage()->formatSize( $this->effectiveMax() )
				)->text(),
				UPLOAD_ERR_PARTIAL => $this->msg( 'wikioasissafety-file-partial' )->text(),
				default => $this->msg( 'wikioasissafety-file-uploadfailed' )->text(),
			} );
		}

		$max = $this->effectiveMax();

		if ( $max > 0 && (int)$upload->getSize() > $max ) {
			return $this->refuse( $this->msg(
				'wikioasissafety-file-toobig-portal',
				$this->getLanguage()->formatSize( $max )
			)->text() );
		}

		$bytes = $this->contents( $upload );

		if ( $bytes === null ) {
			$this->dieWithError( 'apierror-wikioasissafety-nofile', 'nofile' );
		}

		$identity = !$params['anonymous'] && $user->isRegistered()
			? Accounts::identity( $user )
			: [];

		$status = ( new PortalClient() )->attach( $reference, [
			'name' => (string)$upload->getClientFilename(),
			'type' => $upload->getClientMediaType(),
			'content' => base64_encode( $bytes ),
			'central_id' => $identity['central_id'] ?? null,
			'username' => $identity['username'] ?? null,
		] );

		if ( !$status->isGood() ) {
			$this->dieStatus( $status );
		}

		$value = $status->getValue();

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'ok' => true,
			'stored' => !empty( $value['stored'] ),
			'reason' => $value['reason'] ?? null,
		] );
	}

	private function refuse( string $reason ): void {
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'ok' => true,
			'stored' => false,
			'reason' => $reason,
		] );
	}

	private function effectiveMax(): int {
		$limits = [ (int)$this->getConfig()->get( 'WikiOasisSafetyMaxUploadSize' ) ];

		foreach ( [ 'upload_max_filesize', 'post_max_size' ] as $setting ) {
			$bytes = self::iniBytes( (string)ini_get( $setting ) );
			if ( $bytes > 0 ) {
				$limits[] = $bytes;
			}
		}

		$limits = array_filter( $limits, static fn ( int $limit ) => $limit > 0 );

		return $limits === [] ? 0 : min( $limits );
	}

	private static function iniBytes( string $value ): int {
		$value = trim( $value );
		if ( $value === '' ) {
			return 0;
		}

		$number = (int)$value;

		return match ( strtolower( substr( $value, -1 ) ) ) {
			'g' => $number * 1024 * 1024 * 1024,
			'm' => $number * 1024 * 1024,
			'k' => $number * 1024,
			default => $number,
		};
	}

	private function contents( UploadedFileInterface $upload ): ?string {
		try {
			$bytes = (string)$upload->getStream();
		} catch ( \RuntimeException $e ) {
			return null;
		}

		return $bytes === '' ? null : $bytes;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'reference' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'file' => [
				ParamValidator::PARAM_TYPE => 'upload',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'anonymous' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
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
		return 'csrf';
	}
}
