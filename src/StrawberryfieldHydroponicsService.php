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
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;
use Drupal\Core\State\StateInterface;

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
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * StrawberryfieldHydroponicsService constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   * @param \Psr\Log\LoggerInterface $hydroponics_logger
   * @param \Drupal\Core\State\StateInterface $state
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    QueueWorkerManagerInterface $queue_manager,
    QueueFactory $queue_factory,
    LoggerInterface $hydroponics_logger,
    StateInterface $state
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->queueManager = $queue_manager;
    $this->queueFactory = $queue_factory;
    $this->logger = $hydroponics_logger;
    $this->state = $state;
  }


  /**
   * Processes queue items for a time.
   *
   * @param $name
   * @param int $time
   *
   * @return int
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processQueue($name, $time = 120) {
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
        $this->logger->info('--- processing multiple items for @queue', [
          '@queue' => $name]
        );
        try {
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
      $this->logger->info('--- --- Lease time is out for @queue or queue empty', [
          '@queue' => $name]
      );

      return $queue->numberOfItems();
    }
    else {
      return 0;
    }
  }

  /**
   * Processes one item from a queue then return
   *
   * @param $name
   * @param int $time
   *
   * @return int
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processSingleItemQueue($name, $time = 120) {
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
      //process a single element
      if ($item = $queue->claimItem($lease_time)) {
        $this->logger->info('--- processing one item for @queue', [
          '@queue' => $name]
        );
        try {
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
      else {
        $this->logger->info('--- --- Queue empty @queue' , [
            '@queue' => $name]
        );
      }
      return $queue->numberOfItems();
    }
    else {
      return 0;
    }
  }

  /**
   * Count queue items.
   *
   * @param $name
   *
   * @return int
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function countQueue($name) {
    // Grab the defined cron queues.
    $info = $this->queueManager->getDefinition($name);
    if ($info) {
      // Make sure every queue exists. There is no harm in trying to recreate
      // an existing queue.
      $this->queueFactory->get($name)->createQueue();
      $queue = $this->queueFactory->get($name, TRUE);
      return $queue->numberOfItems();
    }
    else {
      return 0;
    }
  }

  /**
  * Return Max Heartbeat delay expected
  * It depends on processing type
  */
  public function getHearbeatMaxDelta() {
    $config = $this->configFactory->get('strawberryfield.hydroponics_settings');
    $processing_type = $config->get('processing_type') ? $config->get('processing_type') : "archipelago:hydroponics";
    $processing_monotime = $config->get('processing_monotime') ? $config->get('processing_monotime') : 60;
    switch ($processing_type) {
      case "archipelago:hydroponics":
        //if MONO then conservative, max queue process time + 5s
        $heartbeat_max_delta = $processing_monotime + 5;
        break;
      case "archipelago:hydroponicsmulti":
        //if MULTI:
        //due to child executed as process
        //hearbeat interval is near to real time value
        //so delta conservative could be heartbeat timer (3s) + 2s
        $heartbeat_max_delta = 5;
    }
    return $heartbeat_max_delta;
  }

  /**
   * Checks if Background Drush can run, and if so, sends it away.
   */
  public function run() {
    $config = $this->configFactory->get('strawberryfield.hydroponics_settings');
    if ($config->get('active')) {

      $processing_type = $config->get('processing_type') ? $config->get('processing_type') : "archipelago:hydroponics";

      global $base_url;
      $site_path = \Drupal::service('site.path'); // e.g.: 'sites/default'
      $site_path = explode('/', $site_path);
      $site_name = $site_path[1];
      $queuerunner_pid = (int) $this->state->get('hydroponics.queurunner_last_pid', 0);
      $lastRunTime = intval($this->state->get('hydroponics.heartbeat'));
      $currentTime = intval(\Drupal::time()->getRequestTime());
      $deltaTime = ($currentTime - $lastRunTime);

      $heartbeat_max_delta = $this->getHearbeatMaxDelta();

      $running_posix = FALSE;
      if ($queuerunner_pid > 0) {
        $running_posix = posix_kill($queuerunner_pid, 0);
      }
      //Normal stopped condition when all is ok
      if (($deltaTime > $heartbeat_max_delta) && ($queuerunner_pid <= 0) && !$running_posix) {
        $this->logger->info('Hydroponics Service Not running, starting, time passed since last seen @time', [
          '@time' => $deltaTime]
        );
        $path = $config->get('drush_path');
        if (empty($path)) {
          $path = '/var/www/html/vendor/drush/drush/drush';
        }
        $path = escapeshellcmd($path);

        //The parameter $processing_type is the drush command
        $cmd = $path.' '.$processing_type.' --quiet --uri=' . $base_url;
        $home = $config->get('home_path');
        if (!empty($home)) {
          $home = escapeshellcmd($home);
          $cmd = "export HOME='".$home."'; ".$cmd;
        }

        $pid = exec(
          sprintf("%s > /dev/null 2>&1 & echo $!", $cmd)
          //sprintf("%s > /dev/null & echo $!", $cmd)
        );
        $this->state->set('hydroponics.queurunner_last_pid', $pid);
        $this->logger->info('PID for Hydroponics Service: @pid', [
            '@pid' => $pid]
        );
      }
      //The normal running condition when all is ok
      elseif (($deltaTime <= $heartbeat_max_delta) && ($queuerunner_pid > 0) && $running_posix) {
        $this->logger->info('Hydroponics Service already running with PID @pid, time passed since last seen @time', [
          '@time' => $deltaTime,
          '@pid' => $queuerunner_pid
          ]
        );
      }
      //Something went wrong so log error and no action
      else {
        $this->logger->error('Hydroponics Service error status: PID @pid, time passed since last seen @time, on Process table @ptable', [
          '@time' => $deltaTime,
          '@pid' => $queuerunner_pid,
          '@ptable' => ($running_posix) ? "YES" : "NO"
          ]
        );
      }
    }
    return;
  }

  public function checkRunning() {
    $queuerunner_pid = (int) $this->state->get('hydroponics.queurunner_last_pid', 0);
    $lastRunTime = intval($this->state->get('hydroponics.heartbeat'));
    $currentTime = intval(\Drupal::time()->getRequestTime());
    $deltaTime = ($currentTime - $lastRunTime);

    $heartbeat_max_delta = $this->getHearbeatMaxDelta();

    $running_posix = FALSE;
    if ($queuerunner_pid > 0) {
      $running_posix = posix_kill($queuerunner_pid, 0);
    }
    //The normal running condition when all is ok
    if (($deltaTime <= $heartbeat_max_delta) && ($queuerunner_pid > 0) && $running_posix) {
      $return = [
       'running' => TRUE,
       'error' => FALSE,
       'message' =>  $this->t('Hydroponics Service Is Running on PID @pid, time passed since last seen @time', [
        '@time' => $deltaTime,
        '@pid' => $queuerunner_pid
       ]),
       'PID' => $queuerunner_pid
      ];
    }
    elseif (($queuerunner_pid <= 0) && !$running_posix) {
      if ($deltaTime > $heartbeat_max_delta) {
        //Normal stopped condition when all is ok
        $return = [
          'running' => FALSE,
          'error' => FALSE,
          'message' =>  $this->t('Hydroponics Service Not running, time passed since last seen @time s', [
           '@time' => $deltaTime
          ]),
          'PID' => $queuerunner_pid,
          'DELTA' => $deltaTime,
          'PTABLE' => $running_posix
        ];
      }
      else {
        //Probably stopped, wait max heartbeat delta to be sure
        $return = [
          'running' => FALSE,
          'error' => TRUE,
          'message' =>  $this->t('Hydroponics Service probably not running, last seen @time s, wait at least @maxdelta s before check', [
           '@time' => $deltaTime,
           '@maxdelta' => $heartbeat_max_delta
          ]),
          'PID' => $queuerunner_pid,
          'DELTA' => $deltaTime,
          'PTABLE' => $running_posix
        ];
      }
    }
    //Something went wrong. See logs and try a reset
    else {
     $return = [
       'running' => FALSE,
       'error' => TRUE,
       'message' =>  $this->t('Hydroponics Service ERROR! Check logs and try a reset. Time passed since last seen @time s, last PID @pid, on Process table @ptable', [
        '@time' => $deltaTime,
        '@pid' => $queuerunner_pid,
        '@ptable' => ($running_posix) ? "YES" : "NO"
       ]),
       'PID' => $queuerunner_pid,
       'DELTA' => $deltaTime,
       'PTABLE' => $running_posix
     ];
    }
    return $return;
  }

  //This function is used for stop and for reset
  public function stop() {
    $queuerunner_pid = (int) $this->state->get('hydroponics.queurunner_last_pid', 0);
    $lastRunTime = intval($this->state->get('hydroponics.heartbeat'));
    $currentTime = intval(\Drupal::time()->getRequestTime());
    $deltaTime = ($currentTime - $lastRunTime);

    $heartbeat_max_delta = $this->getHearbeatMaxDelta();

    $running_posix = FALSE;
    if ($queuerunner_pid > 0) {
      $running_posix = posix_kill($queuerunner_pid, 0);
    }
    //Normal stopped condition when all is ok
    if (($deltaTime > $heartbeat_max_delta) && ($queuerunner_pid <= 0) && !$running_posix) {
      return NULL;
    }
    //process in table and pid available regardless of deltaTime
    elseif (abs($queuerunner_pid) > 0 && $running_posix) {
      if (extension_loaded('pcntl')) {
        $running_posix = posix_kill(abs($queuerunner_pid), SIGTERM);
      }
      else {
        $running_posix = posix_kill(abs($queuerunner_pid), 15);
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
        $this->state->set('hydroponics.queurunner_last_pid', 0);
        sleep(1);
        return "Successfully Stopped. Thanks";
      }
    }
    //PID to clear, not running and not in ptable
    else {
      $this->state->set('hydroponics.queurunner_last_pid', 0);
      sleep(1);
      return "Successfully cleared pid. Thanks";
    }
  }
}
