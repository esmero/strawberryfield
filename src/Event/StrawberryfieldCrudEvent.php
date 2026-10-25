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
   * Construct a new entity event.
   *
   * @param string $eventType
   *   The event type.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity which caused the event.
   */
  public function __construct(
      /**
       * The event type.
       */
      private $eventType,
      /**
       * The Entity.
       */
      private readonly EntityInterface $entity,
      /**
       * The SBF field machine names.
       *
       * @array;
       */
      private readonly array $fields,
      /**
       * Which Subscribers processed this Event
       *
       *
       */
      private array $processedby = []
  )
  {
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
