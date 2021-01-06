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
   * StrawberryfieldHydroponicsService constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
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
          watchdog_exception('cron', $e);

        }
        catch (\Exception $e) {
          // In case of any other kind of exception, log it and leave the item
          // in the queue to be processed again later.
          watchdog_exception('cron', $e);
        }
      }
      $this->logger->info('--- --- Lease time is out for  @queue', [
          '@queue' => $name]
      );
      return $queue->numberOfItems();
    }
    else {
      return 0;
    }
  }


  /**
   * Checks if Background Drush can run, and if so, sends it away.
   */
  public function run() {
    $config = $this->configFactory->get('strawberryfield.hydroponics_settings');
    if ($config->get('active')) {
      global $base_url;
      $site_path = \Drupal::service('site.path'); // e.g.: 'sites/default'
      $site_path = explode('/', $site_path);
      $site_name = $site_path[1];
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
    else {
      return;
    }
  }



}
