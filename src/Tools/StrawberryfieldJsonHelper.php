<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 7:43 PM
 */

namespace Drupal\strawberryfield\Tools;

use JmesPath\Env as JmesPath;


/**
 * Class StrawberryfieldJsonHelper
 *
 * @package Drupal\strawberryfield\Tools
 */
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
   * A URI matching regular expression for RFC 3986
   */
  const URI_REGEXP =  '/
    # URI scheme RFC 3986 (http://tools.ietf.org/html/rfc3986)
    
    (?(DEFINE)
    
      # ABNF notation of RFC 2234 (http://tools.ietf.org/html/rfc2234#section-6.1)
    
      (?<ALPHA>     [\x41-\x5A\x61-\x7A] )    # Latin character (A-Z, a-z)
      (?<CR>        \x0D )                    # Carriage return (\r)
      (?<DIGIT>     [\x30-\x39] )             # Decimal number (0-9)
      (?<DQUOTE>    \x22 )                    # Double quote (")
      (?<HEXDIG>    (?&DIGIT) | [\x41-\x46] ) # Hexadecimal number (0-9, A-F)
      (?<LF>        \x0A )                    # Line feed (\n)
      (?<SP>        \x20 )                    # Space
    
      # RFC 3986 body
    
      (?<uri>    (?&scheme) \: (?&hier_part) (?: \? (?&query) )? (?: \# (?&fragment) )? )
    
      (?<hier_part>    \/\/ (?&authority) (?&path_abempty)
                     | (?&path_absolute)
                     | (?&path_rootless)
                     | (?&path_empty) )
    
      (?<uri_reference>    (?&uri) | (?&relative_ref) )
    
      (?<absolute_uri>    (?&scheme) \: (?&hier_part) (?: \? (?&query) )? )
    
      (?<relative_ref>    (?&relative_part) (?: \? (?&query) )? (?: \# (?&fragment) )? )
    
      (?<relative_part>     \/\/ (?&authority) (?&path_abempty)
                          | (?&path_absolute)
                          | (?&path_noscheme)
                          | (?&path_empty) )
    
      (?<scheme>    (?&ALPHA) (?: (?&ALPHA) | (?&DIGIT) | \+ | \- | \. )* )
    
      (?<authority>    (?: (?&userinfo) \@ )? (?&host) (?: \: (?&port) )? )
      (?<userinfo>     (?: (?&unreserved) | (?&pct_encoded) | (?&sub_delims) | \: )* )
      (?<host>         (?&ip_literal) | (?&ipv4_address) | (?&reg_name) )
      (?<port>         (?&DIGIT)* )
    
      (?<ip_literal>    \[ (?: (?&ipv6_address) | (?&ipv_future) ) \] )
    
      (?<ipv_future>    \x76 (?&HEXDIG)+ \. (?: (?&unreserved) | (?&sub_delims) | \: )+ )
    
      (?<ipv6_address>                                              (?: (?&h16) \: ){6} (?&ls32)
                        |                                      \:\: (?: (?&h16) \: ){5} (?&ls32)
                        |                           (?&h16)?   \:\: (?: (?&h16) \: ){4} (?&ls32)
                        | (?: (?: (?&h16) \: ){0,1} (?&h16) )? \:\: (?: (?&h16) \: ){3} (?&ls32)
                        | (?: (?: (?&h16) \: ){0,2} (?&h16) )? \:\: (?: (?&h16) \: ){2} (?&ls32)
                        | (?: (?: (?&h16) \: ){0,3} (?&h16) )? \:\:     (?&h16) \:      (?&ls32)
                        | (?: (?: (?&h16) \: ){0,4} (?&h16) )? \:\:                     (?&ls32)
                        | (?: (?: (?&h16) \: ){0,5} (?&h16) )? \:\:                     (?&h16)
                        | (?: (?: (?&h16) \: ){0,6} (?&h16) )? \:\: )
    
      (?<h16>             (?&HEXDIG){1,4} )
      (?<ls32>            (?: (?&h16) \: (?&h16) ) | (?&ipv4_address) )
      (?<ipv4_address>    (?&dec_octet) \. (?&dec_octet) \. (?&dec_octet) \. (?&dec_octet) )
    
      (?<dec_octet>    (?&DIGIT)
                     | [\x31-\x39] (?&DIGIT)
                     | \x31 (?&DIGIT){2}
                     | \x32 [\x30-\x34] (?&DIGIT)
                     | \x32\x35 [\x30-\x35] )
    
      (?<reg_name>     (?: (?&unreserved) | (?&pct_encoded) | (?&sub_delims) )* )
    
      (?<path>    (?&path_abempty)
                | (?&path_absolute)
                | (?&path_noscheme)
                | (?&path_rootless)
                | (?&path_empty) )
    
      (?<path_abempty>     (?: \/ (?&segment) )* )
      (?<path_absolute>    \/ (?: (?&segment_nz) (?: \/ (?&segment) )* )? )
      (?<path_noscheme>    (?&segment_nz_nc) (?: \/ (?&segment) )* )
      (?<path_rootless>    (?&segment_nz) (?: \/ (?&segment) )* )
      (?<path_empty>       (?&pchar){0} ) # For explicity only
    
      (?<segment>       (?&pchar)* )
      (?<segment_nz>    (?&pchar)+ )
      (?<segment_nz_nc> (?: (?&unreserved) | (?&pct_encoded) | (?&sub_delims) | \@ )+ )
    
      (?<pchar>    (?&unreserved) | (?&pct_encoded) | (?&sub_delims) | \: | \@ )
    
      (?<query>    (?: (?&pchar) | \/ | \? )* )
    
      (?<fragment>    (?: (?&pchar) | \/ | \? )* )
    
      (?<pct_encoded>    \% (?&HEXDIG) (?&HEXDIG) )
    
      (?<unreserved>    (?&ALPHA) | (?&DIGIT) | \- | \. | \_ | \~ )
      (?<reserved>      (?&gen_delims) | (?&sub_delims) )
      (?<gen_delims>    \: | \/ | \? | \# | \[ | \] | \@ )
      (?<sub_delims>    \! | \$ | \& | \' | \( | \)
                      | \* | \+ | \, | \; | \= )
    
    )
    ^(?&uri)$
    /x';

  CONST URN_REGEXP = '/^urn:[a-z0-9][a-z0-9-]{0,31}:[a-z0-9()+,\-.:=@;$_!*\'%\/?#]+$/x';

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
   * Flattens JSON string into array
   *
   * Converts URI and numeric keys to wildcards
   *
   * @param array $sourcearray
   *    An Associative array coming, maybe, from a JSON string.
   * @param string $propertypath;
   *   Use to accumulate the propertypath between recursive calls.

   */
  public static function arrayToFlatJsonPropertypaths(array $sourcearray = [], $propertypath = '')
  {
    $flat = array();
    foreach ($sourcearray as $key => $values) {
      // If a Key is an URL chances are we are dealing with many different ones
      // Also we want to build JSON Paths here, so replace with *
      // But PHP does not know anything about URIS.. like URN...
      if(filter_var($key, FILTER_VALIDATE_URL) || static::validateURN($key)) {
        $key = "*";
      } elseif (is_integer($key)) {
        $key = '[*]';
        //@TODO research implications of $.field[*] versus $.field.[*]
      }
      // I could break here instead of iterating further, but that could exclude sub properties not present
      // In the first element
      if (is_array($values)) {
        $flat = $flat + static::arrayToFlatJsonPropertypaths($values,  $propertypath.$key.'.');
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
  public static function arrayToFlatCommonkeys(
    array $array,
    &$flat = [],
    $jsonld = TRUE
  ) {
    if (($jsonld) && array_key_exists('@graph', $array)) {
      $array = $array['@graph'];
    }
    else {
      // @TODO We need to deal with posiblity of multiple @Contexts
      // Which could make a same $key mean different things.
      // In this case @context could or not exist.
      unset($array['@context']);
    }
    foreach ($array as $key => $value) {
      if (is_array($value)) {
        static::arrayToFlatCommonkeys($value, $flat, $jsonld);
        // Don't accumulate int keys. Makes no sense.
        if (is_string($key)) {
          if (!isset($flat[$key])) {
            $flat[$key] = [];
          }
          // Means we can't keep flattening
          if (is_array($value)) {
            $flat[$key] = $flat[$key] + $value;
          }
          else {
            $flat[$key][] = $value;
          }
        }
      }
      else {
        // Don't accumulate int keys. Makes no sense.
        if (is_string($key)) {
          $flat[$key][] = $value;
        }
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
   *  TRUE if is associative
   */
  public static function arrayIsMultiSimple(array $sourcearray =  []) {
      return !empty(array_filter(array_keys($sourcearray), 'is_string'));
  }

  /**
   * Another array helper that checks if an array is associative or not
   *
   * This is faster for large arrays than ::arrayIsMultiSimple()
   * @param array $sourcearray
   *
   * @return bool
   *  TRUE if is associative
   */
  public static function arrayIsMultiIterative(array $sourcearray =  []) {
    foreach ($sourcearray as $item) {
      if (is_array($item)) return true;
    }
    return false;
  }



  /**
   *
   *
   * @param $expression
   *  JMES Valid path expression
   * @param array $sourcearray
   *
   * @return mixed|null Returns the matching data or null
   */
  public static function searchJson($expression, array $sourcearray =  []) {
    return JmesPath::search($expression, $sourcearray);
  }


  /**
   * Validate a URI according to RFC 3986
   *
   * @param string $uri
   *
   * @return bool
   */
  public static function validateURN(string $uri)
  {
    return (bool) preg_match(self::URN_REGEXP, $uri);
  }

  /**
   * Transforms our stored JSON Property Paths/Tax. terms into a valid JMESPath.
   *
   * This function assumes the given JSON Property path lacks the
   * last .[] or .{} present in the return expression of a JMESPath.
   * Basically it translates thing like
   *  `ap:images.*.url` into
   *  `"ap:images".*.url"
   *  or
   *  `subject_loc.[*].label` into
   *  `subject_loc[*].label"
   *
   * NOTE: This function is not meant to deal with full JSON Path expressions
   * and value based selectors.
   *
   * @param $jsonproppath
   *
   * @return string
   */
  public static function jsonPropertyPathToJmesPath($jsonproppath) {
    $path_parts_raw = explode(".", $jsonproppath);

    $terms = new CachingIterator(
      new ArrayIterator($path_parts_raw)
    );
    $term_path = [];
    foreach ($terms as $json_node) {
      if ($terms->hasNext()) {
        $next = $terms->getInnerIterator()->current();
        if ($next == '*') {
          $term_path[] = $json_node . "." . $next;
          continue;
        }
        elseif ($next == '[*]') {
          $term_path[] = $json_node . $next;
          continue;
        }
      }
      if ($json_node != '[*]' && $json_node != '*') {
        $term_path[] = self::surroundInDuobleQuotes($json_node);
      }
    }
      $term_path = array_filter($term_path);
      $jmespath = implode('.',$term_path);
    return $jmespath;
  }


  /**
   * Simple helper method that surrounds strings with : in doublequotes
   * @param $string
   *
   * @return string
   */
  public static function surroundInDuobleQuotes($string) {
    // For the sake of performance. Just surround everything.
    // If we check for : or @ or ! we would be doing a lot of
    // Extra processing when JMESPATH loves already double quotes.
    return '"' . $string . '"';
  }
}