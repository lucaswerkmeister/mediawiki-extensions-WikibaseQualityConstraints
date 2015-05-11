<?php

namespace WikidataQuality\ConstraintReport;


use Wikibase\Repo\WikibaseRepo;
use Wikibase\Lib\Store\EntityLookup;
use WikidataQuality\ConstraintReport\ConstraintCheck\DelegatingConstraintChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Helper\ConstraintReportHelper;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\CommonsLinkChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\FormatChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\OneOfChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\QualifierChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\RangeChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\TypeChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\ConflictsWithChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\QualifiersChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\TargetRequiredClaimChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\ItemChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\MandatoryQualifiersChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\ValueTypeChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\SymmetricChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\InverseChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\DiffWithinRangeChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\SingleValueChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\MultiValueChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Checker\UniqueValueChecker;
use WikidataQuality\ConstraintReport\ConstraintCheck\Helper\ConnectionCheckerHelper;
use WikidataQuality\ConstraintReport\ConstraintCheck\Helper\RangeCheckerHelper;
use WikidataQuality\ConstraintReport\ConstraintCheck\Helper\TypeCheckerHelper;


class ConstraintReportFactory {

	/**
	 * @var constraintRepository
	 */
	private $constraintRepository;

	/**
	 * @var array
	 */
	private $constraintCheckerMap;

	/**
	 * @var DelegatingConstraintChecker
	 */
	private $delegatingConstraintChecker;

	/**
	 * @var EntityLookup
	 */
	private $lookup;

	/**
	 * @var array
	 */
	private $constraintParameterMap;

	/**
	 * Returns the default instance.
	 * IMPORTANT: Use only when it is not feasible to inject an instance properly.
	 *
	 * @return ConstraintReportFactory
	 */
	public static function getDefaultInstance() {
		static $instance = null;

		if ( $instance === null ) {
			$instance = new self( WikibaseRepo::getDefaultInstance()->getEntityLookup() );
		}

		return $instance;
	}

	/**
	 * @param EntityLookup $lookup
	 */
	public function __construct( EntityLookup $lookup ) {
		$this->lookup = $lookup;
	}

	/**
	 * @return DelegatingConstraintChecker
	 */
	public function getConstraintChecker() {
		if ( $this->delegatingConstraintChecker === null ) {
			$this->delegatingConstraintChecker = new DelegatingConstraintChecker( $this->lookup, $this->getConstraintCheckerMap( $this->lookup ) );
		}

		return $this->delegatingConstraintChecker;
	}

	/**
	 * @return array
	 */
	private function getConstraintCheckerMap(){
		if ( $this->constraintCheckerMap === null ) {
			$constraintReportHelper = new ConstraintReportHelper();
			$connectionCheckerHelper = new ConnectionCheckerHelper();
			$rangeCheckerHelper = new RangeCheckerHelper();
			$typeCheckerHelper = new TypeCheckerHelper( $this->lookup );

			$this->constraintCheckerMap = array(
				'Conflicts with' => new ConflictsWithChecker( $this->lookup, $constraintReportHelper, $connectionCheckerHelper ),
				'Item' => new ItemChecker( $this->lookup, $constraintReportHelper, $connectionCheckerHelper ),
				'Target required claim' => new TargetRequiredClaimChecker( $this->lookup, $constraintReportHelper, $connectionCheckerHelper ),
				'Symmetric' => new SymmetricChecker( $this->lookup, $constraintReportHelper, $connectionCheckerHelper ),
				'Inverse' => new InverseChecker( $this->lookup, $constraintReportHelper, $connectionCheckerHelper ),
				'Qualifier' => new QualifierChecker( $constraintReportHelper ),
				'Qualifiers' => new QualifiersChecker( $constraintReportHelper ),
				'Mandatory qualifiers' => new MandatoryQualifiersChecker( $constraintReportHelper ),
				'Range' => new RangeChecker( $constraintReportHelper, $rangeCheckerHelper ),
				'Diff within range' => new DiffWithinRangeChecker( $constraintReportHelper, $rangeCheckerHelper ),
				'Type' => new TypeChecker( $this->lookup, $constraintReportHelper, $typeCheckerHelper ),
				'Value type' => new ValueTypeChecker( $this->lookup, $constraintReportHelper, $typeCheckerHelper ),
				'Single value' => new SingleValueChecker(),
				'Multi value' => new MultiValueChecker(),
				'Unique value' => new UniqueValueChecker(),
				'Format' => new FormatChecker( $constraintReportHelper ),
				'Commons link' => new CommonsLinkChecker( $constraintReportHelper ),
				'One of' => new OneOfChecker( $constraintReportHelper ),
			);
		}

		return $this->constraintCheckerMap;
	}

	public function getConstraintParameterMap() {
		if ( $this->constraintParameterMap === null ) {
			$this->constraintParameterMap = array(
				'Commons link' => array( 'namespace' ),
				'Conflicts with' => array( 'property', 'item' ),
				'Diff within range' => array( 'property', 'minimum_quantity', 'maximum_quantity' ),
				'Format' => array( 'pattern' ),
				'Inverse' => array( 'property' ),
				'Item' => array( 'property', 'item' ),
				'Mandatory qualifiers' => array( 'property' ),
				'Multi value' => array(),
				'One of' => array( 'item' ),
				'Qualifier' => array(),
				'Qualifiers' => array( 'property' ),
				'Range' => array( 'minimum_quantity', 'maximum_quantity', 'minimum_date', 'maximum_date' ),
				'Single value' => array(),
				'Symmetric' => array(),
				'Target required claim' => array( 'property', 'item' ),
				'Type' => array( 'class', 'relation' ),
				'Unique value' => array(),
				'Value type' => array( 'class', 'relation' )
			);
		}

		return $this->constraintParameterMap;
	}

	public function getConstraintRepository() {
		if ( $this->constraintRepository === null ) {
			$this->constraintRepository = new ConstraintRepository( CONSTRAINT_TABLE );
		}

		return $this->constraintRepository;
	}

}