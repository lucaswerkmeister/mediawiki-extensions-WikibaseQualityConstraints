<?php

namespace WikibaseQuality\ConstraintReport\Tests\Fake;

use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Statement\Statement;
use WikibaseQuality\ConstraintReport\Constraint;
use WikibaseQuality\ConstraintReport\ConstraintCheck\ConstraintChecker;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Context\Context;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Result\CheckResult;

class FakeChecker implements ConstraintChecker {

	/**
	 * @var string
	 */
	private $status;

	/**
	 * @param string $status
	 */
	public function __construct( $status = CheckResult::STATUS_TODO ) {
		$this->status = $status;
	}

	/**
	 * @see ConstraintChecker::checkConstraint
	 */
	public function checkConstraint( Context $context, Constraint $constraint ) {
		return new CheckResult( $context, $constraint, [], $this->status );
	}

	public function checkConstraintParameters( Constraint $constraint ) {
	}

}
