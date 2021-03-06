<?php

namespace WikibaseQuality\ConstraintReport\Api;

use IBufferingStatsdDataFactory;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\Lib\Store\EntityRevisionLookup;
use Wikibase\Lib\Store\Sql\WikiPageEntityMetaDataAccessor;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Cache\CachedCheckConstraintsResponse;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Cache\CachingMetadata;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Cache\DependencyMetadata;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Cache\Metadata;
use WikibaseQuality\ConstraintReport\ConstraintCheck\Result\CheckResult;

/**
 * A wrapper around another ResultsBuilder that caches results in a ResultsCache.
 *
 * Results are cached independently per entity,
 * and the outermost level of the response returned by the wrapped ResultsBuilder
 * must be an array from entity ID serialization to results for that entity.
 * Apart from that, the array structure does not matter.
 *
 * However, if the response for an entity is an array
 * which contains 'cached' keys anywhere (possibly nested),
 * the corresponding value is assumed to be CachingMetadata in array form,
 * and updated with the age of the value in the WANObjectCache;
 * and if the response contains arrays with a 'constraint' key (also possibly nested),
 * these arrays are assumed to be a CheckResult in array form
 * (as converted by CheckingResultsBuilder::checkResultToArray),
 * and if their 'type' is in the list of $possiblyStaleConstraintTypes,
 * their 'cached' information is also updated.
 *
 * @author Lucas Werkmeister
 * @license GNU GPL v2+
 */
class CachingResultsBuilder implements ResultsBuilder {

	/**
	 * @var ResultsBuilder
	 */
	private $resultsBuilder;

	/**
	 * @var ResultsCache
	 */
	private $cache;

	/**
	 * @var WikiPageEntityMetaDataAccessor
	 */
	private $wikiPageEntityMetaDataAccessor;

	/**
	 * @var EntityIdParser
	 */
	private $entityIdParser;

	/**
	 * @var int
	 */
	private $ttlInSeconds;

	/**
	 * @var string[]
	 */
	private $possiblyStaleConstraintTypes;

	/**
	 * @var IBufferingStatsdDataFactory
	 */
	private $dataFactory;

	/**
	 * @var callable
	 */
	private $microtime = 'microtime';

	/**
	 * TODO: In PHP 5.6, make this a public class constant instead,
	 * and also use it in CheckConstraints::getAllowedParams()
	 * and in some of the tests.
	 *
	 * @var string[]
	 */
	private $cachedStatuses;

	/**
	 * @param ResultsBuilder $resultsBuilder The ResultsBuilder that cache misses are delegated to.
	 * @param ResultsCache $cache The cache where results can be stored.
	 * @param WikiPageEntityMetaDataAccessor $wikiPageEntityMetaDataAccessor Used to get the latest revision ID.
	 * @param EntityIdParser $entityIdParser Used to parse entity IDs in cached objects.
	 * @param int $ttlInSeconds Time-to-live of the cached values, in seconds.
	 * @param string[] $possiblyStaleConstraintTypes item IDs of constraint types
	 * where cached results may always be stale, regardless of invalidation logic
	 * @param IBufferingStatsdDataFactory $dataFactory
	 */
	public function __construct(
		ResultsBuilder $resultsBuilder,
		ResultsCache $cache,
		WikiPageEntityMetaDataAccessor $wikiPageEntityMetaDataAccessor,
		EntityIdParser $entityIdParser,
		$ttlInSeconds,
		array $possiblyStaleConstraintTypes,
		IBufferingStatsdDataFactory $dataFactory
	) {
		$this->resultsBuilder = $resultsBuilder;
		$this->cache = $cache;
		$this->wikiPageEntityMetaDataAccessor = $wikiPageEntityMetaDataAccessor;
		$this->entityIdParser = $entityIdParser;
		$this->ttlInSeconds = $ttlInSeconds;
		$this->possiblyStaleConstraintTypes = $possiblyStaleConstraintTypes;
		$this->dataFactory = $dataFactory;

		$this->cachedStatuses = [
			CheckResult::STATUS_VIOLATION,
			CheckResult::STATUS_WARNING,
			CheckResult::STATUS_BAD_PARAMETERS,
		];
	}

