<?php

namespace WikibaseQuality\ConstraintReport\Tests\Cache;

use WikibaseQuality\ConstraintReport\ConstraintCheck\Cache\CachedArray;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Cache\CachingMetadata;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Cache\Metadata;

/**
 * @covers WikibaseQuality\ConstraintReport\ConstraintCheck\Cache\CachedArray
 *
 * @group WikibaseQualityConstraints
 *
 * @author Lucas Werkmeister
 * @license GNU GPL v2+
 */
class CachedArrayTest extends \PHPUnit_Framework_TestCase {

	public function testGetArray() {
		$array = [ 'array' => true ];
		$cm = Metadata::blank();

		$ca = new CachedArray( $array, $cm );

		$this->assertSame( $array, $ca->getArray() );
	}

	public function testGetCachingMetadata() {
		$array = [];
		$m = Metadata::ofCachingMetadata( CachingMetadata::ofMaximumAgeInSeconds( 42 ) );

		$ca = new CachedArray( $array, $m );

		$this->assertSame( $m, $ca->getMetadata() );
	}

}
