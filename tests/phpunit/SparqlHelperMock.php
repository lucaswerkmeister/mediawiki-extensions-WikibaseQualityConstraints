<?php

namespace WikibaseQuality\ConstraintReport\Tests;

use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Helper\SparqlHelper;

/**
 * @author Lucas Werkmeister
 * @license GNU GPL v2+
 */
trait SparqlHelperMock {

	/**
	 * @param Statement $expectedStatement
	 * @param (EntityId|null)[] $result
	 * @return SparqlHelper
	 */
	private function getSparqlHelperMockFindEntities(
		Statement $expectedStatement,
		$result ) {

		$mock = $this->getMockBuilder( SparqlHelper::class )
			  ->disableOriginalConstructor()
			  ->getMock();

		$mock->expects( $this->exactly( 1 ) )
			->method( 'findEntitiesWithSameStatement' )
			->willReturn( $result )
			->withConsecutive( [ $this->equalTo( $expectedStatement ), $this->equalTo( true ) ] );

		return $mock;
	}

	private function getSparqlHelperMockFindEntitiesQualifierReference(
		EntityId $expectedEntityId,
		PropertyValueSnak $expectedSnak,
		$expectedType,
		$result
	) {
		$mock = $this->getMockBuilder( SparqlHelper::class )
			->disableOriginalConstructor()
			->getMock();

		$mock->expects( $this->exactly( 1 ) )
			->method( 'findEntitiesWithSameQualifierOrReference' )
			->willReturn( $result )
			->withConsecutive( [
				$this->equalTo( $expectedEntityId ),
				$this->equalTo( $expectedSnak ),
				$this->equalTo( $expectedType ),
				$this->equalTo( $expectedType === 'qualifier' )
			] );

		return $mock;
	}

}
