<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\StrawberryfieldEventType;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for SBF bearing entity presave event.
 */
abstract class StrawberryfieldEventInsertSubscriber implements EventSubscriberInterface {

  /**
   * @var int
   */
  protected static $priority = -700;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {

    // @TODO check event priority and adapt to future D9 needs.
    $events[StrawberryfieldEventType::INSERT][] = ['onEntityInsert', static::$priority];
    return $events;
  }

  /**
   * Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *   The event.
   */
  abstract public function onEntityInsert(StrawberryfieldCrudEvent $event);

}
