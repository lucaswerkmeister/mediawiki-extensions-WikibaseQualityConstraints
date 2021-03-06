<?php

namespace WikibaseQuality\ConstraintReport\ConstraintCheck\Message;

use Config;
use DataValues\MultilingualTextValue;
use Message;
use ValueFormatters\ValueFormatter;
use Wikibase\DataModel\Services\EntityId\EntityIdFormatter;

/**
 * Render a {@link ViolationMessage},
 * which may have a multilingual text as the last argument,
 * into a localized string.
 * (For all other messages, this falls back to the base {@link ViolationMessageRenderer}.)
 *
 * Note that this is only supported for specific message keys,
 * where an alternative message key is known
 * which is used if the text is not available in the user’s language.
 * Currently, the only such message key is 'wbqc-violation-message-format-clarification'
 * (falling back to 'wbqc-violation-message-format').
 *
 * @license GNU GPL v2+
 */
class MultilingualTextViolationMessageRenderer extends ViolationMessageRenderer {

	/**
	 * @var string[]
	 */
	private $alternativeMessageKeys;

	public function __construct(
		EntityIdFormatter $entityIdFormatter,
		ValueFormatter $dataValueFormatter,
		Config $config,
		$maxListLength = 10
	) {
		parent::__construct( $entityIdFormatter, $dataValueFormatter, $config, $maxListLength );

		$this->alternativeMessageKeys = [
			'wbqc-violation-message-format-clarification' => 'wbqc-violation-message-format',
		];
	}

	/**
	 * @param ViolationMessage|string $violationMessage
	 * (temporarily, pre-rendered strings are allowed and returned without changes)
	 * @return string
	 */
	public function render( $violationMessage ) {
		if ( is_string( $violationMessage ) ) {
			// TODO remove this once all checkers produce ViolationMessage objects
			return $violationMessage;
		}

		if ( !array_key_exists( $violationMessage->getMessageKey(), $this->alternativeMessageKeys ) ) {
			return parent::render( $violationMessage );
		}

		$arguments = $violationMessage->getArguments();
		$multilingualTextArgument = array_pop( $arguments );
		$multilingualTextParams = $this->renderMultilingualText(
			$multilingualTextArgument['value'],
			$multilingualTextArgument['role']
		);

		$paramsLists = [ [] ];
		foreach ( $arguments as $argument ) {
			$paramsLists[] = $this->renderArgument( $argument );
		}
		$regularParams = call_user_func_array( 'array_merge', $paramsLists );

		if ( $multilingualTextParams === null ) {
			return ( new Message( $this->alternativeMessageKeys[$violationMessage->getMessageKey()] ) )
				->params( $regularParams )
				->escaped();
		} else {
			return ( new Message( $violationMessage->getMessageKey() ) )
				->params( $regularParams )
				->params( $multilingualTextParams )
				->escaped();
		}
	}

	/**
	 * @param MultilingualTextValue $text
	 * @param string|null $role one of the Role::* constants
	 * @return array[]|null list of parameters as accepted by Message::params(),
	 * or null if the text is not available in the user’s language
	 */
	protected function renderMultilingualText( MultilingualTextValue $text, $role ) {
		global $wgLang;
		$languageCodes = $wgLang->getFallbackLanguages();
		array_unshift( $languageCodes, $wgLang->getCode() );

		$texts = $text->getTexts();
		foreach ( $languageCodes as $languageCode ) {
			if ( array_key_exists( $languageCode, $texts ) ) {
				return [ Message::rawParam( $this->addRole(
					htmlspecialchars( $texts[$languageCode]->getText() ),
					$role
				) ) ];
			}
		}

		return null;
	}

}
