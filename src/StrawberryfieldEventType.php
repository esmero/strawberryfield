<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 5/3/19
 * Time: 4:14 PM
 */

namespace Drupal\strawberryfield;


final class StrawberryfieldEventType {
  /**
   * Name of the event fired when pre saving an node with a SBF attached.
   *
   * This event allows modules to perform an action whenever a node
   * with a SBF(Strawberry Field) is saved. The event listener method receives a
   * \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent instance.
   *
   * See \strawberryfield_node_presave
   *
   * @Event
   *
   * @see strawberryfield_node_presave
   * @see \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent
   * @see \Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventPresaveSubscriberVocabCreator
   *
   * @var string
   */
  const PRESAVE = 'sbf.node.presave';

  /**
   * Name of the event fired when inserting a new node with a SBF attached.
   *
   * This event allows modules to perform an action whenever a node
   * with a SBF(Strawberry Field) is inserted for the first time.
   * The event listener method receives a
   * \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent instance.
   *
   * See \strawberryfield_node_insert
   *
   * @Event
   *
   * @see strawberryfield_node_insert
   * @see \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent
   *
   * @var string
   */
  const INSERT = 'sbf.node.insert';

  /**
   * Name of the event fired when updating a node with a SBF attached.
   *
   * This event allows modules to perform an action whenever a node
   * with a SBF(Strawberry Field) is updated. This is after storage
   * was updated (SQL INSERT or UPDATE) so no JSON can be modified via this
   * one.
   * The event listener method receives a
   * \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent instance.
   *
   * See \strawberryfield_node_update
   *
   * @Event
   *
   * @see
   * @see \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent
   *
   * @var string
   */
  const SAVE = 'sbf.node.save';

  /**
   * Name of the event fired when revisioning a node with a SBF attached.
   *
   * This event allows modules to perform an action whenever a node
   * with a SBF(Strawberry Field) gets a new revision inserted.
   * The event listener method receives a
   * \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent instance.
   *
   * See \strawberryfield_node_revision_create
   *
   * @Event
   *
   * @see strawberryfield_node_update
   * @see \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent
   *
   * @var string
   */
  const NEW_REVISION = 'sbf.node.newrevision';

  /**
   * Name of the event fired when a node revision with SBF attached is deleted.
   *
   * This event allows modules to perform an action whenever a node
   * with a SBF(Strawberry Field) gets a new revision inserted.
   * The event listener method receives a
   * \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent instance.
   *
   * See \strawberryfield_node_revision_delete
   *
   * @Event
   *
   * @see strawberryfield_node_revision_delete
   * @see \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent
   *
   * @var string
   */
  const DELETE_REVISION = 'sbf.node.deleterevision';

  /**
   * Name of the event fired when a node with SBF attached is deleted.
   *
   * This event allows modules to perform an action whenever a node
   * with a SBF(Strawberry Field) gets deleted
   * The event listener method receives a
   * \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent instance.
   *
   * See \strawberryfield_node_delete
   *
   * @Event
   *
   * @see strawberryfield_node_delete
   * @see \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent
   *
   * @var string
   */
  const DELETE = 'sbf.node.delete';

  /**
   * Name of the event fired when a SBF JSON needs to be processed
   *
   * This event allows modules to perform an action whenever a SBF
   * JSON needs to be enriched, cleaned and or normalized.
   * This is the state of the JSON just before being saved.
   * The event listener method receives a
   * \Drupal\strawberryfield\Event\StrawberryfieldJsonProcessEvent instance.
   *
   * @Event
   *
   * @see \Drupal\strawberryfield\Event\StrawberryfieldJsonProcessEvent
   *
   * @var string
   */
  const JSONPROCESS = 'sbf.json.process';

  /**
   * Name of the event fired when invoking SBF JSON "Seasoners"
   *
   * This event allows modules to perform an action whenever invoking SBF
   * JSON Seasoners, a.k.a embeded Services.
   * The event listener method receives a
   * \Drupal\strawberryfield\StrawberryfieldServiceEvent instance.
   *
   * @Event
   *
   * @var string
   */
  const INVOKE_SERVICE = 'sbf.json.invokeservice';

  /**
   * Name of the event fired when inserting a SBF JSON Flavour
   *
   * This event allows modules to perform an action whenever a new JSON Flavour
   * is generated.
   * The event listener method receives a
   * \Drupal\strawberryfield\Event\StrawberryfieldFlavourCrudEvent instance.
   *
   * @Event
   *
   * @see \Drupal\strawberryfield\Event\StrawberryfieldFlavourCrudEvent
   *
   * @var string
   */
  const INSERT_FLAVOUR = 'sbf.flavour.insert';

  /**
   * Name of the event fired when deleting a SBF JSON Flavour
   *
   * This event allows modules to perform an action whenever a JSON Flavour
   * is deleted.
   * The event listener method receives a
   * \Drupal\strawberryfield\Event\StrawberryfieldFlavourCrudEvent instance.
   *
   * @Event
   *
   * @see \Drupal\strawberryfield\Event\StrawberryfieldFlavourCrudEvent
   *
   * @var string
   */
  const DELETE_FLAVOUR = 'sbf.flavour.delete';
}