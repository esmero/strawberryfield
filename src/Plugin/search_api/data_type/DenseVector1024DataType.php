<?php

namespace Drupal\strawberryfield\Plugin\search_api\data_type;

use Drupal\search_api\DataType\DataTypePluginBase;

/**
 * Provides a Vector data type for 1024 length search api fields.
 *
 * @SearchApiDataType(
 *   id = "densevector_1024",
 *   label = @Translation("Dense Vector of 1024 length"),
 *   description = @Translation("Contains Dense Vectors, float values."),
 *   fallback_type = "decimal",
 *   prefix = "knn1024"
 * )
 */
class DenseVector1024DataType extends DataTypePluginBase {

  /**
   * {@inheritdoc}
   */
  public function getValue($value) {
    $value = (float) $value;
    return $value;
  }

}
