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
   * @return array;
   */
  public function provideKeyNames();

  public function label();

  public function onDependencyRemoval(array $dependencies);



}