<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ImportsTest extends TestCase {
	private const OWN_NAMESPACE = 'MediaWiki\\Extension\\WikiOasisSafety\\';

	private const OPTIONAL = 'MediaWiki\\Extension\\';

	private const ELSEWHERE = [
		'Job',
		'GenericParameterJob',
		'StatusValue',
		'Throwable',
		'InvalidArgumentException',
		'RuntimeException',
		'UserProfilePage',
	];

	private static function mediaWikiPath(): string {
		return getenv( 'MW_INSTALL_PATH' ) ?: getenv( 'HOME' ) . '/Developer/wikifarm/mediawiki';
	}

	public static function provideSourceFiles(): array {
		$cases = [];
		foreach ( [ 'includes', 'maintenance' ] as $dir ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . "/$dir" )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && $file->getExtension() === 'php' ) {
					$path = $file->getPathname();
					$cases[ substr( $path, strlen( dirname( __DIR__, 2 ) ) + 1 ) ] = [ $path ];
				}
			}
		}
		ksort( $cases );
		return $cases;
	}

	private static function declaredNames( string $mw ): string {
		$haystack = (string)file_get_contents( "$mw/autoload.php" );

		foreach ( [
			'vendor/composer/autoload_classmap.php',
			'vendor/composer/autoload_static.php',
		] as $map ) {
			if ( is_file( "$mw/$map" ) ) {
				$haystack .= (string)file_get_contents( "$mw/$map" );
			}
		}

		return $haystack;
	}

	/** @return list<string> */
	private static function psr4Prefixes( string $mw ): array {
		if ( !is_file( "$mw/vendor/composer/autoload_psr4.php" ) ) {
			return [];
		}
		preg_match_all(
			"/'([A-Za-z0-9_\\\\]+\\\\)'\s*=>/",
			(string)file_get_contents( "$mw/vendor/composer/autoload_psr4.php" ),
			$prefixes
		);
		return array_map( static fn ( $p ) => stripslashes( $p ), $prefixes[1] );
	}

	#[DataProvider( 'provideSourceFiles' )]
	#[TestDox( 'every import resolves' )]
	public function testEveryImportResolves( string $file ): void {
		$mw = self::mediaWikiPath();
		if ( !is_file( "$mw/autoload.php" ) ) {
			$this->markTestSkipped( "no MediaWiki checkout at $mw; set MW_INSTALL_PATH to run this" );
		}

		$haystack = self::declaredNames( $mw );
		$psr4 = self::psr4Prefixes( $mw );

		$source = (string)file_get_contents( $file );
		preg_match_all( '/^use\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $matches );

		$missing = [];

		foreach ( $matches[1] as $class ) {
			if ( str_starts_with( $class, self::OWN_NAMESPACE )
				|| str_starts_with( $class, self::OPTIONAL )
				|| in_array( $class, self::ELSEWHERE, true )
			) {
				continue;
			}

			foreach ( $psr4 as $prefix ) {
				if ( str_starts_with( $class, $prefix ) ) {
					continue 2;
				}
			}

			$needle = "'" . str_replace( '\\', '\\\\', $class ) . "'";

			if ( !str_contains( $haystack, $needle ) ) {
				$missing[] = $class;
			}
		}

		$this->assertSame( [], $missing,
			"MediaWiki's autoloader has no such class: " . implode( ', ', $missing ) );
	}
}
