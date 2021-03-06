<?php

namespace WikibaseQuality\ConstraintReport\Tests;

use TitleParser;
use TitleValue;

/**
 * @author Lucas Werkmeister
 * @license GNU GPL v2+
 */
trait TitleParserMock {

	public function getTitleParserMock() {
		$titleParser = $this->getMock( TitleParser::class );
		$titleParser->method( 'parseTitle' )->will( $this->returnCallback(
			function( $text, $defaultNamespace ) {
				$exploded = explode( ':', $text, 2 );
				if ( count( $exploded ) === 1 ) {
					$title = $exploded[0];
					$namespace = $defaultNamespace;
				} else {
					$title = $exploded[1];
					$namespace = constant( 'NS_' . strtoupper( $exploded[0] ) );
				}
				$title = ucfirst( strtr( $title, ' ', '_' ) );
				return new TitleValue( $namespace, $title );
			}
		) );
		return $titleParser;
	}

}
