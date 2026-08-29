<?php
declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

use MediaWiki\Extension\WikiOasisSafety\Categories;
use MediaWiki\Extension\WikiOasisSafety\WizardDefinition;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class WizardDefinitionTest extends TestCase {
	/** @return string[] */
	private static function stepIds( array $flow ): array {
		return array_column( $flow['steps'], 'id' );
	}

	private static function stepById( array $flow, string $id ): ?array {
		foreach ( $flow['steps'] as $step ) {
			if ( $step['id'] === $id ) {
				return $step;
			}
		}
		return null;
	}

	private static function exampleConfig( array $overrides = [] ): SettingsFileConfig {
		return new SettingsFileConfig( __DIR__ . '/ExampleConfiguration.php', $overrides );
	}

	private static function exampleFlow( array $overrides = [], string $flow = '' ): array {
		return WizardDefinition::resolve( self::exampleConfig( $overrides ), $flow, new TestLocalizer() );
	}

	#[TestDox( 'an extension with no configuration offers no wizard' )]
	public function testNoConfiguration(): void {
		$config = new TestConfig();
		$this->assertFalse( WizardDefinition::hasSteps( $config ),
			'so no entry points are added and Special:SafetyReport says where else to go' );
		$this->assertSame( [], WizardDefinition::resolve( $config )['steps'], 'and there is no flow' );
	}

	#[TestDox( 'the example configuration is a working flow' )]
	public function testExampleConfiguration(): void {
		$flow = self::exampleFlow();
		$this->assertTrue( WizardDefinition::hasSteps( self::exampleConfig() ), 'it has steps' );
		$this->assertSame(
			[ 'triage', 'report-subject', 'report-details', 'report-final', 'guidance' ],
			self::stepIds( $flow ),
			'in the order they are written'
		);
		$this->assertSame( '', $flow['title'], 'the wizard title is the extension\'s own' );
		$this->assertSame( '', $flow['submitLabel'], 'and so is its submit button' );
		$this->assertSame(
			[ 'help', 'report' ],
			array_column( self::stepById( $flow, 'triage' )['fields'], 'name' ),
			'the triage questions'
		);
		$this->assertSame( 'guidance', self::stepById( $flow, 'triage' )['branches'][0]['goTo'],
			'and the branch out of them' );
		$this->assertSame( [ [ 'help', 'report' ] ], $flow['exclusiveFields'],
			'the triage questions are alternatives' );
		$this->assertSame( 'users', $flow['fieldRoles']['users'],
			'and a reported user has somewhere to go' );
	}

	#[TestDox( 'the guidance step collects nothing, so it cannot claim a report was filed' )]
	public function testGuidanceStepCollectsNothing(): void {
		$flow = self::exampleFlow();
		$types = array_column( self::stepById( $flow, 'guidance' )['fields'], 'type' );
		$this->assertSame( [ 'card', 'card', 'card' ], $types, 'three cards and no questions' );
		$this->assertSame( 'Exit', self::stepById( $flow, 'guidance' )['nextLabel'],
			'and its button says so' );
	}

	#[TestDox( 'a step is identified by the key it is written under' )]
	public function testStepIdComesFromItsKey(): void {
		$flow = WizardDefinition::resolve( new TestConfig( [
			'WikiOasisSafetySteps' => [
				'first' => [ 'id' => 'something-else', 'title' => 'First' ],
			],
		] ) );
		$this->assertSame( [ 'first' ], self::stepIds( $flow ),
			'an id in the value cannot rename the step, so branch targets stay meaningful' );
	}

	#[TestDox( 'a step set to false is left out' )]
	public function testStepSetToFalseIsLeftOut(): void {
		$flow = self::exampleFlow( [
			'WikiOasisSafetySteps' => [ 'guidance' => false ] + ( new SettingsFileConfig(
				__DIR__ . '/ExampleConfiguration.php'
			) )->get( 'WikiOasisSafetySteps' ),
		] );
		$this->assertNotContains( 'guidance', self::stepIds( $flow ), 'the step is gone' );
		$this->assertSame( 'guidance', self::stepById( $flow, 'triage' )['branches'][0]['goTo'],
			'and the branch to it is left alone' );
	}

	#[TestDox( 'a field gets an id from its step and its name' )]
	public function testFieldIdIsDerived(): void {
		$flow = self::exampleFlow();
		$this->assertSame(
			[ 'triage-help', 'triage-report' ],
			array_column( self::stepById( $flow, 'triage' )['fields'], 'id' ),
			'so a wiki never has to invent one, and two steps can use the same field name'
		);
	}

	#[TestDox( 'a field with nothing to identify it is dropped, loudly' )]
	public function testUnusableFieldsAreDropped(): void {
		takeWarnings();
		$flow = WizardDefinition::resolve( new TestConfig( [
			'WikiOasisSafetySteps' => [
				'only' => [
					'title' => 'Only',
					'fields' => [
						[ 'type' => 'text', 'name' => 'kept', 'label' => 'Kept' ],
						[ 'type' => 'text', 'label' => 'Nameless' ],
						[ 'name' => 'typeless', 'label' => 'Typeless' ],
						'not a field',
					],
				],
			],
		] ) );
		$this->assertSame(
			[ 'kept' ],
			array_column( self::stepById( $flow, 'only' )['fields'], 'name' ),
			'only the usable field survives'
		);
		$this->assertCount( 3, takeWarnings(), 'and each one that did not was reported' );
	}

	#[TestDox( 'questions that are alternatives are cleaned up' )]
	public function testExclusiveFieldsAreCleanedUp(): void {
		$flow = WizardDefinition::resolve( new TestConfig( [
			'WikiOasisSafetyExclusiveFields' => [ [ 'route', 'report', 'route' ], [ 'lonely' ] ],
		] ) );
		$this->assertSame( [ [ 'route', 'report' ] ], $flow['exclusiveFields'],
			'duplicates collapse and a group of one is dropped' );
	}

	#[TestDox( 'nonsense configuration is reported and ignored, not fatal' )]
	public function testNonsenseConfigurationIsIgnored(): void {
		takeWarnings();
		$flow = WizardDefinition::resolve( new TestConfig( [
			'WikiOasisSafetyWizard' => 'a string',
			'WikiOasisSafetySteps' => [
				'fine' => [ 'title' => 'Fine', 'fields' => [] ],
				'broken' => 'a string',
				'alsoBroken' => [ 'title' => 'Also broken', 'fields' => 'a string' ],
			],
			'WikiOasisSafetyExclusiveFields' => [ 'help, report' ],
			'WikiOasisSafetyFieldRoles' => 'not an array',
		] ) );
		$this->assertSame( [ 'fine', 'alsoBroken' ], self::stepIds( $flow ),
			'the usable steps still run' );
		$this->assertSame( [], self::stepById( $flow, 'alsoBroken' )['fields'],
			'without their bad fields' );
		$this->assertSame( [], $flow['exclusiveFields'], 'no nonsense grouping' );
		$this->assertSame( [], $flow['fieldRoles'], 'no nonsense roles' );
		$this->assertCount( 5, takeWarnings(), 'and every bad value was reported' );
	}

	#[TestDox( 'a label may be a message, and the message wins' )]
	public function testAMessageWinsOverALiteral(): void {
		takeWarnings();
		$localizer = new TestLocalizer( [
			'flow-step' => 'What happened?',
			'flow-label' => 'Tell us',
			'flow-option' => 'Harassment',
			'flow-followup' => 'What kind?',
		] );

		$flow = WizardDefinition::resolve( new TestConfig( [
			'WikiOasisSafetyWizard' => [ 'submitLabel-message' => 'flow-label' ],
			'WikiOasisSafetySteps' => [
				'only' => [
					'title-message' => 'flow-step',
					'fields' => [
						[
							'type' => 'radio',
							'name' => 'what',
							'label' => 'Tell us in English only',
							'label-message' => 'flow-label',
							'options' => [
								[
									'value' => 'harassment',
									'label-message' => 'flow-option',
									'followUp' => [
										'type' => 'text',
										'name' => 'what-kind',
										'label-message' => 'flow-followup',
									],
								],
							],
						],
					],
				],
			],
		] ), '', $localizer );

		$step = self::stepById( $flow, 'only' );
		$field = $step['fields'][0];

		$this->assertSame( 'What happened?', $step['title'], 'a step title' );
		$this->assertSame( 'Tell us', $field['label'],
			'a field label, in place of the literal beside it' );
		$this->assertSame( 'Harassment', $field['options'][0]['label'], 'an option label' );
		$this->assertSame( 'What kind?', $field['options'][0]['followUp']['label'],
			'and the label of an input nested under an option' );
		$this->assertSame( 'Tell us', $flow['submitLabel'], 'and the wizard\'s own buttons' );

		$this->assertArrayNotHasKey( 'label-message', $field,
			'the -message key does not survive, so everything downstream sees one shape' );
		$this->assertSame( [], takeWarnings(), 'and nothing was worth warning about' );
	}

	#[TestDox( 'a message that does not exist falls back to the literal, loudly' )]
	public function testAMissingMessageFallsBackToTheLiteral(): void {
		takeWarnings();
		$flow = WizardDefinition::resolve( new TestConfig( [
			'WikiOasisSafetySteps' => [
				'only' => [
					'fields' => [
						[
							'type' => 'text',
							'name' => 'kept',
							'label' => 'Tell us what happened',
							'label-message' => 'flow-typo',
						],
						[
							'type' => 'text',
							'name' => 'bare',
							'label-message' => 'flow-other-typo',
						],
						[
							'type' => 'text',
							'name' => 'nonsense',
							'label-message' => [ 'flow-label', [ 'a list' ] ],
						],
					],
				],
			],
		] ), '', new TestLocalizer( [] ) );

		$fields = self::stepById( $flow, 'only' )['fields'];
		$this->assertSame( 'Tell us what happened', $fields[0]['label'],
			'a typo in LocalSettings does not put a message key in front of a reporter' );
		$this->assertSame( '⟨flow-other-typo⟩', $fields[1]['label'],
			'and with no literal to fall back on it is visibly wrong rather than blank' );
		$this->assertArrayNotHasKey( 'label', $fields[2],
			'a parameter that is not a string is not a message at all' );
		$this->assertCount( 3, takeWarnings(), 'each of the three was reported' );
	}

	#[TestDox( 'the same flow in two languages is two flows' )]
	public function testAFlowCarriesItsWords(): void {
		$config = new TestConfig( [
			'WikiOasisSafetySteps' => [
				'only' => [ 'title-message' => 'flow-step', 'fields' => [] ],
			],
		] );

		$english = WizardDefinition::resolve( $config, '', new TestLocalizer( [
			'flow-step' => 'What happened?',
		] ) );
		$french = WizardDefinition::resolve( $config, '', new TestLocalizer( [
			'flow-step' => "Que s'est-il passé ?",
		] ) );

		$this->assertNotSame( $english, $french, 'the resolved flow carries the words' );
		$this->assertNotSame(
			md5( (string)json_encode( $english ) ),
			md5( (string)json_encode( $french ) ),
			'so a flow resolved in one language cannot be served from another\'s cache'
		);
	}

	#[TestDox( 'each kind of report asks the questions only it raises' )]
	public function testEachReportAsksItsOwnQuestions(): void {
		$fields = self::stepById( self::exampleFlow(), 'report-details' )['fields'];

		$waitingFor = [];
		foreach ( $fields as $field ) {
			$condition = $field['visibleWhen'] ?? null;
			$waitingFor[$field['name']] = $condition ? $condition['value'] : null;
		}

		$this->assertNull( $waitingFor['details'], 'what happened is always asked' );
		$this->assertNull( $waitingFor['attachments'], 'and so are attachments' );

		$this->assertSame( 'threat-of-physical-harm', $waitingFor['threat-immediacy'],
			'a threat is asked how immediate it is' );
		$this->assertSame( 'a-licensing-issue', $waitingFor['licensing-standing'],
			'a licensing complaint is asked who holds the rights' );
		$this->assertSame( 'a-child-protection-issue', $waitingFor['child-nature'],
			'a child protection report is asked what kind' );
		$this->assertSame( 'harassment', $waitingFor['harassment-target'],
			'a harassment report is asked who it is happening to' );
		$this->assertSame( 'underage-user', $waitingFor['underage-basis'],
			'an age report is asked what the basis is' );

		foreach ( $fields as $field ) {
			if ( isset( $field['visibleWhen'] ) ) {
				$this->assertSame( 'report', $field['visibleWhen']['field'],
					"'{$field['name']}' branches on the triage answer" );
			}
		}
	}

	#[TestDox( 'the follow-up questions do not stop a report being filed' )]
	public function testOnlyTwoFollowUpsAreRequired(): void {
		$fields = self::stepById( self::exampleFlow(), 'report-details' )['fields'];
		$required = [];
		foreach ( $fields as $field ) {
			if ( !empty( $field['required'] ) ) {
				$required[] = $field['name'];
			}
		}

		$this->assertSame( [ 'details', 'underage-basis' ], $required,
			'only these two block the step' );
	}

	#[TestDox( 'an age report is counted as its own thing' )]
	public function testAnAgeReportIsItsOwnCategory(): void {
		$flow = self::exampleFlow();
		$categories = Categories::resolve(
			$flow,
			[ 'report' => 'underage-user', 'underage-basis' => 'said-on-wiki' ]
		);

		$this->assertSame( [ 'underage-user' ], array_column( $categories, 'id' ),
			'the option value names the category, so nothing had to be spelled twice' );
		$this->assertSame( 'Underage user', $categories[0]['label'],
			'read in the reader\'s language' );
		$this->assertSame( 'eligibility', $categories[0]['group'], 'in a group of its own' );
		$this->assertSame( 'harm', $flow['categories']['a-child-protection-issue']['group'],
			'while child protection stays where it was' );
	}

	#[TestDox( 'the version changes when configuration does, and only then' )]
	public function testTheModuleVersionTracksConfiguration(): void {
		$context = new MediaWiki\ResourceLoader\Context();
		$plain = WizardDefinition::provideFlowVersion( $context, self::exampleConfig() );
		$again = WizardDefinition::provideFlowVersion( $context, self::exampleConfig() );
		$edited = WizardDefinition::provideFlowVersion( $context, self::exampleConfig( [
			'WikiOasisSafetyWizard' => [ 'title' => 'Report a problem' ],
		] ) );
		$this->assertSame( $plain, $again, 'the same configuration hashes the same' );
		$this->assertNotSame( $plain, $edited, 'an edit busts the module cache' );
	}
}
