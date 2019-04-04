<?php

namespace Drupal\strawberryfield\Field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldItemList;


/**
 * Represents an entity field; that is, a list of StrawberrField item objects.
 *
 * An entity field is a list of field items, each containing a set of
 * properties. Note that even single-valued entity fields are represented as
 * list of field items, however for easy access to the contained item the entity
 * field delegates __get() and __set() calls directly to the first item.
 */
class StrawberryFieldItemList extends FieldItemList {

  /**
   * {@inheritdoc}
   */
  protected function defaultValueWidget(FormStateInterface $form_state) {

    // Force a non-required widget.
    $definition = $this->getFieldDefinition();
    $definition->setRequired(FALSE);
    $definition->setDescription('');
    // Force the use of our default RAW JSON strawberryField
    $widget = \Drupal::service('plugin.manager.field.widget')->getInstance(['field_definition' => $this->getFieldDefinition()]);

    $form_state->set('default_value_widget', $widget);

    return $form_state->get('default_value_widget');
  }

}