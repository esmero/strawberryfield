<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:21 PM
 */
namespace Drupal\strawberryfield\Plugin\DataType;
use DateTime;
use Drupal\Core\TypedData\Plugin\DataType\ItemList;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use EDTF\EdtfFactory;

class StrawberryValuesViaJmesPathFromJson extends ItemList {
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
      /* @var $item StrawberryFieldItem */
      $definition = $this->getDataDefinition();
      // This key is passed by the property definition in the field class
      // jsonkey in this context is a string containing one or more
      // jmespath's separated by comma.
      $jmespaths = $definition['settings']['jsonkey'];
      $is_date = $definition['settings']['is_date'] ?? FALSE;
      $jmespath_array = array_map('trim', explode(',', $jmespaths));
      $jmespath_result = [];
      foreach ($jmespath_array as $jmespath) {
        $jmespath_result[] = $item->searchPath(trim($jmespath),FALSE);
      }
      $jmespath_result_to_expose = [];
      foreach ($jmespath_result as $item) {
        if (is_array($item)) {
          if (StrawberryfieldJsonHelper::arrayIsMultiSimple($item)) {
            // @TODO should we allow unicode directly?
            // If its multidimensional simple json encode as a string.
            // We could also just get the first order values?
            // @TODO, ask the team.
            $jmespath_result_to_expose[] = json_encode($item, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          }
          else {
            $jmespath_result_to_expose = array_merge($jmespath_result_to_expose, $item);
          }
        }
        else {
          // If a single value, simply cast to array
          $jmespath_result_to_expose[] = $item;
        }
      }
      // This is an array, don't double nest to make the normalizer happy.
      foreach($jmespath_result_to_expose as $i => &$v) {
        if(is_array($v) or is_object($v)) {
          $v = json_encode($v);
        }
        elseif (is_string($v)) {
          $v = trim($v);
        }
      }
      $values = array_filter($jmespath_result_to_expose, function($value) {
        // Only filter out nulls and empties. Keep FALSE/and 0
        return ($value !== NULL && $value !== '');
      });
      $values = array_map('stripslashes', $values);
      if ($is_date) {
        $values_parsed = [];
        $parser = EdtfFactory::newParser();
        foreach ($values as $value) {
          $result = $parser->parse($value);
          if ($result->isValid()) {
            $edtf_value = $result->getEdtfValue();
            // @todo remove once EDTF fixes their invalid Constructor for EDTF\Model\Interval that should per interface never allow NULL for start nor end date
            if (get_class($edtf_value) == "EDTF\Model\Set") {
              //means we have something like [1977, 1984/2023] or {1977, 1984/2023}
              // and each entry needs to be processed like individual elements
              foreach ($edtf_value->getElements() as $element) {
                switch(get_class($element)) {
                  case "EDTF\Model\SetElement\RangeSetElement":
                    $values_parsed[] = date('c', $element->getMinAsUnixTimestamp());
                    $values_parsed[] = date('c', $element->getMaxAsUnixTimestamp());
                    break;
                  default:
                    // Make sure we do not index same day twice
                    $start_day = date('Y-m-d', $element->getMinAsUnixTimestamp());
                    $end_day = date('Y-m-d', $element->getMaxAsUnixTimestamp());
                    if ($start_day === $end_day) {
                      // if this is the same day just index one.
                      $values_parsed[] = date('c',  $element->getMinAsUnixTimestamp());
                    }
                    else {
                      $values_parsed[] = date('c', $element->getMinAsUnixTimestamp());
                      $values_parsed[] = date('c', $element->getMaxAsUnixTimestamp());
                    }
                    break;
                }
              }
            }
            else {
              //single entries.
              switch (get_class($edtf_value)) {
                case "EDTF\Model\Interval":
                  if ($edtf_value->hasStartDate()) {
                    $values_parsed[] = date('c', $edtf_value->getMin());
                  }
                  if ($edtf_value->hasEndDate()) {
                    $values_parsed[] = date('c', $edtf_value->getMax());
                  }
                  break;
                default:
                  // Make sure we do not index same day twice
                  $start_day = date('Y-m-d', $edtf_value->getMin());
                  $end_day = date('Y-m-d', $edtf_value->getMax());
                  if ($start_day === $end_day) {
                    // if this is the same day just index one.
                    $values_parsed[] = date('c', $edtf_value->getMin());
                  } else {
                    $values_parsed[] = date('c', $edtf_value->getMin());
                    $values_parsed[] = date('c', $edtf_value->getMax());
                  }
                  break;
              }
            }
          }
          else {
            // If not EDTF (e.g an already ISO8601 date)
            // try with string based parsing
            $parsed_from_string = $this->parseStringToDate($value);
            $values_parsed[] = $parsed_from_string ? $parsed_from_string: NULL;
          }
        }
        $values = array_unique($values_parsed);
        $values = array_filter(array_values($values));
      }
      $this->processed = array_values($values);
      $this->list = [];
      foreach ($this->processed as $delta => $item) {
        $this->list[$delta] = $this->createItem($delta, $item);
      }
    }
    else {
      $this->processed = [];
      $this->list = [];
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
    return !empty($this->list[$index]) ? $this->list[$index] : NULL;
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

  /**
   * Will try to parse an unknown string to an ISO8601 date.
   *
   * @param mixed $date
   *
   * @return false|string
   *    If string/int could not be parse returns false.
   *    If it was possible, return an ISO8601 date.
   */
  protected function parseStringToDate($date) {
    // Start by using a full ISO8601 date in case time zone is included
    $d = DateTime::createFromFormat('c', $date);
    if (!$d) {
      // If not check if its not a timestamp
      if (!is_numeric($date)) {
        $date = strtotime($date);
      }
      if ($date) {
        $d = DateTime::createFromFormat('U', $date);
      }
    }
    if ($d) {
      return $d->format('c');
    }
    return FALSE;
  }
}
