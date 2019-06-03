<?php

namespace Drupal\strawberryfield\Event;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class to contain a SBF Service event.
 */
class StrawberryfieldServiceEvent extends Event {

  /**
   * The Entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  private $entity;

  /**
   * The SBF field with that contains the service.
   *
   * @var \Drupal\Core\Field\FieldItemInterface;
   */
  private $field;

  /**
   * The Service definition.
   *
   * @array;
   */
  private $service;

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
  public function __construct($event_type, EntityInterface $entity, FieldItemListInterface $field, array $service) {
    $this->entity = $entity;
    $this->eventType = $event_type;
    $this->field = $field;
    $this->service = $service;
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
