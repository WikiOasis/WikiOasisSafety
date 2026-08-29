<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\NoJs;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\WikiOasisSafety\Accounts;
use MediaWiki\Extension\WikiOasisSafety\Anonymity;
use MediaWiki\Extension\WikiOasisSafety\Categories;
use MediaWiki\Extension\WikiOasisSafety\Portal\PortalClient;
use MediaWiki\Extension\WikiOasisSafety\Throttle;
use MediaWiki\Extension\WikiOasisSafety\Wording;
use MediaWiki\Extension\WikiOasisSafety\WizardDefinition;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

class WizardForm {

	private const STATE = 'wostate';

	private const STEP = 'wostep';

	private const BACK = 'woback';

	private array $flow;

	private FlowNavigator $navigator;

	private IContextSource $context;

	private Title $root;

	private string $flowName;

	private array $answers = [];

	private array $history = [];

	/**
	 * @param array $flow
	 * @param IContextSource $context
	 * @param Title $root
	 * @param string $flowName
	 */
	public function __construct(
		array $flow, IContextSource $context, Title $root, string $flowName = 'report'
	) {
		if ( Anonymity::isForced( $context->getUser() ) ) {
			$flow = Anonymity::withoutControl( $flow );
		}

		$this->flow = $flow;
		$this->navigator = new FlowNavigator( $flow );
		$this->context = $context;
		$this->root = $root;
		$this->flowName = $flowName;
	}

	/**
	 * @param string $stepId
	 * @param array $prefill
	 */
	public function show( string $stepId, array $prefill = [] ): void {
		$out = $this->context->getOutput();
		$request = $this->context->getRequest();

		if ( !$this->navigator->hasSteps() ) {
			$out->addWikiMsg( 'wikioasissafety-noflow' );
			return;
		}

		$this->restoreState( $prefill );

		if ( $request->wasPosted() && $request->getCheck( 'wp' . self::BACK ) ) {
			$previous = array_pop( $this->history );
			$this->render( $previous ?? $this->firstStepId() );
			return;
		}

		if ( $request->wasPosted() ) {
			$this->render( (string)$request->getText( 'wp' . self::STEP ) );
			return;
		}

		$this->render( $stepId !== '' ? $stepId : $this->firstStepId() );
	}

	private function firstStepId(): string {
		$first = $this->navigator->firstStep();
		return $first['id'] ?? '';
	}

	/**
	 * @param array $prefill
	 */
	private function restoreState( array $prefill ): void {
		$this->answers = $prefill;
		$raw = $this->context->getRequest()->getText( 'wp' . self::STATE );
		if ( $raw === '' ) {
			return;
		}
		$decoded = json_decode( $raw, true );
		if ( !is_array( $decoded ) ) {
			wfLogWarning( 'WikiOasisSafety: unreadable wizard state; starting over' );
			return;
		}
		$this->answers = is_array( $decoded['a'] ?? null ) ? $decoded['a'] : $prefill;
		$this->history = array_values( array_filter(
			is_array( $decoded['h'] ?? null ) ? $decoded['h'] : [],
			'is_string'
		) );
	}

	private function state(): string {
		return (string)json_encode( [ 'a' => $this->answers, 'h' => $this->history ] );
	}

	/**
	 * @param string $stepId
	 */
	private function render( string $stepId ): void {
		$step = $this->navigator->resolveStep( $stepId, $this->answers );
		if ( !$step ) {
			$this->context->getOutput()->addWikiMsg( 'wikioasissafety-noflow' );
			return;
		}

		$fields = FlowNavigator::visibleFields( $step, $this->answers );
		$descriptor = $this->describeStep( $step, $fields );

		$form = HTMLForm::factory( 'codex', $descriptor, $this->context );
		$form->setAction( $this->root->getLocalURL() )
			->setId( 'wikioasis-safety-nojs-form' )
			->setSubmitTextMsg( $this->nextLabel( $step ) );

		if ( $step['title'] ?? '' ) {
			$form->setWrapperLegend( $step['title'] );
		}
		if ( $this->history ) {
			$form->addButton( [
				'name' => 'wp' . self::BACK,
				'value' => '1',
				'label' => $this->label( $step['backLabel'] ?? '',
					$this->flow['backLabel'] ?? '', 'wikioasissafety-back' ),
				'flags' => [],
			] );
		}

		$submitted = false;
		$form->setSubmitCallback( function ( array $data ) use ( $step, $fields, &$submitted ) {
			$this->collect( $fields, $data );
			$submitted = true;
			return true;
		} );

		$result = $form->show();
		if ( $result !== true || !$submitted ) {
			return;
		}

		$this->advance( $step );
	}

