<?php

namespace Drupal\strawberryfield\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\search_api\Entity\Index;
use Drupal\strawberryfield\StrawberryfieldUtilityService;

/**
 * ConfigurationForm for Breadcrumbs settings in Archipelago
 */
class BreadcrumbSettingsForm extends ConfigFormBase {

  /**
   * Constructs an ImportantSolrSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->setConfigFactory($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {

    return [
      'strawberryfield.breadcrumbs',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {

    return 'strawberryfield_breadcrumbs_settings_form';
  }


  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $breadcrumb_type = $this->config(
      'strawberryfield.breadcrumbs'
    );

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Select which Type of Breadcrumb Processing you want.'),
      '#options' => [
        'longest' => 'Use first Longest parentship trail',
        'smart' => 'Use the the most representative longest parentship trail based also on the shorter ones.',
        'shortest' => 'Use the shorter one. Useful when all paths end in the same parent.',
      ],
      '#default_value' => $breadcrumb_type->get('type'),
      "#empty_value" => NULL,
      '#empty_option' => '- Select Breadcrumb processing -',
      '#required' => TRUE,
    ];
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('If ADO breacrumb processing is enabled.'),
      '#default_value' => $breadcrumb_type->get('enabled'),
      '#required' => FALSE,
    ];


    return parent::buildForm($form, $form_state);
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->config('strawberryfield.breadcrumbs')
      ->set('type', $form_state->getValue('type'))
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->save();
    parent::submitForm($form, $form_state);
  }
}
