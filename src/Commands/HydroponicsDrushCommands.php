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

    $onqueue = [];
    $outputcode = [];

    $child = [];

    $items = [];

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

      // Set number of idle cycle to wait
      $idle[$queue] = 5;

      // Store child start, end, output, exit code
      $child[$queue] = [];

//      $done[$queue] = $loop->addPeriodicTimer(1.0, function ($timer) use ($loop, $queue) {
      $done[$queue] = $loop->addPeriodicTimer(5.0, function ($timer) use ($loop, $queue, &$idle, $cmd, &$child) {
        \Drupal::logger('hydroponics')->info("Starting to process queue @queue. Idle counter @idle", [
          '@queue' => $queue,
          '@idle' => $idle[$queue]
        ]);

        //count items on queue
        $items[$queue] = \Drupal::getContainer()->get('strawberryfield.hydroponics')->countQueue($queue);
\Drupal::logger('hydroponics')->info('Items on queue: ' . $items[$queue]);

        //Check how many child are running (we check if 'end' is_null)
        $running_child = 0;
        if (!empty($child[$queue])) {
          $running_child = count(array_filter($child[$queue], function($element){
            return is_null($element['end']);
          }));
        }
\Drupal::logger('hydroponics')->info('Running child: ' . $running_child);

        //No more than $max_child per queue
        $max_child = 1;


        // Execute child only if NOT more than max_child running AND some items on the queue
        if (($running_child < $max_child) && ($items[$queue] > 0)) {

          // Reset number of idle cycle to wait
          $idle[$queue] = 5;

          //child process
          $child_cmd = $cmd . ' ' . $queue;
\Drupal::logger('hydroponics')->info('Command: ' . $child_cmd);

          $process = new Process($child_cmd);
          $process->start($loop);

          $process_pid = $process->getPid();
          $child[$queue][$process_pid] = [];

          $child[$queue][$process_pid]['start'] = \Drupal::time()->getCurrentTime();
          $child[$queue][$process_pid]['end'] = NULL;
          $child[$queue][$process_pid]['exitcode'] = NULL;
          $child[$queue][$process_pid]['output'] = NULL;

          $process->stdout->on('data', function ($chunk) use ($queue, $process_pid, &$child){
            //read child process output (= item left on queue)
            $child[$queue][$process_pid]['output'] = (int) $chunk;
            \Drupal::logger('hydroponics')->info("OUTPUT event: Queue @queue, process @pid, output @chunk", [
              '@queue' => $queue,
              '@pid' => $process_pid,
              '@chunk' => $chunk
            ]);
          });

          $process->on('exit', function ($code, $term) use ($queue, $process_pid, &$idle, &$child){
            $child[$queue][$process_pid]['end'] = \Drupal::time()->getCurrentTime();
            $child[$queue][$process_pid]['exitcode'] = $code;
            \Drupal::logger('hydroponics')->info("EXIT event: Queue @queue, process @pid, output @output, exit code @code, start @start, end @end", [
              '@queue' => $queue,
              '@pid' => $process_pid,
              '@output' => is_null($child[$queue][$process_pid]['output']) ? "NULL" : $child[$queue][$process_pid]['output'],
              '@code' => is_null($child[$queue][$process_pid]['exitcode']) ? "NULL" : $child[$queue][$process_pid]['exitcode'],
              '@start' => is_null($child[$queue][$process_pid]['start']) ? "NULL" : $child[$queue][$process_pid]['start'],
              '@end' => is_null($child[$queue][$process_pid]['end']) ? "NULL" : $child[$queue][$process_pid]['end']
            ]);
          });

        }
        else {
          \Drupal::logger('hydroponics')->info("Already @max process running on queue @queue OR queue empty", [
            '@max' => $max_child,
            '@queue' => $queue
          ]);

          // If no child running and queue empty then decrement idle timer
          //
          // ToDO This never happens if a process hang so we have to manage by timeout
          //
          if (($running_child == 0) && ($items[$queue] == 0)) {
            $idle[$queue] -= 1;
          }
        }
      });
    }

    $idle_timer = $loop->addPeriodicTimer(80.0, function ($timer) use ($loop, $timer_ping, &$done, &$idle) {
      // Finish all if all queues return 0 elements for at least N cycles
      // Check this every 80 s
      // ToDO also check child if all exited ok
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

/*
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
*/

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
