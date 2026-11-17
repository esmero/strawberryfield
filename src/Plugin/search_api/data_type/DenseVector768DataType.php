<?php

namespace Drupal\strawberryfield\Plugin\search_api\data_type;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiDataType;
use Drupal\search_api\DataType\DataTypePluginBase;
use Drupal\search_api_solr\Plugin\search_api\data_type\SearchApiDataTypePrefixInterface;

/**
 * Provides a Vector data type for 768 length search api fields.
 */
#[SearchApiDataType(
  id: 'densevector_768',
  label: new TranslatableMarkup('Dense Vector of 768 length'),
  description: new TranslatableMarkup('Contains Dense Vectors, float values.'),
  fallback_type: 'decimal'
)]
class DenseVector768DataType extends DataTypePluginBase implements SearchApiDataTypePrefixInterface {

  /**
   * {@inheritdoc}
   */
  public function getValue($value) {
    if ($value !== NULL) {
      $value = (float)$value;
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function getPrefix(): string {
    return 'knn768';
  }

}
