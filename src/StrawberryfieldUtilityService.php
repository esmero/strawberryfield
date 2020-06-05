<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 6/26/19
 * Time: 6:56 PM
 */

namespace Drupal\strawberryfield;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\search_api\Entity\Index; 
/**
 * Provides a SBF utility class.
 */
class StrawberryfieldUtilityService {

  use StringTranslationTrait;

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
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager;

   */
  protected $entityFieldManager;

  /**
   * A list of Field names of type SBF
   *
   * @var array|NULL
   *
   */
  protected $strawberryfieldMachineNames = NULL;

  public function __construct(
    FileSystemInterface $file_system,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    EntityFieldManagerInterface $entity_field_manager
  ) {
    $this->fileSystem = $file_system;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->entityFieldManager = $entity_field_manager;
    $this->strawberryfieldMachineNames = $this->getStrawberryfieldMachineNames();
  }

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
  public function bearsStrawberryfield(ContentEntityInterface $entity) {
    $hassbf = &drupal_static(__FUNCTION__);
    $entitytype = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $key = $entitytype . ":" . $bundle;
    if (!isset($hassbf[$key])) {
      $hassbf[$key] = [];
      $field_item_lists = $entity->getFields();
      foreach ($field_item_lists as $field) {
        if ($field->getFieldDefinition()->getType() == 'strawberryfield_field') {
          $hassbf[$key][] = $field->getFieldDefinition()->getName();
        }
      }
    }
    return $hassbf[$key];
  }

  /**
   * Returns a list of the machine names of all existing SBF fields
   *
   * @return array
   *  Returns array of names
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
   * Given a Bundle returns the SBF field machine names
   *
   * @return array
   *  Returns array of SBF names
   */
  public function getStrawberryfieldMachineForBundle($bundle = 'digital_object') {
    $all_bundled_fields = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    $all_sbf_fields = $this->getStrawberryfieldMachineNames();
    return array_intersect(array_keys($all_bundled_fields), $all_sbf_fields );
  }

  /**
   * Returns the Solr Fields in a Solr Index are from SBFs
   **
   * @param \Drupal\search_api\Entity\Index $index_entity
   *
   * @return array
   *  Returns array of solr fields only including those from SBFs
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
        $property_path_is_sbf[$field_name] = in_array($field_name, $this->strawberryfieldMachineNames);
      }
      $has_sbf_property_path = $property_path_is_sbf[$field_name];
      $has_node_datasource = array_key_exists('datasource_id', $field) ? $field['datasource_id'] === "entity:node" : false;

      if ($has_sbf_property_path && $has_node_datasource) {
        $sbf_solr_fields[$id] = $field;
      }
    }
    
    return $sbf_solr_fields;
  }

  /**
   * Checks if a given command exists and is executable.
   *
   * @param $command
   *
   * @return bool
   */
  public function verifyCommand($execpath) :bool {
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
}
