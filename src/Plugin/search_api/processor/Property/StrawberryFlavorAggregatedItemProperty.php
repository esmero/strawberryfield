<?php

namespace Drupal\strawberryfield\Plugin\search_api\processor\Property;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Processor\ConfigurablePropertyBase;

/**
 * Defines a "Strawberry Flavor Aggregated Item" property.
 *
 * @see \Drupal\strawberryfield\Plugin\search_api\processor\StrawberryFlavorAggregate
 */
class StrawberryFlavorAggregatedItemProperty extends ConfigurablePropertyBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'roles' => [AccountInterface::ANONYMOUS_ROLE],
      'processor_ids' => '',
      'join_fields' => ['top_parent_id','parent_id']
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(FieldInterface $field, array $form, FormStateInterface $form_state) {
    $configuration = $field->getConfiguration() +  $this->defaultConfiguration();
    $index =  $field->getIndex();
    $join_fields = $this->getSbfNidFields($index) ?? ['top_parent_id','parent_id'];
    // Note we want to aggregate across indexes in case we move
    // SBF into another. So we don't use this $index here.
    $form['#tree'] = TRUE;

    $processor_ids = explode(',',$configuration['processor_ids'] ?? '');
    $processor_ids = array_filter(
      array_map('trim', $processor_ids)
    );

    $configuration['processor_ids'] = implode(",", $processor_ids);

    $roles = user_role_names();
    $form['roles'] = [
      '#type' => 'select',
      '#title' => $this->t('User roles'),
      '#description' => $this->t('Flavors inherit its parent ADO permissions but as future proof config we recommend to just use "@anonymous" here to prevent data leaking out to unauthorized roles.', ['@anonymous' => $roles[AccountInterface::ANONYMOUS_ROLE]]),
      '#options' => $roles,
      '#default_value' => $configuration['roles'],
      '#required' => TRUE,
    ];

    $form['processor_ids'] = [
      '#type' => 'textfield',
      '#description' => $this->t('A comma separated list of Strawberry Runners Plugins/Processors Plugin machine names that generated Indexed Strawberry Flavors for an ADO that you want to aggregate. e.g ocr'),
      '#title' => $this->t('The SBR processor ids'),
      '#default_value' => $configuration['processor_ids'],
      '#required' => TRUE,
    ];

    $form['join_fields'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#options' => $join_fields,
      '#title' => $this->t('Strawberry Flavor fields referencing a parent Node ID (any level) to be used for aggregating.'),
      '#description' => $this->t('Select the fields that reference Parent Nodes or ADOs to be used to Aggregate the Flavors to them.'),
      '#default_value' => $configuration['join_fields'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * Retrieves a list of all available Flavor to ADO NID fields.
   *
   * @return string[]
   *   An options list of SBF to ADO field identifiers mapped to their prefixed
   *   labels.
   */
  protected function getSbfNidFields($index) {
    $fields = [];
    $fields_info = $index->getFields();
    foreach ($fields_info as $field_id => $field) {
      if (($field->getDatasourceId() == 'strawberryfield_flavor_datasource') && ($field->getType() == "integer")) {
        $property_path = $field->getPropertyPath();
        $property_path_parts = explode(":", $property_path ?? '');
        if (end($property_path_parts) == "nid" || $property_path == 'parent_id') {
          $fields[$field_id] = $field->getPrefixedLabel() . '('
            . $field->getFieldIdentifier() . ')';
        }
      }
    }
    return $fields;
  }

}
