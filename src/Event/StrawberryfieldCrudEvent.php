<?php

namespace Drupal\strawberryfield\Event;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Component\EventDispatcher\Event;

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
   * Construct a new entity event.
   *
   * @param string $event_type
   *   The event type.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity which caused the event.
   */
  public function __construct($event_type, EntityInterface $entity, array $sbfFields) {
    $this->entity = $entity;
    $this->eventType = $event_type;
    $this->fields =  $sbfFields;
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
