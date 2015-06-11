<?php

namespace WikibaseQuality\ConstraintReport\ConstraintCheck\Checker;

use Wikibase\Lib\Store\EntityLookup;
use WikibaseQuality\ConstraintReport\Constraint;
use WikibaseQuality\ConstraintReport\ConstraintCheck\ConstraintChecker;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Helper\ConnectionCheckerHelper;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Helper\ConstraintReportHelper;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Result\CheckResult;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Entity\Entity;


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
	 * @var ConstraintReportHelper
	 */
	private $constraintReportHelper;

	/**
	 * @var ConnectionCheckerHelper
	 */
	private $connectionCheckerHelper;

	/**
	 * @param EntityLookup $lookup
	 * @param ConstraintReportHelper $constraintReportHelper
	 * ConnectionCheckerHelper $connectionCheckerHelper
	 */
	public function __construct( EntityLookup $lookup, ConstraintReportHelper $constraintReportHelper, ConnectionCheckerHelper $connectionCheckerHelper ) {
		$this->entityLookup = $lookup;
		$this->constraintReportHelper = $constraintReportHelper;
		$this->connectionCheckerHelper = $connectionCheckerHelper;
	}

	/**
	 * Checks 'Conflicts with' constraint.
	 *
	 * @param Statement $statement
	 * @param Constraint $constraint
	 * @param Entity $entity
	 *
	 * @return CheckResult
	 */
	public function checkConstraint( Statement $statement,Constraint $constraint, Entity $entity = null ) {
		$constraintName = 'Conflicts with';
		$parameters = array ();
		$constraintParameters = $constraint->getConstraintParameters();

		/*
		 * error handling:
		 *   parameter $property must not be null
		 */
		if ( !array_key_exists( 'property', $constraintParameters ) ) {
			$message = wfMessage( "wbqc-violation-message-parameter-needed" )->params( $constraintName, 'property' )->escaped();
			return new CheckResult( $statement, $constraint->getConstraintTypeQid(), $parameters, CheckResult::STATUS_VIOLATION, $message );
		}

		$parameters['property'] = $this->constraintReportHelper->parseSingleParameter( $constraintParameters['property'] );
		if ( array_key_exists( 'item', $constraintParameters ) ) {
			$parameters['item'] = $this->constraintReportHelper->parseParameterArray( explode( ',', $constraintParameters['item'] ) );
		};

		if ( array_key_exists( 'constraint_status', $constraintParameters ) ) {
			$parameters['constraint_status'] = $this->constraintReportHelper->parseSingleParameter( $constraintParameters['constraint_status'], true );
		}

		/*
		 * 'Conflicts with' can be defined with
		 *   a) a property only
		 *   b) a property and a number of items (each combination of property and item forming an individual claim)
		 */
		if ( !array_key_exists( 'item', $constraintParameters ) ) {
			if ( $this->connectionCheckerHelper->hasProperty( $entity->getStatements(), $constraintParameters['property'] ) ) {
				$message = wfMessage( "wbqc-violation-message-conflicts-with-property" )->params( $constraintName )->escaped();
				$status = CheckResult::STATUS_VIOLATION;
			} else {
				$message = '';
				$status = CheckResult::STATUS_COMPLIANCE;
			}
		} else {
			if ( $this->connectionCheckerHelper->hasClaim( $entity->getStatements(), $constraintParameters['property'], explode( ',', $constraintParameters['item'] ) ) ) {
				$message = wfMessage( "wbqc-violation-message-conflicts-with-claim" )->params( $constraintName )->escaped();
				$status = CheckResult::STATUS_VIOLATION;
			} else {
				$message = '';
				$status = CheckResult::STATUS_COMPLIANCE;
			}
		}

		return new CheckResult( $statement, $constraint->getConstraintTypeQid(), $parameters, $status, $message );
	}

}