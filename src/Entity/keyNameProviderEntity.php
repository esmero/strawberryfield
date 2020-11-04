<?php

namespace Drupal\strawberryfield\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the Strawberry Key Name Providers entity.
 *
 * @ConfigEntityType(
 *   id = "strawberry_keynameprovider",
 *   label = @Translation("Strawberry Key Name Providers"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\strawberryfield\keyNameProviderEntityListBuilder",
 *     "form" = {
 *       "add" = "Drupal\strawberryfield\Form\keyNameProviderEntityForm",
 *       "edit" = "Drupal\strawberryfield\Form\keyNameProviderEntityForm",
 *       "delete" = "Drupal\strawberryfield\Form\keyNameProviderEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\strawberryfield\keyNameProviderEntityHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "strawberry_keynameprovider",
 *   admin_permission = "administer site configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "canonical" = "/admin/structure/strawberry_keynameprovider/{strawberry_keynameprovider}",
 *     "add-form" = "/admin/structure/strawberry_keynameprovider/add",
 *     "edit-form" = "/admin/structure/strawberry_keynameprovider/{strawberry_keynameprovider}/edit",
 *     "delete-form" = "/admin/structure/strawberry_keynameprovider/{strawberry_keynameprovider}/delete",
 *     "collection" = "/admin/structure/strawberry_keynameprovider"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "pluginid",
 *     "pluginconfig",
 *     "active"
 *   }
 * )
 */
class keyNameProviderEntity extends ConfigEntityBase implements keyNameProviderEntityInterface {

  /**
   * The Strawberry Key Name Providers ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The Strawberry Key Name Providers label.
   *
   * @var string
   */
  protected $label = '';

  /**
   * The plugin id that will be initialized with this config.
   *
   * @var string
   */
  protected $pluginid;


  /**
   * If the plugin should be processed or not.
   *
   * @var boolean
   */
  protected $active = true;

  /**
   * Plugin specific Config
   *
   * @var array
   */
  protected $pluginconfig = [];

  /**
   * @return string
   */
  public function getPluginid(): string {
    return $this->pluginid ?: '';
  }

  /**
   * @param string $pluginid
   */
  public function setPluginid(string $pluginid): void {
    $this->pluginid = $pluginid;
  }

  /**
   * @return bool
   */
  public function isActive(): bool {
    return $this->active;
  }

  /**
   * @param bool $active
   */
  public function setActive(bool $active): void {
    $this->active = $active;
  }

  /**
   * @return array
   */
  public function getPluginconfig(): array {
    return $this->pluginconfig ?:[];
  }

  /**
   * @param array $pluginconfig
   */
  public function setPluginconfig(array $pluginconfig): void {
    $this->pluginconfig = $pluginconfig;
  }




}
