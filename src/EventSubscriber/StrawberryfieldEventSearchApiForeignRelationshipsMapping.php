<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\Event\MappingForeignRelationshipsEvent;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber to alter Foreign Relationships for Indexed Strawberryfields.
 */
class StrawberryfieldEventSearchApiForeignRelationshipsMapping implements EventSubscriberInterface {

  /**
   * @var int
   */
  protected static $priority = -700;

  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {

    $events[SearchApiEvents::MAPPING_FOREIGN_RELATIONSHIPS][] = ['alterMapping', static::$priority];
    return $events;
  }

  public function __construct(StrawberryfieldUtilityService $strawberryfield_utility_service) {
   $this->strawberryfieldUtility = $strawberryfield_utility_service;
  }

  /**
   * Method called when Event occurs.
   *
   * @param \Drupal\search_api\Event\MappingForeignRelationshipsEvent $event
   *   The event.
   */
  public function alterMapping(MappingForeignRelationshipsEvent $event) {
    $sbf_machine_names = $this->strawberryfieldUtility->getStrawberryfieldMachineNames();
    if (is_array($sbf_machine_names) && count($sbf_machine_names)) {
      foreach ($event->getForeignRelationshipsMapping() as $key => $mapping) {
        if ($mapping['datasource'] == 'entity:node') {
          $property_path = explode(":", $mapping['property_path_to_foreign_entity']);
          if (in_array($property_path[0], $sbf_machine_names)) {
            // We can not trigger \Drupal::getContainer()->get('search_api.tracking_helper')
            //    ->trackReferencedEntityUpdate($entity);
            // Because at this level we have no idea which entity was modified
            unset($event->getForeignRelationshipsMapping()[$key]);
          }
        }
      }
    }
  }
}
