<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 6/26/19
 * Time: 6:56 PM
 */

namespace Drupal\strawberryfield;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\search_api\Entity\Index;
/**
 * Provides a SBF utility class.
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
   * StrawberryfieldHydroponicsService constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    QueueWorkerManagerInterface $queue_manager,
    QueueFactory $queue_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->queueManager = $queue_manager;
    $this->queueFactory = $queue_factory;
  }

  /**
   * Processes cron queues.
   */
  public function processQueue($name, $time = 120) {
    // Grab the defined cron queues.
    error_log($name);
    $info = $this->queueManager->getDefinition($name);
    error_log(var_export($info, true));
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
            error_log('--- processing one time for '.$name);
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
            watchdog_exception('cron', $e);

          }
          catch (\Exception $e) {
            // In case of any other kind of exception, log it and leave the item
            // in the queue to be processed again later.
            watchdog_exception('cron', $e);
          }
        }
      }
  }


  public function run() {
    $config = $this->configFactory->get('strawberryfield.hydroponics_settings');
    if ($config->get('active')) {
      global $base_url;
      $site_path = \Drupal::service('site.path'); // e.g.: 'sites/default'
      $site_path = explode('/', $site_path);
      $site_name = $site_path[1];
      $queuerunner_pid = (int) \Drupal::state()->get('hydroponics.queurunner_last_pid', 0);

      $lastRunTime = intval(\Drupal::state()->get('hydroponics.heartbeat'));
      error_log($lastRunTime);
      $currentTime = intval(\Drupal::time()->getRequestTime());
      error_log('Saved PID' . $queuerunner_pid);
      error_log(var_export(posix_kill($queuerunner_pid, 0), TRUE));
      $running_posix = posix_kill($queuerunner_pid, 0);
      if (!$running_posix || !$queuerunner_pid) {
        error_log('Hydroponics Service Not running, starting');
        error_log('Current Time:'.$currentTime);
        error_log('last time seen was:'.$lastRunTime);
        error_log('Time passed:'.($currentTime -$lastRunTime));
        $cmd = 'drush archipelago:hydroponics --uri=' . $base_url;
        $pid = exec(
          sprintf("nohup %s > /tmp/hydroponics.log 2>&1 & echo $!", $cmd)
        );
        \Drupal::state()->set('hydroponics.queurunner_last_pid', $pid);
        error_log('New PID'. $pid);
      } else {
        error_log('is already running');
        error_log('Hydroponics Service Running with:'. $queuerunner_pid);
        error_log('Current Time:'.$currentTime);
        error_log('last time seen was:'.$lastRunTime);
        error_log('Time passed:'.($currentTime -$lastRunTime));
      }
    }
    else {
      return;
    }
  }



}
