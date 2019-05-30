<?php

namespace Drupal\strawberryfield\Plugin\search_api\datasource;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\search_api\Datasource\DatasourcePluginBase;

use Drupal\strawberryfield\Plugin\DataType\StrawberryfieldFlavorData;
use Drupal\strawberryfield\TypedData\StrawberryfieldFlavorDataDefinition;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\Language;


/**
 * Represents a datasource which exposes flavors.
 *
 * @SearchApiDatasource(
 *   id = "strawberryfield_flavor_datasource",
 *   label = @Translation("Strawberryfield Flavor Datasource"),
 *   entity_type = "node",
 * )
 */
class StrawberryfieldFlavorDatasource extends DatasourcePluginBase {

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
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    return \Drupal::typedDataManager()->createDataDefinition('strawberryfield_flavor_data')->getPropertyDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function getItemId(ComplexDataInterface $item) {
    $values = $item->get('page_id')->getValue();
    return $values ?: NULL;
  }
//  public function getItemIds($page = NULL) {
//    $ids = ["1","2","3","4"];
//    return $ids;
//  }
  /**
   * {@inheritdoc}
   */
  public function getItemIds($page = NULL) {

//§/    dpm("In getItemIds");

    return $this->getPartialItemIds($page);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    $plugin_definition = $this->getPluginDefinition();

//    return $plugin_definition['entity_type'];
    return "node";
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $default_configuration = [];

//§/    if ($this->hasBundles()) {
//§/      $default_configuration['bundles'] = [
//§/        'default' => TRUE,
//§/        'selected' => [],
//§/      ];
//§/    }

    if ($this->isTranslatable()) {
      $default_configuration['languages'] = [
        'default' => TRUE,
        'selected' => [Language::LANGCODE_NOT_SPECIFIED],
      ];
    }

    return $default_configuration;
  }


  /**
   * Retrieves the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  public function getEntityTypeManager() {
    return $this->entityTypeManager ?: \Drupal::entityTypeManager();
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
     * Retrieves the language manager.
     *
     * @return \Drupal\Core\Language\LanguageManagerInterface
     *   The language manager.
     */
    public function getLanguageManager() {
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
   * Retrieves the enabled languages.
   *
   * @return \Drupal\Core\Language\LanguageInterface[]
   *   All languages that are enabled for this datasource, keyed by language
   *   code.
   */
  protected function getLanguages() {
    $all_languages = $this->getLanguageManager()->getLanguages();

    if ($this->isTranslatable()) {
      $selected_languages = array_flip($this->configuration['languages']['selected']);
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
     * Retrieves the config factory.
     *
     * @return \Drupal\Core\Config\ConfigFactoryInterface
     *   The config factory.
     */
    public function getConfigFactory() {
      return $this->configFactory ?: \Drupal::configFactory();
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
   * {@inheritdoc}
   */
  public function getPartialItemIds($page = NULL, array $bundles = NULL, array $languages = NULL) {
    // These would be pretty pointless calls, but for the sake of completeness
    // we should check for them and return early. (Otherwise makes the rest of
    // the code more complicated.)
    //§/ if (($bundles === [] && !$languages) || ($languages === [] && !$bundles)) {
    //§/  return NULL;
    //§/ }

//§/ dpm("In getPartialItemIds before select");

    $select = $this->getEntityTypeManager()
      ->getStorage($this->getEntityTypeId())
      ->getQuery();

//§/ dpm("In getPartialItemIds after select");

    // When tracking items, we never want access checks.
    $select->accessCheck(FALSE);

    // We want to determine all entities of either one of the given bundles OR
    // one of the given languages. That means we can't just filter for $bundles
    // if $languages is given. Instead, we have to filter for all bundles we
    // might want to include and later sort out those for which we want only the
    // translations in $languages and those (matching $bundles) where we want
    // all (enabled) translations.
    //§/
    //§/ ToDO manage bundles
    //§/ at the moment bybass
    //§/ if ($this->hasBundles()) {
    if ( 1 == 0) {
      $bundle_property = $this->getEntityType()->getKey('bundle');
      if ($bundles && !$languages) {
        $select->condition($bundle_property, $bundles, 'IN');
      }
      else {
        $enabled_bundles = array_keys($this->getBundles());
        // Since this is also called for removed bundles/languages,
        // $enabled_bundles might not include $bundles.
        if ($bundles) {
          $enabled_bundles = array_unique(array_merge($bundles, $enabled_bundles));
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

    dpm("In getPartialItemIds");
    dpm($entity_ids);

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
      $enabled_languages = array_unique(array_merge($languages, $enabled_languages));
    }
    // Also, we want to always include entities with unknown language.
    $enabled_languages[] = LanguageInterface::LANGCODE_NOT_SPECIFIED;
    $enabled_languages[] = LanguageInterface::LANGCODE_NOT_APPLICABLE;

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    foreach ($this->getEntityStorage()->loadMultiple($entity_ids) as $entity_id => $entity) {
      $translations = array_keys($entity->getTranslationLanguages());
      $translations = array_intersect($translations, $enabled_languages);
      // If only languages were specified, keep only those translations matching
      // them. If bundles were also specified, keep all (enabled) translations
      // for those entities that match those bundles.
      if ($languages !== NULL
          && (!$bundles || !in_array($entity->bundle(), $bundles))) {
        $translations = array_intersect($translations, $languages);
      }
      foreach ($translations as $langcode) {
        $page_id = 1;
        $item_ids[] = "$entity_id:$page_id:$langcode";
        $page_id = 2;
        $item_ids[] = "$entity_id:$page_id:$langcode";
        $page_id = 3;
        $item_ids[] = "$entity_id:$page_id:$langcode";
        $page_id = 4;
        $item_ids[] = "$entity_id:$page_id:$langcode";
      }
    }

    if (Utility::isRunningInCli()) {
      // When running in the CLI, this might be executed for all entities from
      // within a single process. To avoid running out of memory, reset the
      // static cache after each batch.
      $this->getEntityStorage()->resetCache($entity_ids);
    }

dpm("In getPartialItemIds");
dpm($item_ids);

    return $item_ids;
  }


  /**
   * {@inheritdoc}
   */

  public function loadMultiple(array $ids) {
    $documents = [];
    $sbfflavordata_definition = StrawberryfieldFlavorDataDefinition::create('strawberryfield_flavor_data');

dpm("In loadmultiple 2");
dpm($ids);

    foreach($ids as $id){
      //§/ $id = $entity_id : $page_id : $langcode
      $splitted_id = explode(':',$id);
      $data = [
        'page_id' => $splitted_id[1],
        'parent_id' => $splitted_id[0],
        'fulltext' => 'Start ' . $splitted_id[1] . ' End',
     ];
     $documents[$id] = \Drupal::typedDataManager()->create($sbfflavordata_definition);
     $documents[$id]->setValue($data);

    }

dpm("Return doc in loadmultiple");

    return $documents;
  }
}
