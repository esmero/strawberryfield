<?php

namespace Drupal\strawberryfield\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ConfigurationForm for Queue Worker Selection to be run by Hydroponics Service.
 */
class HydroponicsSettingsForm extends ConfigFormBase {


  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The Drupal state storage.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The Drupal module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * @var \Drupal\Core\Queue\QueueWorkerManager
   */
  private $queueWorkerManager;

  /**
   * @var array|NULL
   */
  private $queues = [];

  /**
   * OverviewForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * @param \Drupal\Core\State\StateInterface $state
   * @param \Drupal\Core\Extension\ModuleHandler $module_handler
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queueWorkerManager
   * @param \Drupal\queue_ui\QueueUIManager $queueUIManager
   * @param \Drupal\Core\Messenger\Messenger $messenger
   */
  public function __construct(ConfigFactoryInterface $config_factory, QueueFactory $queue_factory, AccountInterface $current_user, StateInterface $state, ModuleHandler $module_handler, QueueWorkerManagerInterface $queueWorkerManager, Messenger $messenger) {
    parent::__construct($config_factory);
    $this->queueFactory = $queue_factory;
    $this->currentUser = $current_user;
    $this->state = $state;
    $this->moduleHandler = $module_handler;
    $this->queueWorkerManager = $queueWorkerManager;
    $this->messenger = $messenger;
    $this->queues = $this->queueWorkerManager->getDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'strawberryfield.hydroponics_settings'
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'strawberryfield_hydroponics_settings_form';
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('queue'),
      $container->get('current_user'),
      $container->get('state'),
      $container->get('module_handler'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('messenger')
    );
  }


  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('strawberryfield.hydroponics_settings');

    $active =  $config->get('active') ? $config->get('active') : FALSE;
    $drush_path = $config->get('drush_path') ?  $config->get('drush_path') : NULL;
    $home_path = $config->get('home_path') ?  $config->get('home_path') : NULL;
    $enabled_queues =  !empty($config->get('queues')) ? array_flip($config->get('queues')) : [];

    $form['active'] =  [
      '#title' => 'Check to enabled Hydroponics Queue Background processing service wakeup during Drupal Cron.',
      '#type' => 'checkbox',
      '#required' => FALSE,
      '#default_value' => $active,
    ];
    $form['advanced'] =  [
      '#type' => 'details',
      '#title' => 'Advanced settings',
      '#description' => 'If you are not running under under the esmero-php:7.x docker containers you need to provide the following settings'
    ];
    $form['advanced']['drush_path'] =  [
      '#title' => 'The full system path to your composer vendor drush installation (including the actual drush php script).',
      '#description' => 'For a standard archipelago-deployment docker the right path is "/var/www/html/vendor/drush/drush/drush"',
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => !empty($drush_path) ? $drush_path : '/var/www/html/vendor/drush/drush/drush',
      '#prefix' => '<span class="drush_path-validation"></span>',
      '#ajax' => [
        'callback' => [$this, 'validateDrush'],
        'effect' => 'fade',
        'wrapper' => 'drush_path-validation',
        'method' => 'replace',
        'event' => 'change'
      ]
    ];

    $form['advanced']['home_path'] =  [
      '#title' => 'A full system path we can use as $HOME directory for your webserver user.',
      '#description' => 'For a standard archipelago-deployment via Docker this is not needed. For others the webserver user (e.g www-data) may need at least read permissions',
      '#type' => 'textfield',
      '#required' => FALSE,
      '#default_value' => !empty($home_path) ? $home_path : NULL
    ];

    $form['table-row'] = [
      '#type' => 'table',
      '#prefix' => '<div id="table-fieldset-wrapper">',
      '#suffix' => '</div>',
      '#header' => [
        $this->t('Queue Name'),
        $this->t('Items'),
        $this->t('Run via Hydroponics Background Processor'),
      ],
      '#empty' => $this->t('Sorry, There are no items!'),
    ];


    $queues = (isset($this->queues)) ? $this->queues : [];
    foreach ($queues as $name => $queue_definition) {
      /** @var QueueInterface $queue */
      $queue = $this->queueFactory->get($name);

      $form['table-row'][$name] = [
        'title' => [
          '#markup' => (string) $queue_definition['title'],
        ],
        'items' => [
          '#markup' => $queue->numberOfItems(),
        ],
        'active' => [
          '#type' => 'checkbox',
          '#required' => FALSE,
          '#default_value' => isset($enabled_queues[$name]) ? TRUE: FALSE
        ]
      ];

    }

    return parent::buildForm($form, $form_state);
  }



  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    parent::validateForm(
      $form,
      $form_state
    ); // TODO: Change the autogenerated stub

  }
  public function validateDrush(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $command = rtrim($form_state->getValue('drush_path'), '/');
    $command = $command.' --version';
    $canrun = \Drupal::service('strawberryfield.utility')->verifyDrush($command);
    if (!$canrun) {
      $response->addCommand(new InvokeCommand('#edit-drush-path', 'addClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-drush-path', 'removeClass', ['ok']));
      $response->addCommand(new MessageCommand('Drush path is not valid.', NULL, ['type' => 'error', 'announce' => 'Drush path is not valid.']));

    } else {
      $response->addCommand(new InvokeCommand('#edit-drush-path', 'removeClass', ['error']));
      $response->addCommand(new InvokeCommand('#edit-drush-path', 'addClass', ['ok']));
      $response->addCommand(new MessageCommand('Drush path is valid!', NULL, ['type' => 'status', 'announce' => 'Drush path is valid!']));

    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $enabled = [];
    $global_active = (bool) $form_state->getValue('active');
    $drush_path = rtrim($form_state->getValue('drush_path'), '/');
    $home_path = rtrim($form_state->getValue('home_path'), '/');
    foreach($form_state->getValue('table-row') as $queuename => $queue) {
      if ($queue['active'] == 1) {
        $enabled[] = $queuename;
      }
    }

    $this->config('strawberryfield.hydroponics_settings')
      ->set('active', $global_active)
      ->set('drush_path', $drush_path)
      ->set('home_path', $home_path)
      ->set('queues', $enabled)
      ->save();
    parent::submitForm($form, $form_state);
  }
}
