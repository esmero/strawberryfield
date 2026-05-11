<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:21 PM
 */
namespace Drupal\strawberryfield\Plugin\DataType;
use DateTime;
use Drupal\Component\Datetime\DateTimePlus;
use Drupal\Core\TypedData\Plugin\DataType\ItemList;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
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
    if (!empty($item->getValue())) {
      $definition = $this->getDataDefinition();
      // This key is passed by the property definition in the field class
      // jsonkey in this context is a string containing one or more
      // jmespath's separated by comma.
      $jmespaths = $definition['settings']['jsonkey'] ?? '';
      $is_date = $definition['settings']['is_date'] ?? FALSE;
      $is_date_range = $definition['settings']['is_date_range'] ?? FALSE;
      $pattern = '/[,]+(?![^\[]*\]|[^\(]*\)|[^\{]*\})/';
      $jmespaths_split = preg_split($pattern, $jmespaths);
      $jmespath_array = [];
      if ($jmespaths_split && is_array($jmespaths_split) && count($jmespaths_split) > 0 ) {
        $jmespath_array = array_map('trim', $jmespaths_split);
      }
      else {
        // Regular expression for splitting failed. Return silently.
        return;
      }
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
      if ($is_date && !$is_date_range) {
        $values = $this->processDatesFromValues($values);
        $values = array_filter($values, function($value) {
          // Only filter out nulls, empties  and FALSE. Keep 0
          return ($value !== NULL && $value !== '' &&  $value !== FALSE);
        });
      }
      elseif ($is_date_range) {
        $values = $this->processDateRangesFromValues($values);
        foreach ($values as &$value) {
          //$value will be associative with
          // value => "2025-06-01T00:00:00-04:00"
          // end_value => "2025-06-01T23:59:59-04:00"
          if (empty($value['value'] ?? NULL) || empty($value['end_value'] ?? NULL)) {
            $value = NULL;
          }
        }
        $values = array_filter($values);
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

  protected function processDatesFromValues($values):array {
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
                $min = date(DATE_ATOM, $element->getMinAsUnixTimestamp());
                $max = date(DATE_ATOM, $element->getMaxAsUnixTimestamp());
                if ($min && $this->validateDateAsDrupal($min)) {
                  $values_parsed[] = $min;
                }
                if ($max && $this->validateDateAsDrupal($max)) {
                  $values_parsed[] = $max;
                }
                break;
              default:
                // Make sure we do not index same day twice
                $start_day = date('Y-m-d', $element->getMinAsUnixTimestamp());
                $end_day = date('Y-m-d', $element->getMaxAsUnixTimestamp());
                if ($start_day === $end_day) {
                  // if this is the same day just index one.
                  $sameday = date(DATE_ATOM,  $element->getMinAsUnixTimestamp());
                  if ($sameday && $this->validateDateAsDrupal($sameday)) {
                    $values_parsed[] = $sameday;
                  }
                }
                else {
                  $min = date(DATE_ATOM, $element->getMinAsUnixTimestamp());
                  $max = date(DATE_ATOM, $element->getMaxAsUnixTimestamp());
                  if ($min && $this->validateDateAsDrupal($min)) {
                    $values_parsed[] = $min;
                  }
                  if ($max && $this->validateDateAsDrupal($max)) {
                    $values_parsed[] = $max;
                  }
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
                $min = date(DATE_ATOM, $edtf_value->getMin());
                if ($min && $this->validateDateAsDrupal($min)) {
                  $values_parsed[] = $min;
                }
              }
              if ($edtf_value->hasEndDate()) {
                $max = date(DATE_ATOM, $edtf_value->getMax());
                if ($max && $this->validateDateAsDrupal($max)) {
                  $values_parsed[] = $max;
                }
              }
              break;
            default:
              // Make sure we do not index same day twice
              $start_day = date('Y-m-d', $edtf_value->getMin());
              $end_day = date('Y-m-d', $edtf_value->getMax());
              if ($start_day === $end_day) {
                // if this is the same day just index one.
                $sameday = date(DATE_ATOM, $edtf_value->getMin());
                if ($sameday && $this->validateDateAsDrupal($sameday)) {
                  $values_parsed[] = $sameday;
                }
              } else {
                $min = date(DATE_ATOM, $edtf_value->getMin());
                $max = date(DATE_ATOM, $edtf_value->getMax());
                if ($min && $this->validateDateAsDrupal($min)) {
                  $values_parsed[] = $min;
                }
                if ($max && $this->validateDateAsDrupal($max)) {
                  $values_parsed[] = $max;
                }
              }
              break;
          }
        }
      }
      else {
        // If not EDTF (e.g an already ISO8601 date)
        // try with string based parsing
        $parsed_from_string = $this->parseStringToDate($value);
        if ($parsed_from_string && $this->validateDateAsDrupal($parsed_from_string)) {
          $values_parsed[] = $parsed_from_string;
        }
      }
    }
    $values = array_unique($values_parsed);
    $values = array_filter(array_values($values));
    return $values;
  }

  protected function processDateRangesFromValues($values) {
    $values_parsed = [];
    $parser = EdtfFactory::newParser();
    // Setup the map data type
    $data_range_ref = MapDataDefinition::create();
    $data_range_ref->setPropertyDefinition('value', DataDefinition::create('datetime_iso8601'));
    $data_range_ref->setPropertyDefinition('end_value', DataDefinition::create('datetime_iso8601'));
    $data_range_ref->setMainPropertyName('value');

    foreach ($values as $value) {
      $result = $parser->parse($value);
      if ($result->isValid()) {
        $edtf_value = $result->getEdtfValue();
        // @todo remove once EDTF fixes their invalid Constructor for EDTF\Model\Interval that should per interface never allow NULL for start nor end date
        if (get_class($edtf_value) == "EDTF\Model\Set") {
          // means we have something like [1977, 1984/2023] or {1977, 1984/2023}
          // and each entry needs to be processed like individual elements
          foreach ($edtf_value->getElements() as $element) {
            $new_date_range = [];
            $new_date_range['value'] = date(DATE_ATOM, $element->getMinAsUnixTimestamp());
            $new_date_range['end_value'] = date(DATE_ATOM, $element->getMaxAsUnixTimestamp());
            if ($new_date_range['value'] && $new_date_range['end_value'] && $this->validateDateAsDrupal($new_date_range['value']) && $this->validateDateAsDrupal($new_date_range['end_value'])) {
              $values_parsed[] = $this->getTypedDataManager()
                ->create($data_range_ref, $new_date_range)
                ->getValue();
            }
          }
        }
        else {
          //single entries.
          switch (get_class($edtf_value)) {
            case "EDTF\Model\Interval":
              // Skip any Interval that is Open?
              if ($edtf_value->hasStartDate() && $edtf_value->hasEndDate()) {
                $new_date_range = [];
                $new_date_range['value'] = date(DATE_ATOM, $edtf_value->getMin());
                $new_date_range['end_value'] = date(DATE_ATOM, $edtf_value->getMax());
                if ($new_date_range['value'] && $new_date_range['end_value'] && $this->validateDateAsDrupal($new_date_range['value']) && $this->validateDateAsDrupal($new_date_range['end_value'])) {
                  $values_parsed[] = $this->getTypedDataManager()
                    ->create($data_range_ref, $new_date_range)
                    ->getValue();
                }
              }
              break;
            default:
              $new_date_range = [];
              $new_date_range['value'] = date(DATE_ATOM, $edtf_value->getMin());
              $new_date_range['end_value'] = date(DATE_ATOM, $edtf_value->getMax());
              if ($new_date_range['value'] && $new_date_range['end_value'] && $this->validateDateAsDrupal($new_date_range['value']) && $this->validateDateAsDrupal($new_date_range['end_value'])) {
                $values_parsed[] = $this->getTypedDataManager()
                  ->create($data_range_ref, $new_date_range)
                  ->getValue();
              }
              break;
          }
        }
      }
      else {
        // If not EDTF (e.g an already ISO8601 date)
        // try with string based parsing

        $parsed_from_string = $this->parseStringToDate($value);
        if ($parsed_from_string) {
          $result = $parser->parse($value);
          if ($result->isValid()) {
            // Single Dates/ISO8601 will generate a standard EDTF Object
            $edtf_value = $result->getEdtfValue();
            $new_date_range = [];
            $new_date_range['value'] = date(DATE_ATOM, $edtf_value->getMin());
            $new_date_range['end_value'] = date(DATE_ATOM, $edtf_value->getMax());
            if ($new_date_range['value'] && $new_date_range['end_value'] && $this->validateDateAsDrupal($new_date_range['value']) && $this->validateDateAsDrupal($new_date_range['end_value'])) {
              $values_parsed[] = $this->getTypedDataManager()
                ->create($data_range_ref, $new_date_range)
                ->getValue();
            }
          }
        }
      }
    }
    // Really not needed?
    return $values_parsed;
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
    $d = DateTime::createFromFormat(DATE_ATOM, $date);
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
      return $d->format(DATE_ATOM);
    }
    return FALSE;
  }

  /**
   * Validates if the Date (even if already marked as valid PHP) is
   * indexable by Drupal by running the same DateTimePlus casting that happens
   * in the search API
   *
   * @see \Drupal\search_api\Plugin\search_api\data_type\DateDataType
   *
   * @param $value
   *    A pre parsed Date String.
   * @return bool
   *    TRUE if valid Drupal
   *    FALSE if not.
   *
   */
  protected function validateDateAsDrupal($value): bool {
    $timezone = new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE);
    $date = new DateTimePlus($value, $timezone);
    // Check for invalid datetime strings.
    if ($date->hasErrors()) {
      $node_id =  $this->getParent()->getEntity()->id();
      foreach ($date->getErrors() as $error) {
         $args = [
          '@value' => $value,
          '@error' => $error,
          '@node' => $node_id
        ];
        // @TODO: because this is a deeper itemlist, injecting the service might lead
        // to a DB serialization issue. So for now we call the global container
        \Drupal::service('logger.channel.strawberryfield')->warning('Keyname Provider found and error while parsing date/time value "@value" into a valid Drupal date, for ADO with Node ID: @node with error: @error. It might be a date in the distant future or past and still e.g., a valid EDTF, but sadly not indexable by Drupal. We will skip this value.', $args);
      }
      return FALSE;
    }
    return TRUE;
  }

}
