<?php

namespace Drupal\strawberryfield\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class ConfigurationForm.
 */
class iiifConfigurationForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'strawberryfield.iiif_configuration',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'iiif_admin_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('strawberryfield.iiif_configuration');

    $form['pub_server_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public IIIF server URL'),
      '#description' => $this->t('Please provide Public IIIF server URL'),
      '#default_value' => $config->get('pub_server_url'),
    ];

    $form['int_server_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Internal IIIF server URL'),
      '#description' => $this->t('Please provide Internal IIIF server URL'),
      '#default_value' => $config->get('int_server_url'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('strawberryfield.iiif_configuration')
      ->set('pub_server_url', $form_state->getValue('pub_server_url'))
      ->set('int_server_url', $form_state->getValue('int_server_url'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
?>
