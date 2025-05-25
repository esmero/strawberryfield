<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 6/26/19
 * Time: 6:56 PM
 */

namespace Drupal\strawberryfield;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\DelayableQueueInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\IndexInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides a Hydroponics/background processing utility class.
 */
class StrawberryfieldHydroponicsService {

  use StringTranslationTrait;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface $configFactory

   */
  protected $configFactory;

  /**
   * The Module Handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface;
   */
  protected $moduleHandler;

  /**
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  private $queueManager;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;


  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * StrawberryfieldHydroponicsService constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   * @param \Psr\Log\LoggerInterface $hydroponics_logger
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    QueueWorkerManagerInterface $queue_manager,
    QueueFactory $queue_factory,
    LoggerInterface $hydroponics_logger
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->queueManager = $queue_manager;
    $this->queueFactory = $queue_factory;
    $this->logger = $hydroponics_logger;
  }


  /**
   * Processes cron queues.
   *
   * @param $name
   * @param int $time
   *
   * @return int
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processQueue($name, int $time = 120) {
    // Grab the defined cron queues.
    $info = $this->queueManager->getDefinition($name);
    if ($info) {
      // Make sure every queue exists. There is no harm in trying to recreate
      // an existing queue.
      $this->queueFactory->get($name)->createQueue();
      $queue_worker = $this->queueManager->createInstance($name);
      $end = time() + $time;
      $queue = $this->queueFactory->get($name, TRUE);
      $lease_time = $time;
      while (time() < $end && ($item = $queue->claimItem($lease_time))) {
        try {
          $this->logger->info('--- processing one item for @queue', [
            '@queue' => $name]
          );
          $queue_worker->processItem($item->data);
          $queue->deleteItem($item);
        }
        catch (RequeueException $e) {
          // The worker requested the task be immediately requeued.
          $queue->releaseItem($item);
        }
        catch (SuspendQueueException $e) {
          // If the worker indicates there is a problem with the whole queue,
          $queue->releaseItem($item);
          $this->logger->error('Exception was thrown by queue @queue during Hydroponics processing with error @e', [
              '@queue' => $name,
              '@e' => $e->getMessage(),
            ]
          );
        }
        catch (DelayedRequeueException $e) {
          // The worker requested the task not be immediately re-queued.
          // - If the queue doesn't support ::delayItem(), we should leave the
          // item's current expiry time alone.
          // - If the queue does support ::delayItem(), we should allow the
          // queue to update the item's expiry using the requested delay.
          if ($queue instanceof DelayableQueueInterface) {
            // This queue can handle a custom delay; use the duration provided
            // by the exception.
            $queue->delayItem($item, $e->getDelay());
          }
        }
        catch (\Exception $e) {
          // In case of any other kind of exception, log it and leave the item
          // in the queue to be processed again later.
          $this->logger->error('Exception was thrown by queue @queue during Hydroponics processing with error @e', [
              '@queue' => $name,
              '@e' => $e->getMessage(),
            ]
          );
        }
      }
      $this->logger->info('--- --- Lease time is out for  @queue', [
          '@queue' => $name]
      );
      return $queue->numberOfItems();
    }
    else {
      $this->logger->error('Queue definition for queue @queue during Hydroponics processing is missing. Bailing out. ', [
          '@queue' => $name,
        ]
      );
      return 0;
    }
  }

  /**
   * Processes Search API Indexes queues.
   *
   * @param \Drupal\search_api\IndexInterface $index
   * @param $batch_size
   * @param int $time
   *
   * @return int
   * @throws \Drupal\search_api\SearchApiException
   */
  public function processSearchApiIndex(IndexInterface $index, $batch_size, int $time = 120): int {
    $remaining_item_count = ($index->hasValidTracker() ? $index->getTrackerInstance()->getRemainingItemsCount() : 0);
    if (!\Drupal::lock()->lockMayBeAvailable($index->getLockId())) {
      $this->logger->warning('--- Items for Search API index @index are being indexed in a different process that Hydroponics, skippig until our next cycle.', [
          '@index' => $index->label(),
        ]
      );
      return $remaining_item_count;
    }
    if (!$remaining_item_count) {
      $this->logger->info('--- Search API index @index has no items left. All (and well) done.', [
          '@index' => $index->label(),
        ]
      );
      return 0;
    }
    if (!$batch_size) {
      $this->logger->warning('--- Ups. Search API index @index will not be processed because batch size is 0.', [
          '@index' => $index->label(),
        ]
      );
      return 0;
    }
    $end = time() + $time;
    $indexed = 0;
    while (time() < $end) {
      // Determine the number of items to index for this run.
      $to_index = min(($remaining_item_count - $indexed), $batch_size);
      $to_index = (int) $to_index > 0 ? (int) $to_index : 0;
      if (!$to_index) {
        $this->logger->info($this->t('--- Nothing left to index for Search API index @index. Moving on!',
          [
            '@index' => $index->label(),
            '@to_index' => $to_index,
          ]
        ));
        break;
      }
      // Catch any exception that may occur during indexing.
      try {
        // Index items limited by the given count.
        $indexed = $indexed + $index->indexItems($to_index);
        // Increment the indexed result and progress.

        // Display progress message.
        if ($indexed > 0) {
          $message = $this->formatPlural($indexed, '--- Successfully indexed 1 item on Search API index @index.', '--- Successfully indexed @count items on Search API index @index.', ['@index' => $index->label()]);
          $this->logger->info($message);
        }
        else {
          $this->logger->info($this->t('--- We sent @to_index items for Search API index @index but nothing was indexed',
            [
              '@index' => $index->label(),
              '@to_index' => $to_index,
            ]
          ));
        }
      }
      catch (\Exception $e) {
        // Log exception to watchdog and abort the batch job.
        $message = $this->t('--- An error occurred during indexing on Search API index @index: @message', [
          '@index' => $index->label(),
          '@message' => $e->getMessage()
        ]);
        $this->logger->error($message);
        break;
      }
    }
    $this->logger->info('--- --- Lease time is out for Search API index @index', [
        '@index' => $index->label()
      ]
    );
    return $index->getTrackerInstance()->getRemainingItemsCount();
  }


