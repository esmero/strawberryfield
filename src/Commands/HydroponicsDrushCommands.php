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
use React\EventLoop\Factory;

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

    $loop = Factory::create();
    $timer_ping = $loop->addPeriodicTimer(3.0, function () {
      // store a heartbeat every 3 seconds.
      $currenttime = \Drupal::time()->getCurrentTime();
      \Drupal::state()->set('hydroponics.heartbeat', $currenttime);
    });
    $active_queues = \Drupal::config('strawberryfield.hydroponics_settings')->get('queues');
    $processing_monotime = \Drupal::config('strawberryfield.hydroponics_settings')->get('processing_monotime');

    // Store timer per queue
    $done = [];

    //track when queue are empty for n cycles
    $idle = [];

    // Parameters (ToDO put on config page?)
    $queue_check_time = 1;
    $idle_timer_time = 60;
    $idle_timer_cycles = 5;

    // Get which queues we should run:

    foreach($active_queues as $queue) {

      // Set number of idle cycle to wait
      $idle[$queue] = $idle_timer_cycles;

      // Periodic timer for every queue
      $done[$queue] = $loop->addPeriodicTimer($queue_check_time, function ($timer) use ($loop, $queue, $idle_timer_cycles, $processing_monotime, &$idle) {
        \Drupal::logger('hydroponics')->info("Starting to process queue @queue. Idle counter @idle", [
          '@queue' => $queue,
          '@idle' => $idle[$queue]
        ]);

        if (\Drupal::getContainer()->get('strawberryfield.hydroponics')->countQueue($queue) > 0){
          //blocking call for no more then $processing_monotime
          $item_left = \Drupal::getContainer()
            ->get('strawberryfield.hydroponics')
            ->processQueue($queue, $processing_monotime);
            \Drupal::logger('hydroponics')->info("Finished processing queue @queue", [
            '@queue' => $queue
          ]);
          if ($item_left > 0) {
            \Drupal::logger('hydroponics')->info("Queue time processing reached. Items left on queue @queue", [
              '@queue' => $queue
            ]);
            // no empty so reset idle counter
            $idle[$queue] = $idle_timer_cycles;
          }
        }
        else {
          \Drupal::logger('hydroponics')->info("No items on queue @queue", [
            '@queue' => $queue
          ]);
          // decrement idle counter
          $idle[$queue] -= 1;
        }
      });
    }

    // idle check every $idle_timer_time s
    $idle_timer = $loop->addPeriodicTimer($idle_timer_time, function ($timer) use ($loop, $timer_ping, &$done, &$idle) {
      // Close main loop if all queues return 0 elements for at least N cycles
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
        $queuerunner_pid = (int) \Drupal::state()->get('hydroponics.queurunner_last_pid', 0);
        //set pid to negative to avoid lost of information in case of hang
        $queuerunner_pid = $queuerunner_pid * (-1);
        \Drupal::state()->set('hydroponics.queurunner_last_pid', $queuerunner_pid);
        \Drupal::logger('hydroponics')->info("All queues are idle, closing timers");

        $loop->cancelTimer($timer);
        }
      }
    );

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
