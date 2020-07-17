<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/17/19
 * Time: 3:16 PM
 */

namespace Drupal\strawberryfield\Tools;

use \SimpleXMLElement;
/**
 * Class JsonSimpleXMLElementDecorator
 *
 * Implement JsonSerializable for SimpleXMLElement as a Decorator with close to
 * JSON-LD syntax.
 */
class JsonSimpleXMLElementDecorator implements \JsonSerializable {

  const DEF_DEPTH = 512;

  private $options = [
    '@attributes' => TRUE,
    '@text' => TRUE,
    'depth' => self::DEF_DEPTH
  ];

  /**
   * @var SimpleXMLElement
   */
  private $subject;

  public function __construct(
    SimpleXMLElement $element,
    $useAttributes = TRUE,
    $useValue = TRUE,
    $depth = self::DEF_DEPTH
  ) {

    $this->subject = $element;

    if (!is_null($useAttributes)) {
      $this->useAttributes($useAttributes);
    }
    if (!is_null($useValue)) {
      $this->useValue($useValue);
    }
    if (!is_null($depth)) {
      $this->setDepth($depth);
    }
  }

  public function useAttributes($bool) {
    $this->options['@attributes'] = (bool) $bool;
  }

  public function useValue($bool) {
    $this->options['@value'] = (bool) $bool;
  }

  public function setDepth($depth) {
    $this->options['depth'] = (int) max(0, $depth);
  }

  /**
   * Specify data which should be serialized to JSON
   *
   * @return mixed data which can be serialized by json_encode.
   */
  public function jsonSerialize() {
    $subject = $this->subject;

    $array = [];

    // json encode attributes if any.
    if ($this->options['@attributes']) {
      if ($attributes = $subject->attributes()) {
        $array['@attributes'] = array_map(
          'strval',
          iterator_to_array($attributes)
        );
      }
    }

    // traverse into children if applicable
    $children = $subject;
    $this->options = (array) $this->options;
    $depth = $this->options['depth'] - 1;
    if ($depth <= 0) {
      $children = [];
    }

    // json encode child elements if any. group on duplicate names as an array.
    foreach ($children as $name => $element) {
      /* @var SimpleXMLElement $element */
      $decorator = new self($element);
      $decorator->options = ['depth' => $depth] + $this->options;

      if (isset($array[$name])) {
        if (!is_array($array[$name])) {
          $array[$name] = [$array[$name]];
        }
        $array[$name][] = $decorator;
      }
      else {
        $array[$name] = $decorator;
      }
    }

    // json encode non-whitespace element simplexml text values.
    $text = trim($subject);
    if (strlen($text)) {
      if ($array) {
        $this->options['@value'] && $array['@value'] = $text;
      }
      else {
        $array = $text;
      }
    }

    // return empty elements as NULL (self-closing or empty tags)
    if (empty($array) && !is_numeric($array) && !is_bool($array)) {
      $array = NULL;
    }

    return $array;
  }
}