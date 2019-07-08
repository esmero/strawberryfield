<?php

namespace Drupal\strawberryfield\Plugin\DataType;

use Drupal\Core\TypedData\Plugin\DataType\Map;
use Drupal\Component\Plugin\Exception\PluginException;
use Swaggest\JsonSchema\Schema as JsonSchema;
use Swaggest\JsonSchema\Exception as JsonSchemaException;
use Swaggest\JsonSchema\InvalidValue as JsonSchemaInvalidValue;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;

class StrawberryValuesFromFlavorJson extends Map {

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
   * Main JSON key for all flavours
   */
  const baseneedle = 'ap:flavours';


  /**
   * JSON SCHEMA Draft 7.0 for a SBF Flavor service
   */
  const acceptedjsonschema = <<<'JSON'
{
    "$id": "http://archipelago.nyc/jsonschemas/serviceflavor.json",
    "$schema": "http://json-schema.org/schema#",
    "type": "object",
    "properties": {
        "url": {
            "type": "string",
            "format" : "uri"
        },
        "source_url": {
            "type": "string",
            "format" : "iri"
        },
        "name": {
            "type": "string"
        },
        "type": {
            "type": "string",
            "const": "service"
        },
        "dr:uuid": {
            "type": "string",
            "pattern": "^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$"
            
        },
        "checksum": {
           "type": "string"
        },
        "crypHashFunc": {
          "enum": ["md5", "sha1", "sha256", "sha512"]
        }
    }
}
JSON;

  /**
   * @return array|mixed|null
   * @throws \Exception
   */
  public function getValue() {
    if ($this->processed == NULL) {
      $this->process();
    }
    // This is a Map, serializer expects always an iterable
    return !empty($this->processed) ? $this->processed: [] ;
  }


  /**
   * @param null $langcode
   *
   * @throws \Exception
   */
  public function process($langcode = NULL)
  {
    if ($this->computed == TRUE) {
      return;
    }

    $item = $this->getParent();

    if (!empty($item->value)) {
      /* @var $item StrawberryFieldItem */
      $flattened = $item->provideFlatten(FALSE);
      $definition = $this->getDataDefinition();
      // This key is passed by the property definition in the field class
      // e.g ap:hocr
      $needle = $definition['settings']['jsonkey'];
      // our needles are contained inside a baseneedle
      $baseneedle = 'ap:flavours';

      // A single flavor definition goes like this
      /*
      "ap:hocr": {
          "url": "http:\/\/localhost:8001\/hocrfromzip\/hocrfornode1",
          "name": "hocr endpoint",
          "source_url": "s3:\/\/allmyhocr-hash-for-node1.zip",
          "type": "service",
          "dr:uuid": "66768da6-6b34-4c2a-97be-8860a967af20",
          "checksum": "8ab4d172d740829b06bd81bc5911a32e",
          "crypHashFunc": "md5"
      }
      @see \Drupal\strawberryfield\Plugin\DataType\StrawberryfieldFlavorData::acceptedjsonschema
      */
      // @TODO make ap:flavours and ap:anything are part of the schema.
      if (isset($flattened[$baseneedle]) &&
        is_array($flattened[$baseneedle]) &&
        isset($flattened[$needle]) &&
        is_array($flattened[$needle])
      ) {
        $servicearray = $flattened[$needle];

        // This gives us the ID of a parent entity, if any.
        $entityid = 'Unknown';
        $parententityid = $item->getRoot();
        if ($parententityid instanceof EntityAdapter) {
          $entity = $parententityid->getValue();
          $entityid = $entity->id();
        }

        try {
          // @see https://github.com/swaggest/php-json-schema
          $schema = JsonSchema::import(
            json_decode($this::acceptedjsonschema)
          );
          $schema->in((Object) $servicearray);
        }
        catch (JsonSchemaException $exception) {
          //@TODO Give is error message a link to the NODE
          \Drupal::logger('strawberryfield flavour')->error('Wrong Flavor %needle definition in Entity ID %entityid, JSON Schema validation failed ', ['%needle' =>$needle ,'%entityid'  => $entityid]);
        }

        try {
          $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uuid' => $servicearray['dr:uuid']]);
          $file = $files ? current($files) : null;
        }
        catch (PluginException $exception) {
          \Drupal::logger('strawberryfield flavour')->error('Wrong Flavor %needle definition in Entity ID %entityid, JSON Schema validation failed ', ['%needle' =>$needle ,'%entityid'  => $entityid]);
          $file = null;
        }
        //@TODO: return really the full list of files inside the zip based on the manifest.
        //This is just a stub as we share code.
        $this->processed = !empty($servicearray) ? $servicearray : [];
      } else {
        // Means we have no service defined in our field.
        $this->processed = [];
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
    $this->ensureComputedValue();
    return isset($this->computed[$index]) ? $this->computed[$index] : NULL;
  }
  /**
   * {@inheritdoc}
   */
  public function set($index, $value, $notify = TRUE) {
    $this->ensureComputedValue();
    return parent::set($index, $value, $notify);
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

  public function getProperties($include_computed = FALSE) {
    $this->ensureComputedValue();
    //@TODO see parent implementation
    // Since this is a map it would be great to have
    // A Fixed data definition and properties.
    return [];
  }


}
