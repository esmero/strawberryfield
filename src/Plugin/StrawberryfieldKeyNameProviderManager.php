<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 2:12 PM
 */

namespace Drupal\strawberryfield\Plugin;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Plugin\DefaultPluginManager;


/**
 * Provides the Strawberryfield KeyName Provider Plugin  Manager.
 *
 * Class StrawberryfieldKeyNameProviderManager
 *
 * @package Drupal\strawberryfield\Plugin
 */
class StrawberryfieldKeyNameProviderManager extends DefaultPluginManager{

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
  ) {
    parent::__construct(
      'Plugin/StrawberryfieldKeyNameProvider',
      $namespaces,
      $module_handler,
      'Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderInterface',
      'Drupal\strawberryfield\Annotation\StrawberryfieldKeyNameProvider'
    );

    $this->alterInfo('strawberryfield_strawberryfieldkeynameprovider_info');
    $this->setCacheBackend($cache_backend,'strawberryfield_strawberryfieldkeynameprovider_plugins');
  }


}