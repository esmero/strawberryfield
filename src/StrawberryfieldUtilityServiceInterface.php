<?php

namespace Drupal\strawberryfield;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\search_api\Entity\Index;

/**
 * Defines an interface the common strawberry field utility service
 *
 * @ingroup strawberryfield
 */
interface StrawberryfieldUtilityServiceInterface {

  /**
   * Checks if Content entity bears SBF and if so returns field machine names.
   *
   * This function statically caches per Bundle and entity type the results.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *
   * @return array
   *  Returns a numeric keyed array with machine names for the SBF fields
   */
  public function bearsStrawberryfield(ContentEntityInterface $entity);

  /**
   * Returns a list of the machine names of all existing SBF fields
   *
   * @return array
   *  Returns array of names
   */
  public function getStrawberryfieldMachineNames();

  /**
   * Given a Bundle returns yes if it contains a SBF defined via a field config.
   *
   * @param string $bundle
   *
   * @return bool
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function bundleHasStrawberryfield($bundle = 'digital_object');

  /**
   * Given a Bundle returns SBF's field config Object.
   *
   * @param string $bundle
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getStrawberryfieldConfigFromStorage($bundle = 'digital_object');

  /**
   * Given a Bundle returns the SBF field machine names.
   *
   * This include Code generated, overrides, etc. For just the directly created
   * via the UI which are FieldConfig Instances use:
   * \Drupal\strawberryfield\StrawberryfieldUtilityService::getStrawberryfieldConfigFromStorage
   *
   * @param $bundle
   *    A Node Bundle
   *
   * @return array
   *  Returns array of SBF names
   */
  public function getStrawberryfieldMachineForBundle($bundle = 'digital_object');

  /**
   * Given a Bundle returns the SBF field definition Objects
   *
   * This include Code generated, overrides, etc. For just the directly created
   * via the UI which are FieldConfig Instances use:
   * \Drupal\strawberryfield\StrawberryfieldUtilityService::getStrawberryfieldConfigFromStorage
   *
   * @param $bundle
   *    A Node Bundle
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]|array
   *  Returns array of SBF definitions
   */
  public function getStrawberryfieldDefinitionsForBundle($bundle = 'digital_object');

  /**
   * Returns the Solr Fields in a Solr Index are from SBFs
   **
   * @param \Drupal\search_api\Entity\Index $index_entity
   *
   * @return array
   *  Returns array of solr fields only including those from SBFs
   */
  public function getStrawberryfieldSolrFields(Index $index_entity);

  /**
   * Checks if a given command exists and is executable.
   *
   * @param $command
   *
   * @return bool
   */
  public function verifyCommand($execpath) :bool;

  /**
   * Format a quantity of bytes.
   *
   * @param int $size
   * @param int $precision
   *
   * @return string
   */
  public function formatBytes($size, $precision = 2);

  /**
   * Checks if a given drush command exists and is executable.
   *
   * @param string $execpath
   * @param string $home
   *
   * @return bool
   */
  public function verifyDrush(string $execpath, string $home) :bool;

  /**
   * Gets the number of documents that match an entity and processor.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to search for.
   * @param string $processor
   *   Processor to filter, e.g. ocr.
   * @param \Drupal\search_api\IndexInterface[] $indexes
   *   Indexes that are searched, empty for all indexes.
   * @param string $checksum
   *   Optional checksum.
   *
   * @return int
   *   Count of documents found.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function getCountByProcessorInSolr(EntityInterface $entity, string $processor, array $indexes = [], string $checksum = NULL): int;

  /**
   * Gets the number of documents that match an entity and processor.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to search for.
   * @param array $processors
   *   Processors to filter, e.g. ocr.
   * @param string $processor_op
   *    Accepted values are OR/AND
   * @param bool $direct
   *    Find directly on ADO
   * @param bool $children
   *    Find on ADO children
   * @param bool $grandchildren
   *    Find on ADO GrandChildren
   * @param string $level_op
   *    If count is OR/AND of the previos direct, children of grandchildren bool
   * @param \Drupal\search_api\IndexInterface[] $indexes
   *   Indexes that are searched, empty for all indexes. (optiona)
   * @return int
   *   Count of documents found.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function getCountByProcessorsAndLevelInSolr(EntityInterface $entity, array $processors, string $processor_op = 'OR', bool $direct = TRUE, bool $children = FALSE, bool $grandchildren = FALSE, string $level_op = 'OR',  array $indexes = []): int;




  /**
   * Determines if a file system path is valid and can be written to.
   * Utilizes more restrictive Amazon S3 file prefix rules that are
   * described here: https://www.ezs3.com/public/232.cfm:
   *   Between 3 and 63 characters in total length.
   *   Forward slashes delimit virtual directory structure (not actual)
   *   Only lower case letters, numbers, and hyphens permitted for each virtual folder.
   *   Virtual folders must be at least three characters, and may not begin or end with a hyphen.
   *
   * @param  string  $scheme
   *   The URI scheme.
   * @param  string  $path
   *   The path (relative to the scheme).
   *
   * @return bool
   *   FALSE if not valid
   *   TRUE if valid.
   */
  public function filePathIsValid(string $scheme, string $path): bool;

}
