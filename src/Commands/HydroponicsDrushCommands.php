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

    // Get which queues we should run:
    $active_queues = \Drupal::config('strawberryfield.hydroponics_settings')->get('queues');
    foreach($active_queues as $queue) {
      $done[$queue] = 0;
      $loop->addTimer(0.001, function ($timer) use ($queue, &$done) {
        error_log("Starting to process $queue");
        \Drupal::getContainer()
          ->get('strawberryfield.hydroponics')
          ->processQueue($queue, 360);
        error_log("Finished processing $queue");
        $done[$queue] = 1;
      });
    }



    $timer2 = $loop->addPeriodicTimer(0.1, function ($timer) use ($loop, $done, $timer_ping) {
      if (!empty($done) && (array_sum($done)) == count($done)) {
        error_log("All Done, clearing the timers");
        $loop->cancelTimer($timer_ping);
        $loop->cancelTimer($timer);
        \Drupal::state()->set('hydroponics.queurunner_last_pid.heartbeat', 0);
      }
    });

    $loop->run();
    Runtime::setCompleted();
    //We're now in the child process.
  }
}
