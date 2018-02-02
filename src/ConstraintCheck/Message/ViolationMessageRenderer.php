<?php

namespace WikibaseQuality\ConstraintReport\ConstraintCheck\Message;

use Config;
use DataValues\DataValue;
use InvalidArgumentException;
use LogicException;
use Message;
use ValueFormatters\ValueFormatter;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Services\EntityId\EntityIdFormatter;
use WikibaseQuality\ConstraintReport\ConstraintCheck\ItemIdSnakValue;

/**
 * Render a {@link ViolationMessage} into a localized string.
 *
 * @license GNU GPL v2+
 */
class ViolationMessageRenderer {

	/**
	 * @var EntityIdFormatter
	 */
	private $entityIdFormatter;

	/**
	 * @var ValueFormatter
	 */
	private $dataValueFormatter;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var int
	 */
	private $maxListLength;

	/**
	 * @param EntityIdFormatter $entityIdFormatter
	 * @param ValueFormatter $dataValueFormatter
	 * @param Config $config
	 * @param int $maxListLength The maximum number of elements to be rendered in a list parameter.
	 * Longer lists are truncated to this length and then rendered with an ellipsis in the HMTL list.
	 */
	public function __construct(
		EntityIdFormatter $entityIdFormatter,
		ValueFormatter $dataValueFormatter,
		Config $config,
		$maxListLength = 10
	) {
		$this->entityIdFormatter = $entityIdFormatter;
		$this->dataValueFormatter = $dataValueFormatter;
		$this->config = $config;
		$this->maxListLength = $maxListLength;
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

		$messageKey = $violationMessage->getMessageKey();
		$paramsLists = [ [] ];
		foreach ( $violationMessage->getArguments() as $argument ) {
			$params = $this->renderArgument( $argument );
			$paramsLists[] = $params;
		}
		$allParams = call_user_func_array( 'array_merge', $paramsLists );
		return ( new Message( $messageKey ) )
			->params( $allParams )
			->escaped();
	}

	private function addRole( $value, $role ) {
		if ( $role === null ) {
			return $value;
		}

		return '<span class="wbqc-role wbqc-role-' . htmlspecialchars( $role ) . '">' .
			$value .
			'</span>';
	}

	/**
	 * @param array $argument
	 * @return array params (for Message::params)
	 */
	private function renderArgument( array $argument ) {
		$methods = [
			ViolationMessage::TYPE_ENTITY_ID => 'renderEntityId',
			ViolationMessage::TYPE_ENTITY_ID_LIST => 'renderEntityIdList',
			ViolationMessage::TYPE_ITEM_ID_SNAK_VALUE => 'renderItemIdSnakValue',
			ViolationMessage::TYPE_ITEM_ID_SNAK_VALUE_LIST => 'renderItemIdSnakValueList',
			ViolationMessage::TYPE_DATA_VALUE => 'renderDataValue',
			ViolationMessage::TYPE_DATA_VALUE_TYPE => 'renderDataValueType',
			ViolationMessage::TYPE_INLINE_CODE => 'renderInlineCode',
		];

		$type = $argument['type'];
		$value = $argument['value'];
		$role = $argument['role'];

		if ( array_key_exists( $type, $methods ) ) {
			$method = $methods[$type];
			$params = $this->$method( $value, $role );
		} else {
			throw new InvalidArgumentException(
				'Unknown ViolationMessage argument type ' . $type . '!'
			);
		}

		if ( !array_key_exists( 0, $params ) ) {
			$params = [ $params ];
		}
		return $params;
	}

	private function renderList( array $list, $role, callable $render ) {
		if ( $list === [] ) {
			return [
				Message::numParam( 0 ),
				Message::rawParam( '<ul></ul>' ),
			];
		}

		if ( count( $list ) > $this->maxListLength ) {
			$list = array_slice( $list, 0, $this->maxListLength );
			$truncated = true;
		}

		$renderedParams = array_map(
			$render,
			$list,
			array_fill( 0, count( $list ), $role )
		);
		$renderedElements = array_map(
			function ( $param ) {
				return $param['raw'];
			},
			$renderedParams
		);
		if ( isset( $truncated ) ) {
			$renderedElements[] = wfMessage( 'ellipsis' )->escaped();
		}

		return array_merge(
			[
				Message::numParam( count( $list ) ),
				Message::rawParam(
					'<ul><li>' .
					implode( '</li><li>', $renderedElements ) .
					'</li></ul>'
				),
			],
			$renderedParams
		);
	}

	private function renderEntityId( EntityId $entityId, $role ) {
		return Message::rawParam( $this->addRole(
			$this->entityIdFormatter->formatEntityId( $entityId ),
			$role
		) );
	}

	private function renderEntityIdList( array $entityIdList, $role ) {
		return $this->renderList( $entityIdList, $role, [ $this, 'renderEntityId' ] );
	}

	private function renderItemIdSnakValue( ItemIdSnakValue $value, $role ) {
		switch ( true ) {
			case $value->isValue():
				return $this->renderEntityId( $value->getItemId(), $role );
			case $value->isSomeValue():
				return Message::rawParam( $this->addRole(
					'<span class="wikibase-snakview-variation-somevaluesnak">' .
						wfMessage( 'wikibase-snakview-snaktypeselector-somevalue' )->escaped() .
						'</span>',
					$role
				) );
			case $value->isNoValue():
				return Message::rawParam( $this->addRole(
					'<span class="wikibase-snakview-variation-novaluesnak">' .
						wfMessage( 'wikibase-snakview-snaktypeselector-novalue' )->escaped() .
						'</span>',
					$role
				) );
			default:
				// @codeCoverageIgnoreStart
				throw new LogicException(
					'ItemIdSnakValue should guarantee that one of is{,Some,No}Value() is true'
				);
				// @codeCoverageIgnoreEnd
		}
	}

	private function renderItemIdSnakValueList( array $valueList, $role ) {
		return $this->renderList( $valueList, $role, [ $this, 'renderItemIdSnakValue' ] );
	}

	private function renderDataValue( DataValue $dataValue, $role ) {
		return Message::rawParam( $this->addRole(
			$this->dataValueFormatter->format( $dataValue ),
			$role
		) );
	}

	private function renderDataValueType( $dataValueType, $role ) {
		$messageKeys = [
			'string' => 'datatypes-type-string',
			'monolingualtext' => 'datatypes-monolingualtext',
			'wikibase-entityid' => 'wbqc-dataValueType-wikibase-entityid',
		];

		if ( array_key_exists( $dataValueType, $messageKeys ) ) {
			return Message::rawParam( $this->addRole(
				wfMessage( $messageKeys[$dataValueType] )->escaped(),
				$role
			) );
		} else {
			// @codeCoverageIgnoreStart
			throw new LogicException(
				'Unknown data value type ' . $dataValueType
			);
			// @codeCoverageIgnoreEnd
		}
	}

	private function renderInlineCode( $code, $role ) {
		return Message::rawParam( $this->addRole(
			'<code>' . htmlspecialchars( $code ) . '</code>',
			$role
		) );
	}

}
