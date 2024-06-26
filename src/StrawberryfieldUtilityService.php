<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 6/26/19
 * Time: 6:56 PM
 */

namespace Drupal\strawberryfield;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;

/**
 * Provides a SBF utility class.
 */
class StrawberryfieldUtilityService implements StrawberryfieldUtilityServiceInterface {

  use StringTranslationTrait;

  /**
   * S3 file system valid folder name pattern.
   * This is a more restrictive folder name pattern than needed for local file systems,
   * but we apply it to all file systems so that the rules don't change if the user
   * should change the file system.
   * See \Drupal\strawberryfield\StrawberryfieldUtilityServiceInterface::s3FilePathIsValid
   * for explanation of the rules.
   */
  const VALID_S3_PREFIX_PATTERN = "/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/";


  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   */
  protected $configFactory;

  /**
   * The Module Handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface;
   */
  protected $moduleHandler;

  /**
   * The Entity field manager service
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager ;
   */
  protected $entityFieldManager;

  /**
   * A list of Field names of type SBF
   *
   * @var array|NULL
   *
   */
  protected $strawberryfieldMachineNames = NULL;

  /**
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager
   */
  protected $parseModeManager;

  /**
   * StrawberryfieldUtilityService constructor.
   *
   * @param  \Drupal\Core\File\FileSystemInterface  $file_system
   * @param  \Drupal\Core\Entity\EntityTypeManagerInterface  $entity_type_manager
   * @param  \Drupal\Core\Config\ConfigFactoryInterface  $config_factory
   * @param  \Drupal\Core\Extension\ModuleHandlerInterface  $module_handler
   * @param  \Drupal\Core\Entity\EntityFieldManagerInterface  $entity_field_manager
   * @param  \Drupal\search_api\ParseMode\ParseModePluginManager  $parse_mode_manager
   *   The Search API parse Manager
   */
  public function __construct(
    FileSystemInterface $file_system,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    EntityFieldManagerInterface $entity_field_manager,
    ParseModePluginManager $parse_mode_manager
  ) {
    $this->fileSystem = $file_system;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->entityFieldManager = $entity_field_manager;
    $this->strawberryfieldMachineNames = $this->getStrawberryfieldMachineNames();
    $this->parseModeManager = $parse_mode_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function bearsStrawberryfield(ContentEntityInterface $entity) {
    $hassbf = &drupal_static(__FUNCTION__);
    $entitytype = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $key = $entitytype . ":" . $bundle;
    if (!isset($hassbf[$key])) {
      $hassbf[$key] = [];
      $field_item_lists = $entity->getFields();
      foreach ($field_item_lists as $field) {
        if ($field->getFieldDefinition()
            ->getType() == 'strawberryfield_field') {
          $hassbf[$key][] = $field->getFieldDefinition()->getName();
        }
      }
    }
    return $hassbf[$key];
  }

  /**
   * {@inheritdoc}
   */
  public function getStrawberryfieldMachineNames() {
    // Only NUll if not initialized. The moment this Service
    // Gets constructed we set this data.
    // @TODO. Do we want to calculate this expensive function
    // everytime or just on demand?
    if ($this->strawberryfieldMachineNames !== NULL) {
      return $this->strawberryfieldMachineNames;
    }
    $node_field_definitions = $this->entityFieldManager->getFieldStorageDefinitions('node');
    $sbf_field_names = [];
    foreach ($node_field_definitions as $field_definition) {
      if ($field_definition->getType() === "strawberryfield_field") {
        $sbf_field_names[] = $field_definition->getName();
      }
    }

    return $sbf_field_names;
  }

  /**
   * {@inheritdoc}
   */
  public function bundleHasStrawberryfield($bundle = 'digital_object') {

    $field = $this->entityTypeManager->getStorage('field_config');
    $field_ids = $this->entityTypeManager->getStorage('field_config')
      ->getQuery()
      ->condition('entity_type', 'node')
      ->condition('bundle', $bundle)
      ->condition('field_type', 'strawberryfield_field')
      ->accessCheck(FALSE)
      ->execute();
    $fields = $this->entityTypeManager->getStorage('field_config')
      ->loadMultiple($field_ids);

    return count($field_ids) ? TRUE : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getStrawberryfieldConfigFromStorage($bundle = 'digital_object'
  ) {
    $field_config = $this->entityTypeManager->getStorage('field_config');
    $field_ids = $field_config->getQuery()
      ->condition('entity_type', 'node')
      ->condition('bundle', $bundle)
      ->condition('field_type', 'strawberryfield_field')
      ->accessCheck(FALSE)
      ->execute();
    $fields = $field_config->loadMultiple($field_ids);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getStrawberryfieldMachineForBundle($bundle = 'digital_object'
  ) {
    // @WARNING Never call this function inside any field based hook
    // Chances are the hook will be called invoked inside ::getFieldDefinitions
    // All you will find yourself inside a SUPER ETERNAL LOOP. You are adviced.

    $all_bundled_fields = $this->entityFieldManager->getFieldDefinitions('node',
      $bundle);
    $all_sbf_fields = $this->getStrawberryfieldMachineNames();
    return array_intersect(array_keys($all_bundled_fields), $all_sbf_fields);
  }

  /**
   * {@inheritdoc}
   */
  public function getStrawberryfieldDefinitionsForBundle(
    $bundle = 'digital_object'
  ) {
    // @WARNING Never call this function inside any field based hook
    // Chances are the hook will be called invoked inside ::getFieldDefinitions
    // All you will find yourself inside a SUPER ETERNAL LOOP. You are adviced.
    $all_bundled_fields = $this->entityFieldManager->getFieldDefinitions('node',
      $bundle);
    $all_sbf_fields = $this->getStrawberryfieldMachineNames();
    $all_sbf_fields = array_flip($all_sbf_fields);
    return array_intersect_key($all_bundled_fields, $all_sbf_fields);
  }

  /**
   * {@inheritdoc}
   */
  public function getStrawberryfieldSolrFields(Index $index_entity) {
    $solr_fields = $index_entity->get('field_settings');
    $property_path_is_sbf = array();

    $sbf_solr_fields = array();

    foreach ($solr_fields as $id => $field) {
      $property_path = explode(":", $field['property_path']);
      // use the first part of the property path (this will be the field machine name)
      $field_name = $property_path[0];
      // check if this $field_name exists as an sbf field name, and store result in $property_path_is_sbf
      // check if key already exists, as there will be repeats among solr fields
      if (!isset($property_path_is_sbf[$field_name])) {
        $property_path_is_sbf[$field_name] = in_array($field_name,
          $this->strawberryfieldMachineNames);
      }
      $has_sbf_property_path = $property_path_is_sbf[$field_name];
      $has_node_datasource = array_key_exists('datasource_id',
        $field) ? $field['datasource_id'] === "entity:node" : FALSE;

      if ($has_sbf_property_path && $has_node_datasource) {
        $sbf_solr_fields[$id] = $field;
      }
    }

    return $sbf_solr_fields;
  }

  /**
   * {@inheritdoc}
   */
  public function verifyCommand($execpath): bool {
    $iswindows = strpos(PHP_OS, 'WIN') === 0;
    $canexecute = FALSE;
    $execpath = trim(escapeshellcmd($execpath));
    $test = $iswindows ? 'where' : 'command -v';
    $output = shell_exec("$test $execpath");
    if ($output) {
      $canexecute = is_executable($execpath);
    }
    return $canexecute;
  }

  /**
   * {@inheritdoc}
   */
  public function formatBytes($size, $precision = 2) {
    if ($size === 0) {
      return 0;
    }
    $base = log($size, 1024);
    $suffixes = array('', 'k', 'M', 'G', 'T');
    return round(pow(1024, $base - floor($base)),
        $precision) . $suffixes[floor($base)];
  }

  /**
   * {@inheritdoc}
   */
  public function verifyDrush(string $execpath, string $home): bool {
    $site_path = \Drupal::getContainer()->getParameter('site.path'); // e.g.: 'sites/default'
    $site_path = explode('/', $site_path);
    $site_name = $site_path[1];
    $execpath = $execpath . ' --uri=' . $site_name;
    $iswindows = strpos(PHP_OS, 'WIN') === 0;
    $canexecute = FALSE;
    $execpath = trim(escapeshellcmd($execpath));
    $test = $iswindows ? 'where' : 'command -v';
    $output = shell_exec("$test $execpath");
    if ($output) {
      if (!empty($home)) {
        $home = escapeshellcmd($home);
        $execpath = "export HOME='" . $home . "'; " . $execpath;
      }
      $output = shell_exec($execpath);
      if ($output) {
        if (strpos($output, 'Drush Commandline Tool') === 0) {
          $canexecute = TRUE;
        }
      }
    }
    return $canexecute;
  }

  /**
   * {@inheritdoc}
   */
  public function getCountByProcessorInSolr(
    EntityInterface $entity,
    string $processor,
    array $indexes = [],
    string $checksum = NULL
  ): int {
    $count = 0;

    // If no index specified, query all that implement the strawberry flavor
    // datasource.
    if (empty($indexes)) {
      $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();
    }

    foreach ($indexes as $index) {
      // Create the query.
      $query = $index->query([
        'limit' => 1,
        'offset' => 0,
      ]);

      $allfields_translated_to_solr = $index->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($index);
      $parse_mode = $this->parseModeManager->createInstance('terms');
      $query->setParseMode($parse_mode);
      $query->sort('search_api_relevance', 'DESC');

      /* Forcing here two fixed options */
      $parent_conditions = $query->createConditionGroup('OR');

      if (isset($allfields_translated_to_solr['parent_id'])) {
        $parent_conditions->addCondition('parent_id',  $entity->id());
      }
      // The property path for this is: target_id:field_descriptive_metadata:sbf_entity_reference_ispartof:nid
      // TODO: This needs a config form. For now let's document. Even if not present
      // It will not fail.
      if (isset($allfields_translated_to_solr['top_parent_id'])) {
        $parent_conditions->addCondition('top_parent_id',  $entity->id());
      }

      if (count($parent_conditions->getConditions())) {
        $query->addConditionGroup($parent_conditions);
      }

      $query->addCondition('processor_id', $processor)
        ->addCondition('search_api_datasource',
          'strawberryfield_flavor_datasource');

      if ($checksum) {
        $query->addCondition('checksum', $checksum);
      }

      // Another solution would be to make our conditions all together an OR
      // But no post processing here is also good, faster and we just want
      // to know if its there or not.
      $query->setProcessingLevel(QueryInterface::PROCESSING_NONE);
      // @see strawberryfield_search_api_solr_query_alter()
      $query->setOption('ocr_highlight', 'on');
      $results = $query->execute();

      // In case of more than one Index with the same Data Source we accumulate.
      $count += (int) $results->getResultCount();
    }

    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function getContentByProcessorInSolr(
    EntityInterface $entity,
    string $processor,
    array $indexes = [],
    string $checksum = NULL
  ): int {
    $count = 0;

    // If no index specified, query all that implement the strawberry flavor
    // datasource.
    if (empty($indexes)) {
      $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();
    }

    foreach ($indexes as $index) {
      // Create the query.
      $query = $index->query([
        'limit' => 1,
        'offset' => 0,
      ]);

      $allfields_translated_to_solr = $index->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($index);
      $parse_mode = $this->parseModeManager->createInstance('terms');
      $query->setParseMode($parse_mode);
      $query->sort('search_api_relevance', 'DESC');

      /* Forcing here two fixed options */
      $parent_conditions = $query->createConditionGroup('OR');

      if (isset($allfields_translated_to_solr['parent_id'])) {
        $parent_conditions->addCondition('parent_id',  $entity->id());
      }
      // The property path for this is: target_id:field_descriptive_metadata:sbf_entity_reference_ispartof:nid
      // TODO: This needs a config form. For now let's document. Even if not present
      // It will not fail.
      if (isset($allfields_translated_to_solr['top_parent_id'])) {
        $parent_conditions->addCondition('top_parent_id',  $entity->id());
      }

      if (count($parent_conditions->getConditions())) {
        $query->addConditionGroup($parent_conditions);
      }

      $query->addCondition('processor_id', $processor)
        ->addCondition('search_api_datasource',
          'strawberryfield_flavor_datasource');

      if ($checksum) {
        $query->addCondition('checksum', $checksum);
      }

      // Another solution would be to make our conditions all together an OR
      // But no post processing here is also good, faster and we just want
      // to know if its there or not.
      $query->setProcessingLevel(QueryInterface::PROCESSING_NONE);
      // @see strawberryfield_search_api_solr_query_alter()
      $query->setOption('ocr_highlight', 'on');
      $results = $query->execute();

      // In case of more than one Index with the same Data Source we accumulate.
      $count += (int) $results->getResultCount();
    }

    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function filePathIsValid(string $scheme, string $path): bool {
    // Empty path is always valid.
    if (empty($path)) {
      return TRUE;
    }

    $known_schemes = array_merge(['s3'], array_keys(\Drupal::service('stream_wrapper_manager')->getWrappers(StreamWrapperInterface::LOCAL)));
    if(!in_array($scheme, $known_schemes)) {
      // Can't flag as invalid if we don't handle this scheme, so just return TRUE.
      return TRUE;
    }

    // S3 path length limit is 63 characters.
    if(strlen($path) > 63) {
      return FALSE;
    }
    // Split the path into virtual directory names and check each one. If
    // any are not valid, exit the loop and return FALSE.
    $path_parts = explode("/", $path);
    foreach ($path_parts as $path_part) {
      $valid = preg_match(self::VALID_S3_PREFIX_PATTERN, $path_part, $matches);
      if (!$valid) {
        return FALSE;
      }
    }

    // The path validates against our test pattern, but we don't know if it can
    // actually be written to by the file system. We'll check for that here.
    // TODO: Is there a non-invasive way to test this?
    $test_path = $scheme . "://" . $path;
    // FileSystemInterface::prepareDirectory with the MODIFY_PERMISSIONS flag checks
    // to see if it's present and able to be written to.
    $valid = $this->fileSystem->prepareDirectory($test_path, FileSystemInterface::MODIFY_PERMISSIONS);
    if (!$valid) {
      // If not present or able to be written to, let's check to see if we can create it.
      $valid = $this->fileSystem->prepareDirectory($test_path, FileSystemInterface::CREATE_DIRECTORY);
      if ($valid) {
        // Successfully created the directory, so we delete it now.
        $this->fileSystem->rmdir($test_path);
      }
    }
    return !empty($valid);
  }

}
