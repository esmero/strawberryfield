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
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\ChildProcess\Process;

/**
 * A SBF Drush commandfile for ground-less Strawberry Growing.
 *
 * Forks and executes a reactPHP loop to handle queues in background
 *
 */
class HydroponicsMultiDrushCommands extends DrushCommands {

  use ExecTrait;

  /**
   * Forks itself and starts a reactPHP loop to run Queues
   *
   * @throws \Exception if something goes wrong
   *
   * @command archipelago:hydroponicsmulti
   * @aliases ap-hym
   *
   * @usage archipelago:hydroponicsmulti
   */
  public function hydroponicsmulti(
  ) {

    $loop = Factory::create();
    $timer_ping = $loop->addPeriodicTimer(3.0, function () {
      // store a heartbeat every 3 seconds.
      $currenttime = \Drupal::time()->getCurrentTime();
      \Drupal::state()->set('hydroponics.heartbeat', $currenttime);
    });
    $active_queues = \Drupal::config('strawberryfield.hydroponics_settings')->get('queues');

    // Store timer per queue
    $done = [];

    // Store child timeout timer
    $timeout = [];

    //track when queue are empty for n cycles
    $idle = [];

    // store data for each child process
    $child = [];

    // track queue items count
    $items = [];

    // Parameters (ToDO put on config page?)
    $queue_check_time = 1;
    $child_timeout_time = 30;
    $max_child_for_queue = 2;
    $idle_timer_time = 60;
    $idle_timer_cycles = 5;

    //get parameters to run drush command
    global $base_url;
    $site_path = \Drupal::service('site.path'); // e.g.: 'sites/default'
    $site_path = explode('/', $site_path);
    $site_name = $site_path[1];

    $path = \Drupal::config('strawberryfield.hydroponics_settings')->get('drush_path');
    if (empty($path)) {
      $path = '/var/www/html/vendor/drush/drush/drush';
    }
    $path = escapeshellcmd($path);
    $cmd = $path.' archipelago:hydroqueue --uri=' . $base_url;
    $home = \Drupal::config('strawberryfield.hydroponics_settings')->get('home_path');
    if (!empty($home)) {
      $home = escapeshellcmd($home);
      $cmd = "export HOME='".$home."'; ".$cmd;
    }

    // Get which queues we should run:

    foreach($active_queues as $queue) {

      $timeout[$queue] = [];

      // Set number of idle cycle to wait
      $idle[$queue] = $idle_timer_cycles;

      // Store child start, end, output, exit code
      $child[$queue] = [];

      // Periodic timer for every queue
      $done[$queue] = $loop->addPeriodicTimer($queue_check_time, function ($timer) use ($loop, $queue, $cmd, $child_timeout_time, $max_child_for_queue, $idle_timer_cycles, &$idle, &$child, &$timeout) {
        \Drupal::logger('hydroponics')->info("Starting to process queue @queue. Idle counter @idle", [
          '@queue' => $queue,
          '@idle' => $idle[$queue]
        ]);

        //count items on queue
        $items[$queue] = \Drupal::getContainer()->get('strawberryfield.hydroponics')->countQueue($queue);

        //Check how many child are running (we check if 'end' is_null)
        $running_child = 0;
        if (!empty($child[$queue])) {
          $running_child = count(array_filter($child[$queue], function($element){
            return is_null($element['end']);
          }));
        }

        // Execute child only if NOT more than max_child running AND some items on the queue
        if (($running_child < $max_child_for_queue) && ($items[$queue] > 0)) {

          // Reset number of idle cycle to wait
          $idle[$queue] = $idle_timer_cycles;

          //child process
          $child_cmd = $cmd . ' ' . $queue;

          $process = new Process($child_cmd);
          $process->start($loop);

          $process_pid = $process->getPid();
          $child[$queue][$process_pid] = [];

          $child[$queue][$process_pid]['start'] = \Drupal::time()->getCurrentTime();
          $child[$queue][$process_pid]['end'] = NULL;
          $child[$queue][$process_pid]['exitcode'] = NULL;
          $child[$queue][$process_pid]['output'] = NULL;
          $child[$queue][$process_pid]['timeout'] = FALSE;

          //timeout per child per queue
          $timeout[$queue][$process_pid] = $loop->addTimer($child_timeout_time, function () use ($process, $queue, $process_pid, &$child) {
            $process->stdin->end();
            $child[$queue][$process_pid]['timeout'] = TRUE;
            \Drupal::logger('hydroponics')->error("ERROR: Process timeout, pid @pid, on queue @queue", [
              '@pid' => $process_pid,
              '@queue' => $queue
            ]);
          });

          // catch process std output
          $process->stdout->on('data', function ($chunk) use ($queue, $process_pid, &$child){
            //read child process output (= item left on queue)
            $child[$queue][$process_pid]['output'] = (int) $chunk;
          });

          // ctach on exit event
          $process->on('exit', function ($code, $term) use ($loop, $queue, $process_pid, &$child, $timeout){
            // Do we need this? YES, to remove timeout timer if exit before timeout
            $loop->cancelTimer($timeout[$queue][$process_pid]);

            $child[$queue][$process_pid]['end'] = \Drupal::time()->getCurrentTime();
            $child[$queue][$process_pid]['exitcode'] = $code;
            \Drupal::logger('hydroponics')->info("EXIT event: Queue @queue, process @pid, output @output, exit code @code, term @term, start @start, end @end, timeout @timeout", [
              '@queue' => $queue,
              '@pid' => $process_pid,
              '@output' => is_null($child[$queue][$process_pid]['output']) ? "NULL" : $child[$queue][$process_pid]['output'],
              '@code' => is_null($child[$queue][$process_pid]['exitcode']) ? "NULL" : $child[$queue][$process_pid]['exitcode'],
              '@term' => is_null($term) ? "NULL" : $term,
              '@start' => is_null($child[$queue][$process_pid]['start']) ? "NULL" : $child[$queue][$process_pid]['start'],
              '@end' => is_null($child[$queue][$process_pid]['end']) ? "NULL" : $child[$queue][$process_pid]['end'],
              '@timeout' => $child[$queue][$process_pid]['timeout'] ? "TRUE" : "FALSE"
            ]);
          });

        }
        else {
          // not able to run child due to queue empty or max child reached
          \Drupal::logger('hydroponics')->info("Queue empty OR max @max process running on queue @queue", [
            '@max' => $max_child_for_queue,
            '@queue' => $queue
          ]);
          // If no child running and queue empty then decrement idle timer
          if (($running_child == 0) && ($items[$queue] == 0)) {
            $idle[$queue] -= 1;
          }
        }
      });
    }

    // idle check every $idle_timer_time s
    $idle_timer = $loop->addPeriodicTimer($idle_timer_time, function ($timer) use ($loop, $timer_ping, &$done, &$idle) {
      // Close main loop if all queues return 0 elements for at least N cycles
      // ToDO also check or report if some child timeout
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