	/**
	 * @param EntityId[] $entityIds
	 * @param string[] $claimIds
	 * @param string[]|null $constraintIds
	 * @param string[] $statuses
	 * @return CachedCheckConstraintsResponse
	 */
	public function getResults(
		array $entityIds,
		array $claimIds,
		array $constraintIds = null,
		array $statuses
	) {
		$results = [];
		$metadatas = [];
		if ( $this->canUseStoredResults( $entityIds, $claimIds, $constraintIds, $statuses ) ) {
			$storedEntityIds = [];
			foreach ( $entityIds as $entityId ) {
				$storedResults = $this->getStoredResults( $entityId );
				if ( $storedResults !== null ) {
					$this->dataFactory->increment(
						'wikibase.quality.constraints.cache.entity.hit'
					);
					$results += $storedResults->getArray();
					$metadatas[] = $storedResults->getMetadata();
					$storedEntityIds[] = $entityId;
				}
			}
			$entityIds = array_values( array_diff( $entityIds, $storedEntityIds ) );
		}
		if ( $entityIds !== [] || $claimIds !== [] ) {
			$this->dataFactory->updateCount(
				'wikibase.quality.constraints.cache.entity.miss',
				count( $entityIds )
			);
			$response = $this->getAndStoreResults( $entityIds, $claimIds, $constraintIds, $statuses );
			$results += $response->getArray();
			$metadatas[] = $response->getMetadata();
		}
		return new CachedCheckConstraintsResponse(
			$results,
			Metadata::merge( $metadatas )
		);
	}

	/**
	 * We can only use cached constraint results
	 * if exactly the problematic results of a full constraint check were requested:
	 * constraint checks for the full entity (not just individual statements),
	 * without restricting the set of constraints to check,
	 * and with exactly the 'violation', 'warning' and 'bad-parameters' statuses.
	 *
	 * (In theory, we could also use results for requests
	 * that asked for a subset of these result statuses,
	 * but removing the extra results from the cached value is tricky,
	 * especially if you consider that they might have added qualifier contexts to the output
	 * that should not only be empty, but completely absent.)
	 *
	 * @param EntityId[] $entityIds
	 * @param string[] $claimIds
	 * @param string[]|null $constraintIds
	 * @param string[] $statuses
	 * @return bool
	 */
	private function canUseStoredResults(
		array $entityIds,
		array $claimIds,
		array $constraintIds = null,
		array $statuses
	) {
		if ( $claimIds !== [] ) {
			return false;
		}
		if ( $constraintIds !== null ) {
			return false;
		}
		if ( $statuses != $this->cachedStatuses ) {
			return false;
		}
		return true;
	}

	/**
	 * @param EntityId[] $entityIds
	 * @param string[] $claimIds
	 * @param string[]|null $constraintIds
	 * @param string[] $statuses
	 * @return CachedCheckConstraintsResponse
	 */
	public function getAndStoreResults(
		array $entityIds,
		array $claimIds,
		array $constraintIds = null,
		array $statuses
	) {
		$results = $this->resultsBuilder->getResults( $entityIds, $claimIds, $constraintIds, $statuses );

		if ( $this->canStoreResults( $entityIds, $claimIds, $constraintIds, $statuses ) ) {
			foreach ( $entityIds as $entityId ) {
				$value = [
					'results' => $results->getArray()[$entityId->getSerialization()],
					'latestRevisionIds' => $this->getLatestRevisionIds(
						$results->getMetadata()->getDependencyMetadata()->getEntityIds()
					),
				];
				$this->cache->set( $entityId, $value, $this->ttlInSeconds );
			}
		}

		return $results;
	}

