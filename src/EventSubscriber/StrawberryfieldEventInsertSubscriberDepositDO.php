<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\strawberryfield\StrawberryfieldFilePersisterService;
use Datetime;

/**
 * Event subscriber for SBF bearing entity presave event.
 */
class StrawberryfieldEventInsertSubscriberDepositDO extends StrawberryfieldEventInsertSubscriber {

  use StringTranslationTrait;

  /**
   * @var int
   */
  protected static $priority = -700;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The serializer.
   * @var \Symfony\Component\Serializer\SerializerInterface;
   */
  protected $serializer;

  /**
   * The Storage Destination Scheme.
   *
   * @var string;
   */
  protected $destinationScheme = NULL;

  /**
   * The Strawberryfield File Persister Service
   *
   *  @var \Drupal\strawberryfield\StrawberryfieldFilePersisterService
   */
  protected $strawberryfilepersister;

  /**
   * The logger factory.
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * StrawberryfieldEventInsertSubscriberDepositDO constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    SerializerInterface $serializer,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    StrawberryfieldFilePersisterService $strawberry_filepersister
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->serializer = $serializer;
    $this->destinationScheme = $config_factory->get(
      'strawberryfield.storage_settings'
    )->get('object_file_scheme');
    $this->loggerFactory = $logger_factory;
    $this->strawberryfilepersister = $strawberry_filepersister;
  }

  /**
   * Method called when Event occurs.
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function onEntityInsert(StrawberryfieldCrudEvent $event) {
    /* @var $entity \Drupal\Core\Entity\ContentEntityInterface */
    $current_class = get_called_class();
    $entity = $event->getEntity();
    $sbf_fields = $event->getFields();
    // Strawberryfield data
    $sbf_data = [];
    // Full node entity
    $full_node_data = NULL;
    // Full node serialization
    $full_node_data = $this->serializer->serialize(
      $entity,
      'json',
      ['plugin_id' => 'entity']
    );
    //@TODO right now JSON API does not expose its serialization publicly
    // We can use JSONAPI_Extras module for this
    $path = $this->destinationScheme . '://dostorage/' . $entity->uuid();
    $success = FALSE;
    if ($full_node_data) {
      $filename_full_node = 'node_' . $entity->bundle() . '_' . $entity->uuid() . '.json';
      // Create the DO JSON file
      $success = $this->strawberryfilepersister->persistMetadataToDisk(
        $full_node_data,
        $path,
        $filename_full_node,
        TRUE,
        FALSE
      );
    }
    // Now do only SBF data
    foreach ($sbf_fields as $field_name) {
      /* @var $field \Drupal\Core\Field\FieldItemInterface */
      $field = $entity->get($field_name);
      if (!$field->isEmpty()) {
        $sbf_data[] = '"' . $field_name . '": ' . $this->serializer->serialize(
            $field,
            'json',
            ['plugin_id' => 'field']
          );
      }
    }
    if ($sbf_data) {
      $filename_sbf = 'do-' . $entity->uuid() . '.json';
      // A lot of string manip. But faster than decode and rencode.
      $sbf_data[] = '"id": "' . $entity->toUrl('canonical')->toString() . '"';
      $sbf_data[] = '"type": "' . $entity->bundle() . '"';
      $sbf_data[] = '"drn:id": ' . $entity->id();
      $sbf_data[] = '"drn:uuid": "' . $entity->uuid() . '"';
      $timestamp = $entity->get('created')->value;
      $datetime = new DateTime();
      $datetime->setTimestamp($timestamp);
      $sbf_data[] = '"label": "' . $entity->label() . '"';
      $sbf_data[] = '"language": "' . $entity->language()->getId() . '"';

      $sbf_data[] = '"datemodified": "' . $datetime->format('c') . '"';
      $sbf_data[] = '"unixdatemodified": ' . $timestamp;
      $sbf_data[] = '"username": "'  . $entity->get('uid')->entity->getAccountName(). '"';
      $sbf_data_string = '{' . implode(",", $sbf_data) . '}';

      $success = $this->strawberryfilepersister->persistMetadataToDisk(
        $sbf_data_string,
        $path,
        $filename_sbf,
        TRUE,
        FALSE
      );
    }
    $event->setProcessedBy($current_class, $success);
    // Entity is "assigned by reference" so any change on the entity here will persist.
    if ($success) {
      $this->messenger->addStatus(
        t('Digital Object persisted to Filesystem.')
      );
      $this->loggerFactory->get('archipelago')->info(
        'Digital Object persisted to Filesystem.',
        ['Entity ID' => $entity->id(), 'Entity Title' => $entity->label()]
      );
    }
    if (!$success) {
      $this->messenger->addError(
        t(
          'Digital Object Serialization failed? We could not persist to Filesystem. Please check your logs.'
        )
      );
      $this->loggerFactory->get('archipelago')->critical(
        'Digital Object Serialization failed , we could not persist to Filesystem.',
        ['Entity ID' => $entity->id(), 'Entity Title' => $entity->label()]
      );
    }
  }

}