	/**
	 * @param array $step
	 */
	private function advance( array $step ): void {
		$next = $this->navigator->resolveNext( $step, $this->answers );
		if ( $next !== FlowNavigator::SUBMIT ) {
			$this->history[] = $step['id'];
			$this->render( $next );
			return;
		}

		$out = $this->context->getOutput();

		if ( FlowNavigator::isGuidanceStep( $step ) ) {
			$out->addHTML( Html::rawElement( 'p', [],
				$this->context->msg( 'wikioasissafety-nojs-done' )->parse() ) );
			return;
		}

		$this->deliver( $out );
		$out->addHTML( Html::rawElement( 'p', [],
			$this->context->msg( 'wikioasissafety-nojs-done' )->parse() ) );
	}

	/**
	 * @param array $fields
	 * @param array $data
	 */
	private function collect( array $fields, array $data ): void {
		$answeredHere = [];

		foreach ( $fields as $field ) {
			if ( !FlowNavigator::collectsData( $field ) ) {
				continue;
			}
			$name = $field['name'];
			$key = $this->key( $name );
			if ( array_key_exists( $key, $data ) ) {
				$value = $this->fromFormValue( $field, $data[$key] );
				$was = $this->answers[$name] ?? null;

				if ( FlowNavigator::isEmptyValue( $value ) ) {
					unset( $this->answers[$name] );
				} else {
					$this->answers[$name] = $value;

					$answeredHere[$name] = $value !== $was;
				}
			}
			foreach ( $field['options'] ?? [] as $option ) {
				if ( empty( $option['followUp'] ) ) {
					continue;
				}
				$followUp = FlowNavigator::followUpName( $field, $option );
				$followKey = $this->key( $followUp );
				if ( !array_key_exists( $followKey, $data ) ) {
					continue;
				}

				if ( FlowNavigator::isOptionSelected( $field, $option, $this->answers ) &&
					!FlowNavigator::isEmptyValue( $data[$followKey] )
				) {
					$this->answers[$followUp] = $data[$followKey];
				} else {
					unset( $this->answers[$followUp] );
				}
			}
		}

		$this->resolveExclusivity( $answeredHere );
	}

	/**
	 * @param array<string,bool> $answeredHere
	 */
	private function resolveExclusivity( array $answeredHere ): void {
		if ( $answeredHere === [] ) {
			return;
		}

		$changed = array_keys( array_filter( $answeredHere ) );
		$winners = $changed !== [] ? $changed : array_keys( $answeredHere );

		foreach ( $winners as $name ) {
			$this->navigator->applyExclusivity( $name, $this->answers );

			$this->answers[$name] = $this->answers[$name] ?? null;
			if ( $this->answers[$name] === null ) {
				unset( $this->answers[$name] );
			}
		}
	}

	/**
	 * @param string $name
	 * @return string
	 */
	private function key( string $name ): string {
		return 'wo_' . preg_replace( '/[^A-Za-z0-9_-]/', '_', $name );
	}

	/**
	 * @param array $step
	 * @param array $fields
	 * @return array
	 */
	private function describeStep( array $step, array $fields ): array {
		$descriptor = [];

		if ( $step['description'] ?? '' ) {
			$descriptor['wo_stepdescription'] = [
				'type' => 'info',
				'default' => $step['description'],
			];
		}

		foreach ( $fields as $field ) {
			$descriptor += $this->describeField( $field );
		}

		$descriptor[self::STATE] = [ 'type' => 'hidden', 'default' => $this->state() ];
		$descriptor[self::STEP] = [ 'type' => 'hidden', 'default' => $step['id'] ];
		return $descriptor;
	}

