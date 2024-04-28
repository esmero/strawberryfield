<?php

namespace Drupal\strawberryfield\Plugin\search_api\datasource;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;
use Drupal\search_api\LoggerTrait;
use Drupal\search_api\Plugin\search_api\datasource\ContentEntity;
use Drupal\strawberryfield\TypedData\StrawberryfieldFlavorDataDefinition;
use Drupal\Core\Language\LanguageInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\PluginDependencyTrait;

/**
 * Represents a datasource which exposes flavors.
 *
 * @SearchApiDatasource(
 *   id = "strawberryfield_flavor_datasource",
 *   label = @Translation("Strawberryfield Flavor Datasource")
 * )
 */
class StrawberryfieldFlavorDatasource extends DatasourcePluginBase implements StrawberryfieldFlavorDatasourceInterface {

  use PluginFormTrait;
  use PluginDependencyTrait;
  use LoggerTrait;

  /**
   * The Key Value Collection used to store temp values for Items
   */
  public const SBFL_KEY_COLLECTION = 'Strawberryfield_flavor_datasource_temp';

  /**
   * The key for accessing last tracked Page information in site state for flavor datasource.
   */
  protected const TRACKING_OFFSET_STATE_KEY = 'search_api.datasource.strawberryfield_flavor_datasource.last_offset';

  /**
   * An MINI OCR XML defined for empty pages.
   */
  public const EMPTY_MINIOCR_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ocr><p xml:id="empty_sequence" wh="100 100"><b><l><w x="0 0 0 0"> </w></l></b></p></ocr>
XML;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|null
   */
  protected $entityTypeManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|null
   */
  protected $configFactory;


  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|null
   */
  protected $entityFieldManager;

  /**
   * The typed data manager.
   *
   * @var \Drupal\Core\TypedData\TypedDataManagerInterface|null
   */
  protected $typedDataManager;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface|null
   */
  protected $entityTypeBundleInfo;

  /**
   * Key value service.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface
   */
  protected $keyValue;

  /**
   * The entity display repository manager.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface|null
   */
  protected $entityDisplayRepository;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $datasource */
    $datasource = parent::create(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition
    );

    $datasource->entityTypeManager = $container->get('entity_type.manager');
    $datasource->entityFieldManager = $container->get('entity_field.manager');
    $datasource->entityTypeBundleInfo = $container->get('entity_type.bundle.info');
    $datasource->typedDataManager = $container->get('typed_data_manager');
    $datasource->languageManager = $container->get('language_manager');
    $datasource->keyValue = $container->get('strawberryfield.keyvalue.database');
    $datasource->state = $container->get('state');
    return $datasource;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    return $this->typedDataManager
      ->createDataDefinition('strawberryfield_flavor_data')
      ->getPropertyDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function getApplicableBundlesWithSbfField() {
    $listFields = [];
    $entity_type_id = $this->getEntityTypeId();
    if ($this->hasBundles()) {
      $bundles = array_keys($this->getEntityBundles());
      foreach ($bundles as $bundle) {
        $fields = $this->entityFieldManager->getFieldDefinitions(
          $entity_type_id,
          $bundle
        );
        foreach ($fields as $field_name => $field_definition) {
          if (!empty($field_definition->getTargetBundle())
            && $field_definition->getType() == 'strawberryfield_field'
          ) {
            $listFields[$bundle][] = $field_name;
          }
        }
      }
    }
    return $listFields;
  }

  /**
   * {@inheritdoc}
   */
  public function canContainEntityReferences(): bool {
    return TRUE;
  }

