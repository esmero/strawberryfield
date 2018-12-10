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

//@TODO refactor to same as \Drupal\strawberryfield\Plugin\DataType\StrawberryKeysFromJson
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
    $jsonArray = json_decode($item->value,true,10);

    $definition = $this->getDataDefinition();

    // This key is passed by the property definition in the field class
    $needle = $definition['settings']['jsonkey'];

    $flattened = [];
    StrawberryfieldJsonHelper::arrayToFlatCommonkeys($jsonArray,$flattened, TRUE );

    // @TODO, see if we need to quote everything
    if (isset($flattened[$needle])){
      $values[] = implode(",", $flattened[$needle]);
    }
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

    return $this->processed;
  }

}