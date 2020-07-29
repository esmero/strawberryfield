<?php

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\strawberryfield\StrawberryfieldFilePersisterService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;


/**
 * Event subscriber for SBF bearing entity presave event.
 *
 * This subscriber classifies files passed as entity id's into JSON keys
 * and fills the famous as:sometype structures we depend on
 * This subscriber is triggered on presave
 *
 * @NOTE: todo maybe we can trigger this one via a new event
 * That is cast directly before the file Persister Event?
 *
 */
class StrawberryfieldEventPresaveSubscriberAsFileStructureGenerator extends StrawberryfieldEventPresaveSubscriber {

  use StringTranslationTrait;

  /**
   * Base Core Content entities. We should allow modules to extend.
   */
  const SUPPORTED_CORE_ENTITIES = [
    'entity:taxonomy_term',
    'entity:media',
    'entity:node',
    'entity:file',
    'entity:media',
    'entity:aggregator_feed',
    'entity:user',
    'entity:metadatadisplay_entity',
  ];

  /**
   * Needs to run way before the file persister
   *
   * @var int
   */
  protected static $priority = -200;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The serializer.
   *
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
   * @var \Drupal\strawberryfield\StrawberryfieldFilePersisterService
   */
  protected $strawberryfilepersister;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * StrawberryfieldEventPresaveSubscriberAsFileStructureGenerator constructor.
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
  public function onEntityPresave(StrawberryfieldCrudEvent $event) {

    /* @var $entity \Drupal\Core\Entity\ContentEntityInterface */
    $entity = $event->getEntity();
    $sbf_fields = $event->getFields();
    $newlysavedcount = 0;
    foreach ($sbf_fields as $field_name) {
      /* @var $field \Drupal\Core\Field\FieldItemInterface */
      $field = $entity->get($field_name);
      /* @var \Drupal\strawberryfield\Field\StrawberryFieldItemList $field */

      $newlyprocessed = 0;
      if (!$field->isEmpty()) {
        $entity = $field->getEntity();
        $entity_type_id = $entity->getEntityTypeId();
        /** @var $field \Drupal\Core\Field\FieldItemList */
        foreach ($field->getIterator() as $delta => $itemfield) {
          $allprocessedAsValues = [];
          /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
          $fullvalues = $itemfield->provideDecoded(TRUE);
          // SBF needs to have the entity mapping key
          // helper structure to keep elements that map to entities around
          $fullvalues = $this->cleanUpEntityMappingStructure($fullvalues);
          // 'ap:entitymapping' will always exists of ::cleanUpEntityMappingStructure
          $entity_mapping_structure = $fullvalues['ap:entitymapping'];
          $allprocessedAsValues = [];
          if (isset($entity_mapping_structure['entity:file'])) {
            foreach ($entity_mapping_structure['entity:file'] as $jsonkey_with_filenumids) {
              // Here each $jsonkey_with_filenumids is a json key that holds file ids
              // Also $fullvalues[$jsonkeys_with_filenumids] will be there because
              // ::cleanUpEntityMappingStructure. Still, double check please?
              $processedAsValuesForKey = [];
              $fullvalues[$jsonkey_with_filenumids] = isset($fullvalues[$jsonkey_with_filenumids]) ? $fullvalues[$jsonkey_with_filenumids] : [];
              // make even single files an array
              $fids = [];
              $fids = (is_array($fullvalues[$jsonkey_with_filenumids])) ? $fullvalues[$jsonkey_with_filenumids] : [$fullvalues[$jsonkey_with_filenumids]];
              // Only keep ids that can be actually entity ids or uuids
              $fids = array_filter(
                $fids,
                [$this, 'isEntityId']
              );
              if (is_array($fids) && !empty($fids)) {
                $fids = array_unique($fids);
                // @TODO. If UUID the loader needs to be different.
                $processedAsValuesForKey = $this->strawberryfilepersister
                  ->generateAsFileStructure(
                    $fids,
                    $jsonkey_with_filenumids,
                    (array) $fullvalues
                  );
                $allprocessedAsValues = array_merge_recursive(
                  $allprocessedAsValues,
                  $processedAsValuesForKey
                );
              }
            }
            // WE should be able to load also UUIDs here.
           // Now assign back al as:structures
            // Distribute all processed AS values for each field into its final JSON
            // Structure, e.g as:image, as:application, as:documents, etc.\

            foreach ($allprocessedAsValues as $askey => $info) {
              // @TODO ensure non managed files inside structure are preserved.
              // Could come from another URL only field or added manually by some
              // Advanced user.
              $newlyprocessed = $newlyprocessed + count($info);
              $fullvalues[$askey] = $info;
            }
            if (!$itemfield->setMainValueFromArray((array) $fullvalues)) {
              $this->messenger->addError($this->t('We could not persist file classification. Please contact the site admin.'));
            };
          }
        }
      }

    }
    $current_class = get_called_class();
    $event->setProcessedBy($current_class, TRUE);
  }


  /**
   * This function normalizes an entity mapping array.
   *
   * @param array FULL JSON
   *   A full array that contains somewhere, hopefully
   *   something like
   *   "ap:entitymapping": {
   *     "entity:file": [
   *     "images",
   *     "documents",
   *     "audios",
   *     "videos",
   *     "models"
   *     ],
   *   "entity:node": {[
   *     "ismemberof"
   *   }
   *
   * @return array
   *   The cleaned/up $entityMapping
   */
  private function cleanUpEntityMappingStructure(array $fullvalues) {

    if (isset($fullvalues['ap:entitymapping']) && is_array(
        $fullvalues['ap:entitymapping']
      )) {
      $entityMapping = array_filter(
        $fullvalues['ap:entitymapping'],
        [$this,'prefixedEntity'],
        ARRAY_FILTER_USE_KEY
      );
      // We can not have an array of arrays.

      foreach ($entityMapping as $entity_type_key => &$jsonkeys_with_fileids) {
        $jsonkeys_with_fileids = array_filter(
          $jsonkeys_with_fileids,
          [$this,'isNotArray']
        );
        if (is_array($jsonkeys_with_fileids)) {
          foreach ($jsonkeys_with_fileids as $json_key) {
            // If not present simply create
            if (!isset($fullvalues[$json_key])) {
              $fullvalues[$json_key] = [];
            }
          }
        }
        // We are not checking here if the entity part is an actual entity
        // Or if each key contains or not the actual ids
        // nor if they are valid. IF we have 2000 entities
        // doing this here is an overkill. Just do when needed
        // Also: i really want to allow relationships to exist even before
        // the referenced entites are present.
        // We really care here only for the entity:file part
        // but will do our best to clean all.
      }
      $fullvalues['ap:entitymapping'] = $entityMapping;
    }
    else {
      // If not here or not an array create the structure. We want it .
      $fullvalues['ap:entitymapping'] = [
        "entity:file" => [],
      ];
    }
    return $fullvalues;
  }

  /**
   * Checks if value is integer or an UUID.
   *
   * @param mixed $val
   *
   * @return bool
   */
  private function isEntityId($val) {
    return (is_int($val) && $val > 0) || \Drupal\Component\Uuid\Uuid::isValid(
        $val
      );
  }
  /**
   * Array value callback. True if value is not an array.
   *
   * @param mixed $val
   *
   * @return bool
   */
  private  function isNotArray($val) {
    return !is_array($val);
  }

  /**
   * Array value callback. True if $key starts with Entity
   *
   * @param mixed $val
   *
   * @return bool
   */
  private function prefixedEntity($key) {
    return (strpos($key, 'entity:', 0) !== FALSE);
  }
}