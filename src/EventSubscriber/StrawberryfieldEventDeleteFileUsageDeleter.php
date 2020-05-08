<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\strawberryfield\StrawberryfieldFilePersisterService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;


/**
 * Event subscriber for SBF bearing entity delete event.
 */
class StrawberryfieldEventDeleteFileUsageDeleter extends StrawberryfieldEventDeleteSubscriber {

  use StringTranslationTrait;

  /**
   * @var int
   */
  protected static $priority = -800;

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
   * StrawberryfieldEventInsertFileUsageUpdater constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * @param \Drupal\strawberryfield\StrawberryfieldFilePersisterService $strawberry_filepersister
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    StrawberryfieldFilePersisterService $strawberry_filepersister

  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->strawberryfilepersister = $strawberry_filepersister;
  }


  /**
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function onEntityDelete(StrawberryfieldCrudEvent $event) {
    // This removes the file usage when deleting entities.
    /* @var $entity \Drupal\Core\Entity\ContentEntityInterface */
    $entity = $event->getEntity();
    // First one: removed count, second one:orphaned ones cleaned.

    $processedfiles = $this->strawberryfilepersister->removeUsageFilesInJson($entity);

    if ($processedfiles[0] > 0) {
      $this->messenger->addStatus($this->stringTranslation->formatPlural($processedfiles[0], 'One file usage removed for this digital Object.', '@count files usage removed for this digital Object.'));
    }
    if ($processedfiles[1] > 0) {
      $this->messenger->addStatus($this->stringTranslation->formatPlural($processedfiles[1], 'One file usage removed for a no longer existing digital Object.', '@count files usage removed for no longer existing digital Objects.'));
    }
    $current_class = get_called_class();
    $event->setProcessedBy($current_class, TRUE);
  }
}