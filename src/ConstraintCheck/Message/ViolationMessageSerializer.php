<?php

namespace WikibaseQuality\ConstraintReport\ConstraintCheck\Message;

use InvalidArgumentException;
use LogicException;
use Serializers\Serializer;
use Wikibase\DataModel\Entity\EntityId;
use WikibaseQuality\ConstraintReport\ConstraintCheck\ItemIdSnakValue;
use Wikimedia\Assert\Assert;

/**
 * A serializer for {@link ViolationMessage}s.
 *
 * @license GNU GPL v2+
 */
class ViolationMessageSerializer implements Serializer {

	private function abbreviateViolationMessageKey( $fullMessageKey ) {
		return substr( $fullMessageKey, strlen( ViolationMessage::MESSAGE_KEY_PREFIX ) );
	}

	/**
	 * @param ViolationMessage $object
	 * @return array
	 */
	public function serialize( $object ) {
		/** @var ViolationMessage $object */
		Assert::parameterType( ViolationMessage::class, $object, '$object' );

		$arguments = $object->getArguments();
		$serializedArguments = [];
		foreach ( $arguments as $argument ) {
			$serializedArguments[] = $this->serializeArgument( $argument );
		}

		return [
			'k' => $this->abbreviateViolationMessageKey( $object->getMessageKey() ),
			'a' => $serializedArguments,
		];
	}

	/**
	 * @param array $argument element of ViolationMessage::getArguments()
	 * @return array [ 't' => ViolationMessage::TYPE_*, 'v' => serialized value,
	 * 'r' => $role, (optional) 'a' => $alternativeMessageKey ]
	 */
	private function serializeArgument( array $argument ) {
		$methods = [
			ViolationMessage::TYPE_ENTITY_ID => 'serializeEntityId',
			ViolationMessage::TYPE_ENTITY_ID_LIST => 'serializeEntityIdList',
			ViolationMessage::TYPE_ITEM_ID_SNAK_VALUE => 'serializeItemIdSnakValue',
			ViolationMessage::TYPE_ITEM_ID_SNAK_VALUE_LIST => 'serializeItemIdSnakValueList',
		];

		$type = $argument['type'];
		$value = $argument['value'];
		$role = $argument['role'];

		if ( array_key_exists( $type, $methods ) ) {
			$method = $methods[$type];
			$serializedValue = $this->$method( $value );
		} else {
			throw new InvalidArgumentException(
				'Unknown ViolationMessage argument type ' . $type . '!'
			);
		}

		$serialized = [
			't' => $type,
			'v' => $serializedValue,
			'r' => $role,
		];

		if ( array_key_exists( 'alternativeMessageKey', $argument ) ) {
			$serialized['a'] = $this->abbreviateViolationMessageKey(
				$argument['alternativeMessageKey']
			);
		}

		return $serialized;
	}

	/**
	 * @param EntityId $entityId
	 * @return string entity ID serialization
	 */
	private function serializeEntityId( EntityId $entityId ) {
		return $entityId->getSerialization();
	}

	/**
	 * @param EntityId[] $entityIdList
	 * @return string[] entity ID serializations
	 */
	private function serializeEntityIdList( array $entityIdList ) {
		return array_map( [ $this, 'serializeEntityId' ], $entityIdList );
	}

	/**
	 * @param ItemIdSnakValue $value
	 * @return string entity ID serialization, '::somevalue', or '::novalue'
	 * (according to EntityId::PATTERN, entity ID serializations can never begin with two colons)
	 */
	private function serializeItemIdSnakValue( ItemIdSnakValue $value ) {
		switch ( true ) {
			case $value->isValue():
				return $this->serializeEntityId( $value->getItemId() );
			case $value->isSomeValue():
				return '::somevalue';
			case $value->isNoValue():
				return '::novalue';
			default:
				// @codeCoverageIgnoreStart
				throw new LogicException(
					'ItemIdSnakValue should guarantee that one of is{,Some,No}Value() is true'
				);
				// @codeCoverageIgnoreEnd
		}
	}

	/**
	 * @param ItemIdSnakValue[] $valueList
	 * @return string[] array of entity ID serializations, '::somevalue's or '::novalue's
	 */
	private function serializeItemIdSnakValueList( array $valueList ) {
		return array_map( [ $this, 'serializeItemIdSnakValue' ], $valueList );
	}

}