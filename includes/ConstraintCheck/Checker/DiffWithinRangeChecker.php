<?php

namespace WikibaseQuality\ConstraintReport\ConstraintCheck\Checker;

use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\StatementListProvider;
use WikibaseQuality\ConstraintReport\Constraint;
use WikibaseQuality\ConstraintReport\ConstraintCheck\ConstraintChecker;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Context\Context;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Helper\ConstraintParameterException;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Helper\ConstraintParameterParser;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Result\CheckResult;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Helper\RangeCheckerHelper;
use WikibaseQuality\ConstraintReport\ConstraintParameterRenderer;
use WikibaseQuality\ConstraintReport\Role;
use Wikibase\DataModel\Statement\Statement;

/**
 * @package WikibaseQuality\ConstraintReport\ConstraintCheck\Checker
 * @author BP2014N1
 * @license GNU GPL v2+
 */
class DiffWithinRangeChecker implements ConstraintChecker {

	/**
	 * @var ConstraintParameterParser
	 */
	private $constraintParameterParser;

	/**
	 * @var RangeCheckerHelper
	 */
	private $rangeCheckerHelper;

	/**
	 * @var ConstraintParameterRenderer
	 */
	private $constraintParameterRenderer;

	/**
	 * @param ConstraintParameterParser $constraintParameterParser
	 * @param RangeCheckerHelper $rangeCheckerHelper
	 * @param ConstraintParameterRenderer $constraintParameterRenderer
	 */
	public function __construct(
		ConstraintParameterParser $constraintParameterParser,
		RangeCheckerHelper $rangeCheckerHelper,
		ConstraintParameterRenderer $constraintParameterRenderer
	) {
		$this->constraintParameterParser = $constraintParameterParser;
		$this->rangeCheckerHelper = $rangeCheckerHelper;
		$this->constraintParameterRenderer = $constraintParameterRenderer;
	}

	private function parseConstraintParameters( Constraint $constraint ) {
		list( $min, $max ) = $this->constraintParameterParser->parseRangeParameter(
			$constraint->getConstraintParameters(),
			$constraint->getConstraintTypeItemId(),
			'quantity'
		);
		$property = $this->constraintParameterParser->parsePropertyParameter(
			$constraint->getConstraintParameters(),
			$constraint->getConstraintTypeItemId()
		);

		if ( $min !== null ) {
			$parameters['minimum_quantity'] = [ $min ];
		}
		if ( $max !== null ) {
			$parameters['maximum_quantity'] = [ $max ];
		}
		$parameters['property'] = [ $property ];

		return [ $min, $max, $property, $parameters ];
	}

	/**
	 * Checks 'Diff within range' constraint.
	 *
	 * @param Context $context
	 * @param Constraint $constraint
	 *
	 * @return CheckResult
	 */
	public function checkConstraint( Context $context, Constraint $constraint ) {
		if ( $context->getSnakRank() === Statement::RANK_DEPRECATED ) {
			return new CheckResult( $context, $constraint, [], CheckResult::STATUS_DEPRECATED );
		}

		$parameters = [];
		$constraintParameters = $constraint->getConstraintParameters();

		$snak = $context->getSnak();

		if ( !$snak instanceof PropertyValueSnak ) {
			// nothing to check
			return new CheckResult( $context, $constraint, $parameters, CheckResult::STATUS_COMPLIANCE, '' );
		}

		$dataValue = $snak->getDataValue();

		list ( $min, $max, $property, $parameters ) = $this->parseConstraintParameters( $constraint );

		// checks only the first occurrence of the referenced property (this constraint implies a single value constraint on that property)
		/** @var Statement $otherStatement */
		foreach ( $context->getEntity()->getStatements() as $otherStatement ) {
			$otherMainSnak = $otherStatement->getMainSnak();

			if (
				!$property->equals( $otherStatement->getPropertyId() ) ||
				$otherStatement->getRank() === Statement::RANK_DEPRECATED ||
				!$otherMainSnak instanceof PropertyValueSnak
			) {
				continue;
			}

			if ( $otherMainSnak->getDataValue()->getType() === $dataValue->getType() ) {
				$diff = $this->rangeCheckerHelper->getDifference( $dataValue, $otherMainSnak->getDataValue() );

				if ( $this->rangeCheckerHelper->getComparison( $min, $diff ) > 0 ||
					$this->rangeCheckerHelper->getComparison( $diff, $max ) > 0
				) {
					// at least one of $min, $max is set at this point, otherwise there could be no violation
					$openness = $min !== null ? ( $max !== null ? '' : '-rightopen' ) : '-leftopen';
					$message = wfMessage( "wbqc-violation-message-diff-within-range$openness" );
					$message->rawParams(
						$this->constraintParameterRenderer->formatEntityId( $context->getSnak()->getPropertyId(), Role::PREDICATE ),
						$this->constraintParameterRenderer->formatDataValue( $snak->getDataValue(), Role::OBJECT ),
						$this->constraintParameterRenderer->formatEntityId( $otherStatement->getPropertyId(), Role::PREDICATE ),
						$this->constraintParameterRenderer->formatDataValue( $otherMainSnak->getDataValue(), Role::OBJECT )
					);
					if ( $min !== null ) {
						$message->rawParams( $this->constraintParameterRenderer->formatDataValue( $min, Role::OBJECT ) );
					}
					if ( $max !== null ) {
						$message->rawParams( $this->constraintParameterRenderer->formatDataValue( $max, Role::OBJECT ) );
					}
					$message = $message->escaped();
					$status = CheckResult::STATUS_VIOLATION;
				} else {
					$message = '';
					$status = CheckResult::STATUS_COMPLIANCE;
				}
			} else {
				$message = wfMessage( "wbqc-violation-message-diff-within-range-must-have-equal-types" )->escaped();
				$status = CheckResult::STATUS_VIOLATION;
			}

			return new CheckResult( $context, $constraint, $parameters, $status, $message );
		}

		$message = wfMessage( "wbqc-violation-message-diff-within-range-property-must-exist" )->escaped();
		$status = CheckResult::STATUS_VIOLATION;
		return new CheckResult( $context, $constraint, $parameters, $status, $message );
	}

	public function checkConstraintParameters( Constraint $constraint ) {
		$constraintParameters = $constraint->getConstraintParameters();
		$exceptions = [];
		try {
			$this->constraintParameterParser->parseRangeParameter(
				$constraintParameters,
				$constraint->getConstraintTypeItemId(),
				'quantity'
			);
		} catch ( ConstraintParameterException $e ) {
			$exceptions[] = $e;
		}
		try {
			$this->constraintParameterParser->parsePropertyParameter( $constraintParameters, $constraint->getConstraintTypeItemId() );
		} catch ( ConstraintParameterException $e ) {
			$exceptions[] = $e;
		}
		return $exceptions;
	}

}
