<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/4/18
 * Time: 9:50 PM
 */

namespace Drupal\strawberryfield\Tools\Ocfl;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;

/**
 * Class OcflHelper
 * @package Drupal\strawberryfield\Tools\Ocfl
 */
class OcflHelper
{
    //@see https://ocfl.io/draft/implementation-notes/#bib-Pairtree
    public static function pairtreeEncode($identifier) {
        $encode_regex = "/[\"*+,<=>?\\\^|]|[^\x21-\x7e]/";
        $escaped_string = preg_replace_callback($encode_regex, 'self::strtohex', $identifier);
        $escaped_string = str_replace(array('/',':','.'), array('=','+',','),$escaped_string);
        return $escaped_string;
    }

    public static function pairtreeDecode($identifier) {
        $decode_regex = "/\\^(..)/";
        $decoded_string = str_replace(array('=','+',','), array('/',':','.'), $identifier);
        $decoded_string = preg_replace_callback($decode_regex, 'self::hextostr', $decoded_string);
        return $decoded_string;
    }

    private static function hextostr($matches) {
        $s= '';
        $x = trim($matches[0],'^');
        foreach(explode("\n",trim(chunk_split($x,2))) as $h) $s.=chr(hexdec($h));
        return($s);
    }

    private static function strtohex($matches) {
        $s= '';
        $x= $matches[0];
        foreach(str_split($x) as $c) $s.= '^'.sprintf("%02x",ord($c));
        return($s);
    }

    public static function pairtreeIdtoPath($identifier) {
        $encoded_identifier = self::pairtreeEncode($identifier);
        $number = preg_match_all('/..?/',$encoded_identifier,$matches);
        $path = implode('/', $matches[0]);
        return $path;
    }

    public static function pairtreePathtoId($path) {
        $encoded_identifier = implode('',explode('/',$path));
        $identifier = self::pairtreeDecode($encoded_identifier);
        return $identifier;
    }

    /**
     * Given an array that contains an @id and an local IRI give back the file entity.
     *
     * @param array $subgraph
     * @return \Drupal\file\FileInterface|null
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public static function resolvetoURItoFile(array $subgraph) {

        if (!isset($subgraph['@id'])) {
            return null;
        }
        /* @var \Drupal\file\FileInterface[] $files */
        $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $subgraph['@id']]);
        // $files == we need only the first, there should be always one or none.
        return $files ? current($files) : null;
    }

    /**
     * Given an fid return the Drupal URI to that file..
     *
     * @param int $fid
     * @return null | \Drupal\file\FileInterface
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public static function resolvetoFIDtoURI(int $fid) {

        if (!is_integer($fid)) {
            return null;
        }
        /* @var \Drupal\file\FileInterface $file */
        $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
        return $file;
    }


    /**
     * Gets all Drupal registered streamwrappers.
     *
     * @return mixed
     */
    public static function getVisibleStreamWrappers() {
        $stream_wrappers = \Drupal::service('stream_wrapper_manager')->getNames(StreamWrapperInterface::WRITE_VISIBLE);
        return $stream_wrappers;
    }





}
