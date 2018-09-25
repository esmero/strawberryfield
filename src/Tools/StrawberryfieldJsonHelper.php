<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 7:43 PM
 */

namespace Drupal\strawberryfield\Tools;



class StrawberryfieldJsonHelper {

  /**
* Defines a minimal JSON-LD context.
*/
  CONST SIMPLE_JSONLDCONTEXT = '{
    "@context":  {
       "type": "@type",
        "id": "@id",
        "HTML": { "@id": "rdf:HTML" },
        "@vocab": "http://schema.org/",
        "schema": "http://schema.org/",
        "image": { "@id": "schema:image", "@type": "@id"},
        "dataset": { "@id": "schema:dataset"},
        "datasetTimeInterval": { "@id": "schema:datasetTimeInterval", "@type": "DateTime"},
        "dateCreated": { "@id": "schema:dateCreated", "@type": "Date"},
        "dateDeleted": { "@id": "schema:dateDeleted", "@type": "DateTime"},
        "dateIssued": { "@id": "schema:dateIssued", "@type": "DateTime"},
        "dateModified": { "@id": "schema:dateModified", "@type": "Date"},
        "datePosted": { "@id": "schema:datePosted", "@type": "Date"},
        "datePublished": { "@id": "schema:datePublished", "@type": "Date"},
        "Application": "as:Application",
        "Dataset": "dctypes:Dataset",
        "Image": "dctypes:StillImage",
        "Video": "dctypes:MovingImage",
        "Audio": "dctypes:Sound",
        "Text": "dctypes:Text",
        "Service": "svcs:Service",
        "label": {
           "@id": "rdfs:label",
           "@container": ["@language", "@set"]
         },
         "name": { "@id": "schema:name" }
       }
    }';

  /**
   * Flattens JSON string into array
   *
   * @param array $sourcearray
   *    An Associative array coming, maybe, from a JSON string.
   * @param string $propertypath;
   *   Use to accumulate the propertypath between recursive calls.

   */
  public static function arrayToFlatPropertypaths(array $sourcearray = [], $propertypath = '')
  {
    $flat = array();
    foreach ($sourcearray as $key => $values) {
      if (is_array($values)) {
        $flat = $flat + static::arrayToFlatPropertypaths($values,  $propertypath.$key.'.');
      }
      else {
        $flat[$propertypath.$key] = $values;
      }
    }

    return $flat;
  }


  /**
   * @param array $array
   *     An Associative array coming, maybe, from a JSON string.
   * @param array $flat
   *     An by reference accumulator.
   * @param bool $jsonld
   *    If special JSONLD handling is desired.
   *
   * @return array
   *   Same as the accumulator but left there in case someone needs a return.
   */
  public static function arrayToFlatCommonkeys(array $array, &$flat = array(), $jsonld = TRUE)
  {
    if (($jsonld) && array_key_exists('@graph', $array)) {
      $array = $array['@graph'];
    } else {
      // @TODO We need to deal with posiblity of multiple @Contexts
      // Which could make a same $key mean different things.
      // In this case @context could or not exist.
      unset($array['@context']);
    }
    foreach ($array as $key=>$value) {
      if (is_array($value)) {
        static::arrayToFlatCommonkeys($value, $flat, $jsonld);
      }
      else {
        $flat[$key][] = $value;
      }
    }
    return $flat;
  }




  /**
   * Array helper that checks if an array is associative or not
   *
   * @param array $sourcearray
   *
   * @return bool
   */
  public static function jsonIsList(array $sourcearray =  []) {
      return empty(array_filter(array_keys($sourcearray), 'is_string'));
  }



}