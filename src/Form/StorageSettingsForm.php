<?php

namespace Drupal\strawberryfield\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;

/**
 * ConfigurationForm for Strawberryfield File Storage.
 */
class StorageSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'strawberryfield.storage_settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'strawberryfield_storage_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('strawberryfield.storage_settings');
    $scheme_options = OcflHelper::getVisibleStreamWrappers();
    $form['file_scheme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Storage Scheme for Persisting Files'),
      '#description' => $this->t('Please provide your prefered Storage Scheme for Persisting Strawberryfield managed Files'),
      '#default_value' => $config->get('file_scheme'),
      '#options' => $scheme_options,
      '#required' => TRUE

    ];

    $form['object_file_scheme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Storage Scheme for Persisting Digital Objects'),
      '#description' => $this->t('Please provide your prefered Storage Scheme for Persisting Digital Objects as JSON Files'),
      '#default_value' => $config->get('object_file_scheme'),
      '#options' => $scheme_options,
      '#required' => TRUE
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('strawberryfield.storage_settings')
      ->set('file_scheme', $form_state->getValue('file_scheme'))
      ->set('object_file_scheme', $form_state->getValue('object_file_scheme'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}