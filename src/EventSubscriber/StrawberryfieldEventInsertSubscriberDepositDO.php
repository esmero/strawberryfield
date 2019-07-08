<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

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
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->serializer = $serializer;
    $this->destinationScheme = $config_factory->get(
      'strawberryfield.storage_settings'
    )->get('object_file_scheme');
    $this->loggerFactory = $logger_factory;
  }
  /**
   * Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *   The event.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @return StrawberryfieldCrudEvent $event;
   */
  public function onEntityInsert(StrawberryfieldCrudEvent $event) {
    /* @var $entity \Drupal\Core\Entity\ContentEntityInterface */
    $current_class = get_called_class();
    $entity = $event->getEntity();
    $sbf_fields = $event->getFields();
    $data = '';
    foreach ($sbf_fields as $field_name) {
      /* @var $field \Drupal\Core\Field\FieldItemInterface */
      $field = $entity->get($field_name);
      if (!$field->isEmpty()) {
        // Full entity for now. Waiting for group feedback.
        $data = $this->serializer->serialize($entity, 'json', ['plugin_id' => 'entity']);
      }
    }
    if ($data) {
      $filename = 'do-'.$entity->uuid() . '.json';
      $path =  $this->destinationScheme.'://dostorage/'.$entity->uuid();
      $uri = $path . '/' . $filename;
      // Create the DO JSON file
      file_prepare_directory($path, FILE_CREATE_DIRECTORY);
      if (!file_exists($uri) && !file_unmanaged_save_data($data, $uri, FILE_EXISTS_REPLACE)) {
        $event->setProcessedBy($current_class, FALSE);
        return $event;
      }
      // If zlib extension is available
      if (extension_loaded('zlib')) {
        if (!file_exists($uri . '.gz') && !file_unmanaged_save_data(gzencode($data, 9, FORCE_GZIP), $uri . '.gz', FILE_EXISTS_REPLACE)) {
          // We processed, so returning true, but we could not zip. That is not an error.
          $event->setProcessedBy($current_class, TRUE);
          return $event;
        }
      }
      $event->setProcessedBy($current_class, TRUE);
     // Entity is "assignmed by reference" so any change on the entity here will persist.
      $this->messenger->addStatus(t('Digital Object persisted to Filesystem'));
      $this->loggerFactory->get('archipelago')->info('Digital Object persisted to Filesystem.', ['Entity ID' => $entity->id(), 'Entity Title' => $entity->label()]);
      return $event;
    }
    $event->setProcessedBy($current_class, FALSE);
    $this->messenger->addError(t('Digital Object Serialization failed? We could not persist to Filesystem. Please check your logs'));
    $this->loggerFactory->get('archipelago')->critical('Digital Object Serialization failed , We could not persist to Filesystem.', ['Entity ID' => $entity->id(), 'Entity Title' => $entity->label()]);
  }
}
