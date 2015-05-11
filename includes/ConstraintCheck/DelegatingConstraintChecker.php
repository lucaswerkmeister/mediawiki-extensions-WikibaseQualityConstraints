<?php

namespace WikidataQuality\ConstraintReport\ConstraintCheck;

use Wikibase\Lib\Store\EntityLookup;
use Wikibase\Repo\Store;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementList;
use Wikibase\DataModel\Snak;
use Wikibase\DataModel\Entity\Entity;
use Wikibase\DataModel\Entity\PropertyId;
use DataValues\DataValue;
use WikidataQuality\ConstraintReport\ConstraintCheck\Helper\ConstraintReportHelper;
use WikidataQuality\ConstraintReport\ConstraintCheck\Result\CheckResult;
use WikidataQuality\ConstraintReport\ConstraintRepository;
use WikidataQuality\ConstraintReport\Constraint;


/**
 * Class DelegatingConstraintCheck
 * Used to start the constraint-check process and to delegate
 * the statements that has to be checked to the corresponding checkers
 *
 * @package WikidataQuality\ConstraintReport\ConstraintCheck
 * @author BP2014N1
 * @license GNU GPL v2+
 */
class DelegatingConstraintChecker {

	/**
	 * Wikibase entity lookup.
	 *
	 * @var EntityLookup
	 */
	private $entityLookup;

	/**
	 * Class for helper functions for constraint checkers.
	 *
	 * @var ConstraintReportHelper
	 */
	private $helper;

	/**
	 * @var
	 */
	private $checkerMap;

	/**
	 * List of all statements of given entity.
	 *
	 * @var StatementList
	 */
	private $statements;

	public function __construct( EntityLookup $lookup, $checkerMap ) {
		$this->entityLookup = $lookup;
		$this->checkerMap = $checkerMap;
		$this->helper = new ConstraintReportHelper();
		$this->constraintRepository = new ConstraintRepository();
	}

	/**
	 * Starts the whole constraint-check process.
	 * Statements of the entity will be checked against every constraint that is defined on the property.
	 *
	 * @param Entity $entity - Entity that shall be checked against constraints
	 *
	 * @return array|null
	 */
	public function checkAgainstConstraints( $entity ) {
		if ( $entity ) {

			$this->statements = $entity->getStatements();

			$result = $this->checkEveryStatement( $entity );

			return $this->sortResult( $result );
		}
		return null;
	}

	private function checkEveryStatement( $entity ) {
		$result = array ();
		foreach ( $this->statements as $statement ) {

			$claim = $statement->getClaim();

			if ( $claim->getMainSnak()->getType() !== 'value' ) {
				// skip 'somevalue' and 'novalue' cases, todo: handle in a better way
				continue;
			}

			$propertyId = $claim->getPropertyId();
			$numericPropertyId = $propertyId->getNumericId();

			$constraints = $this->constraintRepository->queryConstraintsForProperty( $numericPropertyId );

			$result = array_merge( $result, $this->checkConstraintsForStatementOnEntity( $constraints, $entity, $statement ) );

		}

		return $result;
	}

	private function checkConstraintsForStatementOnEntity( $constraints, $entity, $statement ) {
		$result = array ();
		foreach ( $constraints as $constraint ) {
			$parameter = $constraint->getConstraintParameter();
			if ( in_array( $entity->getId()->getSerialization(), $parameter['exceptions'] ) ) {
				$message = 'This entity is a known exception for this constraint and has been marked as such.';
				$result[ ] = new CheckResult( $statement, $constraint->getConstraintTypeQid(), array (), CheckResult::STATUS_EXCEPTION, $message ); // todo: display parameters anyway
				continue;
			}

			$result[ ] = $this->getCheckResultFor( $statement, $constraint, $entity );
		}
		return $result;
	}

	/**
	 * @param Statement $statement
	 * @param string $constraintTypeQid
	 * @param $constraintParameters
	 * @param Entity $entity
	 *
	 * @return CheckResult
	 */
	private function getCheckResultFor( Statement $statement, Constraint $constraint, Entity $entity ) {

		if( array_key_exists( $constraint->getConstraintTypeQid(), $this->checkerMap ) ) {
			$checker = $this->checkerMap[$constraint->getConstraintTypeQid()];
			return $checker->checkConstraint( $statement, $constraint, $entity );
		} else {
			return new CheckResult( $statement, $constraint->getConstraintTypeQid() );
		}
	}

	private function sortResult( $result ) {
		if ( count( $result ) < 2 ) {
			return $result;
		}

		$sortFunction = function ( $a, $b ) {
			$order = array ( 'other' => 4, 'compliance' => 3, 'exception' => 2, 'violation' => 1 );

			$statusA = $a->getStatus();
			$statusB = $b->getStatus();

			$orderA = array_key_exists( $statusA, $order ) ? $order[ $statusA ] : $order[ 'other' ];
			$orderB = array_key_exists( $statusB, $order ) ? $order[ $statusB ] : $order[ 'other' ];

			if ( $orderA === $orderB ) {
				return 0;
			} else {
				return ( $orderA > $orderB ) ? 1 : -1;
			}
		};

		uasort( $result, $sortFunction );

		return $result;
	}
}