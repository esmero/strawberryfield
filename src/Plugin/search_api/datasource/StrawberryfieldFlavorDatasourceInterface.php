<?php

namespace Drupal\strawberryfield\Plugin\search_api\datasource;

use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api\Datasource\DatasourceInterface;

/**
 * Interface for strawberryfield flavor datasource.
 */
interface StrawberryfieldFlavorDatasourceInterface extends DatasourceInterface, PluginFormInterface {

  /**
   * Returns an associative array with bundles that have a SBF.
   *
   * @return array
   * An associative array of SBF field names keyed by the bundle name.
   */
  public function getApplicableBundlesWithSbfField();

  /**
   * Returns the Search API indexes that have the strawberry flavor datasource.
   *
   * @return \Drupal\search_api\IndexInterface[] $indexes
   *   An array of search indexes.
   */
  public static function getValidIndexes();

}
