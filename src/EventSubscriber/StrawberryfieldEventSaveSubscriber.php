<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\StrawberryfieldEventType;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for SBF bearing entity save event.
 */
abstract class StrawberryfieldEventSaveSubscriber implements EventSubscriberInterface {

  /**
   * @var int
   */
  protected static $priority = 100;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {

    // @TODO check event priority and adapt to future D9 needs.
    $events[StrawberryfieldEventType::SAVE][] = ['onEntitySave', static::$priority];
    return $events;
  }

  /**
   * Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *   The event.
   */
  abstract public function onEntitySave(StrawberryfieldCrudEvent $event);

}
