<?php

namespace Drupal\strawberryfield\Plugin\search_api\processor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\search_api\LoggerTrait;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Aggregates Strawberry Flavors to its parent/top ADO.
 *
 * @SearchApiProcessor(
 *   id = "sbf_reduce_return",
 *   label = @Translation("Strawberry Reduce Returned Fields Processor"),
 *   description = @Translation("Reduces the amount of returned fields for Queries driven by Views by still maintaining highlights. Will also avoid completely returning any Strawberry Flavor Aggregator type of field."),
 *   stages = {
 *     "preprocess_query" = -30,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class StrawberryReduceReturn extends ProcessorPluginBase {

  use LoggerTrait;

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

    $plugin->setLogger($container->get('logger.channel.search_api'));
    $plugin->setConfigFactory($container->get('config.factory'));

    return $plugin;
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
  public function preprocessSearchQuery(QueryInterface $query) {
    // We really don't want to return the aggregated fields this processor
    // Provides
    // Unnecessary HUGE payload.
    if (isset($query->getOptions()['search_api_view']) && $query->getOptions()['search_api_view']->getDisplay()->usesFields()) {
      //don't override any other options set by someone else.

      if (empty($query->getOptions()['search_api_retrieved_field_values'] ?? [])) {
        $fields = [];
        // Get me all the fields of this index (gosh)
        $fields = $query->getIndex()->getFields();
        $fields_aggregated = $this->getFieldsHelper()
          ->filterForPropertyPath($fields, NULL, 'sbf_aggregated_items');
        foreach($fields_aggregated as $key => $value) {
          unset($fields[$key]);
        }
        $fields = array_values(array_keys($fields));
        $fields += ['search_api_relevance','search_api_datasource','search_api_language','search_api_id'];
        $query->setOption('search_api_retrieved_field_values', $fields);
        $query->setOption('highlight_reduce_return', ['*']);
      }
    }
    elseif (isset($query->getOptions()['search_api_view']) && !$query->getOptions()['search_api_view']->getDisplay()->usesFields()) {
      $query->setOption('search_api_retrieved_field_values', ['search_api_relevance','search_api_datasource','search_api_language','search_api_id']);
      $query->setOption('highlight_reduce_return', ['*']);
    }
  }
}
