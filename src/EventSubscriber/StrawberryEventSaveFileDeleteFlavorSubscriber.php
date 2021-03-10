<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\Core\Entity\EntityInterface;
use Drupal\file\FileInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;

/**
 * Event subscriber for SBF bearing entity json process event.
 */
class StrawberryEventSaveFileDeleteFlavorSubscriber extends StrawberryfieldEventSaveSubscriber {

  /**
   * {@inheritdoc}
   */
  protected static $priority = 100;

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
      $this->trackFilesDeleted($entity, $file);
    }
  }

  /**
   * Deletes the documents tracked on Search Api.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to delete flavors contextually.
   * @param \Drupal\file\FileInterface $file
   *   The file associated to the entity.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function trackFilesDeleted(EntityInterface $entity, FileInterface $file) {
    $datasource_id = 'strawberryfield_flavor_datasource';
    foreach (StrawberryfieldFlavorDatasource::getValidIndexes() as $index) {
      $query = $index->query(['offset' => 0]);
      $query->addCondition('file_uuid', $file->uuid())
        ->addCondition('search_api_datasource', $datasource_id)
        ->addCondition('uuid', $entity->uuid());
      $results = $query->execute();

      if ($results->getResultCount() > 0) {
        $tracked_ids = [];
        foreach ($results->getResultItems() as $item) {
          // The tracker methods above prepend the datasource id, so we need to
          // workaround it by removing it beforehand.
          [$unused, $raw_id] = Utility::splitCombinedId($item->getId());
          $tracked_ids[] = $raw_id;
        }
        $index->trackItemsDeleted($datasource_id, $tracked_ids);
      }
    }
  }

}
