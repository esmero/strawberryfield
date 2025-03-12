<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\strawberryfield\Event\StrawberryfieldFileEvent;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for Composting Temp files event.
 */
class StrawberryfieldEventCompostBinSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Try to run last in case someone needs to do something with this file before
   *
   * @var int
   */
  protected static $priority = 700;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * StrawberryfieldEventPresaveSubscriberAsFileStructureGenerator constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
  }
  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {

    $events[StrawberryfieldEventType::TEMP_FILE_CREATION][] = ['onTempFilePushed', static::$priority];
    return $events;
  }

  /**
   * Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldFileEvent $event
   *   The event.
   */
  public function onTempFilePushed(StrawberryfieldFileEvent $event) {
    // Not much here to see. The Queue Worker does the logic
    // We want to be quick, as quick as we can.
    $data = new \stdClass();
    $data->uri = $event->getURI();
    $data->module = $event->getModule();
    $data->timestamp = $event->getTimeStamp();
    if (!\Drupal::queue('sbf_compost_file', TRUE)
      ->createItem($data)) {
      $this->messenger->addError($this->t('We could not keep track of (enqueue) temporary file @file and will not be able to remove it later. Please check your logs and contact your admin to deal with it manually.', ['@file' => $data->uri]));
    }
    $current_class = get_called_class();
    // Silly but i'm a destructor!
    unset($data);
    $event->setProcessedBy($current_class, TRUE);
  }
}
