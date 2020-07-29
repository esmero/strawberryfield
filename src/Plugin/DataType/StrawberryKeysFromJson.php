<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:21 PM
 */

namespace Drupal\strawberryfield\Plugin\DataType;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedData;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\ListInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\TypedData\Plugin\DataType\ItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;


class StrawberryKeysFromJson extends ItemList {

  /**
   * Cached processed value.
   *
   * @var array|null
   */
  protected $processed = NULL;

  /**
   * Whether the values have already been computed or not.
   *
   * @var bool
   */

  protected $computed = FALSE;

  /**
   * Keyed array of items.
   *
   * @var \Drupal\Core\TypedData\TypedDataInterface[]
   */
  protected $list = [];

  public function getValue() {
    if ($this->computed == FALSE) {
      $this->process();
    }
    $values = [];
    foreach ($this->list as $delta => $item) {
      $values[$delta] = $item->getValue();
    }
    return $values;
  }

  /**
   * @param null $langcode
   *
   */
  public function process($langcode = NULL)
  {
    if ($this->computed == TRUE) {
      return;
    }
    $values = [];
    $item = $this->getParent();
    if (!empty($item->value)) {
      // Should 10 be enough? this is json-ld not github.. so maybe...
      $jsonArray = json_decode($item->value, TRUE, 50);
      //@TODO deal with JSON exceptions as we have done before

      $flattened = [];
      $flattened = StrawberryfieldJsonHelper::arrayToFlatJsonPropertypaths(
        $jsonArray
      );
      $this->processed = array_keys($flattened);
      $this->computed = TRUE;
      foreach ($this->processed as $delta => $item) {
        $this->list[$delta] = $this->createItem($delta, $item);
      }
    } else {
      $this->processed = NULL;
    }
    $this->computed = TRUE;
  }

  /**
   * Ensures that values are only computed once.
   */
  protected function ensureComputedValue() {
    if ($this->computed === FALSE) {
      $this->process();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    // Nothing to set
  }

  /**
   * {@inheritdoc}
   */
  public function getString() {
    $this->ensureComputedValue();
    return parent::getString();
  }

  /**
   * {@inheritdoc}
   */
  public function get($index) {
    if (!is_numeric($index)) {
      throw new \InvalidArgumentException('Unable to get a value with a non-numeric delta in a list.');
    }
    $this->ensureComputedValue();

    return isset($this->list[$index]) ? $this->list[$index] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function set($index, $value) {
    $this->ensureComputedValue();
    return parent::set($index, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function appendItem($value = NULL) {
    $this->ensureComputedValue();
    return parent::appendItem($value);
  }

  /**
   * {@inheritdoc}
   */
  public function removeItem($index) {
    $this->ensureComputedValue();
    return parent::removeItem($index);
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $this->ensureComputedValue();
    return parent::isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function offsetExists($offset) {
    $this->ensureComputedValue();
    return parent::offsetExists($offset);
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator() {
    $this->ensureComputedValue();
    return parent::getIterator();
  }

  /**
   * {@inheritdoc}
   */
  public function count() {
    $this->ensureComputedValue();
    return parent::count();
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE) {
    return $this;
  }


}