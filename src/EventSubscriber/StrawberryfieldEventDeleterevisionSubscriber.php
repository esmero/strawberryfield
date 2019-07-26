<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\StrawberryfieldEventType;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for SBF bearing entity presave event.
 */
abstract class StrawberryfieldEventDeleterevisionSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {

    // @TODO check event priority and adapt to future D9 needs.
    $events[StrawberryfieldEventType::DELETE_REVISION][] = ['onEntityDeleterevision', 100];
    return $events;
  }

  /**
   * Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *   The event.
   */
  abstract public function onEntityDeleterevision(StrawberryfieldCrudEvent $event);

}