  public function getItemId(ComplexDataInterface $item) {
    // @TODO This id is not the one for a particular flavor
    // but the one from the source, which in this case is a node
    // This is tricky since we really have a lot of id's for each node
    // we will return  NULL and deal with tracking in our own hook.
    // @see search_api.module \search_api_node_access_records_alter

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemUrl(ComplexDataInterface $item) {
    if ($entity = $this->getEntity($item)) {
      if ($entity->hasLinkTemplate('canonical')) {
        return $entity->toUrl('canonical');
      }
    }
    return NULL;
  }

  /**
   * Retrieves the entity from a search item.
   *
   * @param \Drupal\Core\TypedData\ComplexDataInterface $item
   *   An item of this datasource's type.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The parent entity object for that item, or NULL if none could be
   *   found.
   */
  protected function getEntity(ComplexDataInterface $item) {
    $value = $item->get('target_id')->getValue();

    return $value instanceof EntityInterface ? $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemIds($page = NULL) {
    return $this->getPartialItemIds($page);
  }

  /**
   * {@inheritdoc}
   */
  public function getItemAccessResult(ComplexDataInterface $item, AccountInterface $account = NULL) {
    $entity = $this->getEntity($item);
    if ($entity) {
      return $this->getEntityTypeManager()
        ->getAccessControlHandler($this->getEntityTypeId())
        ->access($entity, 'view', $account, TRUE);
    }
    return AccessResult::neutral('Item is not an entity, so cannot check access');
  }

  /**
   * Retrieves a scalar field value from a result item.
   *
   * @param \Drupal\Core\TypedData\ComplexDataInterface $item
   *   The result item.
   * @param string $config_key
   *   The key in the configuration.
   *
   * @return mixed|null
   *   The scalar value of the specified field (first value for multi-valued
   *   fields), if it exists; NULL otherwise.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getFieldValue(ComplexDataInterface $item, $config_key) {
    if (empty($this->configuration[$config_key])) {
      return NULL;
    }
    $values = $item->get($this->configuration[$config_key])->getValue();
    if (is_array($values)) {
      $values = $values ? reset($values) : NULL;
    }
    return $values ?: NULL;
  }
  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function getItemLabel(ComplexDataInterface $item) {
    if ($this->getFieldValue($item, 'label_field')) {
      return $this->getFieldValue($item, 'label_field');
    }
    elseif ($entity = $this->getEntity($item)) {
      return $entity->label();
    }
    else {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getPartialItemIds($page = NULL, array $bundles = NULL, array $languages = NULL) {

    if (isset($page)) {
      $page_size = $this->getConfigValue('tracking_page_size');
      assert($page_size, 'Tracking page size is not set.');
      $offset = $page * 100; // Yeah hundred is cool.
    }
    else {
      //Bananas! we can't get a Million? No, we can't
      $page_size = $this->getConfigValue('tracking_page_size') ?? 100;
      $page = 0;
      $offset = $page * $page_size;
    }
    if ($page > 0) {
      $offset = $this->state->get(self::TRACKING_OFFSET_STATE_KEY, $offset);
    }


    $finished = FALSE;
    $morethanone = FALSE;
    while ((!$finished && !$morethanone)) {
        $valid_item_ids = $this->getFlavorKeysFromBackend($offset, $page_size);
        if ($valid_item_ids == NULL) {
          $finished = TRUE;
        }
        elseif (count($valid_item_ids)) {
          $morethanone = TRUE;
        }
        else {
          $page = $page + 1;
          $offset = $page * $page_size;
        }
    }

    if ($finished) {
      $this->state->delete(self::TRACKING_OFFSET_STATE_KEY);
      return $valid_item_ids;
    }

    // If so, i want to keep a state of the last page tracked ... bc any next page will if not repeat again the call.
    $this->state->set(self::TRACKING_OFFSET_STATE_KEY, ($offset + $page_size));
    return $valid_item_ids;
  }

  private function getFlavorKeysFromBackend($offset, $page_size) {
    $item_ids =  $this->keyValue->get(self::SBFL_KEY_COLLECTION)->listKeys($offset, $page_size);
    $valid_item_ids = [];
    // Now with this in place we will actually check if Nodes/files for these exist.
    // Should be the case always. But extra security might be good?
    $entity_ids = [];
    $entity_ids_splitted = [];
    foreach ($item_ids as $id => $value) {
      $splitted_id = explode(':', $id);
      if (isset($splitted_id[0])) {
        $entity_ids[$splitted_id[0]] = $splitted_id[0];
        $entity_ids_splitted[$splitted_id[0]][$id] = $splitted_id;
      }
    }
    $entity_ids = array_values($entity_ids);
    foreach (
      $this->getEntityStorage()->loadMultiple($entity_ids) as $entity_id =>
      $entity
    ) {
      $status = $entity->isPublished();
      $uid = $entity->getOwnerId();
      foreach (
        $entity_ids_splitted[$entity_id] as $item_id => $splitted_id_for_node
      ) {
        $fid_uuid = isset($splitted_id_for_node[3]) ? $splitted_id_for_node[3] : NULL;
        $files = $this->entityTypeManager->getStorage('file')->loadByProperties(
          [
            'uuid' => $fid_uuid,
          ]
        );
        $file = $files ? reset($files) : NULL;
        if ($file) {
          $valid_item_ids[] = $item_id;
        }
        unset($file);
      }
    }
    if (Utility::isRunningInCli()) {
      // When running in the CLI, this might be executed for all entities from
      // within a single process. To avoid running out of memory, reset the
      // static cache after each batch.
      $this->getEntityStorage()->resetCache($entity_ids);
    }
    // NULL here means the query returned 0, means we are done.
    return count($item_ids) ? $valid_item_ids : NULL;
  }


  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return 'node';
  }

  /**
   * {@inheritdoc}
   */
  public function getBundles() {
    if (!$this->hasBundles()) {
      // Nodes have always bundles, so if no bundle return empty.
      // @TODO extend datasource support to other entities in the future.
      return [];
    }

    $configuration = $this->getConfiguration();

    // If "default" is TRUE (that is, "All except those selected"),remove all
    // the selected bundles from the available ones to compute the indexed
    // bundles. Otherwise, return all the selected bundles.
    $bundles = [];
    $possiblebundles = $this->getApplicableBundlesWithSbfField();
    $entity_bundles = array_intersect_key(
      $this->getEntityBundles(),
      $possiblebundles
    );
    $selected_bundles = array_flip($configuration['bundles']['selected']);
    $function = $configuration['bundles']['default'] ? 'array_diff_key' : 'array_intersect_key';
    $entity_bundles = $function($entity_bundles, $selected_bundles);
    foreach ($entity_bundles as $bundle_id => $bundle_info) {
      $bundles[$bundle_id] = isset($bundle_info['label']) ? $bundle_info['label'] : $bundle_id;
    }
    return $bundles ?: [$this->getEntityTypeId() => $this->label()];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $default_configuration = [];

    if ($this->hasBundles()) {
      $default_configuration['bundles'] = [
        'default' => TRUE,
        'selected' => [],
      ];
    }

    if ($this->isTranslatable()) {
      $default_configuration['languages'] = [
        'default' => TRUE,
        'selected' => [],
      ];
    }

    $default_configuration['metadatadisplayentity_source'] = NULL;

    return $default_configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    if ($this->hasBundles() && ($bundles = $this->getEntityBundleOptions())) {
      $form['bundles'] = [
        '#type' => 'details',
        '#title' => $this->t('Bundles'),
        '#open' => TRUE,
      ];
      $form['bundles']['default'] = [
        '#type' => 'radios',
        '#title' => $this->t(
          'Which bundles bearing Strawberryfields should be indexed?'
        ),
        '#options' => [
          0 => $this->t('Only those selected'),
          1 => $this->t('All except those selected'),
        ],
        '#default_value' => (int) $this->configuration['bundles']['default'],
      ];
      $form['bundles']['selected'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Bundles'),
        '#options' => $bundles,
        '#default_value' => $this->configuration['bundles']['selected'],
        '#size' => min(4, count($bundles)),
        '#multiple' => TRUE,
      ];
    }

    if ($this->isTranslatable()) {
      $form['languages'] = [
        '#type' => 'details',
        '#title' => $this->t('Languages'),
        '#open' => TRUE,
      ];
      $form['languages']['default'] = [
        '#type' => 'radios',
        '#title' => $this->t('Which languages should be indexed?'),
        '#options' => [
          0 => $this->t('Only those selected'),
          1 => $this->t('All except those selected'),
        ],
        '#default_value' => (int) $this->configuration['languages']['default'],
      ];
      $form['languages']['selected'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Languages'),
        '#options' => $this->getTranslationOptions(),
        '#default_value' => $this->configuration['languages']['selected'],
        '#multiple' => TRUE,
      ];
    }
    if (\Drupal::moduleHandler()->moduleExists('format_strawberryfield')) {
      $entity = NULL;
      if ($this->configuration['metadatadisplayentity_source']) {
        $entity = $this->entityTypeManager->getStorage(
          'metadatadisplay_entity'
        )->load($this->configuration['metadatadisplayentity_source']);
      }
      $form['metadatadisplayentity_source'] = [
        '#title' => $this->t('A Metadata Display entity (Twig Template) to be used to Render a single Item.'),
        '#type' => 'entity_autocomplete',
        '#target_type' => 'metadatadisplay_entity',
        '#selection_handler' => 'default:metadatadisplay',
        '#validate_reference' => FALSE,
        '#default_value' => $entity,
        '#states' => [
          'visible' => [
            ':input[data-formatter-selector="mediasource"]' => ['value' => 'metadatadisplayentity'],
          ],
        ],
      ];
    }
    else {
      $form['metadatadisplayentity_source'] = [
        '#type' => 'value',
        '#default_value' => NULL,
      ];
    }
    return $form;
  }

  public function getAffectedItemsForEntityChange(EntityInterface $entity,
    array $foreign_entity_relationship_map,
    EntityInterface $original_entity = NULL
  ): array {
    return parent::getAffectedItemsForEntityChange(
      $entity, $foreign_entity_relationship_map, $original_entity
    ); // TODO: Change the autogenerated stub
  }


  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids) {
    $documents = [];
    $sbfflavordata_definition = StrawberryfieldFlavorDataDefinition::create(
      'strawberryfield_flavor_data'
    );
    $entity_ids = [];
    $content_item_ids_to_update = [];

    foreach ($ids as $id) {
      $splitted_id = explode(':', $id);
      if (isset($splitted_id[0])) {
        $entity_ids[$splitted_id[0]] = $splitted_id[0];
        $entity_ids_splitted[$splitted_id[0]][$id] = $splitted_id;
      }
    }
    $entity_ids = array_values($entity_ids);
    // IMPORTANT KIDS!
    // This is the structure of one of our ids
    // Means one $entity_ids_splitted will contain this splitted
    // $id = $entity->id() . ':'.$sequence .':'.$translation_id.':'.$file->uuid().':'.$data->plugin_config_entity_id;
    foreach (
      $this->getEntityStorage()->loadMultiple($entity_ids) as $entity_id =>
      $entity
    ) {
      $status = $entity->isPublished();
      $uid = $entity->getOwnerId();
      foreach (
        $entity_ids_splitted[$entity_id] as $item_id => $splitted_id_for_node
      ) {
        $sequence_id = !empty($splitted_id_for_node[1]) ? $splitted_id_for_node[1] : 1;
        $fid_uuid = isset($splitted_id_for_node[3]) ? $splitted_id_for_node[3] : NULL;
        $plugin_id = isset($splitted_id_for_node[4]) ? $splitted_id_for_node[4] : NULL;
        $translation_id = isset($splitted_id_for_node[2]) ? $splitted_id_for_node[2] : NULL;
        // probably we will want to add the module/class namespace for the plugin id?
        $files = $this->entityTypeManager->getStorage('file')->loadByProperties(
          [
            'uuid' => $fid_uuid,
          ]
        );
        $file = $files ? reset($files) : NULL;

        if ($file && $plugin_id !== NULL
          && ($processed_data
            = $this->getFlavorFromBackend($item_id))
        ) {
          // Put the package File ID / Package.
          $fulltext = isset($processed_data->fulltext) ? (string) $processed_data->fulltext : '';
          $label = isset($processed_data->label) ? (string) $processed_data->label : "Sequence {$sequence_id}";
          $plaintext = isset($processed_data->plaintext) ? (string) $processed_data->plaintext : '';
          $checksum = isset($processed_data->checksum) ? (string) $processed_data->checksum : NULL;
          $where = isset($processed_data->where) ? (array) $processed_data->where : [];
          $where= preg_grep(
            "/^[\p{L}|\p{N}\s+]+[\p{L}|\p{N}\s\-'+]+[\p{L}|\p{N}\s+]+$/u", $where);
          $who = isset($processed_data->who) ? (array) $processed_data->who : [];
          // NLP will come cleaned but still good to do again in case it was modified on the backend
          // Or is an oldie.
          $who = preg_grep(
            "/^[\p{L}|\p{N}\s+]+[\p{L}|\p{N}\s\-'+]+[\p{L}|\p{N}\s+]+$/u", $who);
          $metadata = isset($processed_data->metadata) ? (array) $processed_data->metadata : [];
          $metadata = preg_grep(
            "/^[\p{L}|\p{N}\s+]+[\p{L}|\p{N}\s\-'+]+[\p{L}|\p{N}\s+]+$/u", $metadata);
          $when = isset($processed_data->when) ? (array) $processed_data->when : [];

          $ts = isset($processed_data->ts) ? (string) $processed_data->ts : date("c");
          $sentiment = isset($processed_data->sentiment) ? (is_scalar($processed_data->sentiment) ? $processed_data->sentiment : 0) : 0;
          $uri = isset($processed_data->uri) ? (string) $processed_data->uri : '';
          $sequence_total = isset($processed_data->sequence_total) ? (string) $processed_data->sequence_total : $sequence_id;
          $sequence_id = isset($processed_data->sequence_id) ? (int) $processed_data->sequence_id : $sequence_id;
          $config_processor_id = isset($processed_data->config_processor_id) ? $processed_data->config_processor_id : '';
          $nlplang = isset($processed_data->nlplang) ? $processed_data->nlplang : [];
          $processlang = isset($processed_data->processlang) ? $processed_data->processlang : [];
          if ($checksum) {
            $data = [
              'item_id'             => $item_id,
              'label'               => $label,
              'sequence_id'         => $sequence_id,
              'sequence_total'      => $sequence_total,
              'target_id'           => $entity_id,
              'parent_id'           => $entity_id,
              'file_uuid'           => $file->uuid(),
              'target_fileid'       => $file->id(),
              'config_processor_id' => $config_processor_id,
              'processor_id'        => $plugin_id,
              'fulltext'            => '',
              'plaintext'           => '',
              'metadata'            => $metadata,
              'who'                 => $who,
              'nlplang'             => $nlplang,
              'processlang'         => $processlang,
              'where'               => $where,
              'when'                => $when,
              'ts'                  => $ts,
              'sentiment'           => $sentiment,
              'uri'                 => $uri,
              'checksum'            => $checksum,
              'status'              => $status,
              'uid'                 => $uid,
            ];
            // This will then always create a new Index document, even if empty.
            // Needed if we e.g are gonna use this for Book search/IIIF search
            // to make sure it at least exists!
            if (!empty(trim($fulltext))) {
              try {
                // This only meant for backwards compat
                // for existing Solr Documents/ Data Sources.
                // @TODO remove in 1.2.0
                // Make this change persist in the backend.
                $doc = new \DOMDocument();
                $doc->preserveWhiteSpace = false;
                $doc->loadxml($fulltext);
                $xpath = new \DOMXPath($doc);
                foreach( $xpath->query('//*[not(node())]') as $node ) {
                  $node->parentNode->removeChild($node);
                }
                $doc->formatOutput = true;
                $fulltext = $doc->savexml();
              }
              catch (\Exception $e) {
              }
              $data['fulltext'] = trim($fulltext);
            }
            if (!empty(trim($plaintext))) {
              $data['plaintext'] = trim($plaintext);
            }
            elseif (!empty($data['fulltext'])) {
              // This assumes miniOCR and is only meant for backwards compat
              // for existing Solr Documents/ Data Sources.
              $data['plaintext'] = str_replace(
                "<w>", "<w> ", $data['fulltext']
              );
              $data['plaintext'] = strip_tags(
                str_replace("<l>", PHP_EOL . "<l> ", $data['plaintext'])
              );
            }

            $documents[$item_id] = $this->typedDataManager->create(
              $sbfflavordata_definition
            );
            $documents[$item_id]->setValue($data);
          }
          // As good as we can here
          // Try avoiding tracking for update if we are still processing partial sequences
          // This will of course make no difference for Single Files/Single Sequence.
          if ($sequence_id == $sequence_total) {
            // We store the entity. That way we can get the parents
            // out of it.
            // We don't know much at this stage
            // But we do know ispartof (static for now)
            // Putting a collection to be updated in the index
            // is cheaper than querying for the actual property.
            $content_item_ids_to_update[$entity->getType()][$entity_id . ':' . $translation_id] = $entity;
            // Fetch also second level of parent. We don't aggregate further than that
            if ($entity->field_sbf_nodetonode instanceof EntityReferenceFieldItemListInterface) {
              foreach ($entity->field_sbf_nodetonode->referencedEntities() as $key => $referencedEntity) {
                $content_item_ids_to_update[$referencedEntity->getType()][$referencedEntity->id() . ':' . $translation_id] = $referencedEntity;
              }
            }
          }
        }
        else {
          //@TODO should we untrack when this is the case?
          $this->getLogger()
            ->warning(
              'Passed Data Source with Item_id @id for Strawberryfield_flavor_datasource had NULL elements in its path or a source file does no longer exists or is not in the backend',
              [
                '@id' => $item_id,
              ]
            );
        }
      }
    }

    /** @var \Drupal\search_api\Plugin\search_api\datasource\ContentEntityTrackingManager $tracking_manager */
    $tracking_manager = \Drupal::getContainer()
      ->get('search_api.entity_datasource.tracking_manager');
    // We don't want to do this many times. We only need to call fetching the index once
    // Per bundle.
    foreach ($content_item_ids_to_update as $bundle => $items_by_bundle) {
      $one_entity = reset($items_by_bundle);
      $indexes = $tracking_manager->getIndexesForEntity($one_entity);
      foreach ($indexes as $index) {
        $index->trackItemsUpdated('entity:node', array_keys($items_by_bundle));
      }
    }

    return $documents;
  }

  /**
   * {@inheritdoc}
   */
  public static function getValidIndexes() {
    $datasource_id = 'strawberryfield_flavor_datasource';

    /** @var \Drupal\search_api\IndexInterface[] $indexes */
    $indexes = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->loadMultiple();

    foreach ($indexes as $index_id => $index) {
      // Filter out indexes that don't contain the datasource in question.
      if (!$index->isValidDatasource($datasource_id)) {
        unset($indexes[$index_id]);
      }
    }
    return $indexes;
  }


  /**
   * {@inheritdoc}
   */
  public function getViewModes($bundle = NULL) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function viewItem(ComplexDataInterface $item, $view_mode, $langcode = NULL) {
    return [];
  }
  /**
   * {@inheritdoc}
   */
  public function viewMultipleItems(array $items, $view_mode, $langcode = NULL) {
    try {
      $view_builder = $this->getEntityTypeManager()
        ->getViewBuilder($this->getEntityTypeId());
      // Langcode passed, use that for viewing.
      if (isset($langcode)) {
        $entities = [];
        foreach ($items as $i => $item) {
          if ($entity = $this->getEntity($item)) {
            $entities[$i] = $entity;
          }
        }
        if ($entities) {
          return $view_builder->viewMultiple($entities, $view_mode, $langcode);
        }
        return [];
      }
      // Otherwise, separate the items by language, keeping the keys.
      $items_by_language = [];
      foreach ($items as $i => $item) {
        if ($entity = $this->getEntity($item)) {
          $items_by_language[$entity->language()->getId()][$i] = $entity;
        }
      }
      // Then build the items for each language. We initialize $build beforehand
      // and use array_replace() to add to it so the order stays the same.
      $build = array_fill_keys(array_keys($items), []);
      foreach ($items_by_language as $langcode => $language_items) {
        $build = array_replace(
          $build,
          $view_builder->viewMultiple($language_items, $view_mode, $langcode)
        );
      }
      return $build;
    } catch (\Exception $e) {
      // The most common reason for this would be a
      // \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException in
      // getViewBuilder(), because the entity type definition doesn't specify a
      // view_builder class.
      return [];
    }
  }

  /**
   * Retrieves the entity display repository.
   *
   * @return \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   *   The entity entity display repository.
   */
  protected function getEntityDisplayRepository() {
    return $this->entityDisplayRepository ?: \Drupal::service(
      'entity_display.repository'
    );
  }

  /**
   * Sets the entity display repository.
   *
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The new entity display repository.
   *
   * @return $this
   */
  protected function setEntityDisplayRepository(EntityDisplayRepositoryInterface $entity_display_repository) {
    $this->entityDisplayRepository = $entity_display_repository;
    return $this;
  }

  /**
   * Retrieves the config factory.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The config factory.
   */
  protected function getConfigFactory() {
    return $this->configFactory ?: \Drupal::configFactory();
  }

  /**
   * Retrieves the entity storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The entity storage.
   */
  protected function getEntityStorage() {
    return $this->getEntityTypeManager()->getStorage($this->getEntityTypeId());
  }

  /**
   * Retrieves the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  protected function getEntityTypeManager() {
    return $this->entityTypeManager ?: \Drupal::entityTypeManager();
  }

  /**
   * Retrieves the language manager.
   *
   * @return \Drupal\Core\Language\LanguageManagerInterface
   *   The language manager.
   */
  protected function getLanguageManager() {
    return $this->languageManager ?: \Drupal::languageManager();
  }

  /**
   * Determines whether the entity type supports translations.
   *
   * @return bool
   *   TRUE if the entity is translatable, FALSE otherwise.
   */
  protected function isTranslatable() {
    return $this->getEntityType()->isTranslatable();
  }

  /**
   * Retrieves the available bundles of this entity type as an options list.
   *
   * @return array
   *   An associative array of bundle labels, keyed by the bundle name.
   */
  protected function getEntityBundleOptions() {
    $options = [];
    // returns an array with bundles and SBF field names
    $possiblebundles = $this->getApplicableBundlesWithSbfField();
    if ($bundles = $this->getEntityBundles()) {
      $bundles = array_intersect_key($bundles, $possiblebundles);
      // Filter against the bundles we can process
      foreach ($bundles as $bundle => $bundle_info) {
        $options[$bundle] = Utility::escapeHtml($bundle_info['label']);
      }
    }
    return $options;
  }

  /**
   * Retrieves the available languages of this entity type as an options list.
   *
   * @return array
   *   An associative array of language labels, keyed by the language name.
   */
  protected function getTranslationOptions() {
    $options = [];
    foreach ($this->getLanguageManager()->getLanguages() as $language) {
      $options[$language->getId()] = $language->getName();
    }
    return $options;
  }

  /**
   * Determines whether the entity type supports bundles.
   *
   * @return bool
   *   TRUE if the entity type supports bundles, FALSE otherwise.
   */
  protected function hasBundles() {
    return $this->getEntityType()->hasKey('bundle');
  }

  /**
   * Retrieves all bundles of this datasource's entity type.
   *
   * @return array
   *   An associative array of bundle infos, keyed by the bundle names.
   */
  protected function getEntityBundles() {
    return $this->hasBundles() ? $this->entityTypeBundleInfo->getBundleInfo(
      $this->getEntityTypeId()
    ) : [];
  }


  /**
   * Returns the definition of this datasource's entity type.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface
   *   The entity type definition.
   */
  protected function getEntityType() {
    return $this->getEntityTypeManager()
      ->getDefinition($this->getEntityTypeId());
  }

  /**
   * Retrieves the config value for a certain key in the Search API settings.
   *
   * @param string $key
   *   The key whose value should be retrieved.
   *
   * @return mixed
   *   The config value for the given key.
   */
  protected function getConfigValue($key) {
    return $this->getConfigFactory()->get('search_api.settings')->get($key);
  }

  /**
   * Retrieves the enabled languages.
   *
   * @return \Drupal\Core\Language\LanguageInterface[]
   *   All languages that are enabled for this datasource, keyed by language
   *   code.
   */
  protected function getLanguages() {
    $all_languages = $this->getLanguageManager()->getLanguages();

    if ($this->isTranslatable()) {
      $selected_languages = array_flip(
        $this->configuration['languages']['selected']
      );
      if ($this->configuration['languages']['default']) {
        return array_diff_key($all_languages, $selected_languages);
      }
      else {
        return array_intersect_key($all_languages, $selected_languages);
      }
    }

    return $all_languages;
  }

  /**
   * Retrieves on StrawberryFlavor Entry from the backend.
   *
   * @param $item_id
   *
   * @return mixed
   */
  public function getFlavorFromBackend($item_id) {
    return $this->keyValue->get(self::SBFL_KEY_COLLECTION)->get(
      $item_id
    );
  }

}