	/**
	 * We can only store constraint results
	 * if the set of constraints to check was not restricted
	 * and exactly the problematic results were requested.
	 * However, it doesn’t matter whether constraint checks on individual statements were requested:
	 * we only store results for the mentioned entity IDs,
	 * and those will be complete regardless of what’s in the statement IDs.
	 *
	 * (In theory, we could also store results of checks that requested extra result statuses,
	 * but removing the extra results before caching the value is tricky,
	 * especially if you consider that they might have added qualifier contexts to the output
	 * that should not only be empty, but completely absent.)
	 *
	 * @param EntityId[] $entityIds
	 * @param string[] $claimIds
	 * @param string[]|null $constraintIds
	 * @param string[] $statuses
	 * @return bool
	 */
	private function canStoreResults(
		array $entityIds,
		array $claimIds,
		array $constraintIds = null,
		array $statuses
	) {
		if ( $constraintIds !== null ) {
			return false;
		}
		if ( $statuses != $this->cachedStatuses ) {
			return false;
		}
		return true;
	}

	/**
	 * @param EntityId $entityId
	 * @return CachedCheckConstraintsResponse|null
	 */
	public function getStoredResults(
		EntityId $entityId
	) {
		$value = $this->cache->get( $entityId, $curTTL, [], $asOf );
		$now = call_user_func( $this->microtime, true );

		if ( $value === false ) {
			return null;
		}

		$ageInSeconds = (int)ceil( $now - $asOf );

		$dependedEntityIds = array_map(
			[ $this->entityIdParser, "parse" ],
			array_keys( $value['latestRevisionIds'] )
		);

		if ( $value['latestRevisionIds'] !== $this->getLatestRevisionIds( $dependedEntityIds ) ) {
			return null;
		}

		$cachingMetadata = $ageInSeconds > 0 ?
			CachingMetadata::ofMaximumAgeInSeconds( $ageInSeconds ) :
			CachingMetadata::fresh();

		if ( is_array( $value['results'] ) ) {
			array_walk( $value['results'], [ $this, 'updateCachingMetadata' ], $cachingMetadata );
		}

		return new CachedCheckConstraintsResponse(
			[ $entityId->getSerialization() => $value['results'] ],
			array_reduce(
				$dependedEntityIds,
				function( Metadata $metadata, EntityId $entityId ) {
					return Metadata::merge( [
						$metadata,
						Metadata::ofDependencyMetadata(
							DependencyMetadata::ofEntityId( $entityId )
						)
					] );
				},
				Metadata::ofCachingMetadata( $cachingMetadata )
			)
		);
	}

	/**
	 * @param EntityId[] $entityIds
	 * @return int[]
	 */
	private function getLatestRevisionIds( array $entityIds ) {
		$revisionInformations = $this->wikiPageEntityMetaDataAccessor->loadRevisionInformation(
			$entityIds,
			EntityRevisionLookup::LATEST_FROM_REPLICA
		);
		$latestRevisionIds = [];
		foreach ( $revisionInformations as $serialization => $revisionInformation ) {
			$latestRevisionIds[$serialization] = $revisionInformation->page_latest;
		}
		return $latestRevisionIds;
	}

	public function updateCachingMetadata( &$element, $key, CachingMetadata $cachingMetadata ) {
		if ( $key === 'cached' ) {
			$element = CachingMetadata::merge( [
				$cachingMetadata,
				CachingMetadata::ofArray( $element ),
			] )->toArray();
		}
		if (
			is_array( $element ) &&
			array_key_exists( 'constraint', $element ) &&
			in_array( $element['constraint']['type'], $this->possiblyStaleConstraintTypes, true )
		) {
			$element['cached'] = CachingMetadata::merge( [
				$cachingMetadata,
				CachingMetadata::ofArray(
					array_key_exists( 'cached', $element ) ? $element['cached'] : null
				),
			] )->toArray();
		}
		if ( is_array( $element ) ) {
			array_walk( $element, [ $this, __FUNCTION__ ], $cachingMetadata );
		}
	}

	/**
	 * Set a custom function to get the current time, instead of microtime().
	 *
	 * @param callable $microtime
	 */
	public function setMicrotimeFunction( callable $microtime ) {
		$this->microtime = $microtime;
	}

}
