<?php

namespace WikibaseQuality\ConstraintReport\ConstraintCheck\Checker;

use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Statement\StatementListProvider;
use WikibaseQuality\ConstraintReport\Constraint;
use WikibaseQuality\ConstraintReport\ConstraintCheck\ConstraintChecker;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Context\Context;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Helper\ConnectionCheckerHelper;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Helper\ConstraintParameterException;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Helper\ConstraintParameterParser;
use WikibaseQuality\ConstraintReport\ConstraintCheck\ItemIdSnakValue;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Result\CheckResult;
use WikibaseQuality\ConstraintReport\ConstraintParameterRenderer;
use WikibaseQuality\ConstraintReport\Role;
use Wikibase\DataModel\Statement\Statement;

/**
 * @package WikibaseQuality\ConstraintReport\ConstraintCheck\Checker
 * @author BP2014N1
 * @license GNU GPL v2+
 */
class ConflictsWithChecker implements ConstraintChecker {

	/**
	 * @var EntityLookup
	 */
	private $entityLookup;

	/**
	 * @var ConstraintParameterParser
	 */
	private $constraintParameterParser;

	/**
	 * @var ConnectionCheckerHelper
	 */
	private $connectionCheckerHelper;

	/**
	 * @var ConstraintParameterRenderer
	 */
	private $constraintParameterRenderer;

	/**
	 * @param EntityLookup $lookup
	 * @param ConstraintParameterParser $constraintParameterParser
	 * @param ConnectionCheckerHelper $connectionCheckerHelper
	 * @param ConstraintParameterRenderer $constraintParameterRenderer
	 */
	public function __construct(
		EntityLookup $lookup,
		ConstraintParameterParser $constraintParameterParser,
		ConnectionCheckerHelper $connectionCheckerHelper,
		ConstraintParameterRenderer $constraintParameterRenderer
	) {
		$this->entityLookup = $lookup;
		$this->constraintParameterParser = $constraintParameterParser;
		$this->connectionCheckerHelper = $connectionCheckerHelper;
		$this->constraintParameterRenderer = $constraintParameterRenderer;
	}

	/**
	 * Checks 'Conflicts with' constraint.
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

		$propertyId = $this->constraintParameterParser->parsePropertyParameter( $constraintParameters, $constraint->getConstraintTypeItemId() );
		$parameters['property'] = [ $propertyId ];

		$items = $this->constraintParameterParser->parseItemsParameter( $constraintParameters, $constraint->getConstraintTypeItemId(), false );
		$parameters['items'] = $items;

		/*
		 * 'Conflicts with' can be defined with
		 *   a) a property only
		 *   b) a property and a number of items (each combination of property and item forming an individual claim)
		 */
		if ( $items === [] ) {
			$offendingStatement = $this->connectionCheckerHelper->findStatementWithProperty(
				$context->getEntity()->getStatements(),
				$propertyId
			);
			if ( $offendingStatement !== null ) {
				$message = wfMessage( "wbqc-violation-message-conflicts-with-property" )
						 ->rawParams(
							 $this->constraintParameterRenderer->formatEntityId( $context->getSnak()->getPropertyId(), Role::CONSTRAINT_PROPERTY ),
							 $this->constraintParameterRenderer->formatEntityId( $propertyId, Role::PREDICATE )
						 )
						 ->escaped();
				$status = CheckResult::STATUS_VIOLATION;
			} else {
				$message = '';
				$status = CheckResult::STATUS_COMPLIANCE;
			}
		} else {
			$offendingStatement = $this->connectionCheckerHelper->findStatementWithPropertyAndItemIdSnakValues(
				$context->getEntity()->getStatements(),
				$propertyId,
				$items
			);
			if ( $offendingStatement !== null ) {
				$offendingValue = ItemIdSnakValue::fromSnak( $offendingStatement->getMainSnak() );
				$message = wfMessage( "wbqc-violation-message-conflicts-with-claim" )
						 ->rawParams(
							 $this->constraintParameterRenderer->formatEntityId( $context->getSnak()->getPropertyId(), Role::CONSTRAINT_PROPERTY ),
							 $this->constraintParameterRenderer->formatEntityId( $propertyId, Role::PREDICATE ),
							 $this->constraintParameterRenderer->formatItemIdSnakValue( $offendingValue, Role::OBJECT )
						 )
						 ->escaped();
				$status = CheckResult::STATUS_VIOLATION;
			} else {
				$message = '';
				$status = CheckResult::STATUS_COMPLIANCE;
			}
		}

		return new CheckResult( $context, $constraint, $parameters, $status, $message );
	}

	public function checkConstraintParameters( Constraint $constraint ) {
		$constraintParameters = $constraint->getConstraintParameters();
		$exceptions = [];
		try {
			$this->constraintParameterParser->parsePropertyParameter( $constraintParameters, $constraint->getConstraintTypeItemId() );
		} catch ( ConstraintParameterException $e ) {
			$exceptions[] = $e;
		}
		try {
			$this->constraintParameterParser->parseItemsParameter( $constraintParameters, $constraint->getConstraintTypeItemId(), false );
		} catch ( ConstraintParameterException $e ) {
			$exceptions[] = $e;
		}
		return $exceptions;
	}

}
