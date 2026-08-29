<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class StylesTest extends TestCase {
	private static function mediaWikiPath(): string {
		return getenv( 'MW_INSTALL_PATH' ) ?: getenv( 'HOME' ) . '/Developer/wikifarm/mediawiki';
	}

	private static function importDirs( string $mw ): array {
		$dirs = array_fill_keys( [ $mw . '/resources/src/mediawiki.less' ], '' );
		$dirs[] = static function ( string $path ) use ( $mw ) {
			$map = [
				'@wikimedia/codex-icons/' => '/resources/lib/codex-icons/',
				'mediawiki.skin.codex/' => '/resources/lib/codex/',
				'mediawiki.skin.codex-design-tokens/' => '/resources/lib/codex-design-tokens/',
			];
			foreach ( $map as $prefix => $target ) {
				if ( str_starts_with( $path, $prefix ) ) {
					$resolved = $mw . $target . substr( $path, strlen( $prefix ) );
					if ( file_exists( $resolved ) ) {
						return [ $resolved, '' ];
					}
				}
			}
			return null;
		};
		return $dirs;
	}

	private static function styleIn( string $path ): ?string {
		$source = (string)file_get_contents( $path );
		if ( str_ends_with( $path, '.less' ) ) {
			return $source;
		}
		if ( str_ends_with( $path, '.vue' ) &&
			preg_match( '/<style lang="less">(.*)<\/style>/s', $source, $m )
		) {
			return $m[1];
		}
		return null;
	}

	public static function provideStyleBlocks(): array {
		$cases = [];
		foreach ( glob( __DIR__ . '/../../resources/ext.wikiOasisSafety/*' ) as $path ) {
			if ( self::styleIn( $path ) !== null ) {
				$cases[ basename( $path ) ] = [ $path ];
			}
		}
		return $cases;
	}

	#[DataProvider( 'provideStyleBlocks' )]
	#[TestDox( 'compiles with every token resolved' )]
	public function testEveryTokenResolves( string $path ): void {
		$mw = self::mediaWikiPath();
		$autoload = $mw . '/vendor/autoload.php';

		if ( !is_file( $autoload ) ) {
			$this->markTestSkipped(
				"no MediaWiki checkout at $mw; set MW_INSTALL_PATH to run the style checks"
			);
		}
		require_once $autoload;

		if ( !class_exists( Less_Parser::class ) ) {
			$this->markTestSkipped(
				"$mw has no less.php; set MW_INSTALL_PATH to a checkout with vendor/"
			);
		}

		$name = basename( $path );

		$parser = new Less_Parser( [ 'math' => 'parens-division', 'relativeUrls' => false ] );
		$parser->SetImportDirs( self::importDirs( $mw ) );
		$parser->parse( (string)self::styleIn( $path ) );
		$css = $parser->getCss();

		preg_match_all( '/var\(\s*(--[a-z0-9-]+)\s*\)/', $css, $bare );
		$dead = array_values( array_unique( $bare[1] ) );

		$this->assertSame( [], $dead,
			"$name asks for tokens that are not custom properties: " . implode( ', ', $dead )
			. ". Import 'mediawiki.skin.variables.less' and use '@token' instead." );
	}
}
