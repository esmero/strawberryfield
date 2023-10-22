<?php

namespace Drupal\strawberryfield\Event;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Class to contain a SBF bearing entity event.
 */
class StrawberryfieldCrudEvent extends Event {

  /**
   * The Entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  private $entity;

  /**
   * The SBF field machine names.
   *
   * @array;
   */
  private $fields;

  /**
   * The event type.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldEventType
   */
  private $eventType;


  /**
   * Which Subscribers processed this Event
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
  public function __construct($event_type, EntityInterface $entity, array $sbfFields, array $processedby = []) {
    $this->entity = $entity;
    $this->eventType = $event_type;
    $this->fields =  $sbfFields;
    $this->processedby = $processedby;
  }

  /**
   * Method to get the entity from the event.
   */
  public function getEntity() {
    return $this->entity;
  }

  /**
   * Method to get the fields from the event.
   */
  public function getFields() {
    return $this->fields;
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
