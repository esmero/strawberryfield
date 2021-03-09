<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
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
  public function onEntitySave(StrawberryfieldCrudEvent $event) {
    // We need to compare the original entity with the saved just now as file
    // events don't really work for this use case. The file is still attached to
    // the revisions. This event is run only on update, so we're not treating
    // the isNew() possibility here.
    $entity = $event->getEntity();
    $original_entity = $entity->original;
    $sbf_fields = $event->getFields();
    $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();

    // We only care about the original entity ones, so that's the system of
    // record to compare. If $files_deleted is empty, it means new files, or no
    // deletions so we don't care.
    $entity_documents = $this->getDocuments($entity, $sbf_fields);
    $original_entity_documents = $this->getDocuments($original_entity, $sbf_fields);
    $files_deleted = array_diff($original_entity_documents, $entity_documents);

    foreach ($files_deleted as $file_id) {
      $file = OcflHelper::resolvetoFIDtoURI($file_id);
      // This is very important as we need to compare with the CURRENT revision,
      // default looks for historical so there will be always references. In any
      // case, in the unlikely event that this same file is reused accross the
      // system, we bail out.
      $references = file_get_file_references($file, NULL, EntityStorageInterface::FIELD_LOAD_CURRENT);
      if (empty($references)) {
        $this->trackDocumentsDeleted($indexes, $file);
      }
    }
  }

  /**
   * Gets the documents from an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param array $sbf_fields
   *   Array of SBF fields.
   *
   * @return array
   *   Array of document ids belonging to the entity.
   */
  protected function getDocuments(EntityInterface $entity, array $sbf_fields) {
    $documents = [];
    // @TODO get this from the field config instead?
    $key = 'as:document';
    foreach ($sbf_fields as $field_name) {
      /** @var \Drupal\strawberryfield\Field\StrawberryFieldItemList $field */
      $field = $entity->get($field_name);
      if (!$field->isEmpty()) {
        foreach ($field->getIterator() as $delta => $item) {
          // @TODO we could benefit of a getFile method on the SBF or at least
          // a static.
          $jsondata = json_decode($item->value, true);
          if (!empty($jsondata[$key])) {
            foreach ($jsondata[$key] as $mediaitem) {
              if (isset($mediaitem['type']) && $mediaitem['type'] == 'Document') {
                if (isset($mediaitem['dr:fid'])) {
                  $documents[$mediaitem['dr:fid']] = $mediaitem['dr:fid'];
                }
              }
            }
          }
        }
      }
    }

    return $documents;
  }

  /**
   * Deletes the documents tracked on Search Api.
   *
   * @param \Drupal\search_api\IndexInterface[] $indexes
   *   Array of contextual Search Api indexes for SBF.
   * @param \Drupal\file\FileInterface $file
   *   The file associated to the document.
   */
  protected function trackDocumentsDeleted(array $indexes, FileInterface $file) {
    $datasource_id = 'strawberryfield_flavor_datasource';
    foreach ($indexes as $index) {
      $query = $index->query(['offset' => 0]);
      $query->addCondition('file_uuid', $file->uuid())
        ->addCondition('search_api_datasource', $datasource_id)
        ->addCondition('processor_id', 'ocr');
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
