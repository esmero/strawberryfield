<?php

namespace Drupal\strawberryfield\Plugin\search_api\datasource;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;

use Drupal\strawberryfield\Plugin\DataType\SBFWidget;
use Drupal\strawberryfield\TypedData\SBFWidgetDefinition;

/**
 * Represents a datasource which exposes widgets.
 *
 * @SearchApiDatasource(
 *   id = "strawberryfield_test_widget",
 *   label = @Translation("SBFWidgets"),
 * )
 */
class SBFWidgetDatasource extends DatasourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    return \Drupal::typedDataManager()->createDataDefinition('strawberryfield_test_widget')->getPropertyDefinitions();
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
    $sbfwidget_definition = SBFWidgetDefinition::create('strawberryfield_test_widget');

    foreach($ids as $id){
      $data = [
        'page_id' => $id,
        'parent_id' => '1',
        'fulltext' => 'Start' . $id . 'End',
     ];
     $documents[$id] = \Drupal::typedDataManager()->create($sbfwidget_definition);
     $documents[$id]->setValue($data);

    }


//    $id = "1";
//    $data = [
//      'page_id' => '1',
//      'parent_id' => '1',
//      'fulltext' => 'Start 1 End',
//   ];

//    $documents[$id] = \Drupal::typedDataManager()->create($sbfwidget_definition);
//    $documents[$id]->setValue($data);

    return $documents;
  }
}
