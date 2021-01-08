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

    //count running child per queue
    $running = [];

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
      $idle[$queue] = 3;

      // Reset running child counter per queue
      $running[$queue] = 0;

//      $done[$queue] = $loop->addPeriodicTimer(1.0, function ($timer) use ($loop, $queue) {
      $done[$queue] = $loop->addPeriodicTimer(5.0, function ($timer) use ($loop, $queue, &$idle, $cmd, &$running) {
        \Drupal::logger('hydroponics')->info("Starting to process queue @queue", [
          '@queue' => $queue
        ]);

        //No more than 1 child per queue
        if ($running[$queue] < 1) {

          $running[$queue] += 1;

          //child process
          $child_cmd = $cmd . ' ' . $queue;
          \Drupal::logger('hydroponics')->info('Command: ' . $child_cmd);

          $process = new Process($child_cmd);
          $process->start($loop);
          $process_pid = $process->getPid();

          $process->stdout->on('data', function ($chunk) use ($queue, $process_pid){
            //code to read chunck from child process output
            \Drupal::logger('hydroponics')->info("Queue @queue, process @pid, output @chunk", [
              '@queue' => $queue,
              '@pid' => $process_pid,
              '@chunk' => $chunk
            ]);
          });

          $process->on('exit', function ($code, $term) use ($queue, $process_pid, &$running){
            $running[$queue] -= 1;
            //ToDO: more deep check
            \Drupal::logger('hydroponics')->info("Queue @queue, process @pid, exit code @code", [
              '@queue' => $queue,
              '@pid' => $process_pid,
              '@code' => $code
            ]);
          });

          //for test
          $number = 0;


  /*
          $number = \Drupal::getContainer()
            ->get('strawberryfield.hydroponics')
            ->processQueue($queue, 60);
            \Drupal::logger('hydroponics')->info("Finished processing queue @queue", [
            '@queue' => $queue
          ]);
  */


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
        }
        else {
          \Drupal::logger('hydroponics')->info("Already 1 process running on queue @queue, wait next cycle", [
            '@queue' => $queue
          ]);
        }
      });
    }

    $idle_timer = $loop->addPeriodicTimer(80.0, function ($timer) use ($loop, $timer_ping, &$done, &$idle) {
      // Finish all if all queues return 0 elements for at least 3 cycles
      // Check this every 80 s
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
