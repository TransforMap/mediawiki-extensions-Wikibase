<?php

namespace Wikibase\Lib\Test;

use PHPUnit_Framework_TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @group WikibaseLib
 * @group Wikibase
 *
 * @license GPL-2.0+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class NoBadDependencyUsageTest extends PHPUnit_Framework_TestCase {

	public function testNoRepoUsageInLib() {
		// Increasing this allowance is forbidden
		$this->assertStringNotInLib( 'WikibaseRepo' . '::', 2 );
		$this->assertStringNotInLib( 'Wikibase\\Repo\\', 4 );
	}

	public function testNoClientUsageInLib() {
		// Increasing this allowance is forbidden
		$this->assertStringNotInLib( 'WikibaseClient' . '::', 2 );
		$this->assertStringNotInLib( 'Wikibase\\Client\\', 2 );
	}

	/**
	 * @param string $string
	 * @param int $maxAllowance
	 */
	private function assertStringNotInLib( $string, $maxAllowance ) {
		$this->assertLessThanOrEqual(
			$maxAllowance,
			$this->countStringInDir( $string, __DIR__ . '/../../' ),
			'You are not allowed to use ' . $string . ' in this component'
		);
	}

	/**
	 * @param string $string
	 * @param string $dir
	 *
	 * @return int
	 */
	private function countStringInDir( $string, $dir ) {
		$count = 0;
		$directoryIterator = new RecursiveDirectoryIterator( $dir );

		/**
		 * @var SplFileInfo $fileInfo
		 */
		foreach ( new RecursiveIteratorIterator( $directoryIterator ) as $fileInfo ) {
			if ( $fileInfo->isFile() && substr( $fileInfo->getFilename(), -4 ) === '.php' ) {
				$text = file_get_contents( $fileInfo->getPathname() );
				$text = preg_replace( '@/\*.*?\*/@s', '', $text );

				if ( strpos( $text, $string ) !== false ) {
					$count++;
				}
			}
		}

		return $count;
	}

}
