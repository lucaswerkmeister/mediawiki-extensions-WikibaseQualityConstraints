<?php

namespace WikibaseQuality\ConstraintReport\Test\ValueCountChecker;

use Wikibase\Repo\Tests\NewItem;
use Wikibase\Repo\Tests\NewStatement;
use WikibaseQuality\ConstraintReport\Constraint;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Checker\SingleValueChecker;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Context\MainSnakContext;
use WikibaseQuality\ConstraintReport\Tests\ResultAssertions;

/**
 * @covers WikibaseQuality\ConstraintReport\ConstraintCheck\Checker\SingleValueChecker
 *
 * @group WikibaseQualityConstraints
 *
 * @author Lucas Werkmeister
 * @license GNU GPL v2+
 */
class SingleValueCheckerTest extends \MediaWikiTestCase {

	use ResultAssertions;

	/**
	 * @var Constraint
	 */
	private $constraint;

	/**
	 * @var SingleValueChecker
	 */
	private $checker;

	protected function setUp() {
		parent::setUp();

		$this->constraint = $this->getMockBuilder( Constraint::class )
			->disableOriginalConstructor()
			->getMock();
		$this->checker = new SingleValueChecker();
	}

	public function testSingleValueConstraint_One() {
		$statement = NewStatement::noValueFor( 'P1' )->withSomeGuid()->build();
		$item = NewItem::withStatement( $statement )->build();
		$context = new MainSnakContext( $item, $statement );

		$checkResult = $this->checker->checkConstraint( $context, $this->constraint );

		$this->assertCompliance( $checkResult );
	}

	public function testSingleValueConstraint_Two() {
		$statement1 = NewStatement::noValueFor( 'P1' )->withSomeGuid()->build();
		$statement2 = NewStatement::noValueFor( 'P1' )->withSomeGuid()->build();
		$item = NewItem::withStatement( $statement1 )->andStatement( $statement2 )->build();
		$context = new MainSnakContext( $item, $statement1 );

		$checkResult = $this->checker->checkConstraint( $context, $this->constraint );

		$this->assertViolation( $checkResult, 'wbqc-violation-message-single-value' );
	}

	public function testSingleValueConstraint_TwoButOneDeprecated() {
		$statement1 = NewStatement::noValueFor( 'P1' )->withSomeGuid()->build();
		$statement2 = NewStatement::noValueFor( 'P1' )
			->withDeprecatedRank()
			->withSomeGuid()->build();
		$item = NewItem::withStatement( $statement1 )->andStatement( $statement2 )->build();
		$context = new MainSnakContext( $item, $statement1 );

		$checkResult = $this->checker->checkConstraint( $context, $this->constraint );

		$this->assertCompliance( $checkResult );
	}

	public function testSingleValueConstraintDeprecatedStatement() {
		$statement = NewStatement::noValueFor( 'P1' )
			->withDeprecatedRank()
			->withSomeGuid()->build();
		$entity = NewItem::withId( 'Q1' )
			->build();
		$context = new MainSnakContext( $entity, $statement );

		$checkResult = $this->checker->checkConstraint( $context, $this->constraint );

		$this->assertDeprecation( $checkResult );
	}

	public function testCheckConstraintParameters() {
		$result = $this->checker->checkConstraintParameters( $this->constraint );

		$this->assertEmpty( $result );
	}

}
