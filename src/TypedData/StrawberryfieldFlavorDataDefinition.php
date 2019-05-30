<?php

namespace Drupal\strawberryfield\TypedData;

use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\Core\TypedData\DataDefinition;

/**
 * A typed data definition class for describing widgets.
 */
class StrawberryfieldFlavorDataDefinition extends ComplexDataDefinitionBase {
  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {

    if(!isset($this->propertyDefinitions)){

      $info = &$this->propertyDefinitions;
      $info['page_id'] = DataDefinition::create('string')->setLabel('Page ID');
      $info['parent_id'] = DataDefinition::create('string')->setLabel('Parent ID');
      $info['fulltext'] = DataDefinition::create('string')->setLabel('FullText test');
      //§/ required by Content Access processor , maybe we can disable it in some manner
      $info['status'] = DataDefinition::create('boolean')->setLabel('Status');
      $info['uid'] = DataDefinition::create('integer')->setLabel('UID');

    }
    return $this->propertyDefinitions;
  }

}
