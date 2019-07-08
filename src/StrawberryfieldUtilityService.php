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
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\Core\Entity\ContentEntityInterface;

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
   * The Module Handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface;
   */
  protected $moduleHandler;

  public function __construct(
    FileSystemInterface $file_system,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler
  ) {
    $this->fileSystem = $file_system;
    $this->entityTypeManager = $entity_type_manager;
    $this->configfactory = $config_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Checks if Content entity bears SBF and if so returns machine names
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
}