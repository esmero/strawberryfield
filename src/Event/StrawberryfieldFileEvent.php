<?php

namespace Drupal\strawberryfield\Event;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * File Event. Works on File paths/urls, not File Entities.
 */
class StrawberryfieldFileEvent extends Event {


  /**
   * The Path with StreamWrapper to a File.
   *
   * @var string
   */
  private string $uri;


  /**
   * The Time this event was Fired
   *
   * @var integer
   */
  private int $timestamp;


  /**
   * The Drupal Module machine name that triggered this event.
   *
   * @var string
   */
  private $module;

  /**
   * The event type.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldEventType
   */
  private $eventType;


  /**
   * Which Subscribers processed this Event.
   *
   * @var array
   *
   */
  private $processedby = [];

  /**
   * Construct a new entity event.
   *
   * @param string $event_type
   *   The event type.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity which caused the event.
   */
  public function __construct($event_type, $module, $uri, $timestamp,  array $processedby = []) {
    $this->eventType = $event_type;
    $this->uri = $uri;
    $this->timestamp = $timestamp;
    $this->module = $module;
    $this->processedby = $processedby;
  }

  /**
   * Method to get the URL from the event.
   */
  public function getURI() {
    return $this->uri;
  }


  /**
   * Method to get the Timestamp from the event.
   */
  public function getTimeStamp() {
    return $this->timestamp;
  }
  /**
   * Method to get triggering Module from the event.
   */
  public function getModule() {
    return $this->module;
  }

  /**
   * Method to get the event type.
   */
  public function getEventType() {
    return $this->eventType;
  }
  /**
   * Method to get the all Subscribers that processed this in the past.
   */
  public function getProcessedBy() {
    return $this->processedby;
  }
  /**
   * Method to get the append a Subscriber's processed state.
   */
  public function setProcessedBy(string $class, bool $success) {
    return $this->processedby[] = ['class' => $class, 'success' => $success];
  }

}
