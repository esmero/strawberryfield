<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\StrawberryfieldEventType;
use Drupal\strawberryfield\Event\StrawberryfieldJsonProcessEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for SBF bearing entity json process event.
 */
abstract class StrawberryfieldEventJsonProcessingSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {

    // @TODO check event priority and adapt to future D9 needs.
    $events[StrawberryfieldEventType::JSONPROCESS][] = ['onJsonInvokeProcess', 100];
    return $events;
  }

  /**
   * Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldServiceEvent $event
   *   The event.
   */
  abstract public function onJsonInvokeProcess(StrawberryfieldJsonProcessEvent $event);

}
