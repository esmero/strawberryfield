<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\StrawberryfieldEventType;
use Drupal\strawberryfield\Event\StrawberryfieldServiceEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for SBF bearing entity Service Invoke event.
 */
abstract class StrawberryfieldEventInvokeServiceSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {

    // @TODO check event priority and adapt to future D9 needs.
    $events[StrawberryfieldEventType::INVOKE_SERVICE][] = ['onEntityInvokeService', 100];
    return $events;
  }

  /**
   * Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldServiceEvent $event
   *   The event.
   */
  abstract public function onEntityInvokeService(StrawberryfieldServiceEvent $event);

}
