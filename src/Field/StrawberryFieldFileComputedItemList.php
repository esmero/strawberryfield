<?php

namespace Drupal\strawberryfield\Field;

use Drupal\file\Plugin\Field\FieldType\FileFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;


class StrawberryFieldFileComputedItemList extends FileFieldItemList {

  use ComputedItemListTrait;

  protected function computeValue() {
    $sbf_fields = \Drupal::service('strawberryfield.utility')
      ->bearsStrawberryfield($this->getEntity());
    foreach ($sbf_fields as $field_name) {
      /* @var $field \Drupal\Core\Field\FieldItemInterface */
      $field = $this->getEntity()->get($field_name);
      if (!$field->isEmpty()) {
        foreach ($field->getIterator() as $delta => $itemfield) {
          // Note: we are not longer touching the metadata here.
          /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
          $flatvalues = (array) $itemfield->provideFlatten();
          if (isset($flatvalues['dr:fid']) && is_array(
              $flatvalues['dr:fid']
            ) && !empty($flatvalues['dr:fid'])) {
            $file_entities = array_filter($flatvalues['dr:fid'], 'is_int');
            foreach ($file_entities as $delta => $id) {
              $this->list[$delta] = $this->createItem($delta, $id);
            }
          }
        }
      }
    }
  }
}
