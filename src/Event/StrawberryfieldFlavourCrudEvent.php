<?php

namespace Drupal\strawberryfield\Event;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class to contain a SBF bearing entity flavour event.
 *
 * @TODO flesh this one out once we have flavour processors.
 */
class StrawberryfieldFlavourCrudEvent extends Event {

  /**
   * The Source Entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  private $entity;

  /**
   * The SBF fields.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface;
   */
  private $fields;

  /**
   * The event type.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldEventType
   */
  private $eventType;

  /**
   * Construct a new entity event.
   *
   * @param string $event_type
   *   The event type.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity which caused the event.
   */
  public function __construct($event_type, EntityInterface $entity, FieldItemListInterface $fields) {
    $this->entity = $entity;
    $this->eventType = $event_type;
    $this->fields = $fields;
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