	/**
	 * @param array $field
	 * @return array
	 */
	private function describeField( array $field ): array {
		$key = $this->key( $field['name'] ?? '' );
		$descriptor = [ $key => $this->fieldDescriptor( $field ) ];

		foreach ( $field['options'] ?? [] as $option ) {
			if ( empty( $option['followUp'] ) ) {
				continue;
			}
			$followUp = $option['followUp'];
			$name = FlowNavigator::followUpName( $field, $option );
			$entry = [
				'type' => $followUp['type'] ?? 'text',
				'label' => $this->context->msg( 'wikioasissafety-nojs-followup' )
					->params( $option['label'] ?? '',
						$followUp['label'] ?? $this->context->msg( 'wikioasissafety-followup-label' )->text() )
					->text(),
				'default' => (string)( $this->answers[$name] ?? '' ),
				'validation-callback' => fn ( $value, array $all ) =>
					$this->validateFollowUp( $field, $option, $value, $all ),
			];
			if ( ( $entry['type'] ?? '' ) === 'textarea' ) {
				$entry['rows'] = 3;
			}

			if ( in_array( $field['type'] ?? '', [ 'radio', 'select', 'combobox' ], true ) ) {
				$entry['hide-if'] = [ '!==', $key, (string)( $option['value'] ?? '' ) ];
			}
			$descriptor[$this->key( $name )] = $entry;
		}

		return $descriptor;
	}

	/**
	 * @param array $field
	 * @return array
	 */
	private function fieldDescriptor( array $field ): array {
		$type = $field['type'] ?? '';
		$name = $field['name'] ?? '';
		$current = $this->answers[$name] ?? null;

		$content = $this->contentDescriptor( $field );
		if ( $content !== null ) {
			return $content;
		}

		$entry = [
			'label' => $field['label'] ?? '',
			'help' => $field['helpText'] ?? '',
			'validation-callback' => fn ( $value, array $all ) =>
				$this->validateField( $field, $value, $all ),
		];
		if ( $field['description'] ?? '' ) {
			$entry['help'] = trim( $field['description'] . "\n" . $entry['help'] );
		}
		if ( !empty( $field['disabled'] ) ) {
			$entry['disabled'] = true;
		}

		switch ( $type ) {
			case 'textarea':
				return $entry + [
					'type' => 'textarea',
					'rows' => $field['rows'] ?? 4,
					'placeholder' => $field['placeholder'] ?? '',
					'default' => (string)( $current ?? '' ),
				];

			case 'select':
			case 'combobox':
				return $entry + [
					'type' => $type === 'select' ? 'select' : 'combobox',
					'options' => $this->options( $field ),
					'default' => (string)( $current ?? '' ),
				];

			case 'radio':
				return $entry + [
					'type' => 'radio',
					'options' => $this->options( $field ),
					'default' => (string)( $current ?? '' ),
				];

			case 'checkboxGroup':
				return $entry + [
					'type' => 'multiselect',
					'options' => $this->options( $field ),
					'default' => is_array( $current ) ? $current : [],
				];

			case 'checkbox':
			case 'toggle':
				return $entry + [
					'type' => 'check',
					'default' => (bool)$current,
				];

			case 'chipInput':
			case 'lookup':
				return $entry + [
					'type' => 'text',
					'help' => trim( $entry['help'] . "\n" .
						$this->context->msg( 'wikioasissafety-nojs-list-help' )->text() ),
					'default' => is_array( $current ) ? implode( ', ', $current ) : (string)( $current ?? '' ),
				];

			case 'fileUpload':
				return [
					'type' => 'info',
					'label' => $field['label'] ?? '',
					'default' => $this->context->msg( 'wikioasissafety-nojs-nofiles' )->parse(),
					'raw' => true,
				];

			case 'text':
			default:
				return $entry + [
					'type' => in_array( $field['inputType'] ?? 'text', [ 'email', 'url' ], true )
						? $field['inputType'] : 'text',
					'placeholder' => $field['placeholder'] ?? '',
					'maxlength' => $field['maxLength'] ?: null,
					'default' => (string)( $current ?? '' ),
				];
		}
	}

