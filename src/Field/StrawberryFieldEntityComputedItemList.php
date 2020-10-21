<?php

namespace Drupal\strawberryfield\Field;

use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;


class StrawberryFieldEntityComputedItemList extends EntityReferenceFieldItemList {

  use ComputedItemListTrait;

  protected function computeValue() {
    $sbf_fields = \Drupal::service('strawberryfield.utility')
      ->bearsStrawberryfield($this->getEntity());
    foreach ($sbf_fields as $field_name) {
      /* @var $field \Drupal\Core\Field\FieldItemInterface */
      $field = $this->getEntity()->get($field_name);
      $node_entities = [];
      if (!$field->isEmpty()) {
        foreach ($field->getIterator() as $delta => $itemfield) {
          // Note: we are not longer touching the metadata here.
          /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
          $flatvalues = (array) $itemfield->provideFlatten();
          $values = (array) $itemfield->provideDecoded();
          // This is just for semantic consistency since we DO allow
          // dr:fid.
          if (isset($flatvalues['dr:nid']) && !empty($flatvalues['dr:nid'])) {
            $entity_ids = (array) $flatvalues['dr:nid'];
            $node_entities = array_filter($entity_ids, 'is_int');
          }
          // Also get mapped ones
          if (isset($values["ap:entitymapping"]["entity:node"]) &&
            !empty($values["ap:entitymapping"]["entity:node"])) {
            $jsonkeys_with_node_entities = (array) $values["ap:entitymapping"]["entity:node"];
            foreach ($jsonkeys_with_node_entities as $jsonkey_with_node_entity) {
              if (isset($values[$jsonkey_with_node_entity]) && !empty($values[$jsonkey_with_node_entity])) {
                $entity_ids = (array) $values[$jsonkey_with_node_entity];
                $node_entities = array_merge($node_entities, array_filter($entity_ids, 'is_int'));
              }
            }
          }
        }
        // Now see if we got entities
        // I will not deduplicate here since frequency could be a desired factor
        foreach ($node_entities as $index => $id) {
          $this->list[$index] = $this->createItem($index, $id);
        }
      }
    }
  }
}
