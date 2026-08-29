<?php
declare( strict_types=1 );

require_once __DIR__ . '/../../includes/Portal/Hmac.php';

use MediaWiki\Extension\WikiOasisSafety\Portal\Hmac as WikiHmac;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class HmacParityTest extends TestCase {
	private const SECRET = 'a-secret-both-sides-hold';

	private WikiHmac $wiki;
	private \App\Services\MediaWiki\Hmac $portal;

	protected function setUp(): void {
		parent::setUp();

		$portalPath = getenv( 'TSPORTAL_PATH' ) ?: dirname( __DIR__, 3 ) . '/TSPortal';
		$portalHmacFile = $portalPath . '/app/Services/MediaWiki/Hmac.php';

		if ( !is_file( $portalHmacFile ) ) {
			$this->markTestSkipped(
				"no TSPortal checkout at $portalPath — set TSPORTAL_PATH to compare signatures"
			);
		}

		if ( !class_exists( \Illuminate\Support\Str::class ) ) {
			eval( 'namespace Illuminate\Support; class Str { public static function random( $n = 16 ) {
				return str_repeat( "x", $n ); } }' );
		}
		if ( !function_exists( 'config' ) ) {
			eval( 'function config( $key = null, $default = null ) { return $default; }' );
		}

		require_once $portalHmacFile;

		$this->wiki = new WikiHmac( self::SECRET, 300, 'oasis.example' );
		$this->portal = new \App\Services\MediaWiki\Hmac( self::SECRET, 300 );
	}

	public static function provideRequests(): array {
		return [
			'a plain GET' => [ 'GET', '/api/wiki/v1/health', '' ],
			'a POST with a body' => [ 'POST', '/api/wiki/v1/submissions', '{"type":"report"}' ],
			'a path with a trailing slash' => [ 'GET', '/api/wiki/v1/health/', '' ],
			'a path with a query string' => [ 'GET', '/api/wiki/v1/health?format=json', '' ],
			'a percent-encoded username' => [ 'GET', '/api/wiki/v1/accounts/Halcyon%20Reed/standing', '' ],
			'a body with non-ASCII in it' => [ 'POST', '/api/wiki/v1/appeals', '{"body":"café — ünïcode"}' ],
			'an empty body on a POST' => [ 'POST', '/api/wiki/v1/appeals', '' ],
		];
	}

	#[DataProvider( 'provideRequests' )]
	#[TestDox( 'the canonical string matches' )]
	public function testTheCanonicalStringMatches( string $method, string $path, string $body ): void {
		$this->assertSame(
			$this->wiki->canonical( $method, $path, '1750000000', 'a-nonce', $body ),
			$this->portal->canonical( $method, $path, '1750000000', 'a-nonce', $body ),
			'the two sides canonicalise the request differently'
		);
	}

	#[DataProvider( 'provideRequests' )]
	#[TestDox( 'the signature matches' )]
	public function testTheSignatureMatches( string $method, string $path, string $body ): void {
		$this->assertSame(
			$this->wiki->sign( $method, $path, '1750000000', 'a-nonce', $body ),
			$this->portal->sign( $method, $path, '1750000000', 'a-nonce', $body ),
			'the two sides sign the request differently'
		);
	}

	#[TestDox( 'the portal accepts what this extension signs' )]
	public function testThePortalAcceptsOurSignature(): void {
		$headers = $this->wiki->headers( 'POST', '/api/wiki/v1/submissions', '{"type":"report"}' );

		$this->assertTrue( $this->portal->verify(
			'POST',
			'/api/wiki/v1/submissions',
			$headers[ WikiHmac::HEADER_TIMESTAMP ],
			$headers[ WikiHmac::HEADER_NONCE ],
			'{"type":"report"}',
			$headers[ WikiHmac::HEADER_SIGNATURE ]
		), 'a request signed here was refused there' );
	}

	#[TestDox( 'this extension accepts what the portal signs' )]
	public function testWeAcceptThePortalsSignature(): void {
		$headers = $this->portal->headers( 'POST', '/w/api.php', 'action=wikioasissafetysync' );

		$this->assertTrue( $this->wiki->verify(
			'POST',
			'/w/api.php',
			$headers[ \App\Services\MediaWiki\Hmac::HEADER_TIMESTAMP ],
			$headers[ \App\Services\MediaWiki\Hmac::HEADER_NONCE ],
			'action=wikioasissafetysync',
			$headers[ \App\Services\MediaWiki\Hmac::HEADER_SIGNATURE ]
		), 'a request signed there was refused here' );
	}

	#[TestDox( 'a different secret is not accepted' )]
	public function testADifferentSecretIsRefused(): void {
		$theirs = new \App\Services\MediaWiki\Hmac( 'a-different-secret', 300 );
		$headers = $this->wiki->headers( 'POST', '/api/wiki/v1/submissions', '{}' );

		$this->assertFalse( $theirs->verify(
			'POST', '/api/wiki/v1/submissions',
			$headers[ WikiHmac::HEADER_TIMESTAMP ],
			$headers[ WikiHmac::HEADER_NONCE ],
			'{}',
			$headers[ WikiHmac::HEADER_SIGNATURE ]
		), 'a signature under the wrong secret was accepted' );
	}
}
