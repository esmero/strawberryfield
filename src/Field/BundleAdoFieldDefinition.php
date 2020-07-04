<?php

namespace Drupal\strawberryfield\Field;

use Drupal\Core\Field\BaseFieldDefinition;

/**
* A class for Bundled entity fields.
*/
class BundleAdoFieldDefinition extends BaseFieldDefinition {

  public function isBaseField() {
    return FALSE;

  }
}
