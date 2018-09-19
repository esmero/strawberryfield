<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:21 PM
 */

namespace Drupal\strawberryfield\Plugin\DataType;

use Drupal\Core\TypedData\TypedData;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;


class StrawberryDataByKeyProvider extends TypedData
{

  /**
   * Cached processed value.
   *
   * @var string|null
   */
  protected $processed = NULL;

  /**
   * Implements \Drupal\Core\TypedData\TypedDataInterface::getValue().
   */
  public function getValue($langcode = NULL)
  {
    if ($this->processed !== NULL) {
      return $this->processed;
    }
    $values = [];
    $item = $this->getParent();
    // Should 10 be enough? this is json-ld not github.. so maybe...
    $json = json_decode($item->value,true,10);

    $definition = $this->getDataDefinition();

    // This key is passed by the property definition in the field class
    $needle = $definition['settings']['jsonkey'];

    $flattened = [];
    // BY reference it fills @var $flattened with a shallow json
    StrawberryfieldJsonHelper::jsonFlattener($json, $flattened);

    foreach ($flattened as $graphitems) {
      if (isset($graphitems[$needle])) {
        if (is_array($graphitems[$needle])) {
          $values[] = implode(",", $graphitems[$needle]);
        }
        else {
          $values[] = $graphitems[$needle];
        }
      }

    }
    // Right now we are just joining all values in a large string
    $this->processed = implode(",", $values);
    // @TODO refactor this whole class to be a ItemList.
    // @TODO refactor solr to be able to process MapData Type

    return $this->processed;
  }

}