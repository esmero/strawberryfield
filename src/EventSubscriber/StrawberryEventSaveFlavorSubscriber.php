<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\file\FileInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\search_api\SearchApiException;

/**
 * Event subscriber that tracks deleted Files and status updates for Flavors.
 */
class StrawberryEventSaveFlavorSubscriber extends StrawberryfieldEventSaveSubscriber {

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
  public function onEntitySave(StrawberryfieldCrudEvent $event) {
    // We need to compare the original entity with the saved just now as file
    // events don't really work for this use case. The file is still attached to
    // the revisions. This event is run only on update, so we're not treating
    // the isNew() possibility here.
    $entity = $event->getEntity();
    $original_entity = $entity->original;
    $tracked_deleted = [];

    // We only care about the original entity ones, so that's the system of
    // record to compare. If $files_deleted is empty, it means new files, or no
    // deletions so we don't treat those.
    $entity_files = array_column($entity->get('field_file_drop')->getValue() ?? [], 'target_id');
    $original_entity_files = array_column($original_entity->get('field_file_drop')->getValue() ?? [], 'target_id');
    $files_deleted = array_diff($original_entity_files, $entity_files);

    foreach ($files_deleted as $file_id) {
      $file = OcflHelper::resolvetoFIDtoURI($file_id);
      // No need to review if the file is being used somewhere else as we're
      // removing the tracking contextually to the entity.
      if ($file) {
        $tracked_deleted = array_merge($tracked_deleted, $this->trackFilesDeleted($entity, $file));
      }
    }

    $tracked_deleted = array_unique($tracked_deleted);

    /* if ($entity->isPublished() != $original_entity->isPublished() || $entity->getOwnerId() != $original_entity->getOwnerId()) {
      $this->trackFlavorsNeedUpdate($entity, $tracked_deleted);
    } */
    // Small change in approach here, Nov 2022.
    // Even if more expensive we will retrack any SBF flavor on 'any' ADO change
    // This allows parent-parent changes to apply on a direct parent change
    // @TODO. We could also do this for parent-parent changes. Would
    // Require an extra SOLR query/means also a config form to set the field that
    // Connects... so not yet ok?
    $this->trackFlavorsNeedUpdate($entity, $tracked_deleted);
    $current_class = get_called_class();
    $event->setProcessedBy($current_class, TRUE);
  }

  /**
   * Deletes the documents tracked on Search Api.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to delete flavors contextually.
   * @param \Drupal\file\FileInterface $file
   *   The file associated to the entity.
   * @return array
   *    An array of tracked to be deleted Search API entities.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function trackFilesDeleted(EntityInterface $entity, FileInterface $file): array {
    $datasource_id = 'strawberryfield_flavor_datasource';
    $tracked_ids = [];
    $limit = 200;
    foreach (StrawberryfieldFlavorDatasource::getValidIndexes() as $index) {
      $query = $index->query(['offset' => 0, 'limit' => $limit]);
      $query->addCondition('file_uuid', $file->uuid())
        ->addCondition('search_api_datasource', $datasource_id)
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
            $query = $query->getOriginalQuery();
            $query->range($limit * $i, $limit);
            $results = $query->execute();
            $newcount = $results->getResultCount();
          }
        }
        // Untrack after all possible query calls with offsets.
        if (count($tracked_ids) > 0) {
          $index->trackItemsDeleted($datasource_id, $tracked_ids);
          $this->keyValue
            ->get(StrawberryfieldFlavorDatasource::SBFL_KEY_COLLECTION)
            ->deleteMultiple($tracked_ids);
        }
      }
      catch (SearchApiException $searchApiException) {
        watchdog_exception('strawberryfield', $searchApiException, 'We could not untrack Strawberry Flavor Documents from Index because the Solr Query returned an exception at server level.');
      }
    }
    return $tracked_ids;
  }

  /**
   * Track SBF documents to be updated on Search Api.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to delete flavors contextually.
   * @param array $deleted_ids
   *   A list of IDs already tracked to be deleted
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function trackFlavorsNeedUpdate(EntityInterface $entity, array $deleted_ids) {
    $datasource_id = 'strawberryfield_flavor_datasource';
    $limit = 200;
    $parent_entity_index_needs_update = FALSE;
    foreach (StrawberryfieldFlavorDatasource::getValidIndexes() as $index) {
      $query = $index->query(['offset' => 0, 'limit' => $limit]);
      $query->addCondition('search_api_datasource', $datasource_id)
        ->addCondition('uuid', $entity->uuid());
      //$query->createConditionGroup()
      $query->setOption('search_api_retrieved_field_values', ['id']);
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

        // Update after all possible query calls with offsets.
        $tracked_ids = array_diff($tracked_ids, $deleted_ids);
        if (count($tracked_ids) > 0) {
          $parent_entity_index_needs_update = TRUE;
          $index->trackItemsUpdated($datasource_id, $tracked_ids);
        }
      }
      catch (SearchApiException $searchApiException) {
        watchdog_exception('strawberryfield', $searchApiException, 'We could not update tracking Strawberry Flavor Documents to Index because the Solr Query returned an exception at server level.');
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
        $langcode = [$referencedEntity->language()->getId()];
        $combine_id = function ($langcode) use ($entity_id) {
          return $entity_id . ':' . $langcode;
        };
        $updated_item_ids = array_map($combine_id, array_values($langcode));
        if (isset($indexes[$referencedEntity->getType()])) {
          foreach ($indexes[$referencedEntity->getType()] as $index) {
            $index->trackItemsUpdated('entity:node', $updated_item_ids);
          }
        }
      }
    }
  }

}
