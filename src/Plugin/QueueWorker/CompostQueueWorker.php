<?php

namespace Drupal\strawberryfield\Plugin\QueueWorker;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
/**
 * Deal with Temporary File cleanup/composting.
 *
 * @QueueWorker(
 *   id = "sbf_compost_file",
 *   title = @Translation("Archipelago Temporary File Composter Queue Worker")
 * )
 */
class CompostQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Drupal\Core\Entity\EntityTypeManager definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The StreamWrapper Manager
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * The File System Service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private ImmutableConfig $config;

  /**
   * Constructor.
   *
   * @param array                                                    $configuration
   * @param string                                                   $plugin_id
   * @param mixed                                                    $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface           $entity_type_manager
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface        $logger_factory
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService    $strawberryfield_utility_service
   * @param \Drupal\Core\Messenger\MessengerInterface                $messenger
   * @param \Drupal\Core\File\FileSystemInterface                    $file_system
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   * @param \Drupal\Core\Extension\ModuleHandlerInterface            $module_handler
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    MessengerInterface $messenger,
    FileSystemInterface $file_system,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->messenger = $messenger;
    $this->fileSystem = $file_system;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->moduleHandler = $module_handler;
    $this->config = $config_factory->get('strawberryfield.storage_settings');
  }

  /**
   * Implementation of the container interface to allow dependency injection.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @return static
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      empty($configuration) ? [] : $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('strawberryfield.utility'),
      $container->get('messenger'),
      $container->get('file_system'),
      $container->get('stream_wrapper_manager'),
      $container->get('module_handler'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {

    /* $data will have the following structure
     (object) array(
        'uri' => 'private://webform/default_descriptive_metadata_ami/_sid_/cool file.jpg',
        'module' => 'strawberryfield',
        'timestamp' => 1652919451,
    )
*/
    if (!function_exists('str_starts_with')) {
      function str_starts_with($haystack, $needle) {
        return (string) $needle !== ''
          && strncmp(
            $haystack, $needle, strlen($needle)
          ) === 0;
      }
    }
    if (!function_exists('str_ends_with')) {
      function str_ends_with(string $haystack, string $needle): bool {
        $needle_len = strlen($needle);
        return ($needle_len === 0
          || 0 === substr_compare(
            $haystack, $needle, -$needle_len
          ));
      }
    }
    // Yes i know i'm being paranoid here!
    $unsafe_prefix = ["."];
    $unsafe_suffix = [".php", ".conf", ".yml", "settings"];

    $safe_paths
      = $safe_paths_default = [
      'temporary://',
      'private://webform/',
      's3://webform/'
    ];
    $this->moduleHandler->alter(
      'strawberryfield_compost_safe_basepaths',
      $safe_paths
    );
    // Do not let a module delete all
    if (empty($safe_paths)) {
      $safe_paths = $safe_paths_default;
    }

    // First check if the file is there at all
    if (!file_exists($data->uri)) {
      // Nothing to check, nothing to do. Done.
      return;
    }
    $safe = FALSE;
    foreach ($safe_paths as $prefix) {
      if (str_starts_with($data->uri, $prefix)) {
        $safe = TRUE;
        break;
      }
    }

    $base_name = $this->fileSystem->basename($data->uri);

    foreach ($unsafe_prefix as $prefix) {
      if (str_starts_with($base_name, $prefix)) {
        $safe = FALSE;
        break;
      }
    }

    foreach ($unsafe_suffix as $suffix) {
      if (str_ends_with($base_name, $suffix)) {
        $safe = FALSE;
        break;
      }
    }

    if (!$safe) {
      // return silently. Safer...
      return;
    }
    // Now check if the file is attached to an entity or not
    /** @var \Drupal\file\FileInterface[] $files */
    $files = $this->entityTypeManager
      ->getStorage('file')
      ->loadByProperties(['uri' => $data->uri]);
    /** @var \Drupal\file\FileInterface|null $file */
    $file = reset($files) ?: NULL;
    if ($file) {
      // return silently. Means some File entity took control over it
      // Nothing we can do. Eventually someone will trigger the event
      // Or Drupal will clean its mess.
      return;
    }
    // Finall, now check the modification date: This is documented to work with
    // S3:// too
    $file_timestamp = filemtime($data->uri);

    if (!$file_timestamp) {
      // return silently. Means we can not stat the file. Means we won't be able
      // to delete it.
      return;
    }

    $composting_time = ($file_timestamp + (int) ($this->config->get(
          'compost_maximum_age'
        ) ?? 21600) <= (new DrupalDateTime())->getTimestamp());

    if ($composting_time) {
      // Deal with possibly some Drupal 8 until release 1.1.0
      try {
        // This will log errors but we do not want to throw and exception
        // So we will get it
        // ::unlink() will be silent but we do want to keep logs around i guess?
        $uri = $data->uri ?? NULL;
        if ($uri) {
          $this->fileSystem->delete($uri);
        }
      }
      catch (FileException $exception) {
        // Fail silently. We won't be able to delete it anyways.
        return;
      }
    }
    else {
      if (class_exists('\Drupal\Core\Queue\DelayedRequeueException')) {
        throw new \Drupal\Core\Queue\DelayedRequeueException(
          0, 'Not yet the time to compost'
        );
      }
      else {
        // Drupal 8 Compat. This might make the queue run over this until
        // Lease time expires. Not optimal.
        throw new RequeueException(
          'Not yet the time to compost'
        );
      }
    }
  }
}
