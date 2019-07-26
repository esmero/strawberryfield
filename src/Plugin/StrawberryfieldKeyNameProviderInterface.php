<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 2:24 PM
 */

namespace Drupal\strawberryfield\Plugin;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Component\Plugin\ConfigurablePluginInterface;

/**
 * Defines and Interface for StrawberryfieldKeyNameProvider Plugins
 *
 * Interface StrawberryfieldKeyNameProviderInterface
 *
 * @package Drupal\strawberryfield\Plugin
 */
interface StrawberryfieldKeyNameProviderInterface extends PluginInspectionInterface, PluginWithFormsInterface, ConfigurablePluginInterface{


  /**
   * Provides a list of Key name strawberryfield properties
   *
   * @param string $config_entity_id
   *   The Config Entity's id where this plugin instance's config is stored.
   *   This value comes from the config entity used to store all this settings
   *   and needed to generate separate cache bins for each
   *   Plugin Instance.
   *
   * @return mixed
   */
  public function provideKeyNames(string $config_entity_id);

  public function label();

  public function onDependencyRemoval(array $dependencies);



}