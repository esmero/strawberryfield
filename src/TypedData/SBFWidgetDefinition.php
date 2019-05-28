<?php

namespace Drupal\strawberryfield\TypedData;

use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\Core\TypedData\DataDefinition;

/**
 * A typed data definition class for describing widgets.
 */
class SBFWidgetDefinition extends ComplexDataDefinitionBase {
  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {

    if(!isset($this->propertyDefinitions)){
      $info = &$this->propertyDefinitions;

      $info['page_id'] = DataDefinition::create('string')->setLabel('Page ID');
      $info['parent_id'] = DataDefinition::create('string')->setLabel('Parent ID');
      $info['fulltext'] = DataDefinition::create('string')->setLabel('FullText test');
    }

/**
    $this->propertyDefinitions['page_id'] = \Drupal::typedDataManager()
      ->createListDataDefinition('string')
      ->setLabel('Page ID');
    $this->propertyDefinitions['parent_id'] = \Drupal::typedDataManager()
      ->createListDataDefinition('string')
      ->setLabel('Parent ID');
    $this->propertyDefinitions['fulltext'] = \Drupal::typedDataManager()
      ->createListDataDefinition('string')
      ->setLabel('SBF Full-text');
*/

    return $this->propertyDefinitions;
  }

}
