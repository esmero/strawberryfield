<?php

namespace Drupal\strawberryfield\Plugin\search_api\data_type;

use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * Provides a Vector data type for 12 length (3 colors) search api fields.
 *
 * @SearchApiDataType(
 *   id = "densevector_12=",
 *   label = @Translation("Dense Vector of 12 length"),
 *   description = @Translation("Contains Dense Vectors, float values."),
 *   fallback_type = "decimal",
 *   prefix = "knn12"
 * )
 */
class DenseVector12DataType extends DataTypePluginBase {

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
