<?php

namespace Drupal\strawberryfield;

use Drupal\Core\Field\BaseFieldDefinition;

/**
 * A custom field storage definition class for Bundles.
 *
 * @todo Provide and make use of a proper FieldStorageDefinition class instead:
 *   https://www.drupal.org/node/2280639.
 */
class BundleFieldStorageDefinition extends BaseFieldDefinition {

  /**
   * {@inheritdoc}
   */
  public function isBaseField() {
    return FALSE;
  }

}
