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
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\file\Entity\File;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Ramsey\Uuid\Uuid;
use SplFileObject;

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
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * StrawberryfieldUtilityService constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface  $file_system
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface  $entity_type_manager
   * @param \Drupal\Core\Config\ConfigFactoryInterface  $config_factory
   * @param \Drupal\Core\Extension\ModuleHandlerInterface  $module_handler
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface  $entity_field_manager
   * @param \Drupal\search_api\ParseMode\ParseModePluginManager  $parse_mode_manager
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The Search API parse Manager
   */
  public function __construct(
    FileSystemInterface $file_system,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    EntityFieldManagerInterface $entity_field_manager,
    ParseModePluginManager $parse_mode_manager,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->fileSystem = $file_system;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->entityFieldManager = $entity_field_manager;
    $this->strawberryfieldMachineNames = $this->getStrawberryfieldMachineNames();
    $this->parseModeManager = $parse_mode_manager;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->loggerFactory = $logger_factory;
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
  public function getStrawberryfieldParentADOs(ContentEntityInterface $entity) {

    $sbf_fields = $this->bearsStrawberryfield($entity);

    $node_entities['nids'] = [];
    $node_entities['uuids'] = [];
    $node_entities_by_predicate = [];

    foreach ($sbf_fields as $field_name) {
      /* @var $field \Drupal\Core\Field\FieldItemInterface */
      $field = $entity->get($field_name);
      $node_entities = [];
      if (!$field->isEmpty()) {
        foreach ($field->getIterator() as $delta => $itemfield) {
          // Note: we are not longer touching the metadata here.
          /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
          $flatvalues = (array) $itemfield->provideFlatten();
          $values = (array) $itemfield->provideDecoded();
          // This is just for semantic consistency since we DO allow
          // dr:fid.
          // only try to fetch these if we are not asking for a predicate
          //  dr:fid is not in use in production, but if found will be a generic
          // predicate
          if (isset($flatvalues['dr:nid']) && !empty($flatvalues['dr:nid'])) {
            $entity_ids = (array) $flatvalues['dr:nid'];
            $node_entities = array_filter($entity_ids, function ($el) {
              $el = filter_var($el, FILTER_VALIDATE_INT);
              return $el;
            });
            if (count($node_entities)) {
              $node_entities['nids']['dr:nid'] = $node_entities;
            }
          }
          // Get mapped ones
          if (isset($values["ap:entitymapping"]["entity:node"]) &&
            !empty($values["ap:entitymapping"]["entity:node"])) {
            $jsonkeys_with_node_entities = (array) $values["ap:entitymapping"]["entity:node"];
            foreach ($jsonkeys_with_node_entities as $jsonkey_with_node_entity) {
              if (is_string($jsonkey_with_node_entity)) {
                if (isset($values[$jsonkey_with_node_entity]) && !empty($values[$jsonkey_with_node_entity])) {
                  $entity_ids = (array) $values[$jsonkey_with_node_entity];
                  // We filter for scalar that way we don't end sending an array or object to UUid validator.
                  $entity_ids = array_filter($entity_ids, 'is_scalar');
                  $node_entities_ids = array_filter($entity_ids, function ($el) {
                    $el = filter_var($el, FILTER_VALIDATE_INT);
                    return $el;
                  });
                  $node_entities_uuids = array_filter($entity_ids, [
                    '\Ramsey\Uuid\Uuid',
                    'isValid'
                  ]);
                  $node_entities['nids'][$jsonkey_with_node_entity] = array_merge($node_entities['nids'][$jsonkey_with_node_entity] ?? [], $node_entities_ids);
                  $node_entities['uuids'][$jsonkey_with_node_entity] = array_merge($node_entities['uuids'][$jsonkey_with_node_entity] ?? [], $node_entities_uuids);
                }
              }
            }
          }
        }
      }
      // Now see if we can load the Node entities
      // If the user repeats the ADOs, then we will load multiple times
      // This is less optimal than loading All IDs
      // Then all UUIDs
      // And then distributing based on source
      // But it is easier to read.
      try {
        if (is_array($node_entities['uuids'] ?? NULL)) {
          foreach ($node_entities['uuids'] as $predicate => $nodelist) {
            if (is_array($nodelist) && !empty($nodelist)) {
              $ados_from_uuid = $this->entityTypeManager->getStorage('node')
                ->loadByProperties(['uuid' => $nodelist]);
              if (count($ados_from_uuid)) {
                $node_entities_by_predicate[$predicate] = $ados_from_uuid;
              }
            }
          }
        }
        if (is_array($node_entities['nids'] ?? NULL)) {
          foreach ($node_entities['nids'] as $predicate => $nodelist) {
            // We can remove here by key, $node_entities_by_predicate[$predicate] will have NODE_ID as indexes
            // To avoid duplicates
            $loaded_node_ids = array_keys($node_entities_by_predicate[$predicate] ?? []);
            $not_loaded_yet_is = array_diff($nodelist, $loaded_node_ids);
            if (is_array($not_loaded_yet_is) && !empty($not_loaded_yet_is)) {
              $ados_from_id = $this->entityTypeManager->getStorage('node')
                ->loadMultiple($not_loaded_yet_is);
              if (count($ados_from_id)) {
                $node_entities_by_predicate[$predicate] = ($node_entities_by_predicate[$predicate] ?? []) + $ados_from_id;
              }
            }
          }
        }
      }
      catch (\Throwable) {
        $this->loggerFactory->get('strawberryfield')->error($this->t('Error while computing parent ADOs for Node ID @nid', ['@nid' => $entity->id()]));
        $node_entities_by_predicate = [];
      }
    }
    return $node_entities_by_predicate;
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

  /**
   * @inheritDoc
   */
  public function getCountByProcessorsAndLevelInSolr(EntityInterface $entity, array $processors, string $processor_op = 'OR', bool $direct = TRUE, bool $children = FALSE, bool $grandchildren = FALSE, string $level_op = 'OR', array $indexes = []): int {
    $count = 0;

    if (!in_array($processor_op, ['OR','AND'])) {
      return $count;
    }
    if (!in_array($level_op, ['OR','AND'])) {
      return $count;
    }
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
      $parent_conditions = $query->createConditionGroup($level_op);
      /* Forcing here two fixed options */
      $processor_conditions = $query->createConditionGroup($processor_op);

      if (isset($allfields_translated_to_solr['parent_id']) && $direct) {
        $parent_conditions->addCondition('parent_id',  $entity->id());
      }
      // The property path for this is: target_id:field_descriptive_metadata:sbf_entity_reference_ispartof:nid
      // TODO: This needs a config form. For now let's document. Even if not present
      // It will not fail.
      if (isset($allfields_translated_to_solr['top_parent_id']) && $children) {
        $parent_conditions->addCondition('top_parent_id',  $entity->id());
      }
      // This fields should be target_id:field_descriptive_metadata:sbf_entity_reference_ispartof:field_descriptive_metadata:sbf_entity_reference_ispartof:nid
      if (isset($allfields_translated_to_solr['top_parent_parent_id']) && $children) {
        $parent_conditions->addCondition('top_parent_parent_id',  $entity->id());
      }


      if (count($parent_conditions->getConditions())) {
        $query->addConditionGroup($parent_conditions);
      }
      else {
        // If no Flavor to NODE conditions are set we can't do anything ok?
        // Would return all Flavors from the index. Too much.
        continue;
      }

      foreach ($processors as $processor) {
        $processor = trim($processor);
        $processor_conditions->addCondition('processor_id', $processor);
      }
      if (count($processor_conditions->getConditions())) {
        $query->addConditionGroup($processor_conditions);
      }
      $query->addCondition('search_api_datasource',
        'strawberryfield_flavor_datasource');

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
   * @param \Drupal\file\Entity\File $file
   * @param int $offset
   *    Where to start to read the file, starting from 0.
   * @param int $count
   *    Number of results, 0 will fetch all
   * @param bool $always_include_header
   *    Always return header even with an offset.
   *
   * @param bool $escape_characters
   *
   * @return array|null
   *   Returning array will be in this form:
   *    'headers' => $rowHeaders_utf8 or [] if $always_include_header == FALSE
   *    'data' => $table,
   *    'totalrows' => $maxRow,
   */
  public function csv_read(File $file, int $offset = 0, int $count = 0, bool $always_include_header = TRUE, bool $escape_characters = TRUE, string $caller_module = 'strawberryfield') {

    $wrapper = $this->streamWrapperManager->getViaUri($file->getFileUri());
    if (!$wrapper) {
      return NULL;
    }

    $url = $wrapper->getUri();
    $uri = $this->streamWrapperManager->normalizeUri($url);
    if (!is_file($uri)) {
      $message = $this->t(
        'CSV File referenced by @caller set for processing at @uri is no longer present. Check your composting times. Skipping',
        [
          '@uri' => $uri,
          '@caller' => $caller_module,
        ]
      );
      $this->loggerFactory->get($caller_module)->error($message);
      return NULL;
    }

    $spl = new \SplFileObject($url, 'r');
    if ($offset > 0) {
      // We only set this flags when an offset is present.
      // Because if not fgetcsv is already dealing with multi line CSV rows.
      $spl->setFlags(
        SplFileObject::READ_CSV |
        SplFileObject::READ_AHEAD |
        SplFileObject::SKIP_EMPTY |
        SplFileObject::DROP_NEW_LINE
      );
      if (!$escape_characters) {
        $spl->setCsvControl(',', '"', "");
      }
    }

    if ($offset > 0 && !$always_include_header) {
      // If header needs to be included then we offset later on
      // PHP 8.0.16 IS STILL BUGGY with SEEK.
      //$spl->seek($offset) does not work here.
      for ($i = 0; $i < $offset; $i++) {
        $spl->next();
      }

    }
    $data = [];
    $seek_to_offset = ($offset > 0 && $always_include_header);
    while (!$spl->eof() && ($count == 0 || ($spl->key() < ($offset + $count)))) {
      if (!$escape_characters) {
        $data[] = $spl->fgetcsv( ',', '"', "");
      }
      else {
        $data[] = $spl->fgetcsv();
      }
      if ($seek_to_offset) {
        for ($i = 0; $i < $offset; $i++) {
          $spl->next();
        }
        // PHP 8.0.16 IS STILL BUGGY with SEEK.
        //$spl->seek($offset); doe snot work here
        // So we do not process this again.
        $seek_to_offset = FALSE;
      }
    }

    $table = [];
    $maxRow = 0;

    $highestRow = count($data);
    if ($always_include_header) {
      $rowHeaders = $data[0] ?? [];
      $rowHeaders_utf8 = array_map(function($value) {
        $value = $value ?? '';
        $value = stripslashes($value);
        $value = function_exists('mb_convert_encoding') ? mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1') : utf8_encode($value);
        $value = strtolower($value);
        $value = trim($value);
        return $value;
      }, $rowHeaders);
      $headercount = count($rowHeaders);
    }
    else {
      $rowHeaders = $rowHeaders_utf8 = [];
      $not_a_header = $data[0] ?? [];
      $headercount = count($not_a_header);
    }

    if (($highestRow) >= 1) {
      // Returns Row Headers.

      $maxRow = 1; // at least until here.
      $rowindex = 0;
      foreach ($data as $rowindex => $row) {
        if ($rowindex == 0) {
          // Skip header
          continue;
        }
        // Ensure row is always an array.
        $row = $row ?? [];
        $flat = trim(implode('', $row));
        //check for empty row...if found stop there.
        $maxRow = $rowindex;
        if (strlen($flat) == 0) {
          break;
        }

        $row = $this->arrayEquallySeize(
          $headercount,
          $row
        );
        // Offsetting all rows by 1. That way we do not need to remap numeric parents
        $table[$rowindex + 1] = $row;
      }
      $maxRow = $maxRow ?? $rowindex;
    }

    return  [
      'headers' => $rowHeaders_utf8,
      'data' => $table,
      'totalrows' => $maxRow,
    ];
  }

  /**
   * Match different sized arrays.
   *
   * @param integer $headercount
   *   an array length to check against.
   * @param array $row
   *   a CSV data row
   *
   * @return array
   *  a resized to header size data row
   */
  public function arrayEquallySeize($headercount, $row = []):array {

    $rowcount = count($row);
    if ($headercount > $rowcount) {
      $more = $headercount - $rowcount;
      for ($i = 0; $i < $more; $i++) {
        $row[] = "";
      }

    }
    else {
      if ($headercount < $rowcount) {
        // more fields than headers
        // Header wins always
        $row = array_slice($row, 0, $headercount);
      }
    }

    return $row;
  }

}
