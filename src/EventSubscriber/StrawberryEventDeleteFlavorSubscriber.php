<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\search_api\SearchApiException;

/**
 * Event subscriber for SBF bearing entity json process event.
 */
class StrawberryEventDeleteFlavorSubscriber extends StrawberryfieldEventDeleteSubscriber {

  /**
   * {@inheritdoc}
   */
  protected static $priority = 100;

  /**
   * Key value service.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValue;


  /**
   * StrawberryEventDeleteFlavorSubscriber constructor.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyvalue
   */
  public function __construct(KeyValueFactoryInterface $keyvalue) {
    $this->keyValue = $keyvalue;
  }


  /**
   * {@inheritdoc}
   */
  public function onEntityDelete(StrawberryfieldCrudEvent $event) {
      $entity = $event->getEntity();
      $this->trackDeleted($entity);
      $current_class = get_called_class();
      $event->setProcessedBy($current_class, TRUE);
  }

  /**
   * Deletes all documents tracked on Search Api for an ADO.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to delete all flavors from.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function trackDeleted(EntityInterface $entity) {

    $datasource_id = 'strawberryfield_flavor_datasource';
    $limit = 200;
    $parent_entity_index_needs_update = FALSE;
    foreach (StrawberryfieldFlavorDatasource::getValidIndexes() as $index) {
      $query = $index->query(['offset' => 0, 'limit' => $limit]);
      $query->addCondition('search_api_datasource', $datasource_id)
        ->addCondition('uuid', $entity->uuid());
      $query->setOption('search_api_retrieved_field_values', ['id' => 'id']);
      // Query breaks if not because standard hl is enabled for all fields.
      // and normal hl offsets on OCR HL specific ones.
      $query->setOption('ocr_highlight', 'on');
      // We want all documents removed. No server access needed here.
      $query->setOption('search_api_bypass_access', TRUE);
      $query->setProcessingLevel(QueryInterface::PROCESSING_NONE);
      try {
        $results = $query->execute();
        $max = $newcount = $results->getResultCount();
        $tracked_ids = [];
        $i = 0;
        // Only reason we use $newcount and $max is in the rare case
        // that while untracking deletion is happening in real time
        // and the actual $newcount decreases "live"
        while (count($tracked_ids) < $max && $newcount > 0) {
          $i++;
          foreach ($results->getResultItems() as $item) {
            // The tracker methods above prepend the datasource id, so we need to
            // workaround it by removing it beforehand.
            [$unused, $raw_id] = Utility::splitCombinedId($item->getId());
            $tracked_ids[] = $raw_id;
          }
          // If there are still more left, change the range and query again.
          if (count($tracked_ids) < $max) {
            $query->range($limit * $i, $limit);
            $results = $query->execute();
            $newcount = $results->getResultCount();
          }
        }
        // Untrack after all possible query calls with offsets.
        if (count($tracked_ids) > 0) {
          $parent_entity_index_needs_update = TRUE;
          $index->trackItemsDeleted($datasource_id, $tracked_ids);
          // Removes temporary stored Flavors from Key Collection
          $this->keyValue
            ->get(StrawberryfieldFlavorDatasource::SBFL_KEY_COLLECTION)
            ->deleteMultiple($tracked_ids);
        }
      }
      catch (SearchApiException $searchApiException) {
        watchdog_exception('strawberryfield', $searchApiException, 'We could not untrack Strawberry Flavor Documents from Index because the Solr Query returned an exception at server level.');
      }
    }

    if ($parent_entity_index_needs_update && $entity->field_sbf_nodetonode instanceof EntityReferenceFieldItemListInterface) {
      $indexes = [];
      /** @var \Drupal\search_api\Plugin\search_api\datasource\ContentEntityTrackingManager $tracking_manager */
      $tracking_manager = \Drupal::getContainer()
        ->get('search_api.entity_datasource.tracking_manager');
      foreach ($entity->field_sbf_nodetonode->referencedEntities() as $key => $referencedEntity) {
        if (!isset($indexes[$referencedEntity->getType()])) {
          $indexes[$referencedEntity->getType()]
            = $tracking_manager->getIndexesForEntity($referencedEntity);
        }
        $updated_item_ids = [];
        $entity_id = $referencedEntity->id();
        $languages = $referencedEntity->getTranslationLanguages();
        $combine_id = function ($langcode) use ($entity_id) {
          return $entity_id . ':' . $langcode;
        };
        $updated_item_ids = array_map($combine_id, array_keys($languages));
        if (isset($indexes[$referencedEntity->getType()])) {
          foreach ($indexes[$referencedEntity->getType()] as $index) {
            $index->trackItemsUpdated('entity:node', $updated_item_ids);
          }
        }
      }
    }
  }
}
