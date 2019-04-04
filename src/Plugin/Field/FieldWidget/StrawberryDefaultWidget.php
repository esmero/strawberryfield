<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 9:26 PM
 */

namespace Drupal\strawberryfield\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextareaWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
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
class StrawberryDefaultWidget extends StringTextareaWidget {

  use MessengerTrait;
  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Be smart, don't overprocess and empty field.
    $prettyjson = "";
    if (!$items[$delta]->isEmpty()) {
      $rawjson = $items[$delta]->value;
      $prettyjson = $rawjson;
      $objectjson = json_decode($rawjson, FALSE);
      $json_error = json_last_error();
      if ($json_error == JSON_ERROR_NONE) {
        $prettyjson = json_encode($objectjson, JSON_PRETTY_PRINT);
      }
      else {
        // This should never happen since the basefield has a JSON symfony validator.
        $this->messenger()->addError(
          $this->t(
            'Looks like your stored field data is not in JSON format.<br> JSON says: @jsonerror <br>. Please correct it!',
            [
              '@jsonerror' => json_last_error_msg()
            ]
          )
        );
      }
    }

    $element['value'] = $element + [
        '#type' => 'textarea',
        '#default_value' => $prettyjson,
        '#rows' => $this->getSetting('rows'),
        '#placeholder' => $this->getSetting('placeholder'),
        '#attributes' => ['class' => ['js-text-full', 'text-full']],
      ];

    return $element;
  }

}
