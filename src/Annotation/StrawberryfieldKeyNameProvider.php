<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 2:26 PM
 */

namespace Drupal\strawberryfield\Annotation;
use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;

/**
 * Defines a StrawberryfieldKeyNameProvider item annotation object.
 *
 * Class StrawberryfieldKeyNameProvider
 *
 * @package Drupal\strawberryfield\Annotation
 *
 * @Annotation
 */
class StrawberryfieldKeyNameProvider extends Plugin {

  /**
   * The plugin id.
   *
   * @var string;
   */
  public $id;

  /**
   * @var Translation;
   *
   * @ingroup plugin_translatable;
   */
  public $label;

  /**
   * @var Translation;
   *
   * @ingroup plugin_translatable;
   */
  public $description;

}