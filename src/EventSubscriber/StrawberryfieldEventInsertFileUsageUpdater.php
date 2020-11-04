<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\strawberryfield\StrawberryfieldFilePersisterService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;


/**
 * Event subscriber for SBF bearing entity presave event.
 */
class StrawberryfieldEventInsertFileUsageUpdater extends StrawberryfieldEventInsertSubscriber {

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
   * The logger factory.
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The Strawberryfield File Persister Service
   *
   *  @var \Drupal\strawberryfield\StrawberryfieldFilePersisterService
   */
  protected $strawberryfilepersister;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * StrawberryfieldEventInsertFileUsageUpdater constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * @param \Drupal\strawberryfield\StrawberryfieldFilePersisterService $strawberry_filepersister
   * @param \Drupal\Core\Session\AccountInterface $account
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    StrawberryfieldFilePersisterService $strawberry_filepersister,
    AccountInterface $account


  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->strawberryfilepersister = $strawberry_filepersister;
    $this->account = $account;
  }


  /**
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function onEntityInsert(StrawberryfieldCrudEvent $event) {
    // This updates the file usage for first time ingested nodes.
    /* @var $entity \Drupal\Core\Entity\ContentEntityInterface */
    $entity = $event->getEntity();
    $sbf_fields = $event->getFields();
    $updatedfiles = 0;
    foreach ($sbf_fields as $field_name) {
      /* @var $field \Drupal\Core\Field\FieldItemInterface */
      $field = $entity->get($field_name);
      $updatedfiles = $updatedfiles + $this->strawberryfilepersister->updateUsageFilesInJson($field);
    }
    // Only generate UI messages if the user has this permission.
    if (($updatedfiles > 0) &&  ($this->account->hasPermission('display strawberry messages'))) {
      $this->messenger->addStatus($this->stringTranslation->formatPlural($updatedfiles, 'One file usage tracked for this digital Object.', '@count files usage tracked for this digital Object.'));
    }
    $current_class = get_called_class();
    $event->setProcessedBy($current_class, TRUE);
  }
}