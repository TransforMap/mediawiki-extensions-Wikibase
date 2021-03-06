<?php

namespace Wikibase\Test;

use DataValues\StringValue;
use HashSiteStore;
use MediaWikiLangTestCase;
use TestSites;
use Title;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Reference;
use Wikibase\DataModel\ReferenceList;
use Wikibase\DataModel\Services\Entity\NullEntityPrefetcher;
use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookup;
use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookupException;
use Wikibase\DataModel\SiteLink;
use Wikibase\DataModel\SiteLinkList;
use Wikibase\DataModel\Snak\PropertyNoValueSnak;
use Wikibase\DataModel\Snak\PropertySomeValueSnak;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementList;
use Wikibase\DataModel\Term\AliasGroup;
use Wikibase\DataModel\Term\AliasGroupList;
use Wikibase\DataModel\Term\Fingerprint;
use Wikibase\DataModel\Term\Term;
use Wikibase\DataModel\Term\TermList;
use Wikibase\DumpRdf;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Lib\Tests\MockRepository;
use Wikibase\Rdf\RdfVocabulary;
use Wikibase\DataModel\Services\EntityId\EntityIdPager;
use Wikibase\Repo\Store\Sql\SqlEntityIdPagerFactory;
use Wikibase\Repo\Tests\Store\MockEntityIdPager;
use Wikibase\Repo\WikibaseRepo;

/**
 * @covers Wikibase\DumpRdf
 *
 * @group WikibaseRepo
 * @group Wikibase
 *
 * @license GPL-2.0+
 * @author Addshore
 */
class DumpRdfTest extends MediaWikiLangTestCase {

