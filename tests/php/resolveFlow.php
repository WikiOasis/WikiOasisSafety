<?php
declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

$overrides = ( $argv[1] ?? '' ) !== '' ?
	json_decode( $argv[1], true, 512, JSON_THROW_ON_ERROR ) :
	[];

$config = new SettingsFileConfig( __DIR__ . '/ExampleConfiguration.php', $overrides );
$flow = MediaWiki\Extension\WikiOasisSafety\WizardDefinition::resolve(
	$config,
	$argv[2] ?? '',
	new TestLocalizer()
);

$warnings = takeWarnings();
if ( $warnings ) {
	fwrite( STDERR, implode( "\n", $warnings ) . "\n" );
}

echo json_encode( $flow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
