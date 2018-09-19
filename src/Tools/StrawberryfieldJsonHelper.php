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
   * @param string $json
   * @param array $flat
   */
  public static function jsonFlattener(array $json = [], array &$flat = [])
  {
    //@TODO refactor to https://github.com/tonirilix/nested-json-flattener/blob/20396e7dfd040b6061a5b85e72fe4e187f3d499f/src/Flattener/Flattener.php#L47
    $flat = $json;

  }

}