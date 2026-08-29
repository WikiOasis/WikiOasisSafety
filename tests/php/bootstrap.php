<?php
declare( strict_types=1 );

namespace MediaWiki\Config {
	interface Config {
		public function get( string $name );
	}
}

namespace {
	interface MessageLocalizer {
		/** @return TestMessage */
		public function msg( $key, ...$params );
	}
}

namespace MediaWiki\ResourceLoader {
	class Context implements \MessageLocalizer {
		public function msg( $key, ...$params ) {
			return ( new \TestLocalizer() )->msg( $key, ...$params );
		}
	}
}

namespace {
	$GLOBALS['wikioasisWarnings'] = [];

	function wfLogWarning( string $message ): void {
		$GLOBALS['wikioasisWarnings'][] = $message;
	}

	function takeWarnings(): array {
		$warnings = $GLOBALS['wikioasisWarnings'];
		$GLOBALS['wikioasisWarnings'] = [];
		return $warnings;
	}

	require_once __DIR__ . '/../../includes/Wording.php';
	require_once __DIR__ . '/../../includes/Anonymity.php';
	require_once __DIR__ . '/../../includes/Categories.php';
	require_once __DIR__ . '/../../includes/WizardDefinition.php';
	require_once __DIR__ . '/../../includes/NoJs/FlowNavigator.php';

	class TestMessage {
		private ?string $text;

		public function __construct( private string $key, ?string $text, private array $params ) {
			$this->text = $text;
		}

		public function exists(): bool {
			return $this->text !== null;
		}

		public function text(): string {
			if ( $this->text === null ) {
				return '⟨' . $this->key . '⟩';
			}
			$text = $this->text;
			foreach ( $this->params as $i => $value ) {
				$text = str_replace( '$' . ( $i + 1 ), (string)$value, $text );
			}
			return $text;
		}
	}

	class TestLocalizer implements MessageLocalizer {
		private array $messages;

		public function __construct( ?array $messages = null ) {
			$this->messages = $messages ?? json_decode(
				(string)file_get_contents( __DIR__ . '/../../i18n/en.json' ),
				true
			);
		}

		public function msg( $key, ...$params ): TestMessage {
			$text = $this->messages[$key] ?? null;
			return new TestMessage( (string)$key, is_string( $text ) ? $text : null, $params );
		}
	}

	class TestConfig implements MediaWiki\Config\Config {
		private array $values;

		public function __construct( array $values = [] ) {
			$this->values = $values + [
				'WikiOasisSafetyEnabled' => true,
				'WikiOasisSafetyNoticeboard' => 'Project:Counter-vandalism unit',
				'WikiOasisSafetyWizard' => [],
				'WikiOasisSafetySteps' => [],
				'WikiOasisSafetyExclusiveFields' => [],
				'WikiOasisSafetyFieldRoles' => [],
				'WikiOasisSafetyDataWizard' => [],
				'WikiOasisSafetyDataSteps' => [],
				'WikiOasisSafetyDataExclusiveFields' => [],
				'WikiOasisSafetyDataFieldRoles' => [],
				'WikiOasisSafetyContactWizard' => [],
				'WikiOasisSafetyContactSteps' => [],
				'WikiOasisSafetyContactExclusiveFields' => [],
				'WikiOasisSafetyContactFieldRoles' => [],
				'WikiOasisSafetyCategories' => [],
				'WikiOasisSafetyDataCategories' => [],
				'WikiOasisSafetyContactCategories' => [],
			];
		}

		public function get( string $name ) {
			return $this->values[$name] ?? null;
		}
	}

	class SettingsFileConfig implements MediaWiki\Config\Config {
		private array $values = [];

		public function __construct( string $path, array $overrides = [] ) {
			require $path;
			foreach ( get_defined_vars() as $name => $value ) {
				if ( str_starts_with( $name, 'wg' ) ) {
					$this->values[substr( $name, 2 )] = $value;
				}
			}
			$this->values = $overrides + $this->values;
		}

		public function get( string $name ) {
			return $this->values[$name] ?? null;
		}
	}
}
