<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 7/21/20
 * Time: 12:01 PM
 */

namespace Drupal\strawberryfield\Tools;

use \SimpleXMLElement;


class SimpleXMLtoArray {

  private $options = [
    'namespaceSeparator' => ':',//you may want this to be something other than a colon
    'attributePrefix' => '@',   //to distinguish between attributes and nodes with the same name
    'alwaysArray' => [],   //array of xml tag names which should always become arrays
    'autoArray' => FALSE,        //only create arrays for tags which appear more than once
    'textContent' => '@value',       //key used for the text content of elements
    'autoText' => FALSE,         //skip textContent key if node has no attributes or child nodes
    'keySearch' => FALSE,       //optional search and replace on tag and attribute names
    'keyReplace' => FALSE,
    'keepPrefix' => TRUE // Don't user prefixes for elements. As simple as that.
  ];

  /**
   * @var SimpleXMLElement
   */
  private $subject;

  public function __construct(
    SimpleXMLElement $element,
    $options = []
  ) {
    $this->options = array_merge($options, $this->options);
    $this->subject = $element;

  }

  public function xmlToArray(SimpleXMLElement $element = null) {

    if ($element === null) {
      $element = $this->subject;
    }

    //$namespaces = $element->getDocNamespaces();
    $namespaces =  $element->getNamespaces();
    $namespaces = array_unique($namespaces);
    if (!isset($namespaces[''] )) {
      $namespaces['']  = null;
    }


    //get attributes from all namespaces
    $attributesArray = [];
    foreach ($namespaces as $prefix => $namespace) {
      foreach ($element->attributes($namespace) as $attributeName => $attribute) {
        //replace characters in attribute name
        if ($this->options['keySearch']) $attributeName =
          str_replace($this->options['keySearch'], $this->options['keyReplace'], $attributeName);
        if ($this->options['keepPrefix']) {
          $attributeKey = $this->options['attributePrefix']
            . ($prefix ? $prefix . $this->options['namespaceSeparator'] : '')
            . $attributeName;
        } else {
          // If we don't want prefixes. Because sometimes they repeat!
          $attributeKey = $this->options['attributePrefix']
            . $attributeName;
        }
        $attributesArray[$attributeKey] = (string)$attribute;
      }
    }

    //get child nodes from all namespaces
    $tagsArray = [];
    foreach ($namespaces as $prefix => $namespace) {
      foreach ($element->children($namespace) as $childXml) {
        //recurse into child nodes
        $childArray = $this->xmlToArray($childXml);
        // We can not use each() anymore, deprecated since PHP 7.2
        list($childTagName, $childProperties) = [ key($childArray), current($childArray) ];
        next($childArray);
        //replace characters in tag name
        if ($this->options['keySearch']) $childTagName =
          str_replace($this->options['keySearch'], $this->options['keyReplace'], $childTagName);
        //add namespace prefix, if any
        if (($this->options['keepPrefix']) && ($prefix)) {
          $childTagName = $prefix . $this->options['namespaceSeparator'] . $childTagName;
        }

        if (!isset($tagsArray[$childTagName])) {
          //only entry with this key
          //test if tags of this type should always be arrays, no matter the element count
          $tagsArray[$childTagName] =
            in_array($childTagName, $this->options['alwaysArray']) || !$this->options['autoArray']
              ? [$childProperties] : $childProperties;
        } elseif (
          is_array($tagsArray[$childTagName]) && array_keys($tagsArray[$childTagName])
          === range(0, count($tagsArray[$childTagName]) - 1)
        ) {
          //key already exists and is integer indexed array
          $tagsArray[$childTagName][] = $childProperties;
        } else {
          //key exists so convert to integer indexed array with previous value in position 0
          $tagsArray[$childTagName] = [$tagsArray[$childTagName], $childProperties];
        }
      }
    }

    //get text content of node
    $textContentArray = [];
    $plainText = trim((string)$element);
    if ($plainText !== '') $textContentArray[$this->options['textContent']] = $plainText;

    //stick it all together
    $propertiesArray = !$this->options['autoText'] || $attributesArray || $tagsArray || ($plainText === '')
      ? array_merge($attributesArray, $tagsArray, $textContentArray) : $plainText;

    //return node as array
    return [
      $element->getName() => $propertiesArray
    ];
  }


}

