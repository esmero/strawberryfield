<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:21 PM
 */
namespace Drupal\strawberryfield\Plugin\DataType;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\DataReferenceDefinition;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;


class StrawberryEntitiesViaJmesPathFromJson extends StrawberryValuesViaJmesPathFromJson {
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
    if ($this->processed == NULL) {
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
    $item = $this->getParent();
    if (!empty($item->value)) {
      $node_entities = [];
      /* @var $item StrawberryFieldItem */
      $definition = $this->getDataDefinition();
      // This key is passed by the property definition in the field class
      // jsonkey in this context is a string containing one or more
      // jmespath's separated by comma.
      $jmespaths = $definition['settings']['jsonkey'];
      $jmespath_array = array_map('trim', explode(',', $jmespaths));
      $jmespath_result = [];
      foreach ($jmespath_array as $jmespath) {
        $jmespath_result[] = $item->searchPath(trim($jmespath),FALSE);
      }
        foreach ($jmespath_result as $nodeid) {
          $item_values = (array) $nodeid;
            if (StrawberryfieldJsonHelper::arrayIsMultiSimple($item_values) === false) {
            $node_entities = array_merge(
                $node_entities,
                $item_values
              );
            }
        }

      $this->processed = array_values($node_entities);
      $delta = 0;
      foreach ($this->processed as $reference) {

       // No way we can use  $this->createItem($delta, $reference); here
       // Because our public facing datatype is not what it seems
       // We can not use DataReferenceDefinitions here, we need actually EntityAdapter!
       // Because \Drupal\search_api\Utility\FieldsHelper::extractFields can only act
       // on Complexdatainterface elements
       // Entity Adapter on the other side is one we can use
       // Solution: we create datareferences and we call getTarget to get the actual
        // entityAdapter which we add into the list
        // That way Search API is able to get the Values.
        // Hackish! But genius?
        if (is_scalar($reference)) {
          $target_id_definition = DataReferenceDefinition::create('entity')
            ->setTargetDefinition(EntityDataDefinition::create('node'));
          $thing = $this->typedDataManager->create($target_id_definition);
          // No parent, so don't notify
          $thing->setValue($reference, FALSE);
          if ($thing->getTarget() instanceof ComplexDataInterface) {
            $delta++;
            $this->list[$delta] = $thing->getTarget();
          }
        }
      }
    }
    else {
      $this->processed = [];
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

