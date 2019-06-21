<?php

namespace Drupal\strawberryfield\Field;

use Drupal\file\Plugin\Field\FieldType\FileFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;


class StrawberryFieldFileComputedItemList extends FileFieldItemList {

  use ComputedItemListTrait;

  protected function computeValue() {
    dpm($this->getEntity()->getFields(FALSE));
    // @TODO Only compute values if this applies to a SBF bearing entity
    // THIS IS JUST STUB to explain how we are going to allow compute to
    // EDIT the SBF
    $test_entities = [1,2,3,4,5];
    foreach ($test_entities as $delta => $id) {
      $this->list[$delta] = $this->createItem($delta, $id);
    }
  }
}
