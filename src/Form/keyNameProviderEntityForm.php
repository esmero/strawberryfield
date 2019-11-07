<?php

namespace Drupal\strawberryfield\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\strawberryfield\Entity\keyNameProviderEntity;
use Drupal\Component\Utility\NestedArray;

/**
 * Class keyNameProviderEntityForm.
 */
class keyNameProviderEntityForm extends EntityForm {


  /**
   * The StrawberryfieldKeyNameProvider Plugin Manager.
   *
   * @var StrawberryfieldKeyNameProviderManager;
   */
  protected $strawberryfieldKeyNameProviderManager;

  public function __construct(StrawberryfieldKeyNameProviderManager $strawberryfieldKeyNameProviderManager) {
    $this->strawberryfieldKeyNameProviderManager = $strawberryfieldKeyNameProviderManager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('strawberryfield.keyname_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /* @var keyNameProviderEntity $strawberry_keynameprovider */
    $strawberry_keynameprovider = $this->entity;

    //@TODO allow people to select to which field instance this applies
    // Right now to all strawberry fields.
    // That will require some logic into \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $strawberry_keynameprovider->label(),
      '#description' => $this->t("Label for the Strawberry Key Name Providers."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $strawberry_keynameprovider->id(),
      '#machine_name' => [
        'exists' => '\Drupal\strawberryfield\Entity\keyNameProviderEntity::load',
      ],
      '#disabled' => !$strawberry_keynameprovider->isNew(),
    ];

    $ajax = [
      'callback' => [get_class($this), 'ajaxCallback'],
      'wrapper' => 'keynameproviderentity-ajax-container',
    ];
    /* @var \Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderManager $keyprovider_plugin_definitions */
    $keyprovider_plugin_definitions = $this->strawberryfieldKeyNameProviderManager->getDefinitions();
    foreach ($keyprovider_plugin_definitions as $id => $definition) {
      $options[$id] = $definition['label'];
    }

    $form['pluginid'] = [
      '#type' => 'select',
      '#title' => $this->t('Strawberry Key Name Provider Plugin'),
      '#default_value' => $strawberry_keynameprovider->getPluginid(),
      '#options' => $options,
      "#empty_option" =>t('- Select One -'),
      '#required'=> true,
      '#ajax' => $ajax
    ];

    $form['container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'keynameproviderentity-ajax-container'],
      '#weight' => 100,
      '#tree' => true
    ];

    $pluginid = $form_state->getValue('pluginid')?:$strawberry_keynameprovider->getPluginid();
    if (!empty($pluginid))  {
      $this->messenger()->addMessage($form_state->getValue('pluginid'));
      $form['container']['pluginconfig'] = [
        '#type' => 'container',
        '#parents' => ['pluginconfig']
      ];
      $parents = ['container','pluginconfig'];
      $elements = $this->strawberryfieldKeyNameProviderManager->createInstance($pluginid,[])->settingsForm($parents, $form_state);
      $pluginconfig = $strawberry_keynameprovider->getPluginconfig();

      $form['container']['pluginconfig'] = array_merge($form['container']['pluginconfig'],$elements);
      if (!empty($pluginconfig)) {
        foreach ($pluginconfig as $key => $value) {
            if (isset($form['container']['pluginconfig'][$key])) {
              ($form['container']['pluginconfig'][$key]['#default_value'] = $value);
            }
        }
      }
    } else {
      $form['container']['pluginconfig'] = [
        '#type' => 'container',
      ];

    }

    $form['active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Is this plugin active?'),
      '#return_value' => TRUE,
      '#default_value' => $strawberry_keynameprovider->isActive(),
    ];

    //@TODO allow a preview of the processing via ajax

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $strawberry_keynameprovider = $this->entity;
    $status = $strawberry_keynameprovider->save();

    switch ($status) {
      case SAVED_NEW:
        $this->messenger->addStatus($this->t('Created the %label Strawberry Key Name Provider.', [
          '%label' => $strawberry_keynameprovider->label(),
        ]));
        break;

      default:
        $this->messenger->addStatus($this->t('Saved the %label Strawberry Key Name Provider.', [
          '%label' => $strawberry_keynameprovider->label(),
        ]));
    }
    $form_state->setRedirectUrl($strawberry_keynameprovider->toUrl('collection'));
  }

  /**
   * Ajax callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   An associative array containing entity reference details element.
   */
  public static function ajaxCallback(array $form, FormStateInterface $form_state) {
    $form_state->setRebuild();
    return $form['container'];
  }


}
