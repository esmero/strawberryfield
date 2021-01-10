<?php

namespace Drupal\strawberryfield\Commands;

use Drush\Commands\DrushCommands;
use Drush\Exec\ExecTrait;
use Drush\Runtime\Runtime;
use React\EventLoop\Factory;


/**
 * A SBF Drush commandfile for ground-less Strawberry Growing.
 *
 * One queue element processing to be executed in background with react-php child process
 *
 */
class HydroponicsQueueProcessDrushCommands extends DrushCommands {

  use ExecTrait;

  /**
   * One queue element processing
   *
   * @param string $queue
   *   Argument with queue to be managed.
   * @throws \Exception if something goes wrong
   *
   * @command archipelago:hydroqueue
   * @aliases ap-hq
   *
   * @usage archipelago:hydroqueue
   */
  public function hydroqueue($queue = NULL
  ) {

    if ($queue) {
      \Drupal::logger('hydroqueue')->info("Starting to process one element from queue @queue", [
        '@queue' => $queue
      ]);

      $number = \Drupal::getContainer()
        ->get('strawberryfield.hydroponics')
        ->processQueue($queue, 60, TRUE);

      \Drupal::logger('hydroqueue')->info("Finished processing one element from queue @queue. Items left @number", [
        '@queue' => $queue,
        '@number' => $number
      ]);

      echo $number . PHP_EOL;
    }
    else {
      \Drupal::logger('hydroqueue')->info("Queue parameters missing");
    }
    Runtime::setCompleted();
  }
}
