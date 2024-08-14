<?php

namespace Drupal\strawberryfield\Plugin\search_api\processor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\UserSession;
use Drupal\node\NodeInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Plugin\search_api\data_type\value\TextValue;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\LoggerTrait;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasourceInterface;
use Drupal\strawberryfield\Plugin\search_api\processor\Property\StrawberryFlavorAggregatedItemProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Aggregates Strawberry Flavors to its parent/top ADO.
 *
 * @SearchApiProcessor(
 *   id = "sbf_flavor_aggregated_item",
 *   label = @Translation("Strawberry Flavor Aggregator"),
 *   description = @Translation("Aggregates Strawberry Flavors (Child Documents) into a field that can be attached to an ADO"),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class StrawberryFlavorAggregate extends ProcessorPluginBase {

  use LoggerTrait;

  /**
   * The current_user service used by this plugin.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface|null
   */
  protected $accountSwitcher;

  /**
   * Theme settings config.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $plugin */
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $plugin->setAccountSwitcher($container->get('account_switcher'));
    $plugin->setLogger($container->get('logger.channel.search_api'));
    $plugin->setConfigFactory($container->get('config.factory'));

    return $plugin;
  }

  /**
   * Retrieves the account switcher service.
   *
   * @return \Drupal\Core\Session\AccountSwitcherInterface
   *   The account switcher service.
   */
  public function getAccountSwitcher() {
    return $this->accountSwitcher ?: \Drupal::service('account_switcher');
  }

  /**
   * Sets the account switcher service.
   *
   * @param \Drupal\Core\Session\AccountSwitcherInterface $current_user
   *   The account switcher service.
   *
   * @return $this
   */
  public function setAccountSwitcher(AccountSwitcherInterface $current_user) {
    $this->accountSwitcher = $current_user;
    return $this;
  }


  /**
   * Retrieves the config factory service.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The config factory.
   */
  protected function getConfigFactory() {
    return $this->configFactory ?: \Drupal::configFactory();
  }

  /**
   * Sets the config factory service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   *
   * @return $this
   */
  protected function setConfigFactory(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Aggregated Strawberry Flavor Documents'),
        'description' => $this->t('Children Strawberry Flavors aggregated under an ADO'),
        'type' => 'search_api_html',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ];
      $properties['sbf_aggregated_items'] = new StrawberryFlavorAggregatedItemProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $datasource_id = $item->getDatasourceId();
    $datasource = $item->getDatasource();
    // Don't add this field to a Flavor. That makes no sense at all.
    if ($datasource instanceof StrawberryfieldFlavorDatasourceInterface == FALSE ) {
      $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();
      $fields = $this->getFieldsHelper()
        ->filterForPropertyPath($item->getFields(), NULL, 'sbf_aggregated_items');
      foreach ($fields as $field) {
        $configuration = $field->getConfiguration();
        // Change the current user to our dummy implementation to ensure we are
        // using the configured roles.
        // This is really not needed given that SBF have a separate Permission
        // But we still want to aggregate (to avoid a reindex?) at the NODE level
        $this->getAccountSwitcher()
          ->switchTo(new UserSession(['roles' => $configuration['roles']]));
        try {
          // Fetch all Strawberry flavors connected to the current Item ID
          $node_original = $item->getOriginalObject();
          if ($node_original && $node = $node_original->getValue()) {
            if ($node instanceof NodeInterface) {
              $processor_ids = explode(
                ',', $configuration['processor_ids'] ?? ''
              );
              $processor_ids = array_filter(
                array_map('trim', $processor_ids)
              );
              foreach ($processor_ids as $processor_id) {
                $flavors = $this->flavorsfromSolrIndex(
                  $node->id(), $processor_id, $indexes,  50, 500
                );
                $flavors = array_filter($flavors);
                if (count($flavors)) {
                  $flavors = array_values($flavors);
                  // If we use $field->setValues data needs to be already
                  // in the destination data/value format/class (e.g Full Text)
                  foreach ($flavors as $flavor) {
                    if (!empty(trim($flavor ?? ''))) {
                      $field->addValue($flavor);
                    }
                  }
                }
              }
            }
          }
        }
        catch (\Exception $e) {
          $variables = [
            '%item_id' => $item->getId(),
            '%index' => $this->index->label(),
          ];
          $this->logException($e, '%type while trying to fetch Strawberry Flavors for item %item_id for search index %index: @message in %function (line %line of %file).', $variables);
        }
      }

      // Restore the original user.
      $this->getAccountSwitcher()->switchBack();
    }
  }

  /**
   * Gets All Flavors (e.g OCR) from search API
   *
   * @param int $nodeid
   * @param string $processor
   * @param string $file_uuid
   * @param array $indexes
   * @param int $limit
   *    The number of SBF Documents to get per query
   * @param int $max number of SBF to fetch at all.
   * @return array[]
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function flavorsfromSolrIndex(int $nodeid, string $processor, array $indexes, $limit = 50, $max = 500) {
    $values = [];
    /* @var \Drupal\search_api\IndexInterface[] $indexes */
    foreach ($indexes as $search_api_index) {
      // Create the query.
      $query = $search_api_index->query(
        [
          'limit' => $limit,
          'offset' => 0,
        ]
      );
      $parseModeManager = $query->getParseModeManager();
      if ($parseModeManager) {
        $parse_mode = $parseModeManager->createInstance('direct');
        $query->setParseMode($parse_mode);
      }

      $allfields_translated_to_solr = $search_api_index->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($query->getIndex());

      /* Forcing here two fixed options, we aggregate only from two levels down */
      $parent_conditions = $query->createConditionGroup('OR');
      if (isset($allfields_translated_to_solr['parent_id'])) {
        $parent_conditions->addCondition('parent_id', $nodeid);
      }
      // The property path for this is: target_id:field_descriptive_metadata:sbf_entity_reference_ispartof:nid
      // TODO: This needs a config form. For now let's document. Even if not present
      // It will not fail.
      if (isset($allfields_translated_to_solr['top_parent_id'])) {
        $parent_conditions->addCondition('top_parent_id', $nodeid);
      }

      if (count($parent_conditions->getConditions())) {
        $query->addConditionGroup($parent_conditions);
      }

      $query->addCondition(
        'search_api_datasource', 'strawberryfield_flavor_datasource'
      )
        ->addCondition('processor_id', $processor);

      $query->setProcessingLevel(QueryInterface::PROCESSING_NONE);
      $query->setOption('no_highlight', 'on');
      // Needed because NODE itself might not be published yet.
      // @TODO test more this. So far we have ended with 0 results
      // if not bypassing.
      $query->setOption('search_api_bypass_access', TRUE);

      $fields_with_sequence_id = $this->getFieldsHelper()
        ->filterForPropertyPath(
          $search_api_index->getFields(), 'strawberryfield_flavor_datasource',
          'sequence_id'
        );

      $sorted = FALSE;
      // Override Sort if we have a sequence ID for this data source
      foreach ($fields_with_sequence_id as $field_with_sequence_id) {
        // \Drupal\search_api\Plugin\search_api\data_type\IntegerDataType
        if ($field_with_sequence_id->getType() == 'integer'
        ) {
          $query->sort($field_with_sequence_id->getFieldIdentifier(), 'ASC');
          $sorted = TRUE;
          break;
        }
      }
      if (!$sorted) {
        // No difference of sorting by string than the id itself.
        $query->sort('search_api_id', 'ASC');
      }

      $fields_with_plaintext = $this->getFieldsHelper()
        ->filterForPropertyPath(
          $search_api_index->getFields(), 'strawberryfield_flavor_datasource',
          'plaintext'
        );
      // Needed to avoid statically caching the results
      // $query->getOriginalQuery() is not reliable and eventually
      // gets poluted (marked as processed)
      // Drupal why is your code so messy?
      $query->setProcessingLevel(QueryInterface::PROCESSING_NONE);

      try {
        $fields = ['search_api_relevance','search_api_datasource','search_api_language','search_api_id'];
        foreach ($fields_with_plaintext as $key => $field_data) {
          $fields[] = $key;
        }
        $fields = array_combine($fields, $fields);
        $query->setOption('search_api_retrieved_field_values', $fields);
        $results = $query->execute();
      }
      catch (\Exception $exception) {
        $this->logException(
          $exception,
          '%type while trying to fetch Strawberry Flavors from Search API'
        );
        return $values;
      }
      // remove the ID and the parent, not needed for file matching
      $required_properties_by_datasource = [
        'strawberryfield_flavor_datasource' => [
          'plaintext' => 'plaintext'
        ]
      ];

      $i = 0;
      $j = 0;
      $max_from_backend = $results->getResultCount();
      $max_from_backend = $newcount = $max_from_backend > $max ? $max : $max_from_backend;

      while ($j < $max_from_backend && $newcount > 0) {
        $i++;
        foreach ($results->getResultItems() as $resultItem) {
          $j++;
          $property_values = $this->getFieldsHelper()->extractItemValues(
            [$resultItem], $required_properties_by_datasource, FALSE
          );
          foreach ($property_values as $plaintext) {
            if (($plaintext['plaintext'][0] ?? NULL) instanceof TextValue) {
              // Wonder if we can use __toString() here as a magic prop
              // And avoid the difference.
              $text_to_clean = $plaintext['plaintext'][0]->getOriginalText();
            }
            else {
              $text_to_clean = $plaintext['plaintext'][0] ?? '';
            }
            $text_to_clean = html_entity_decode($text_to_clean);
            $text_to_clean = str_replace("-\n ", "", $text_to_clean);
            $text_to_clean = str_replace("\n ", " ", $text_to_clean);
            $text_to_clean = str_replace("\n", " ", $text_to_clean);
            $text_to_clean = preg_replace(
              ['/\h{2,}|(\h*\v{1,})/umi', '/\v{2,}/uim', '/\h{2,}/uim'],
              [' ', ' ', ' '], $text_to_clean
            );
            if (strlen(trim($text_to_clean)) > 0) {
              $values[] = $text_to_clean;
            }
          }
        }
        if ($j < $max_from_backend && $j > 0) {
          // Reusing the query can not be done bc it will return the original query results
          // statically cached
          // I could clone and clone but that would use extra memory
          // so i remove PROCESSING to avoid returning the same 50!
          $query = $query->getOriginalQuery();
          $query->range($limit * $i, $limit);
          $results = $query->execute();
          $newcount = $results->getResultCount();
        }
      }
    }
    return $values;
  }
}
