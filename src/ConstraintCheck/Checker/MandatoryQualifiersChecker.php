<?php

namespace WikibaseQuality\ConstraintReport\ConstraintCheck\Checker;

use Wikibase\DataModel\Snak\Snak;
use WikibaseQuality\ConstraintReport\Constraint;
use WikibaseQuality\ConstraintReport\ConstraintCheck\ConstraintChecker;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Context\Context;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Helper\ConstraintParameterException;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Helper\ConstraintParameterParser;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Message\ViolationMessage;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Result\CheckResult;
use WikibaseQuality\ConstraintReport\ConstraintParameterRenderer;
use WikibaseQuality\ConstraintReport\Role;
use Wikibase\DataModel\Statement\Statement;

/**
 * @author BP2014N1
 * @license GNU GPL v2+
 */
class MandatoryQualifiersChecker implements ConstraintChecker {

	/**
	 * @var ConstraintParameterParser
	 */
	private $constraintParameterParser;

	/**
	 * @var ConstraintParameterRenderer
	 */
	private $constraintParameterRenderer;

	/**
	 * @param ConstraintParameterParser $constraintParameterParser
	 * @param ConstraintParameterRenderer $constraintParameterRenderer should return HTML
	 */
	public function __construct(
		ConstraintParameterParser $constraintParameterParser,
		ConstraintParameterRenderer $constraintParameterRenderer
	) {
		$this->constraintParameterParser = $constraintParameterParser;
		$this->constraintParameterRenderer = $constraintParameterRenderer;
	}

	/**
	 * @codeCoverageIgnore This method is purely declarative.
	 */
	public function getSupportedContextTypes() {
		return [
			Context::TYPE_STATEMENT => CheckResult::STATUS_COMPLIANCE,
			Context::TYPE_QUALIFIER => CheckResult::STATUS_NOT_IN_SCOPE,
			Context::TYPE_REFERENCE => CheckResult::STATUS_NOT_IN_SCOPE,
		];
	}

	/**
	 * @codeCoverageIgnore This method is purely declarative.
	 */
	public function getDefaultContextTypes() {
		return [
			Context::TYPE_STATEMENT,
		];
	}

	/**
	 * Checks 'Mandatory qualifiers' constraint.
	 *
	 * @param Context $context
	 * @param Constraint $constraint
	 *
	 * @throws ConstraintParameterException
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

		$message = ( new ViolationMessage( 'wbqc-violation-message-mandatory-qualifier' ) )
			->withEntityId( $context->getSnak()->getPropertyId(), Role::CONSTRAINT_PROPERTY )
			->withEntityId( $propertyId, Role::QUALIFIER_PREDICATE );
		$status = CheckResult::STATUS_VIOLATION;

		/** @var Snak $qualifier */
		foreach ( $context->getSnakStatement()->getQualifiers() as $qualifier ) {
			if ( $propertyId->equals( $qualifier->getPropertyId() ) ) {
				$message = null;
				$status = CheckResult::STATUS_COMPLIANCE;
				break;
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
		return $exceptions;
	}

}
