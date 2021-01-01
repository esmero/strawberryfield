<?php

namespace Drupal\strawberryfield\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\queue_ui\Form\OverviewForm;
use Drupal\queue_ui\QueueUIManager;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
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
   * @var \Drupal\queue_ui\QueueUIManager
   */
  private $queueUIManager;


  /**
   * OverviewForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * @param \Drupal\Core\State\StateInterface $state
   * @param \Drupal\Core\Extension\ModuleHandler $module_handler
   * @param \Drupal\Core\Queue\QueueWorkerManager $queueWorkerManager
   * @param \Drupal\queue_ui\QueueUIManager $queueUIManager
   * @param \Drupal\Core\Messenger\Messenger $messenger
   */
  public function __construct(ConfigFactoryInterface $config_factory, QueueFactory $queue_factory, AccountInterface $current_user, StateInterface $state, ModuleHandler $module_handler, QueueWorkerManager $queueWorkerManager, Messenger $messenger) {
    parent::__construct($config_factory);
    $this->queueFactory = $queue_factory;
    $this->currentUser = $current_user;
    $this->state = $state;
    $this->moduleHandler = $module_handler;
    $this->queueWorkerManager = $queueWorkerManager;
    $this->messenger = $messenger;
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
    $enabled_queues =  !empty($config->get('queues')) ? array_flip($config->get('queues')) : [];

    $form['active'] =  [
      '#title' => 'If Hydroponics Queue Background processing Service should run or not.',
      '#type' => 'checkbox',
      '#required' => FALSE,
      '#default_value' => $active,
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


    $queues = $this->queueWorkerManager->getDefinitions();
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

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $enabled = [];
    $global_active = (bool) $form_state->getValue('active');
    foreach($form_state->getValue('table-row') as $queuename => $queue) {
      if ($queue['active'] == 1) {
        $enabled[] = $queuename;
      }
    }

    $this->config('strawberryfield.hydroponics_settings')
      ->set('active', $global_active)
      ->set('queues', $enabled)
      ->save();
    parent::submitForm($form, $form_state);
  }
}
