<?php

namespace Drupal\strawberryfield\Event;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Class to contain a SBF Service event.
 */
class StrawberryfieldServiceEvent extends Event {

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
       * The SBF field with that contains the service.
       *
       * @var \Drupal\Core\Field\FieldItemInterface;
       */
      private readonly FieldItemListInterface $field,
      /**
       * The Service definition.
       *
       * @array;
       */
      private readonly array $service
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
  public function getField() {
    return $this->field;
  }

  /**
   * Method to get the service.
   */
  public function getService() {
    return $this->service;
  }
  /**
   * Method to get the event type.
   */
  public function getEventType() {
    return $this->eventType;
  }

}
