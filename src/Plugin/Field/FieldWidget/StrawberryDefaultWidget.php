<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 9:26 PM
 */

namespace Drupal\strawberryfield\Plugin\FieldWidget;

use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextareaWidget;
/**
 * Plugin implementation of the 'strawberry_textarea' widget.
 *
 * @FieldWidget(
 *   id = "strawberry_textarea",
 *   label = @Translation("Text area  for Strawberry field editing"),
 *   field_types = {
 *     "strawberryfield_field"
 *   }
 * )
 */
class StrawberryDefaultWidget extends StringTextareaWidget {}
