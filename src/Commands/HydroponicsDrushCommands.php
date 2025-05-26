<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 6/2/20
 * Time: 11:15 AM
 */

namespace Drupal\strawberryfield\Commands;

use Drush\Commands\DrushCommands;
use Drush\Exec\ExecTrait;
use Drush\Runtime\Runtime;
use React\EventLoop\Loop;


/**
 * A SBF Drush commandfile for ground-less Strawberry Growing.
 *
 * Forks and executes a reactPHP loop to handle queues in background
 *
 */
class HydroponicsDrushCommands extends DrushCommands {

  use ExecTrait;

  /**
   * Forks itself and starts a reactPHP loop to run Queues
   *
   * @throws \Exception if something goes wrong
   *
   * @command archipelago:hydroponics
   * @aliases ap-hy
   *
   * @usage archipelago:hydroponics
   */
  public function hydroponics(
  ) {

    $loop = Loop::get();
    $timer_ping = $loop->addPeriodicTimer(3.0, function () {
      // store a heartbeat every 3 seconds.
      $currenttime = \Drupal::time()->getCurrentTime();
      \Drupal::state()->set('hydroponics.heartbeat', $currenttime);
    });
    $active_queues = \Drupal::config('strawberryfield.hydroponics_settings')->get('queues');
    $active_indexes = \Drupal::config('strawberryfield.hydroponics_settings')->get('search_api_indexes') ?? [];
    $indexes_batch_size = \Drupal::config('strawberryfield.hydroponics_settings')->get('search_api_indexes_count') ?? 0;
    $done = [];

    //track when queue are empty for n cycles
    $idle = [];
    $search_api_idle = [];

    // Get which queues we should run:
    if (count($active_queues)) {
      \Drupal::logger('hydroponics')
        ->info("Hydroponics is waking up, will process the following queues: @queues", [
          '@queues' => implode(",", $active_queues)
        ]);
    }
     // Get which Search API Indexes we should run:
    $loaded_indexes = [];
    if (count($active_indexes) && $indexes_batch_size) {
      \Drupal::logger('hydroponics')
        ->info("Hydroponics is waking up for Search API Indexing and will process the following indexes: @indexes", [
          '@indexes' => implode(",", $active_indexes)
        ]);
      $loaded_indexes = \Drupal::getContainer()
        ->get('entity_type.manager')
        ->getStorage('search_api_index')
        ->loadMultiple($active_indexes);
    }


    foreach($active_queues as $queue) {
      // Set number of idle cycle to wait
      $idle['queue:'.$queue] = 3;

      $done['queue:'.$queue] = $loop->addPeriodicTimer(1.0, function ($timer) use ($loop, $queue, &$idle) {
        \Drupal::logger('hydroponics')->info("Starting to process queue @queue", [
          '@queue' => $queue
        ]);

        $number = \Drupal::getContainer()
          ->get('strawberryfield.hydroponics')
          ->processQueue($queue, 60);
          \Drupal::logger('hydroponics')->info("Finished processing queue @queue", [
          '@queue' => $queue
        ]);

        if ($number == 0) {
          \Drupal::logger('hydroponics')->info("No items left on queue @queue", [
            '@queue' => $queue
          ]);
          // decrement idle counter
          $idle['queue:'.$queue] -= 1;
        }
        else {
          // no empty so reset idle counter
          $idle['queue:'.$queue] = 3;
        }
      });
    }

    foreach($loaded_indexes as $index) {
      // Set number of idle cycle to wait
      $idle['search_api_index:'.$index->id()] = 3;

      $done['search_api_index:'.$index->id()] = $loop->addPeriodicTimer(1.0, function ($timer) use ($loop, $index, $indexes_batch_size, &$idle) {
        \Drupal::logger('hydroponics')->info("Starting to process Index @index with @batch increments", [
          '@index' => $index->label(),
          '@batch' => $indexes_batch_size,
        ]);

        $number = \Drupal::getContainer()
          ->get('strawberryfield.hydroponics')
          ->processSearchApiIndex($index, $indexes_batch_size, 120);
        \Drupal::logger('hydroponics')->info("Finished processing Search API Index @index", [
          '@index' => $index->label()
        ]);

        if ($number == 0) {
          \Drupal::logger('hydroponics')->info("No items left on Search API Index @index", [
            '@index' => $index->label()
          ]);
          // decrement idle counter
          $idle['search_api_index:'.$index->id()] -= 1;
        }
        else {
          // no empty so reset idle counter
          \Drupal::logger('hydroponics')->info("@number of items left on Search API Index @index", [
            '@index' => $index->label(),
            '@number' => $number
          ]);
          $idle['search_api_index:'.$index->id()] = 3;
        }
      });
    }

    $idle_timer = $loop->addPeriodicTimer(60.0, function ($timer) use ($loop, $timer_ping, &$done, &$idle) {
      // Finish all if all queues return 0 elements for at least 3 cycles
      // Check this every 60 s
      $all_idle = 1;
      foreach($idle as $queue_idle) {
        if ($queue_idle > 0) {
          $all_idle = 0;
        }
      }
      if ($all_idle === 1) {
        $loop->cancelTimer($timer_ping);
        foreach($done as $queue_timer) {
          $loop->cancelTimer($queue_timer);
        }
        \Drupal::state()->set('hydroponics.queurunner_last_pid', 0);
        \Drupal::logger('hydroponics')->info("All tasks are idle, closing timers");

        $loop->cancelTimer($timer);
        $loop->stop();
        }
      }
    );
    $time_to_expire = (int) \Drupal::config('strawberryfield.hydroponics_settings')->get('time_to_expire');
    if ($time_to_expire > 0 ) {
      $time_to_expire = round($time_to_expire, 1);
      $securitytimer = $loop->addTimer($time_to_expire,
        function ($timer) use ($loop, $timer_ping, $idle_timer, &$done, $time_to_expire) {
          // Finish all if Time to live in seconds is reached
          \Drupal::logger('hydroponics')
            ->info("@time_to_expire seconds passed closing Hydroponics Service", [
              '@time_to_expire' => $time_to_expire,
            ]);
          $loop->cancelTimer($timer_ping);
          foreach ($done as $queue_timer) {
            $loop->cancelTimer($queue_timer);
          }
          \Drupal::state()->set('hydroponics.queurunner_last_pid', 0);
          $loop->cancelTimer($idle_timer);
          $loop->stop();
        }
      );
    }

    /* TODO recompile with PCNTL enabled
    \pcntl_signal(SIGINT, 'signalhandler');
    \pcntl_signal_dispatch();
    $signalhandler = function ($signal) use ($loop) {
      error_log('We got a signal, breaking');
      $loop->stop();
    };
    */

    $loop->run();
    Runtime::setCompleted();
  }
}