	public function testScript() {
		$dumpScript = new DumpRdf();

		$mockRepo = new MockRepository();
		$mockEntityIdPager = new MockEntityIdPager();

		$snakList = new SnakList();
		$snakList->addSnak( new PropertySomeValueSnak( new PropertyId( 'P12' ) ) );
		$snakList->addSnak( new PropertyValueSnak( new PropertyId( 'P12' ), new StringValue( 'stringVal' ) ) );
		/** @var EntityDocument[] $testEntities */
		$testEntities = array(
			new Item( new ItemId( 'Q1' ) ),
			new Property( new PropertyId( 'P1' ), null, 'string' ),
			new Property(
				new PropertyId( 'P12' ),
				null,
				'string',
				new StatementList( array(
					new Statement(
						// P999 is non existent thus the datatype will not be present
						new PropertySomeValueSnak( new PropertyId( 'P999' ) ),
						null,
						null,
						'GUID1'
					)
				) )
			),
			new Item(
				new ItemId( 'Q2' ),
				new Fingerprint(
					new TermList( array(
						new Term( 'en', 'en-label' ),
						new Term( 'de', 'de-label' ),
					) ),
					new TermList( array(
						new Term( 'fr', 'en-desc' ),
						new Term( 'de', 'de-desc' ),
					) ),
					new AliasGroupList( array(
						new AliasGroup( 'en', array( 'ali1', 'ali2' ) ),
						new AliasGroup( 'dv', array( 'ali11', 'ali22' ) )
					) )
				),
				new SiteLinkList( array(
					new SiteLink( 'enwiki', 'Berlin' ),
					new SiteLink( 'dewiki', 'England', array( new ItemId( 'Q1' ) ) )
				) ),
				new StatementList( array(
					new Statement(
						new PropertySomeValueSnak( new PropertyId( 'P12' ) ),
						null,
						null,
						'GUID1'
					),
					new Statement(
						new PropertySomeValueSnak( new PropertyId( 'P12' ) ),
						$snakList,
						new ReferenceList( array(
							new Reference( array(
								new PropertyValueSnak( new PropertyId( 'P12' ), new StringValue( 'refSnakVal' ) ),
								new PropertyNoValueSnak( new PropertyId( 'P12' ) ),
							) ),
						) ),
						'GUID2'
					)
				) )
			),
			new Item(
				new ItemId( 'Q4' ),
				null,
				new SiteLinkList( array(
					new SiteLink( 'enwiki', 'San Jose' ),
					new SiteLink( 'dewiki', 'USA' )
				) ),
				null
			),
		);

		foreach ( $testEntities as $key => $testEntity ) {
			$mockRepo->putEntity( $testEntity, $key, '20000101000000' );
			$mockEntityIdPager->addEntityPage( $testEntity->getId(), $key );
		}

		$sqlEntityIdPagerFactory = $this->getMockBuilder( SqlEntityIdPagerFactory::class )
			->disableOriginalConstructor()
			->getMock();
		$sqlEntityIdPagerFactory->expects( $this->once() )
			->method( 'newSqlEntityIdPager' )
			->with( null, EntityIdPager::INCLUDE_REDIRECTS )
			->will( $this->returnValue( $mockEntityIdPager ) );

		// Note: We are testing with the actual RDF bindings, so we can check for actual RDF output.
		$rdfBuilder = WikibaseRepo::getDefaultInstance()->getValueSnakRdfBuilderFactory();

		$dumpScript->setServices(
			$sqlEntityIdPagerFactory,
			new NullEntityPrefetcher(),
			new HashSiteStore( TestSites::getSites() ),
			$this->getMockPropertyDataTypeLookup(),
			$rdfBuilder,
			$mockRepo,
			new RdfVocabulary( 'fooUri/', 'acme/EntityData/' ),
			$this->getEntityTitleLookup()
		);

		$logFileName = tempnam( sys_get_temp_dir(), "Wikibase-DumpRdfTest" );
		$outFileName = tempnam( sys_get_temp_dir(), "Wikibase-DumpRdfTest" );

		$dumpScript->loadParamsAndArgs(
			null,
			array(
				'log' => $logFileName,
				'output' => $outFileName,
				'format' => 'n-triples',
			)
		);

		$dumpScript->execute();

		$expectedLog = file_get_contents( __DIR__ . '/../data/maintenance/dumpRdf-log.txt' );
		$expectedOut = file_get_contents( __DIR__ . '/../data/maintenance/dumpRdf-out.txt' );

		$actualOut = file_get_contents( $outFileName );
		$actualOut = preg_replace(
			'/<http:\/\/wikiba.se\/ontology-beta#Dump> <http:\/\/schema.org\/dateModified> "[^"]+"/',
			"<http://wikiba.se/ontology-beta#Dump> <http://schema.org/dateModified> \"2015-01-01T00:00:00Z\"",
			$actualOut
		);

		$this->assertEquals(
			$this->fixLineEndings( $expectedLog ),
			$this->fixLineEndings( file_get_contents( $logFileName ) )
		);
		$this->assertEquals(
			$this->fixLineEndings( $expectedOut ),
			$this->fixLineEndings( $actualOut )
		);
	}

	/**
	 * @return PropertyDataTypeLookup
	 */
	private function getMockPropertyDataTypeLookup() {
		$mockDataTypeLookup = $this->getMock( PropertyDataTypeLookup::class );
		$mockDataTypeLookup->expects( $this->any() )
			->method( 'getDataTypeIdForProperty' )
			->will( $this->returnCallback( function( PropertyId $id ) {
				if ( $id->getSerialization() === 'P999' ) {
					throw new PropertyDataTypeLookupException( $id );
				}
				return 'string';
			} ) );
		return $mockDataTypeLookup;
	}

	private function fixLineEndings( $string ) {
		return preg_replace( '~(*BSR_ANYCRLF)\R~', "\n", $string );
	}

	/**
	 * @return EntityTitleLookup
	 */
	private function getEntityTitleLookup() {
		$entityTitleLookup = $this->getMock( EntityTitleLookup::class );
		$entityTitleLookup->expects( $this->any() )
			->method( 'getTitleForId' )
			->will( $this->returnCallback( function( EntityId $entityId ) {
				return Title::newFromText( $entityId->getSerialization() );
			} ) );

		return $entityTitleLookup;
	}

}
