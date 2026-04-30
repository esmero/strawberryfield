<?php

namespace Drupal\strawberryfield\Plugin\search_api\data_type;

use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * Provides a Vector data type for 3 length (primary color) search api fields.
 *
 * @SearchApiDataType(
 *   id = "densevector_3=",
 *   label = @Translation("Dense Vector of 3 length"),
 *   description = @Translation("Contains Dense Vectors, float values."),
 *   fallback_type = "decimal",
 *   prefix = "knn3"
 * )
 */
class DenseVector3DataType extends DataTypePluginBase {

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
