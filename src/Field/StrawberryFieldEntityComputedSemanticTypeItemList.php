<?php

namespace Drupal\strawberryfield\Field;

use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\Core\Field\FieldItemList;
class StrawberryFieldEntityComputedSemanticTypeItemList extends FieldItemList {


  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    $this->ensurePopulated();
  }

  /**
   * Computes the calculated values for this item list.
   *
   * In this example, there is only a single item/delta for this field.
   *
   * The ComputedItemListTrait only calls this once on the same instance; from
   * then on, the value is automatically cached in $this->items, for use by
   * methods like getValue().
   */
  protected function ensurePopulated() {
    if (!isset($this->list[0])) {
      $sbf_fields = \Drupal::service('strawberryfield.utility')->bearsStrawberryfield($this->getEntity());
      foreach ($sbf_fields as $field_name) {
        /** @var \Drupal\strawberryfield\Field\StrawberryFieldItemList $field */
        $field = $this->getEntity()->get($field_name);
        if (!$field->isEmpty()) {
          foreach ($field->getIterator() as $itemfield) {
            // Note: we are not longer touching the metadata here.
            /** @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $itemfield */
            $flatvalues = (array) $itemfield->provideFlatten();
            // @TODO use future flatversion precomputed at field level as a property
            $json_error = json_last_error();
            $sbf_type = [];
            if (isset($flatvalues['type'])) {
              $sbf_type = (array) $flatvalues['type'];
              $sbf_type = array_filter($sbf_type, 'is_string');
            }


              foreach ($sbf_type as $delta => $type) {
                $this->list[$delta] = $this->createItem($delta,$type );
              }
            }
          }
        }
      }

  }
}