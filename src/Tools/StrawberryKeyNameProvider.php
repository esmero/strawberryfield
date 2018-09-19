<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 7:29 PM
 */

namespace Drupal\strawberryfield\Tools;

use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;


class StrawberryKeyNameProvider {

  public static function fetchKeyNames() {
    $jsonldcontext = StrawberryfieldJsonHelper::SIMPLE_JSONLDCONTEXT;
    dump($jsonldcontext);
    // @TODO refactor the keyname generation to multiple methods
    $validkeys = [];
    $jsonldcontextarray = json_decode($jsonldcontext, TRUE);

    $jsonld_reservedkeys = [
      '@context',
      '@id',
      '@value',
      '@language',
      '@type',
      '@container',
      '@list',
      '@set',
      '@reverse',
      '@index',
      '@base',
      '@vocab',
      '@graph',
    ];
    $validkeys = array_keys(
      array_merge(
        $jsonldcontextarray['@context'],
        array_fill_keys($jsonld_reservedkeys, 'stub')
      )
    );

    return $validkeys;
  }


}