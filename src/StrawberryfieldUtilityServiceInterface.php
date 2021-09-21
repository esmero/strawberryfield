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
   * Determines if a file system path is valid and can be written to.
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
  public function internalFilePathIsValid(string $scheme, string $path): bool;

  /**
   * Determines if an S3 directory path is valid according to a restricted version
   * of the rules defined here: https://www.ezs3.com/public/232.cfm:
   *   Between 3 and 63 characters in total length.
   *   Forward slashes delimit virtual directory structure (not actual)
   *   Only lower case letters, numbers, and hyphens permitted for each virtual folder.
   *   Virtual folders must be at least three characters, and may not begin or end with a hyphen.
   *
   * @param  string  $path
   *   The virtual folder path, not including the "s3://" scheme at the beginning.
   *
   * @return bool
   */
  public function s3FilePathIsValid(string $path): bool;

}