	/**
	 * @param array $field
	 * @return array|null
	 */
	private function contentDescriptor( array $field ): ?array {
		switch ( $field['type'] ?? '' ) {
			case 'heading':
				return [
					'type' => 'info',
					'default' => Html::element( 'strong', [], $field['text'] ?? '' ),
					'raw' => true,
				];

			case 'paragraph':
				return [ 'type' => 'info', 'default' => $field['text'] ?? '' ];

			case 'message':
				return [
					'type' => 'info',
					'default' => Html::noticeBox( htmlspecialchars( $field['text'] ?? '' ), '' ),
					'raw' => true,
				];

			case 'infoChip':
				return [ 'type' => 'info', 'default' => $field['text'] ?? '' ];

			case 'accordion':
				return [
					'type' => 'info',
					'label' => $field['label'] ?? '',
					'default' => trim( ( $field['description'] ?? '' ) . "\n" . ( $field['text'] ?? '' ) ),
				];

			case 'card':
				$title = $field['label'] ?? '';
				$body = $field['description'] ?? '';
				$url = $field['url'] ?? '';
				$heading = $url !== ''
					? Html::element( 'a', [ 'href' => $url ], $title )
					: Html::element( 'strong', [], $title );
				return [
					'type' => 'info',
					'default' => Html::rawElement( 'div', [ 'class' => 'wikioasis-safety-nojs-card' ],
						Html::rawElement( 'strong', [], $heading ) .
						( $body !== '' ? Html::element( 'p', [], $body ) : '' ) ),
					'raw' => true,
				];

			default:
				return null;
		}
	}

	private function options( array $field ): array {
		$options = [];
		$type = $field['type'] ?? '';

		if ( $type === 'select' && ( $field['defaultLabel'] ?? '' ) ) {
			$options[$field['defaultLabel']] = '';
		}

		$optional = empty( $field['required'] )
			|| isset( $this->navigator->exclusiveGroups()[$field['name'] ?? ''] );
		if ( $type === 'radio' && $optional ) {
			$options[$this->context->msg( 'wikioasissafety-nojs-none' )->text()] = '';
		}
		foreach ( $field['options'] ?? [] as $option ) {
			$label = (string)( $option['label'] ?? $option['value'] ?? '' );
			if ( $option['description'] ?? '' ) {
				$label .= ' — ' . $option['description'];
			}
			$options[$label] = (string)( $option['value'] ?? '' );
		}
		return $options;
	}

	/**
	 * @param array $all
	 * @return array
	 */
	private function merged( array $all ): array {
		$merged = $this->answers;
		foreach ( $this->navigator->steps() as $step ) {
			foreach ( $step['fields'] ?? [] as $field ) {
				$key = $this->key( $field['name'] ?? '' );
				if ( array_key_exists( $key, $all ) ) {
					$merged[$field['name']] = $this->fromFormValue( $field, $all[$key] );
				}
			}
		}
		return $merged;
	}

	/**
	 * @param array $field
	 * @param mixed $value
	 * @param array $all
	 * @return bool|string
	 */
	private function validateField( array $field, $value, array $all ) {
		if ( empty( $field['required'] ) || !FlowNavigator::collectsData( $field ) ) {
			return true;
		}

		$merged = $this->merged( $all );
		if ( !FlowNavigator::evaluateCondition( $field['visibleWhen'] ?? null, $merged ) ) {
			return true;
		}

		if ( !FlowNavigator::isEmptyValue( $this->fromFormValue( $field, $value ) ) ) {
			return true;
		}

		if ( $this->navigator->isAnswered( $field, $merged ) ) {
			return true;
		}

		return $this->context->msg( ( $field['type'] ?? '' ) === 'checkbox'
			? 'wikioasissafety-error-checkbox-required'
			: 'wikioasissafety-error-required' )->text();
	}

	/**
	 * @param array $field
	 * @param array $option
	 * @param mixed $value
	 * @param array $all
	 * @return bool|string
	 */
	private function validateFollowUp( array $field, array $option, $value, array $all ) {
		if ( empty( $option['followUp']['required'] ) ) {
			return true;
		}
		$merged = $this->merged( $all );
		if ( !FlowNavigator::isOptionSelected( $field, $option, $merged ) ) {
			return true;
		}
		return FlowNavigator::isEmptyValue( $value )
			? $this->context->msg( 'wikioasissafety-error-required' )->text()
			: true;
	}

