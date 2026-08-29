<?php
declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

use MediaWiki\Extension\WikiOasisSafety\NoJs\FlowNavigator;
use MediaWiki\Extension\WikiOasisSafety\WizardDefinition;

$answers = ( $argv[1] ?? '' ) !== ''
	? json_decode( $argv[1], true, 512, JSON_THROW_ON_ERROR )
	: [];

$overrides = ( $argv[3] ?? '' ) !== ''
	? json_decode( $argv[3], true, 512, JSON_THROW_ON_ERROR )
	: [];

$config = new SettingsFileConfig( __DIR__ . '/ExampleConfiguration.php', $overrides );
$flow = WizardDefinition::resolve( $config, $argv[2] ?? '', new TestLocalizer() );
$navigator = new FlowNavigator( $flow );

$walk = [];
$step = $navigator->firstStep();
$seen = [];

while ( $step && !in_array( $step['id'], $seen, true ) ) {
	$seen[] = $step['id'];

	$fields = [];
	foreach ( FlowNavigator::visibleFields( $step, $answers ) as $field ) {
		$fields[] = $field['name'];
	}

	$walk[] = [
		'step' => $step['id'],
		'fields' => $fields,
		'blocking' => $navigator->blockingFields( $step, $answers ),
		'guidance' => FlowNavigator::isGuidanceStep( $step ),
	];

	$next = $navigator->resolveNext( $step, $answers );
	if ( $next === FlowNavigator::SUBMIT ) {
		$walk[] = [ 'step' => '__submit__' ];
		break;
	}
	$step = $navigator->step( $next );
}

echo json_encode( $walk, JSON_UNESCAPED_SLASHES ) . "\n";
