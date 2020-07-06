<?php

namespace Drupal\strawberryfield\Event;

use Symfony\Component\EventDispatcher\Event;

/**
 * Class to contain a SBF JSON processing event.
 */
class StrawberryfieldJsonProcessEvent extends Event {

  /**
   * The RAW JSON
   *
   * Should hopefully never modified during an Event life clycle
   *
   * @var array
   */
  protected $originalJson;

  /**
   * a processed JSON
   *
   * This is where all changes happen and event subscribers are encourage to
   * deal with changing, adding, filtering it.
   *
   * @var array
   */
  private $processedJson;

  /**
   * The event type.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldEventType
   */
  private $eventType;

  /**
   * If processed JSON was modified since the Event was dispatched.
   *
   * @var boolean
   */
  private $modified = FALSE;


  /**
   * Construct a new Json processing event.
   *
   * @param string $event_type
   *   The event type.
   * @param array $originalJson
   *   The original JSON
   * @param array $processedJson
   *   The processed JSON since these are arrays and not Objects by reference.
   */
  public function __construct($event_type, array $originalJson, array $processedJson) {
    $this->eventType = $event_type;
    $this->originalJson = $originalJson;
    $this->processedJson = $processedJson;
  }

  /**
   * Method to get the Processed JSON from the event.
   */
  public function getProcessedJson() {
    return $this->processedjson;
  }


  /**
   * Method to set the Processed JSON back to the event.
   */
  public function setProcessedJson(array $modifiedjson) : void {
    if ($modifiedjson!== $this->processedjson) {
      $this->processedjson = $modifiedjson;
      $this->setModified(TRUE);
    }
  }

  /**
   * @param bool $modified
   *
   * Invoked internally when setting a different processed JSON
   */
  private function setModified(bool $modified): void {
    $this->modified = $modified;
  }

  /**
   * @return bool
   */
  public function wasModified(): bool {
    return $this->modified;
  }

  /**
   * Method to get the fields from the event.
   */
  public function getOriginalJson() {
    return $this->originalJson;
  }

  /**
   * Method to get the event type.
   */
  public function getEventType() {
    return $this->eventType;
  }

}
