<?php

namespace Drupal\strawberryfield\Plugin\search_api\processor\Property;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
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
      'processor_ids' => [],

    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(FieldInterface $field, array $form, FormStateInterface $form_state) {
    $configuration = $field->getConfiguration();
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

    return $form;
  }

}
