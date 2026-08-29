<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class MessagesTest extends TestCase {
	private const MARKUP = [
		'an HTML tag' => '/<\/?[a-zA-Z][^>]*>/',
		'wikitext bold or italic' => "/''/",
		'a link' => '/\[\[|\]\]|\[(?:https?:|mailto:)/',
		'an HTML entity' => '/&[a-zA-Z]+;|&#\d+;/',
	];

	/** @return array<string, mixed> */
	private function readJson( string $path ): array {
		$decoded = json_decode( (string)file_get_contents( __DIR__ . '/../../' . $path ), true );
		$this->assertIsArray( $decoded, "$path is not readable JSON" );

		return $decoded;
	}

	/** @return list<string> */
	private function clientMessageKeys(): array {
		$extension = $this->readJson( 'extension.json' );
		$keys = [];

		foreach ( $extension['ResourceModules'] ?? [] as $module ) {
			foreach ( $module['messages'] ?? [] as $key ) {
				$keys[$key] = true;
			}
		}

		return array_keys( $keys );
	}

	#[TestDox( 'no message the client reads contains markup it cannot render' )]
	public function testClientMessagesArePlainText(): void {
		$en = $this->readJson( 'i18n/en.json' );
		$offenders = [];

		foreach ( $this->clientMessageKeys() as $key ) {
			$text = $en[$key] ?? null;
			if ( !is_string( $text ) ) {
				continue;
			}

			foreach ( self::MARKUP as $what => $pattern ) {
				if ( preg_match( $pattern, $text ) ) {
					$offenders[] = "$key contains $what";
				}
			}
		}

		$this->assertSame( [], $offenders, "these are read with mw.msg() and rendered as text:\n"
			. '          - ' . implode( "\n          - ", $offenders )
			. "\n         Rewrite them as plain text, or split the part that wants markup into a"
			. "\n         message of its own that only the PHP paths use — see"
			. "\n         wikioasissafety-submit-sent-follow." );
	}

	#[TestDox( 'no message a wizard label names contains markup either' )]
	public function testFlowWordingIsPlainText(): void {
		$en = $this->readJson( 'i18n/en.json' );
		$offenders = [];

		foreach ( $en as $key => $text ) {
			$key = (string)$key;
			$isFlowWording = str_starts_with( $key, 'wikioasissafety-flow-' )
				|| str_starts_with( $key, 'wikioasissafety-category-' );
			if ( !is_string( $text ) || !$isFlowWording ) {
				continue;
			}

			foreach ( self::MARKUP as $what => $pattern ) {
				if ( preg_match( $pattern, $text ) ) {
					$offenders[] = "$key contains $what";
				}
			}
		}

		$this->assertSame( [], $offenders, "these are rendered as text by both the wizard and the"
			. " no-JavaScript form:\n          - " . implode( "\n          - ", $offenders ) );
	}

	#[TestDox( 'the wizard the extension ships names messages the extension has' )]
	public function testTheExampleFlowNamesRealMessages(): void {
		$en = $this->readJson( 'i18n/en.json' );

		$config = (string)file_get_contents( __DIR__ . '/ExampleConfiguration.php' );
		preg_match_all(
			"/'[a-zA-Z]+-message'\s*=>\s*'([^']+)'/",
			$config,
			$matches
		);

		$missing = [];
		foreach ( array_unique( $matches[1] ) as $key ) {
			if ( str_starts_with( $key, 'wikioasissafety-' ) && !isset( $en[$key] ) ) {
				$missing[] = $key;
			}
		}

		$this->assertNotSame( [], $matches[1], 'the example flow names messages at all' );
		$this->assertSame( [], $missing, 'the example flow names messages that do not exist: '
			. implode( ', ', $missing ) );
	}

	#[TestDox( 'both client modules can process magic words' )]
	public function testModulesDeclareJqueryMsg(): void {
		$extension = $this->readJson( 'extension.json' );

		foreach ( $extension['ResourceModules'] ?? [] as $name => $module ) {
			$messages = $module['messages'] ?? [];
			if ( !$messages ) {
				continue;
			}

			$this->assertContains(
				'mediawiki.jqueryMsg',
				$module['dependencies'] ?? [],
				"module '$name' declares messages but not mediawiki.jqueryMsg, so magic "
					. 'words in them are shown literally'
			);
		}
	}

	#[TestDox( 'an Echo notification header is plain text' )]
	public function testNotificationHeadersArePlainText(): void {
		$en = $this->readJson( 'i18n/en.json' );

		foreach ( $en as $key => $text ) {
			if ( !is_string( $text ) || !str_starts_with( (string)$key, 'notification-header-' ) ) {
				continue;
			}

			$this->assertDoesNotMatchRegularExpression(
				'/<\/?[a-zA-Z][^>]*>|&[a-zA-Z]+;/',
				$text,
				"$key contains markup; Echo renders headers as text in email and in the "
					. 'notification fallback'
			);
		}
	}

	#[TestDox( 'a message that is parsed uses one markup language, not two' )]
	public function testParsedMessagesDoNotMixMarkup(): void {
		$en = $this->readJson( 'i18n/en.json' );
		$client = $this->clientMessageKeys();
		$offenders = [];

		foreach ( $en as $key => $text ) {
			if ( !is_string( $text ) || in_array( (string)$key, $client, true ) ) {
				continue;
			}

			if ( preg_match( '/<(?:strong|b|em|i)>/', $text ) && str_contains( $text, "'''" ) ) {
				$offenders[] = (string)$key;
			}
		}

		$this->assertSame( [], $offenders, 'these mix HTML and wikitext formatting: '
			. implode( ', ', $offenders ) );
	}

	#[TestDox( 'every message has documentation' )]
	public function testEveryMessageIsDocumented(): void {
		$en = $this->readJson( 'i18n/en.json' );
		$qqq = $this->readJson( 'i18n/qqq.json' );
		$missing = [];

		foreach ( $en as $key => $text ) {
			if ( is_string( $text ) && !isset( $qqq[$key] ) ) {
				$missing[] = (string)$key;
			}
		}

		$this->assertSame( [], $missing, 'no qqq entry for: ' . implode( ', ', $missing ) );
	}

	#[TestDox( 'the upload module only calls methods a PSR-7 upload has' )]
	public function testUploadModuleCallsPsr7Methods(): void {
		$source = (string)file_get_contents( __DIR__ . '/../../includes/Api/ApiSafetyUpload.php' );

		$allowed = [
			'getStream', 'moveTo', 'getSize', 'getError', 'getClientFilename', 'getClientMediaType',
		];

		$wrong = [ 'exists', 'getName', 'getTempName', 'getType', 'isIniSizeOverflow' ];

		preg_match_all( '/\$upload->([a-zA-Z_]+)\s*\(/', $source, $matches );

		$called = array_unique( $matches[1] );
		$offenders = [];

		foreach ( $called as $method ) {
			if ( in_array( $method, $wrong, true ) ) {
				$offenders[] = "$method() is WebRequestUpload's, not a PSR-7 upload's";
			} elseif ( !in_array( $method, $allowed, true ) ) {
				$offenders[] = "$method() is not on UploadedFileInterface";
			}
		}

		$this->assertSame( [], $offenders, "ApiSafetyUpload calls methods the object does not have:\n"
			. '          - ' . implode( "\n          - ", $offenders )
			. "\n         `PARAM_TYPE => 'upload'` gives a PSR-7 UploadedFile: getStream, moveTo,"
			. "\n         getSize, getError, getClientFilename, getClientMediaType. Nothing else." );
	}

	#[TestDox( 'the upload module reads a size limit PHP will honour' )]
	public function testUploadModuleReadsPhpLimits(): void {
		$source = (string)file_get_contents( __DIR__ . '/../../includes/Api/ApiSafetyUpload.php' );

		foreach ( [ 'upload_max_filesize', 'post_max_size' ] as $setting ) {
			$this->assertStringContainsString(
				$setting,
				$source,
				"ApiSafetyUpload does not account for $setting, which is smaller than "
					. 'the configured limit on a default PHP'
			);
		}
	}
}
