<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class SubmissionParityTest extends TestCase {
	public static function provideSubmissionPaths(): array {
		return [
			'the API module' => [ 'includes/Api/ApiSafetySubmit.php' ],
			'the no-JavaScript form' => [ 'includes/NoJs/WizardForm.php' ],
		];
	}

	private function sourceOf( string $path ): string {
		return (string)file_get_contents( __DIR__ . '/../../' . $path );
	}

	/**
	 * @param string $path
	 * @param string $needle
	 * @return list<string>
	 */
	private function payloadKeys( string $path, string $needle ): array {
		$source = $this->sourceOf( $path );

		$start = strpos( $source, $needle );
		$this->assertNotFalse( $start, "$path no longer contains '$needle'" );

		$open = strpos( $source, '[', $start + strlen( $needle ) - 1 );
		$this->assertNotFalse( $open, "no array literal after '$needle' in $path" );

		$depth = 0;
		$end = null;

		for ( $i = $open; $i < strlen( $source ); $i++ ) {
			if ( $source[$i] === '[' ) {
				$depth++;
			} elseif ( $source[$i] === ']' ) {
				$depth--;
				if ( $depth === 0 ) {
					$end = $i;
					break;
				}
			}
		}

		$this->assertNotNull( $end, "unbalanced array literal after '$needle' in $path" );

		$body = substr( $source, $open + 1, $end - $open - 1 );

		$keys = [];
		$depth = 0;

		foreach ( preg_split( '/\R/', $body ) as $line ) {
			if ( $depth === 0 && preg_match( "/^\s*'([a-z_]+)'\s*=>/", $line, $m ) ) {
				$keys[] = $m[1];
			}
			$depth += substr_count( $line, '[' ) - substr_count( $line, ']' );
		}

		sort( $keys );

		return $keys;
	}

	#[TestDox( 'both submission paths send the same envelope' )]
	public function testBothPathsSendTheSameEnvelope(): void {
		$api = $this->payloadKeys( 'includes/Api/ApiSafetySubmit.php', '$submission = [' );
		$noJs = $this->payloadKeys( 'includes/NoJs/WizardForm.php', '->submit( [' );

		$allowedOnlyInApi = [ 'attachments' ];

		$this->assertSame(
			array_values( array_diff( $api, $allowedOnlyInApi ) ),
			$noJs,
			"ApiSafetySubmit and NoJs\\WizardForm disagree about what a submission is.\n"
				. '         A key in one and not the other is a submission that silently loses '
				. "something\n         depending on whether the reporter had JavaScript."
		);
	}

	#[DataProvider( 'provideSubmissionPaths' )]
	#[TestDox( 'both paths resolve categories on the server' )]
	public function testBothPathsResolveCategories( string $path ): void {
		$this->assertStringContainsString(
			'Categories::resolve(',
			$this->sourceOf( $path ),
			"$path does not resolve its own categories"
		);
	}

	#[DataProvider( 'provideSubmissionPaths' )]
	#[TestDox( 'both paths decide anonymity the same way, and neither invents its own' )]
	public function testBothPathsAskAnonymity( string $path ): void {
		$source = $this->sourceOf( $path );

		$this->assertStringContainsString(
			'Anonymity::of(',
			$source,
			"$path decides for itself whether a submission is anonymous instead of "
				. 'asking Anonymity'
		);
		$this->assertStringNotContainsString(
			"'anonymous' => !Accounts::isNamed(",
			$source,
			"$path still reads anonymity out of the session alone, which ignores "
				. 'what the reporter asked for'
		);
	}

	#[DataProvider( 'provideSubmissionPaths' )]
	#[TestDox( 'the flow-to-settings name lives in one place' )]
	public function testTheSettingsSuffixLivesInOnePlace( string $path ): void {
		$source = $this->sourceOf( $path );

		$this->assertStringContainsString(
			'WizardDefinition::suffixFor(',
			$source,
			"$path works out its own settings suffix instead of asking WizardDefinition"
		);
		$this->assertStringNotContainsString(
			"ucfirst( \$this->flowName )",
			$source,
			"$path still derives the settings suffix with ucfirst()"
		);
	}
}
