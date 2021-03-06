<?php
namespace WikibaseQuality\ConstraintReport;

use Config;
use DataValues\DataValue;
use InvalidArgumentException;
use ValueFormatters\ValueFormatter;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\EntityId\EntityIdFormatter;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Context\Context;
use WikibaseQuality\ConstraintReport\ConstraintCheck\ItemIdSnakValue;

/**
 * Used to format the constraint values for output.
 *
 * @author BP2014N1
 * @license GNU GPL v2+
 */
class ConstraintParameterRenderer {

	/**
	 * Maximum number of displayed values for parameters with multiple ones.
	 *
	 * @var int
	 */
	const MAX_PARAMETER_ARRAY_LENGTH = 10;

	/**
	 * @var EntityIdFormatter
	 */
	private $entityIdLabelFormatter;

	/**
	 * @var ValueFormatter
	 */
	private $dataValueFormatter;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @param EntityIdFormatter $entityIdFormatter should return HTML
	 * @param ValueFormatter $dataValueFormatter should return HTML
	 * @param Config $config used to look up item IDs of constraint scopes (Context::TYPE_* constants)
	 */
	public function __construct(
		EntityIdFormatter $entityIdFormatter,
		ValueFormatter $dataValueFormatter,
		Config $config
	) {
		$this->entityIdLabelFormatter = $entityIdFormatter;
		$this->dataValueFormatter = $dataValueFormatter;
		$this->config = $config;
	}

	/**
	 * Formats parameter values of constraints.
	 *
	 * @param string|ItemId|PropertyId|DataValue $value
	 *
	 * @return string HTML
	 */
	public function formatValue( $value ) {
		if ( is_string( $value ) ) {
			// Cases like 'Format' 'pattern' or 'minimum'/'maximum' values, which we have stored as
			// strings
			return htmlspecialchars( $value );
		} elseif ( $value instanceof EntityId ) {
			// Cases like 'Conflicts with' 'property', to which we can link
			return $this->formatEntityId( $value );
		} elseif ( $value instanceof ItemIdSnakValue ) {
			// Cases like EntityId but can also be somevalue or novalue
			return $this->formatItemIdSnakValue( $value );
		} else {
			// Cases where we format a DataValue
			return $this->formatDataValue( $value );
		}
	}

	/**
	 * Formats constraint parameters.
	 *
	 * @param (string|ItemId|PropertyId|DataValue)[]|null $parameters
	 *
	 * @return string HTML
	 */
	public function formatParameters( $parameters ) {
		if ( $parameters === null || count( $parameters ) == 0 ) {
			return null;
		}

		$valueFormatter = function ( $value ) {
			return $this->formatValue( $value );
		};

		$formattedParameters = [];
		foreach ( $parameters as $parameterName => $parameterValue ) {
			$formattedParameterValues = implode( ', ',
				$this->limitArrayLength( array_map( $valueFormatter, $parameterValue ) ) );
			$formattedParameters[] = sprintf( '%s: %s', $parameterName, $formattedParameterValues );
		}

		return implode( '; ', $formattedParameters );
	}

	/**
	 * Cuts an array after n values and appends dots if needed.
	 *
	 * @param array $array
	 *
	 * @return array
	 */
	private function limitArrayLength( array $array ) {
		if ( count( $array ) > self::MAX_PARAMETER_ARRAY_LENGTH ) {
			$array = array_slice( $array, 0, self::MAX_PARAMETER_ARRAY_LENGTH );
			array_push( $array, '...' );
		}

		return $array;
	}

	/**
	 * If $role is non-null, wrap $value in a span with the CSS classes wdqc-role and wdqc-role-$role.
	 *
	 * @param string|null $role one of the Role constants or null
	 * @param string $value HTML
	 * @return string HTML
	 */
	public static function formatByRole( $role, $value ) {
		if ( $role === null ) {
			return $value;
		}

		return '<span class="wbqc-role wbqc-role-' . htmlspecialchars( $role ) . '">'
			. $value
			. '</span>';
	}

	/**
	 * @param DataValue $value
	 * @param string|null $role one of the Role constants or null
	 * @return string HTML
	 */
	public function formatDataValue( DataValue $value, $role = null ) {
		return self::formatByRole( $role,
			$this->dataValueFormatter->format( $value ) );
	}

	/**
	 * @param EntityId $entityId
	 * @param string|null $role one of the Role constants or null
	 * @return string HTML
	 */
	public function formatEntityId( EntityId $entityId, $role = null ) {
		return self::formatByRole( $role,
			$this->entityIdLabelFormatter->formatEntityId( $entityId ) );
	}

