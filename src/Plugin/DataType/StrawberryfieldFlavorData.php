<?php

namespace Drupal\strawberryfield\Plugin\DataType;

use Drupal\Core\TypedData\Plugin\DataType\Map;

/**
 * Defines the "StrawberryfieldFlavorData" data type.
 *
 * @DataType(
 *  id = "strawberryfield_flavor_data",
 *  label = @Translation("Strawberryfield Flavor Data"),
 *  definition_class = "\Drupal\strawberryfield\TypedData\StrawberryfieldFlavorDataDefinition",
 * )
 */
class StrawberryfieldFlavorData extends Map {

  /**
   * Gets the Parent referenced Node Entity or NULL of none.
   *
   * This should never return NULL, if so, the parent NODE does no longer exists
   * And we failed removing it via our events.
   *
   * @return \Drupal\node\NodeInterface|null
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function getParentNode() {
    if ($this->isEmpty()) {
      return NULL;
    }
    // Being over careful here to not call nested methods if
    // between results may be null. Better at least here than a try/catch.
    /** @var \Drupal\Core\Entity\Plugin\DataType\EntityReference|null $target_id */
    $target_id = $this->get('target_id');
    $target = $target_id ? $target_id->getTarget() : NULL;
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $target ? $target->getValue() : NULL;
    return $node;
  }

}