  /**
   * Checks if Background Drush can run, and if so, sends it away.
   */
  public function run() {
    $config = $this->configFactory->get('strawberryfield.hydroponics_settings');
    if ($config->get('active')) {
      global $base_url;
      $queuerunner_pid = (int) \Drupal::state()->get('hydroponics.queurunner_last_pid', 0);
      $lastRunTime = intval(\Drupal::state()->get('hydroponics.heartbeat'));
      $currentTime = intval(\Drupal::time()->getRequestTime());
      $running_posix = posix_kill($queuerunner_pid, 0);
      if (!$running_posix || !$queuerunner_pid) {
        $this->logger->info('Hydroponics Service Not running, starting, time passed since last seen @time', [
          '@time' => ($currentTime - $lastRunTime)]
        );
        $path = $config->get('drush_path');
        if (empty($path)) {
          $path = '/var/www/html/vendor/drush/drush/drush';
        }
        $path = escapeshellcmd($path);
        $cmd = $path.' archipelago:hydroponics --quiet --uri=' . $base_url;
        $home = $config->get('home_path');
        if (!empty($home)) {
          $home = escapeshellcmd($home);
          $cmd = "export HOME='".$home."'; ".$cmd;
        }

        $pid = exec(
          sprintf("%s > /dev/null 2>&1 & echo $!", $cmd)
        );
        \Drupal::state()->set('hydroponics.queurunner_last_pid', $pid);
        $this->logger->info('PID for Hydroponics Service: @pid', [
            '@pid' => $pid]
        );
      } else {
        $this->logger->info('Hydroponics Service already running with PID @pid, time passed since last seen @time', [
            '@time' => ($currentTime - $lastRunTime),
            '@pid' => $queuerunner_pid
          ]
        );
      }
    }
  }

  public function checkRunning() {
    $queuerunner_pid = (int) \Drupal::state()->get('hydroponics.queurunner_last_pid', 0);
    $lastRunTime = intval(\Drupal::state()->get('hydroponics.heartbeat'));
    $currentTime = intval(\Drupal::time()->getRequestTime());
    $running_posix = posix_kill($queuerunner_pid, 0);

    if (!$running_posix || !$queuerunner_pid) {
     $return = [
       'running' => FALSE,
       'message' =>  $this->t('Hydroponics Service Not running, time passed since last seen @time', [
        '@time' => ($currentTime - $lastRunTime)]),
        'PID' => $queuerunner_pid
     ];
    } else {
      $return = [
       'running' => TRUE,
       'message' =>  $this->t('Hydroponics Service Is Running on PID @pid, time passed since last seen @time', [
        '@time' => ($currentTime - $lastRunTime),
        '@pid' => $queuerunner_pid,
       ]),
       'PID' => $queuerunner_pid
     ];
    }
    return $return;
  }

  public function stop() {
    $queuerunner_pid = (int) \Drupal::state()->get('hydroponics.queurunner_last_pid', 0);
    error_log($queuerunner_pid);
    $running_posix = posix_kill($queuerunner_pid, 0);
    if (!$running_posix || !$queuerunner_pid) {
      return NULL;
    } else {
      if (extension_loaded('pcntl')) {
        $running_posix = posix_kill($queuerunner_pid, SIGTERM);
      }
      else {
        $running_posix = posix_kill($queuerunner_pid, 15);
      }
      error_log($running_posix);
      sleep(2);
      if (!$running_posix) {
        $errorcode = posix_get_last_error();
        $this->logger->info('Hydroponics Service could not stop because of @code', [
            '@code' => posix_strerror($errorcode)]
        );

        return posix_strerror($errorcode);
      } else {
        \Drupal::state()->set('hydroponics.queurunner_last_pid', 0);
        sleep(1);
        return "Successfully Stopped. Thanks";
      }
    }
  }



}
