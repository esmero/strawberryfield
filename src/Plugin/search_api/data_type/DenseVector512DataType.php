<?php

namespace Drupal\strawberryfield\Plugin\search_api\data_type;

use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * Provides a Vector data type for 512 length search api fields.
 *
 * @SearchApiDataType(
 *   id = "densevector_512",
 *   label = @Translation("Dense Vector of 512 length"),
 *   description = @Translation("Contains Dense Vectors, float values."),
 *   fallback_type = "decimal",
 *   prefix = "knn512"
 * )
 */
class DenseVector512DataType extends DataTypePluginBase {

  /**
   * {@inheritdoc}
   */
  public function getValue($value) {
    if ($value !== NULL) {
      $value = (float)$value;
    }
    return $value;
  }

}
