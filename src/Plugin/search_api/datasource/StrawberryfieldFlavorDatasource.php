<?php

namespace Drupal\strawberryfield\Plugin\search_api\datasource;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;
use Drupal\strawberryfield\TypedData\StrawberryfieldFlavorDataDefinition;
use Drupal\Core\Language\LanguageInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
    $datasource->keyValue = $container->get('keyvalue');

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
  public function getPartialItemIds($page = NULL, array $bundles = NULL, array $languages = NULL) {
    $select = $this->getEntityTypeManager()
      ->getStorage($this->getEntityTypeId())
      ->getQuery();

    // When tracking items, we never want access checks.
    $select->accessCheck(FALSE);

    // We want to determine all entities of either one of the given bundles OR
    // one of the given languages. That means we can't just filter for $bundles
    // if $languages is given. Instead, we have to filter for all bundles we
    // might want to include and later sort out those for which we want only the
    // translations in $languages and those (matching $bundles) where we want
    // all (enabled) translations.
    if ($this->hasBundles()) {

      $bundle_property = $this->getEntityType()->getKey('bundle');
      if ($bundles && !$languages) {
        $select->condition($bundle_property, $bundles, 'IN');
      }
      else {
        $enabled_bundles = array_keys($this->getBundles());
        // Since this is also called for removed bundles/languages,
        // $enabled_bundles might not include $bundles.
        if ($bundles) {
          $enabled_bundles = array_unique(
            array_merge($bundles, $enabled_bundles)
          );
        }
        if (count($enabled_bundles) < count($this->getEntityBundles())) {
          $select->condition($bundle_property, $enabled_bundles, 'IN');
        }
      }
    }

    if (isset($page)) {
      $page_size = $this->getConfigValue('tracking_page_size');
      assert($page_size, 'Tracking page size is not set.');
      $select->range($page * $page_size, $page_size);
      // For paging to reliably work, a sort should be present.
      $entity_id = $this->getEntityType()->getKey('id');
      $select->sort($entity_id);
    }

    $entity_ids = $select->execute();

    if (!$entity_ids) {
      return NULL;
    }

    // For all loaded entities, compute all their item IDs (one for each
    // translation we want to include). For those matching the given bundles (if
    // any), we want to include translations for all enabled languages. For all
    // other entities, we just want to include the translations for the
    // languages passed to the method (if any).
    $item_ids = [];
    $enabled_languages = array_keys($this->getLanguages());
    // As above for bundles, $enabled_languages might not include $languages.
    if ($languages) {
      $enabled_languages = array_unique(
        array_merge($languages, $enabled_languages)
      );
    }
    // Also, we want to always include entities with unknown language.
    $enabled_languages[] = LanguageInterface::LANGCODE_NOT_SPECIFIED;
    $enabled_languages[] = LanguageInterface::LANGCODE_NOT_APPLICABLE;

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    foreach ($this->getEntityStorage()->loadMultiple(
      $entity_ids
    ) as $entity_id => $entity) {
      $translations = array_keys($entity->getTranslationLanguages());
      $translations = array_intersect($translations, $enabled_languages);
      // If only languages were specified, keep only those translations matching
      // them. If bundles were also specified, keep all (enabled) translations
      // for those entities that match those bundles.
      if ($languages !== NULL
        && (!$bundles || !in_array($entity->bundle(), $bundles))) {
        $translations = array_intersect($translations, $languages);
      }
      // Well, well well. Happens that we may have gotten this wrong?
      // Flavours can be also file based. One ADO manny files?
      // Many times files flavors...
      // Also should we track here who provides the data
      // SB_runners config ID?

      // We will throw the service invoke event here
      // And fetch back which ones to be indexed?

      foreach ($translations as $langcode) {
        $sequence_id = 1;
        $item_ids[] = "$entity_id:$sequence_id:$langcode";
        // WE probably don't want anything here at all
        // since this is pushed really by a queue
        // and should never run if there is no real data source!
      }
    }

    if (Utility::isRunningInCli()) {
      // When running in the CLI, this might be executed for all entities from
      // within a single process. To avoid running out of memory, reset the
      // static cache after each batch.
      $this->getEntityStorage()->resetCache($entity_ids);
    }

    return [];
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

    return $form;
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
    $keyvalue_collection = 'Strawberryfield_flavor_datasource_temp';

    foreach ($this->getEntityStorage()->loadMultiple(
      $entity_ids
    ) as $entity_id => $entity) {
      foreach ($entity_ids_splitted[$entity_id] as $item_id => $splitted_id_for_node) {
        $fid_uuid = isset($splitted_id_for_node[3]) ? $splitted_id_for_node[3] : NULL;
        $plugin_id = isset($splitted_id_for_node[4]) ? $splitted_id_for_node[4] : NULL;
        // probably we will want to add the module/class namespace for the plugin id?
        $files = $this->entityTypeManager->getStorage('file')->loadByProperties(
          [
            'uuid' => $fid_uuid,
          ]
        );
        $file = $files ? reset($files) : NULL;

        if ($file && $plugin_id !== NULL) {
          $processed_data = $this->keyValue->get($keyvalue_collection)->get(
            $item_id
          );
          // Put the package File ID / Package.
          $fulltext = isset($processed_data->fulltext) ? (string) $processed_data->fulltext : '';
          $checksum = isset($processed_data->checksum) ? (string) $processed_data->checksum : NULL;
          if ($checksum) {
            $data = [
              'item_id' => $item_id,
              'sequence_id' => $splitted_id_for_node[1],
              'target_id' => $splitted_id_for_node[0],
              'parent_id' => $splitted_id_for_node[0],
              'file_uuid' => $fid_uuid,
              'target_fileid' => $file->id(),
              'processor_id' => $plugin_id,
              'fulltext' => '',
              'checksum' => $checksum,
            ];
            // This will then always create a new Index document, even if empty.
            // Needed if we e.g are gonna use this for Book search/IIIF search
            // to make sure it at least exists!
            if (!empty(trim($fulltext))) {
              $data['fulltext'] = trim($fulltext);
            }
            $documents[$item_id] = $this->typedDataManager->create(
              $sbfflavordata_definition
            );
            $documents[$item_id]->setValue($data);
          }
        }
        else {
          //@TODO replace with an actual verbose Logger Entry
          error_log(
            'passed Data Source ID for Strawberryfield_flavor_datasource had NULL elements in its path or a source file does no longer exists'
          );
        }
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

      return $indexes;
    }
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

}
