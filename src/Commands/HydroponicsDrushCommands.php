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
      error_log('pinging');
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
        error_log("Starting to process $queue");

        $number = \Drupal::getContainer()
          ->get('strawberryfield.hydroponics')
          ->processQueue($queue, 60);
        error_log("Finished processing $queue");

        if ($number == 0) {
          error_log("No items left for $queue");

          // decrement idle counter
          $idle[$queue] -= 1;
//          $loop->cancelTimer($timer);
        }

        else {
          // no empty so reset idle counter
          $idle[$queue] = 3;
        }

      });
    }

    $loop->addPeriodicTimer(60.0, function ($timer) use ($loop, $timer_ping, &$done, &$idle) {
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
        }
      }
    );

    $loop->addTimer(720.0, function ($timer) use ($loop, $timer_ping, &$done) {
      // Finish all if 720 seconds are reached
      error_log("All Done, 720 Seconds past, clearing the timers");
      $loop->cancelTimer($timer_ping);
        foreach($done as $queue_timer) {
          $loop->cancelTimer($queue_timer);
        }
        \Drupal::state()->set('hydroponics.queurunner_last_pid', 0);
      }
    );

    $loop->run();
    Runtime::setCompleted();
    //We're now in the child process.
  }
}
