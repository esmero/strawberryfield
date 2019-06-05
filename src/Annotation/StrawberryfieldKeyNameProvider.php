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
   * @var label;
   *
   * @ingroup plugin_translatable;
   */
  public $label;

  /**
   * @var description;
   *
   * @ingroup plugin_translatable;
   */
  public $description;

  /**
   * @var processor_class;
   * Use to define which class will process the data from the JSON.
   * Example: \Drupal\strawberryfield\Plugin\DataType\StrawberryValuesFromJson
   *
   * @ingroup plugin_translatable;
   */
  public $processor_class;

}