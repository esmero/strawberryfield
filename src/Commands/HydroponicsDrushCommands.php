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
    $done = [];

    //track when queue are empty for n cycles
    $idle = [];

    // Get which queues we should run:

    foreach($active_queues as $queue) {

      // Set number of idle cycle to wait
      $idle[$queue] = 3;

//      $done[$queue] = $loop->addPeriodicTimer(1.0, function ($timer) use ($loop, $queue) {
      $done[$queue] = $loop->addPeriodicTimer(1.0, function ($timer) use ($loop, $queue, &$idle) {
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
          $idle[$queue] -= 1;
        }

        else {
          // no empty so reset idle counter
          $idle[$queue] = 3;
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
        \Drupal::logger('hydroponics')->info("All queues are idle, closing timers");

        $loop->cancelTimer($timer);
        }
      }
    );

    $securitytimer = $loop->addTimer(720.0, function ($timer) use ($loop, $timer_ping, $idle_timer, &$done) {
      // Finish all if 720 seconds are reached
      \Drupal::logger('hydroponics')->info("720 seconds passed closing Hydroponics Service");
      $loop->cancelTimer($timer_ping);
      foreach($done as $queue_timer) {
        $loop->cancelTimer($queue_timer);
      }
      \Drupal::state()->set('hydroponics.queurunner_last_pid', 0);
      $loop->cancelTimer($idle_timer);
      $loop->stop();
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