	/**
	 * Format a property ID parameter, potentially unparsed (string).
	 *
	 * If you know that your property ID is already parsed, use {@see formatEntityId}.
	 *
	 * @param PropertyId|string $propertyId
	 * @param string|null $role one of the Role constants or null
	 * @return string HTML
	 */
	public function formatPropertyId( $propertyId, $role = null ) {
		if ( $propertyId instanceof PropertyId ) {
			return $this->formatEntityId( $propertyId, $role );
		} elseif ( is_string( $propertyId ) ) {
			try {
				return $this->formatEntityId( new PropertyId( $propertyId ), $role );
			} catch ( InvalidArgumentException $e ) {
				return self::formatByRole( $role,
					htmlspecialchars( $propertyId ) );
			}
		} else {
			throw new InvalidArgumentException( '$propertyId must be either PropertyId or string' );
		}
	}

	/**
	 * Format an item ID parameter, potentially unparsed (string).
	 *
	 * If you know that your item ID is already parsed, use {@see formatEntityId}.
	 *
	 * @param ItemId|string $itemId
	 * @param string|null $role one of the Role constants or null
	 * @return string HTML
	 */
	public function formatItemId( $itemId, $role = null ) {
		if ( $itemId instanceof ItemId ) {
			return $this->formatEntityId( $itemId, $role );
		} elseif ( is_string( $itemId ) ) {
			try {
				return $this->formatEntityId( new ItemId( $itemId ), $role );
			} catch ( InvalidArgumentException $e ) {
				return self::formatByRole( $role,
					htmlspecialchars( $itemId ) );
			}
		} else {
			throw new InvalidArgumentException( '$itemId must be either ItemId or string' );
		}
	}

	/**
	 * Format an {@link ItemIdSnakValue} (known value, unknown value, or no value).
	 *
	 * @param ItemIdSnakValue $value
	 * @param string|null $role one of the Role constants or null
	 * @return string HTML
	 */
	public function formatItemIdSnakValue( ItemIdSnakValue $value, $role = null ) {
		switch ( true ) {
			case $value->isValue():
				return $this->formatEntityId( $value->getItemId(), $role );
			case $value->isSomeValue():
				return self::formatByRole( $role,
					'<span class="wikibase-snakview-variation-somevaluesnak">'
						. wfMessage( 'wikibase-snakview-snaktypeselector-somevalue' )->escaped()
						. '</span>' );
			case $value->isNoValue():
				return self::formatByRole( $role,
					'<span class="wikibase-snakview-variation-novaluesnak">'
						. wfMessage( 'wikibase-snakview-snaktypeselector-novalue' )->escaped()
						. '</span>' );
		}
	}

	/**
	 * Format a constraint scope (check on main snak, on qualifiers, or on references).
	 *
	 * @param string $scope one of the Context::TYPE_* constants
	 * @param string|null $role one of the Role constants or null
	 * @return string HTML
	 */
	public function formatConstraintScope( $scope, $role = null ) {
		switch ( $scope ) {
			case Context::TYPE_STATEMENT:
				$itemId = $this->config->get(
					'WBQualityConstraintsConstraintCheckedOnMainValueId'
				);
				break;
			case Context::TYPE_QUALIFIER:
				$itemId = $this->config->get(
					'WBQualityConstraintsConstraintCheckedOnQualifiersId'
				);
				break;
			case Context::TYPE_REFERENCE:
				$itemId = $this->config->get(
					'WBQualityConstraintsConstraintCheckedOnReferencesId'
				);
				break;
			default:
				// callers should never let this happen, but if it does happen,
				// showing “unknown value” seems reasonable
				// @codeCoverageIgnoreStart
				return $this->formatItemIdSnakValue( ItemIdSnakValue::someValue(), $role );
				// @codeCoverageIgnoreEnd
		}
		return $this->formatItemId( $itemId, $role );
	}

