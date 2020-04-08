<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use CachingIterator;
use ArrayIterator;

/**
 * Event subscriber for SBF bearing entity presave event.
 */
class StrawberryfieldEventPresaveSubscriberVocabCreator extends StrawberryfieldEventPresaveSubscriber {

  use StringTranslationTrait;

  /**
   * @var int
   */
  protected static $priority = -1000;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;


  /**
   * Constructs a new StrawberryfieldEventPresaveSubscriberVocabCreator.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
  }


  /**
   * Method called when Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function onEntityPresave(StrawberryfieldCrudEvent $event) {

    /* @var $entity \Drupal\Core\Entity\ContentEntityInterface */
    $entity = $event->getEntity();
    $sbf_fields = $event->getFields();
    $newlycreatedcount = 0;
    foreach ($sbf_fields as $field_name) {
      /* @var $field \Drupal\Core\Field\FieldItemInterface */
      $field = $entity->get($field_name);
      if (!$field->isEmpty()) {
        $jsonkeys = [];
        $jsonkeys = $field->{'str_flatten_keys'};
        foreach ($jsonkeys as $path) {
          // Since our keys will be jsonpaths, we can explode them first by dot
          $path_parts_raw = [];
          $path_parts_raw = explode(".", $path);
          $terms = [];
          $terms = new CachingIterator(
            new ArrayIterator($path_parts_raw)
          );
          $term_path = [];
          foreach ($terms as $json_node) {
            if ($terms->hasNext()) {
              $next = $terms->getInnerIterator()->current();
              if ($next == '[*]' || $next == '*') {
                $term_path[] = $json_node . "." . $next;
                continue;
              }
            }
            if ($json_node != '[*]' && $json_node != '*') {
              $term_path[] = $json_node;
            }
            $parent_id = 0;
            $term_path = array_filter($term_path);
            $breadcrumb = '';
            foreach ($term_path as $path) {
              $breadcrumb = empty($breadcrumb) ? $path : $breadcrumb . '.' . $path;
              $query = \Drupal::entityQuery('taxonomy_term');
              $query->condition('vid', "strawberryfield_voc_id");
              $query->condition('name', $path);
              $query->condition('parent', $parent_id, 'IN');
              $tids = $query->execute();
              if (count($tids) == 0) {
                $new_term = \Drupal\taxonomy\Entity\Term::create(
                  [
                    'vid' => 'strawberryfield_voc_id',
                    'name' => $path,
                    'parent' => $parent_id,
                    'field_jsonpath' => $breadcrumb,
                  ]
                );
                $newlycreatedcount++;
                $new_term->enforceIsNew();
                $new_term->save();
                $parent_id = $new_term->id();
              }
              else {
                //Assuming parent is there
                foreach ($tids as $terms_id) {
                  $parent_id = $terms_id;
                }
              }
            }
          }
        }
      }
    }
    if ($newlycreatedcount > 0) {
      $this->messenger->addStatus(t('New Terms added to the vocabulary'));
    }
  }
}