	/**
	 * @param array $field
	 * @param mixed $value
	 * @return mixed
	 */
	private function fromFormValue( array $field, $value ) {
		if ( in_array( $field['type'] ?? '', [ 'chipInput', 'lookup' ], true ) ) {
			$parts = array_filter( array_map( 'trim', explode( ',', (string)$value ) ),
				static fn ( string $part ): bool => $part !== '' );
			return array_values( $parts );
		}
		if ( ( $field['type'] ?? '' ) === 'checkboxGroup' ) {
			return is_array( $value ) ? array_values( $value ) : [];
		}
		return $value;
	}

	private function nextLabel( array $step ): Message {
		$next = $this->navigator->resolveNext( $step, $this->answers );
		if ( $next !== FlowNavigator::SUBMIT ) {
			return $this->rawMsg( $this->label( $step['nextLabel'] ?? '',
				$this->flow['nextLabel'] ?? '', 'wikioasissafety-next' ) );
		}
		if ( FlowNavigator::isGuidanceStep( $step ) ) {
			return $this->context->msg( 'wikioasissafety-close' );
		}
		return $this->rawMsg( $this->label( $step['nextLabel'] ?? '',
			$this->flow['submitLabel'] ?? '', 'wikioasissafety-submit' ) );
	}

	private function label( string $own, string $flowDefault, string $fallbackKey ): string {
		if ( $own !== '' ) {
			return $own;
		}
		if ( $flowDefault !== '' ) {
			return $flowDefault;
		}
		return $this->context->msg( $fallbackKey )->text();
	}

	private function rawMsg( string $text ): Message {
		return $this->context->msg( 'rawmessage' )->plaintextParams( $text );
	}

	/**
	 * @param \MediaWiki\Output\OutputPage $out
	 */
	private function deliver( $out ): void {
		$user = $this->context->getUser();
		$config = $this->context->getConfig();

		if ( Throttle::refusesReport( $user ) ) {
			$out->addHTML( Html::errorBox(
				$this->context->msg( 'wikioasissafety-submit-throttled' )->escaped() ) );
			return;
		}

		$suffix = WizardDefinition::suffixFor( $this->flowName );
		$roles = $config->get( 'WikiOasisSafety' . $suffix . 'FieldRoles' );

		$counted = $this->flow;
		$counted['categories'] = Categories::vocabulary(
			$config,
			$suffix,
			Wording::inContentLanguage()
		);

		$anonymous = Anonymity::of( $user, $this->flow, $this->answers );

		$status = ( new PortalClient() )->submit( [
			'type' => $this->flowName === 'report' ? 'report' : $this->flowName,
			'flow' => $this->flowName,
			'wiki' => WikiMap::getCurrentWikiId(),
			'anonymous' => $anonymous,
			'reporter' => $anonymous ? null : Accounts::identity( $user ),
			'answers' => $this->answers,
			'roles' => is_array( $roles ) ? $roles : [],
			'categories' => Categories::resolve( $counted, $this->answers ),
		] );

		if ( !$status->isGood() ) {
			$out->addHTML( Html::errorBox(
				$this->context->msg( 'wikioasissafety-submit-failed' )->escaped() ) );
			return;
		}

		$value = $status->getValue();

		if ( !empty( $value['queued'] ) ) {
			$out->addHTML( Html::warningBox(
				$this->context->msg( 'wikioasissafety-submit-queued' )->escaped() ) );
			return;
		}

		if ( $anonymous ) {
			$next = $this->context->msg( 'wikioasissafety-submit-sent-anonymous' )->escaped();
		} elseif ( Accounts::isNamed( $user ) ) {
			$next = $this->context->msg( 'wikioasissafety-submit-sent-follow' )->parse();
		} else {
			$next = $this->context->msg( 'wikioasissafety-submit-sent-temp' )->escaped();
		}

		$out->addHTML( Html::successBox(
			$this->context->msg( 'wikioasissafety-submit-sent', (string)$value['reference'] )->escaped()
			. ' ' . $next
		) );
	}

}
