<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Session\AccountInterface;


/**
 * Event subscriber for SBF bearing entity presave event.
 */
class StrawberryfieldEventPresaveSubscriberSetTitlefromMetadata extends StrawberryfieldEventPresaveSubscriber {

  use StringTranslationTrait;

  /**
   * @var int
   */
  protected static $priority = -900;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The Storage Destination Scheme.
   *
   * @var string;
   */
  protected $destinationScheme = NULL;

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
   * StrawberryfieldEventPresaveSubscriberSetTitlefromMetadata constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    AccountInterface $account

  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->account = $account;
  }


  /**
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   */
  public function onEntityPresave(StrawberryfieldCrudEvent $event) {

    /* @var $entity \Drupal\node\Entity\Node */
    $entity = $event->getEntity();
    $sbf_fields = $event->getFields();
    $originallabel = NULL;
    $forceupdate = TRUE;
    if (!$entity->isNew()) {
      // Check if we had a title, if the new one is different and not empty.
      $originallabel = $entity->original->getTitle();
      if (($originallabel != $entity->label()) && !empty($entity->label())) {
        // Means someone manually, via a Title Widget, changed the title
        // If so, enforce that and don't try to overwrite.
        // But, webform widget, if updating title automatically is set, should
        // unset the 'title', allowing us to always get metadata title into
        // the node one. Still, allowing other widgets to set titles without us
        // overriding it. Smart?
        // Reality is, if you don't use Title, just leave it out.
        $forceupdate = FALSE;
      }
    }

    if (!$entity->label() || $forceupdate) {
      foreach ($sbf_fields as $field_name) {
        /* @var $field \Drupal\Core\Field\FieldItemInterface */
        $field = $entity->get($field_name);
        /* @var \Drupal\strawberryfield\Field\StrawberryFieldItemList $field */
        // This will try with any possible match.
        foreach ($field->getIterator() as $delta => $itemfield) {
          $full = $itemfield->provideDecoded(TRUE);
          $labelsource = isset($full['label']) ? $full : $itemfield->provideFlatten();
          // @TODO Should we allow which JSON key sets the label a setting?
          if (isset($labelsource['label'])) {
            // Flattener should always give me an array?
            if (is_array($labelsource['label'])) {
              $title = reset($labelsource['label']);
            }
            else {
              $title = $labelsource['label'];
            }
            if (strlen(trim($title)) > 0) {
              $title = Unicode::truncate($title, 128, TRUE, TRUE, 24);
              // we could check if originallabel != from the new title
              // I feel safer assinging and checking only for the status.
              $entity->setTitle($title);
              break 2;
            }
          }
        }
      }

      // If at this stage we have no entity label, but maybe its just not in our metadata,
      // or someone forget to set a label key.
      if (!$entity->label()) {
        // Means we need a title, got nothing from metadata or node, dealing with it.
        $title = $this->t(
          'New Untitled Archipelago Digital Object by @author',
          ['@author' => $entity->getOwner()->getDisplayName()]
        );
        $entity->setTitle($title);
      }
    }
    $current_class = get_called_class();
    $event->setProcessedBy($current_class, TRUE);
  }
}
