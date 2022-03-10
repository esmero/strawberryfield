<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;


/**
 * Event subscriber for SBF bearing that invalidates related ADOs caches.
 */
class StrawberryfieldEventPresaveSubscriberInvalidateParentCaches extends StrawberryfieldEventPresaveSubscriber {

  use StringTranslationTrait;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * @var int
   */
  protected static $priority = 900;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger factory.
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * StrawberryfieldEventPresaveSubscriberInvalidateParentCaches constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    AccountInterface $account,
    CacheTagsInvalidatorInterface $cache_tags_invalidator
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->account = $account;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
  }


  /**
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   */
  public function onEntityPresave(StrawberryfieldCrudEvent $event) {

    /* @var $entity \Drupal\node\Entity\Node */
    $entity = $event->getEntity();
    $tags = [];
    if (!$entity->isNew()) {
      // Get first the previous parents
      if ($entity->original->field_sbf_nodetonode instanceof EntityReferenceFieldItemListInterface) {
        foreach ($entity->original->field_sbf_nodetonode->referencedEntities() as $key => $referencedEntity) {
          /* @var $referencedEntity \Drupal\Core\Entity\EntityInterface */
          $tags = Cache::mergeTags($tags, $referencedEntity->getCacheTags());
        }
      }
    }
    // Now get current parents
    if ($entity->field_sbf_nodetonode instanceof EntityReferenceFieldItemListInterface) {
      foreach ($entity->field_sbf_nodetonode->referencedEntities() as $key => $referencedEntity) {
        /* @var $referencedEntity \Drupal\Core\Entity\EntityInterface */
        $tags = Cache::mergeTags($tags, $referencedEntity->getCacheTags());
      }
    }
    if (count($tags)) {
      $this->cacheTagsInvalidator->invalidateTags($tags);
    }
    $current_class = get_called_class();
    $event->setProcessedBy($current_class, TRUE);
  }
}
