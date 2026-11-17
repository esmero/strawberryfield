<?php

namespace Drupal\strawberryfield\Event;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Class to contain a SBF bearing entity flavour event.
 *
 * @TODO flesh this one out once we have flavour processors.
 */
class StrawberryfieldFlavourCrudEvent extends Event {

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
       * The Source Entity.
       */
      private readonly EntityInterface $entity,
      /**
       * The SBF fields.
       *
       * @var \Drupal\Core\Field\FieldItemListInterface;
       */
      private readonly FieldItemListInterface $fields
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

}
