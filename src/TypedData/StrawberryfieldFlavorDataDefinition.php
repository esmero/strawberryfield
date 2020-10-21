<?php

namespace Drupal\strawberryfield\TypedData;

use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataReferenceTargetDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\TypedData\DataReferenceDefinition;

/**
 * A typed data definition class for describing SBF Flavor Data Sources .
 */
class StrawberryfieldFlavorDataDefinition extends ComplexDataDefinitionBase {
  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {

    if(!isset($this->propertyDefinitions)){
      $info = &$this->propertyDefinitions;
      $info['item_id'] = DataDefinition::create('string')->setLabel('Item ID');
      $info['sequence_id'] = DataDefinition::create('string')->setLabel('Sequence ID');
      $info['parent_id'] = DataReferenceTargetDefinition::create('integer')->setLabel('Parent Node ID');
      $info['target_id'] = DataReferenceDefinition::create('entity')
      ->setLabel('Parent Node')
      ->setComputed(TRUE)
      ->setReadOnly(FALSE)
      ->setTargetDefinition(EntityDataDefinition::create('node'))
      ->addConstraint('EntityType', 'node');
      $info['fulltext'] = DataDefinition::create('string')->setLabel('FullText test');
      //§/ required by Content Access processor , maybe we can disable it in some manner
      $info['status'] = DataDefinition::create('boolean')->setLabel('Status');
      $info['uid'] = DataDefinition::create('integer')->setLabel('UID');
    }
    return $this->propertyDefinitions;
  }

}
