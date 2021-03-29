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
      $info['sequence_total'] = DataDefinition::create('string')->setLabel('Expected total sequence count');
      $info['uri'] = DataDefinition::create('string')->setLabel('A source or related Uri/URL');
      $info['checksum'] = DataDefinition::create('string')->setLabel('Checksum that can be used to check if the source needs reprocessing');
      $info['processor_id'] = DataDefinition::create('string')->setLabel('Processor Plugin id that populated this data');
      $info['config_processor_id'] = DataDefinition::create('string')->setLabel('Processor Config id that populated this data');
      $info['file_uuid'] = DataDefinition::create('string')->setLabel('Source File UUID (first one passed in a Post Processor chain)');
      $info['parent_id'] = DataReferenceTargetDefinition::create('integer')->setLabel('Parent Node ID');
      $info['target_id'] = DataReferenceDefinition::create('entity')
        ->setLabel('Parent Node')
        ->setComputed(TRUE)
        ->setReadOnly(FALSE)
        ->setTargetDefinition(EntityDataDefinition::create('node'))
        ->addConstraint('EntityType', 'node');
      $info['target_fileid'] = DataReferenceDefinition::create('entity')
        ->setLabel('Parent File')
        ->setComputed(TRUE)
        ->setReadOnly(FALSE)
        ->setTargetDefinition(EntityDataDefinition::create('file'))
        ->addConstraint('EntityType', 'file');
      $info['fulltext'] = DataDefinition::create('string')->setLabel('Unmodified data body');
      $info['plaintext'] = DataDefinition::create('string')->setLabel('Data body containing un formatted text from fulltext');
      //§/ required by Content Access processor , maybe we can disable it in some manner
      $info['status'] = DataDefinition::create('boolean')->setLabel('Status');
      $info['uid'] = DataDefinition::create('integer')->setLabel('UID');
    }
    return $this->propertyDefinitions;
  }

}
