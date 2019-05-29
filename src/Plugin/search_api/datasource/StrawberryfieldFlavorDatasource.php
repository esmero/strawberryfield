<?php

namespace Drupal\strawberryfield\Plugin\search_api\datasource;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;

use Drupal\strawberryfield\Plugin\DataType\StrawberryfieldFlavorData;
use Drupal\strawberryfield\TypedData\StrawberryfieldFlavorDataDefinition;

/**
 * Represents a datasource which exposes flavors.
 *
 * @SearchApiDatasource(
 *   id = "strawberryfield_flavor_datasource",
 *   label = @Translation("Strawberryfield Flavor Datasource"),
 * )
 */
class StrawberryfieldFlavorDatasource extends DatasourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    return \Drupal::typedDataManager()->createDataDefinition('strawberryfield_flavor_data')->getPropertyDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function getItemId(ComplexDataInterface $item) {
    $values = $item->get('page_id')->getValue();
    return $values ?: NULL;
  }
  public function getItemIds($page = NULL) {
    $ids = ["1","2","3","4"];
    return $ids;
  }

  /**
   * {@inheritdoc}
   */

  public function loadMultiple(array $ids) {
    $documents = [];
    $sbfflavordata_definition = StrawberryfieldFlavorDataDefinition::create('strawberryfield_flavor_data');

    foreach($ids as $id){
      $data = [
        'page_id' => $id,
        'parent_id' => '1',
        'fulltext' => 'Start' . $id . 'End',
     ];
     $documents[$id] = \Drupal::typedDataManager()->create($sbfflavordata_definition);
     $documents[$id]->setValue($data);

    }

    return $documents;
  }
}
