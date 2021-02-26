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

  /**
   * {@inheritdoc}
   */
  public function guess($path) {
    if ($this->mapping === NULL) {
      $mapping = $this->defaultMapping;
      // Allow modules to alter the default mapping.
      $this->moduleHandler->alter('file_mimetype_mapping', $mapping);
      $this->mapping = $mapping;
    }

    $extension = '';
    $file_parts = explode('.', \Drupal::service('file_system')->basename($path));

    // Remove the first part: a full filename should not match an extension.
    array_shift($file_parts);

    // Iterate over the file parts, trying to find all matches.
    // For my.awesome.image.jpeg, we try and accumulate:
    //   - jpeg
    //   - image.jpeg, and
    //   - awesome.image.jpeg
    $qualified = ['application/octet-stream'];
    while ($additional_part = array_pop($file_parts)) {
      $extension = strtolower($additional_part . ($extension ? '.' . $extension : ''));
      if (isset($this->mapping['extensions'][$extension])) {
        $qualified[] = $this->mapping['mimetypes'][$this->mapping['extensions'][$extension]];
      }
    }

    // We return only the last one giving any more precise one to have a chance.
    return end($qualified);

  }



}
