<?php
declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

use MediaWiki\Extension\WikiOasisSafety\Anonymity;
use MediaWiki\Extension\WikiOasisSafety\WizardDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class AnonymityTest extends TestCase {
	private static function example(): array {
		return WizardDefinition::resolve(
			new SettingsFileConfig( __DIR__ . '/ExampleConfiguration.php' ),
			'',
			new TestLocalizer()
		);
	}

	private static function flowWith( array $fields ): array {
		return WizardDefinition::resolve( new TestConfig( [
			'WikiOasisSafetySteps' => [ 'only' => [ 'fields' => $fields ] ],
		] ) );
	}

	private static function fieldNames( array $flow ): array {
		return array_column( $flow['steps'][0]['fields'] ?? [], 'name' );
	}

	#[TestDox( "the example flow's toggle is the control, with nothing declared" )]
	public function testTheExampleFlowsToggleIsTheControl(): void {
		$this->assertSame(
			[ 'anonymous' ],
			Anonymity::controlNames( self::example() ),
			'the shipped example flow has exactly one anonymity control'
		);
	}

	#[TestDox( 'the resolver marks it, so the client does not have to work it out' )]
	public function testTheResolverMarksTheControl(): void {
		$marked = [];

		foreach ( self::example()['steps'] as $step ) {
			foreach ( $step['fields'] as $field ) {
				if ( ( $field['anonymous'] ?? null ) !== null ) {
					$marked[ $field['name'] ] = $field['anonymous'];
				}
			}
		}

		$this->assertSame( [ 'anonymous' => true ], $marked, 'one field carries the marking' );
	}

	#[TestDox( 'a flow that calls it something else says so instead' )]
	public function testAFlowMayNameItsOwnControl(): void {
		$flow = self::flowWith( [
			[ 'type' => 'toggle', 'name' => 'ohne-konto-melden', 'anonymous' => true ],
		] );

		$this->assertSame( [ 'ohne-konto-melden' ], Anonymity::controlNames( $flow ),
			'declared by the field' );
		$this->assertTrue(
			Anonymity::requestedIn( $flow, [ 'ohne-konto-melden' => true ] ),
			'and its answer is read'
		);
	}

	#[TestDox( 'a wiki that means something else by the word opts out' )]
	public function testAFieldMayOptOut(): void {
		$flow = self::flowWith( [
			[ 'type' => 'checkbox', 'name' => 'anonymous', 'anonymous' => false ],
		] );

		$this->assertSame( [], Anonymity::controlNames( $flow ), 'not a control' );
		$this->assertFalse(
			Anonymity::requestedIn( $flow, [ 'anonymous' => true ] ),
			'so ticking it asks for nothing'
		);
	}

	#[TestDox( 'a field that cannot be a yes-or-no question is not the control' )]
	public function testATextFieldIsNotAControl(): void {
		$flow = self::flowWith( [ [ 'type' => 'text', 'name' => 'anonymous' ] ] );

		$this->assertSame( [], Anonymity::controlNames( $flow ), 'a text field is not one' );
		$this->assertFalse(
			Anonymity::requestedIn( $flow, [ 'anonymous' => 'yes please' ] ),
			'and typing in it is not asking'
		);
	}

	#[TestDox( 'a control under an option is found too' )]
	public function testAControlUnderAnOptionIsFound(): void {
		$flow = self::flowWith( [ [
			'type' => 'radio',
			'name' => 'route',
			'options' => [
				[ 'value' => 'report', 'followUp' => [
					'type' => 'toggle',
					'name' => 'hide-me',
					'anonymous' => true,
				] ],
			],
		] ] );

		$this->assertSame( [ 'hide-me' ], Anonymity::controlNames( $flow ), 'found under the option' );
		$this->assertTrue( Anonymity::requestedIn( $flow, [ 'hide-me' => true ] ), 'and read' );
	}

	#[TestDox( 'more than one control means any of them' )]
	public function testAnyControlCounts(): void {
		$flow = self::flowWith( [
			[ 'type' => 'toggle', 'name' => 'anonymous' ],
			[ 'type' => 'checkbox', 'name' => 'appeal-anonymously', 'anonymous' => true ],
		] );

		$this->assertSame(
			[ 'anonymous', 'appeal-anonymously' ],
			Anonymity::controlNames( $flow ),
			'both are controls'
		);
		$this->assertTrue(
			Anonymity::requestedIn( $flow, [ 'appeal-anonymously' => true ] ),
			'and the one the reporter reached is the one that counts'
		);
	}

	public static function provideTicked(): array {
		return array_map(
			static fn ( $answer ): array => [ $answer ],
			[ true, 1, '1', 'true', 'on', 'yes', 'YES', ' true ' ]
		);
	}

	#[DataProvider( 'provideTicked' )]
	#[TestDox( 'what counts as ticked: $answer' )]
	public function testWhatCountsAsTicked( $answer ): void {
		$this->assertTrue(
			Anonymity::requestedIn( self::example(), [ 'anonymous' => $answer ] ),
			'a yes: ' . json_encode( $answer )
		);
	}

	public static function provideNotTicked(): array {
		return array_map(
			static fn ( $answer ): array => [ $answer ],
			[ false, 0, '0', '', 'no', 'false', null, [], [ 'anonymous' ] ]
		);
	}

	#[DataProvider( 'provideNotTicked' )]
	#[TestDox( 'what does not count as ticked: $answer' )]
	public function testWhatDoesNotCountAsTicked( $answer ): void {
		$this->assertFalse(
			Anonymity::requestedIn( self::example(), [ 'anonymous' => $answer ] ),
			'not a yes: ' . json_encode( $answer )
		);
	}

	#[TestDox( 'a step the reporter never reached asks for nothing' )]
	public function testAnUnansweredControlAsksForNothing(): void {
		$this->assertFalse( Anonymity::requestedIn( self::example(), [] ) );
	}

	#[TestDox( 'the control can be taken out without taking the step with it' )]
	public function testTheControlCanBeRemoved(): void {
		$flow = self::example();
		$before = null;
		$after = null;

		foreach ( $flow['steps'] as $step ) {
			if ( $step['id'] === 'report-final' ) {
				$before = array_column( $step['fields'], 'name' );
			}
		}

		foreach ( Anonymity::withoutControl( $flow )['steps'] as $step ) {
			if ( $step['id'] === 'report-final' ) {
				$after = array_column( $step['fields'], 'name' );
			}
		}

		$this->assertSame( [ 'threat-to-life', 'anonymous' ], $before, 'the step as configured' );
		$this->assertSame( [ 'threat-to-life' ], $after, 'and with the control taken out' );
		$this->assertSame( [], Anonymity::controlNames( Anonymity::withoutControl( $flow ) ),
			'nothing left to find' );
	}

	#[TestDox( 'a flow with no control at all is left alone' )]
	public function testAFlowWithNoControlIsLeftAlone(): void {
		$flow = self::flowWith( [ [ 'type' => 'textarea', 'name' => 'details' ] ] );

		$this->assertSame( [], Anonymity::controlNames( $flow ), 'nothing to find' );
		$this->assertSame(
			[ 'details' ],
			self::fieldNames( Anonymity::withoutControl( $flow ) ),
			'and nothing taken'
		);
	}
}
