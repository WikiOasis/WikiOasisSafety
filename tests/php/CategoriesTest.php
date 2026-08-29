<?php
declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

use MediaWiki\Extension\WikiOasisSafety\Categories;
use MediaWiki\Extension\WikiOasisSafety\WizardDefinition;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class CategoriesTest extends TestCase {
	private static function ids( array $categories ): array {
		return array_column( $categories, 'id' );
	}

	private static function example( string $flow = '' ): array {
		return WizardDefinition::resolve(
			new SettingsFileConfig( __DIR__ . '/ExampleConfiguration.php' ),
			$flow,
			new TestLocalizer()
		);
	}

	private static function flowWith( array $fields, array $vocabulary ): array {
		return WizardDefinition::resolve( new TestConfig( [
			'WikiOasisSafetySteps' => [ 'only' => [ 'fields' => $fields ] ],
			'WikiOasisSafetyCategories' => $vocabulary,
		] ) );
	}

	#[TestDox( 'a flow that declares no categories resolves none' )]
	public function testNoVocabulary(): void {
		$flow = WizardDefinition::resolve( new TestConfig( [
			'WikiOasisSafetySteps' => [ 'only' => [ 'fields' => [
				[ 'type' => 'text', 'name' => 'what' ],
			] ] ],
		] ) );

		$this->assertSame( [], $flow['categories'], 'no vocabulary' );
		$this->assertSame( [], Categories::resolve( $flow, [ 'what' => 'anything' ] ),
			'nothing to resolve to' );
	}

	#[TestDox( 'a bare string is the label' )]
	public function testABareStringIsTheLabel(): void {
		$flow = self::flowWith(
			[ [ 'type' => 'radio', 'name' => 'q', 'options' => [ [ 'value' => 'spam' ] ] ] ],
			[ 'spam' => 'Spam' ]
		);

		$this->assertSame( 'Spam', $flow['categories']['spam']['label'],
			'the string became the label' );
		$this->assertNull( $flow['categories']['spam']['group'], 'and there is no group' );
	}

	#[TestDox( 'a half-written entry still renders' )]
	public function testAHalfWrittenEntryStillRenders(): void {
		$flow = self::flowWith(
			[ [ 'type' => 'radio', 'name' => 'q', 'options' => [ [ 'value' => 'spam' ] ] ] ],
			[ 'spam' => [ 'group' => 'content' ] ]
		);

		$this->assertSame( 'spam', $flow['categories']['spam']['label'],
			'the id stood in for the label' );
	}

	#[TestDox( 'ids are compared in one case, however they were written' )]
	public function testIdsAreNormalised(): void {
		$flow = self::flowWith(
			[ [ 'type' => 'radio', 'name' => 'q', 'options' => [ [ 'value' => 'Harassment' ] ] ] ],
			[ '  HARASSMENT ' => 'Harassment' ]
		);

		$this->assertSame( [ 'harassment' ], array_keys( $flow['categories'] ),
			'the declaration was normalised' );
		$this->assertSame(
			[ 'harassment' ],
			self::ids( Categories::resolve( $flow, [ 'q' => 'Harassment' ] ) ),
			'and so was the answer'
		);
	}

	#[TestDox( "an option's value names its own category" )]
	public function testAnOptionValueNamesItsCategory(): void {
		$flow = self::flowWith(
			[ [ 'type' => 'radio', 'name' => 'q', 'options' => [
				[ 'value' => 'harassment' ],
				[ 'value' => 'spam' ],
			] ] ],
			[ 'harassment' => 'Harassment', 'spam' => 'Spam' ]
		);

		$this->assertSame(
			[ 'harassment' ],
			self::ids( Categories::resolve( $flow, [ 'q' => 'harassment' ] ) ),
			'no category key was needed on the option'
		);
	}

	#[TestDox( 'an explicit category on the option beats the value' )]
	public function testAnExplicitCategoryBeatsTheValue(): void {
		$flow = self::flowWith(
			[ [ 'type' => 'radio', 'name' => 'q', 'options' => [
				[ 'value' => 'yes', 'category' => 'harassment' ],
			] ] ],
			[ 'harassment' => 'Harassment', 'yes' => 'Yes' ]
		);

		$this->assertSame(
			[ 'harassment' ],
			self::ids( Categories::resolve( $flow, [ 'q' => 'yes' ] ) ),
			'the option said which, and was believed over its own value'
		);
	}

	#[TestDox( 'a field can carry a category, for a box with no options' )]
	public function testAFieldCanCarryACategory(): void {
		$flow = self::flowWith(
			[ [ 'type' => 'textarea', 'name' => 'tellus', 'category' => 'harassment' ] ],
			[ 'harassment' => 'Harassment' ]
		);

		$this->assertSame(
			[ 'harassment' ],
			self::ids( Categories::resolve( $flow, [ 'tellus' => 'they keep following me' ] ) ),
			'answering it at all was enough'
		);
		$this->assertSame(
			[],
			self::ids( Categories::resolve( $flow, [ 'tellus' => '' ] ) ),
			'and leaving it blank was not'
		);
	}

	#[TestDox( 'a follow-up under an option can carry one too' )]
	public function testAFollowUpCanCarryACategory(): void {
		$flow = self::flowWith(
			[ [ 'type' => 'radio', 'name' => 'q', 'options' => [
				[ 'value' => 'other', 'followUp' => [
					'type' => 'text', 'name' => 'other-what', 'category' => 'unclassified',
				] ],
			] ] ],
			[ 'unclassified' => 'Not yet classified' ]
		);

		$this->assertSame(
			[ 'unclassified' ],
			self::ids( Categories::resolve( $flow, [ 'q' => 'other', 'other-what' => 'something odd' ] ) ),
			'a follow-up is a field like any other'
		);
	}

	#[TestDox( 'an unknown category id is dropped, not passed on' )]
	public function testAnUnknownCategoryIsDropped(): void {
		$flow = self::flowWith(
			[ [ 'type' => 'radio', 'name' => 'q', 'options' => [
				[ 'value' => 'yes', 'category' => 'harrassment' ],
			] ] ],
			[ 'harassment' => 'Harassment' ]
		);

		$this->assertSame( [], self::ids( Categories::resolve( $flow, [ 'q' => 'yes' ] ) ),
			'the misspelling was dropped' );
	}

	#[TestDox( 'an unanswered question contributes nothing' )]
	public function testAnUnansweredQuestionContributesNothing(): void {
		$flow = self::flowWith(
			[
				[ 'type' => 'checkbox', 'name' => 'harassed', 'category' => 'harassment' ],
				[ 'type' => 'checkbox', 'name' => 'spammed', 'category' => 'spam' ],
			],
			[ 'harassment' => 'Harassment', 'spam' => 'Spam' ]
		);

		$this->assertSame(
			[ 'harassment' ],
			self::ids( Categories::resolve( $flow, [ 'harassed' => true, 'spammed' => false ] ) ),
			'only the ticked one counted'
		);
	}

	#[TestDox( 'zero is an answer' )]
	public function testZeroIsAnAnswer(): void {
		$flow = self::flowWith(
			[ [ 'type' => 'radio', 'name' => 'q', 'options' => [
				[ 'value' => '0', 'category' => 'none' ],
			] ] ],
			[ 'none' => 'None of these' ]
		);

		$this->assertSame( [ 'none' ], self::ids( Categories::resolve( $flow, [ 'q' => '0' ] ) ),
			"'0' was not treated as blank" );
	}

	#[TestDox( 'ticking three boxes gives three categories' )]
	public function testAListAnswerGivesACategoryEach(): void {
		$flow = self::flowWith(
			[ [ 'type' => 'checkbox', 'name' => 'q', 'options' => [
				[ 'value' => 'harassment' ], [ 'value' => 'spam' ], [ 'value' => 'copyright' ],
			] ] ],
			[ 'harassment' => 'Harassment', 'spam' => 'Spam', 'copyright' => 'Copyright' ]
		);

		$this->assertSame(
			[ 'harassment', 'spam', 'copyright' ],
			self::ids( Categories::resolve( $flow, [ 'q' => [ 'harassment', 'spam', 'copyright' ] ] ) ),
			'a list answer produced a category each'
		);
	}

	#[TestDox( 'the same category named twice is one category' )]
	public function testACategoryIsCountedOnce(): void {
		$flow = self::flowWith(
			[
				[ 'type' => 'radio', 'name' => 'triage', 'options' => [ [ 'value' => 'harassment' ] ] ],
				[ 'type' => 'textarea', 'name' => 'detail', 'category' => 'harassment' ],
			],
			[ 'harassment' => 'Harassment' ]
		);

		$resolved = Categories::resolve(
			$flow,
			[ 'triage' => 'harassment', 'detail' => 'here is what happened' ]
		);

		$this->assertSame( [ 'harassment' ], self::ids( $resolved ), 'counted once' );
		$this->assertSame( 'triage', $resolved[0]['field'],
			'and credited to the question that framed the report' );
	}

	#[TestDox( 'a branch the reporter never walked contributes nothing' )]
	public function testAnUnwalkedBranchStaysSilent(): void {
		$flow = self::example();

		$resolved = Categories::resolve( $flow, [ 'report' => 'harassment' ] );

		$this->assertSame( [ 'harassment' ], self::ids( $resolved ),
			'the guidance branch stayed silent' );
	}

	#[TestDox( 'the example report flow categorises without any option being edited' )]
	public function testTheExampleFlowCategorises(): void {
		$flow = self::example();

		$this->assertNotSame( [], $flow['categories'], 'the example declares a vocabulary' );

		$resolved = Categories::resolve( $flow, [ 'report' => 'a-child-protection-issue' ] );

		$this->assertSame( [ 'a-child-protection-issue' ], self::ids( $resolved ),
			'the option value was the id' );
		$this->assertSame( 'Child protection', $resolved[0]['label'],
			'and it came back with its label' );
		$this->assertSame( 'harm', $resolved[0]['group'], 'and its group' );
	}

	#[TestDox( 'every example option value that looks like a category is one' )]
	public function testEveryReportableOptionHasACategory(): void {
		$flow = self::example();
		$declared = array_keys( $flow['categories'] );
		$missing = [];

		foreach ( $flow['steps'] as $step ) {
			foreach ( $step['fields'] as $field ) {
				if ( ( $field['name'] ?? '' ) !== 'report' ) {
					continue;
				}
				foreach ( $field['options'] ?? [] as $option ) {
					$value = Categories::normalise( (string)$option['value'] );
					if ( !in_array( $value, $declared, true ) && !isset( $option['category'] ) ) {
						$missing[] = $value;
					}
				}
			}
		}

		$this->assertSame( [], $missing, 'every reportable option has a category' );
	}

	#[TestDox( "the contact flow has its own vocabulary, not the report flow's" )]
	public function testTheContactFlowHasItsOwnVocabulary(): void {
		$contact = self::example( 'Contact' );
		$report = self::example();

		$this->assertNotSame( [], $contact['categories'], 'the contact flow declares one' );
		$this->assertNotSame(
			array_keys( $report['categories'] ),
			array_keys( $contact['categories'] ),
			'and it is not the report flow\'s list'
		);

		$this->assertSame(
			[ 'appeal' ],
			self::ids( Categories::resolve( $contact, [ 'contact-reason' => 'appeal' ] ) ),
			'a message categorises the same way a report does'
		);
	}

	#[TestDox( 'a message that is a general question is counted as one' )]
	public function testAGeneralQuestionIsCategorised(): void {
		$this->assertSame(
			[ 'general' ],
			self::ids( Categories::resolve( self::example( 'Contact' ), [ 'contact-reason' => 'general' ] ) ),
			'message info is categorised, not only report info'
		);
	}

	#[TestDox( 'a deletion request is counted as an erasure' )]
	public function testADeletionRequestIsAnErasure(): void {
		$this->assertSame(
			[ 'erasure' ],
			self::ids( Categories::resolve( self::example( 'Data' ), [ 'erase-confirm' => true ] ) ),
			'the request that can actually be sent is counted'
		);

		$this->assertSame(
			[],
			self::ids( Categories::resolve( self::example( 'Data' ), [ 'erase-details' => 'Please.' ] ) ),
			'and an unconfirmed one, which cannot be sent, counts as nothing'
		);
	}
}
