<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 6/26/19
 * Time: 6:56 PM
 */

namespace Drupal\strawberryfield;

use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;
use Drupal\Core\File\MimeType\ExtensionMimeTypeGuesser;

/**
 * Makes possible to guess the extension of a file using the MIME type.
 */
class StrawberryfieldMimeService extends ExtensionMimeTypeGuesser implements MimeTypeGuesserInterface {


  /**
   * {@inheritdoc}
   */
  public function inverseGuess($mimetype) {
    if ($this->mapping === NULL) {
      $mapping = $this->defaultMapping;
      // Allow modules to alter the default mapping.
      $this->moduleHandler->alter('file_mimetype_mapping', $mapping);
      $this->mapping = $mapping;
    }
    $extension = 'bin';
    foreach($this->mapping['mimetypes'] as $machine_name => $storedmimetype) {
      if ($storedmimetype == $mimetype) {
        $flipped = array_flip(array_reverse($this->mapping['extensions']));
        if (isset($flipped[$machine_name])) {
          return $flipped[$machine_name];
        }
      }
    }
    return $extension;
  }
}