	/**
	 * Format a list of (potentially unparsed) property IDs.
	 *
	 * The returned array begins with an HTML list of the formatted property IDs
	 * and then contains all the individual formatted property IDs.
	 *
	 * @param PropertyId[]|string[] $propertyIds
	 * @param string|null $role one of the Role constants or null
	 * @return string[] HTML
	 */
	public function formatPropertyIdList( array $propertyIds, $role = null ) {
		if ( empty( $propertyIds ) ) {
			return [ '<ul></ul>' ];
		}
		$propertyIds = $this->limitArrayLength( $propertyIds );
		$formattedPropertyIds = array_map( [ $this, "formatPropertyId" ], $propertyIds, array_fill( 0, count( $propertyIds ), $role ) );
		array_unshift(
			$formattedPropertyIds,
			'<ul><li>' . implode( '</li><li>', $formattedPropertyIds ) . '</li></ul>'
		);
		return $formattedPropertyIds;
	}

	/**
	 * Format a list of (potentially unparsed) item IDs.
	 *
	 * The returned array begins with an HTML list of the formatted item IDs
	 * and then contains all the individual formatted item IDs.
	 *
	 * @param ItemId[]|string[] $itemIds
	 * @param string|null $role one of the Role constants or null
	 * @return string[] HTML
	 */
	public function formatItemIdList( array $itemIds, $role = null ) {
		if ( empty( $itemIds ) ) {
			return [ '<ul></ul>' ];
		}
		$itemIds = $this->limitArrayLength( $itemIds );
		$formattedItemIds = array_map( [ $this, "formatItemId" ], $itemIds, array_fill( 0, count( $itemIds ), $role ) );
		array_unshift(
			$formattedItemIds,
			'<ul><li>' . implode( '</li><li>', $formattedItemIds ) . '</li></ul>'
		);
		return $formattedItemIds;
	}

	/**
	 * Format a list of entity IDs.
	 *
	 * The returned array begins with an HTML list of the formatted entity IDs
	 * and then contains all the individual formatted entity IDs.
	 *
	 * @param (EntityId|null)[] $entityIds (null elements are skipped)
	 * @param string|null $role one of the Role constants or null
	 * @return string[] HTML
	 */
	public function formatEntityIdList( array $entityIds, $role = null ) {
		if ( empty( $entityIds ) ) {
			return [ '<ul></ul>' ];
		}
		$formattedEntityIds = [];
		foreach ( $entityIds as $entityId ) {
			if ( count( $formattedEntityIds ) >= self::MAX_PARAMETER_ARRAY_LENGTH ) {
				$formattedEntityIds[] = '...';
				break;
			}
			if ( $entityId !== null ) {
				$formattedEntityIds[] = $this->formatEntityId( $entityId, $role );
			}
		}
		array_unshift(
			$formattedEntityIds,
			'<ul><li>' . implode( '</li><li>', $formattedEntityIds ) . '</li></ul>'
		);
		return $formattedEntityIds;
	}

	/**
	 * Format a list of {@link ItemIdSnakValue}s (containing known values, unknown values, and/or no values).
	 *
	 * The returned array begins with an HTML list of the formatted values
	 * and then contains all the individual formatted values.
	 *
	 * @param ItemIdSnakValue[] $values
	 * @param string|null $role one of the Role constants or null
	 * @return string[] HTML
	 */
	public function formatItemIdSnakValueList( array $values, $role = null ) {
		if ( empty( $values ) ) {
			return [ '<ul></ul>' ];
		}
		$values = $this->limitArrayLength( $values );
		$formattedValues = array_map(
			function( $value ) use ( $role ) {
				if ( $value === '...' ) {
					return '...';
				} else {
					return $this->formatItemIdSnakValue( $value, $role );
				}
			},
			$values
		);
		array_unshift(
			$formattedValues,
			'<ul><li>' . implode( '</li><li>', $formattedValues ) . '</li></ul>'
		);
		return $formattedValues;
	}

	/**
	 * Format a list of constraint scopes (check on main snak, qualifiers, and/or references).
	 *
	 * The returned array begins with an HTML list of the formatted scopes
	 * and then contains all the individual formatted scopes.
	 *
	 * @param string[] $scopes
	 * @param string|null $role one of the Role constants or null
	 * @return string[] HTML
	 */
	public function formatConstraintScopeList( array $scopes, $role = null ) {
		if ( empty( $scopes ) ) {
			return [ '<ul></ul>' ];
		}
		$scopes = $this->limitArrayLength( $scopes );
		$formattedScopes = array_map(
			function( $scope ) use ( $role ) {
				if ( $scope === '...' ) {
					return '...';
				} else {
					return $this->formatConstraintScope( $scope, $role );
				}
			},
			$scopes
		);
		array_unshift(
			$formattedScopes,
			'<ul><li>' . implode( '</li><li>', $formattedScopes ) . '</li></ul>'
		);
		return $formattedScopes;
	}

}
