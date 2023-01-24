<?php

namespace Drupal\strawberryfield\Plugin\search_api\processor;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\LoggerTrait;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Plugin\search_api\processor\Highlight;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\DataTypeHelperInterface;
use Drupal\user\Entity\User;

/**
 * Adds a highlighted excerpt to results and highlights returned fields.
 *
 * This processor won't run for queries with the "basic" processing level set.
 *
 * @SearchApiProcessor(
 *   id = "sbf_highlight",
 *   label = @Translation("Advanced Backend Highlight"),
 *   description = @Translation("Adds a highlighted excerpt to results and highlights returned fields using only Backend instead of frontend re-rendering."),
 *   stages = {
 *     "pre_index_save" = 0,
 *     "postprocess_query" = 0
 *   }
 * )
 */
class StrawberryFieldHighlight extends Highlight implements PluginFormInterface {

  /**
   * {@inheritdoc}
   */
  public function postprocessSearchResults(ResultSetInterface $results) {
    $query = $results->getQuery();
    if (!$results->getResultCount()
      || $query->getProcessingLevel() != QueryInterface::PROCESSING_FULL
      || !($keys = $this->getKeywordsParseModeAware($query, $query->getParseMode()->getPluginId()))) {
      return;
    }

    $excerpt_fulltext_fields = $this->index->getFulltextFields();
    if (!empty($this->configuration['exclude_fields'])) {
      $excerpt_fulltext_fields = array_diff($excerpt_fulltext_fields, $this->configuration['exclude_fields']);
    }

    $result_items = $results->getResultItems();
    if ($this->configuration['excerpt']) {
      $this->addExcerpts($result_items, $excerpt_fulltext_fields, $keys);
    }
    if ($this->configuration['highlight'] != 'never') {
      $highlighted_fields = $this->highlightFields($result_items, $keys);
      foreach ($highlighted_fields as $item_id => $item_fields) {
        $item = $result_items[$item_id];
        $item->setExtraData('highlighted_fields', $item_fields);
      }
    }
  }

  /**
   * Adds excerpts to all results, if possible.
   *
   * @param \Drupal\search_api\Item\ItemInterface[] $results
   *   The result items to which excerpts should be added.
   * @param string[] $fulltext_fields
   *   The fulltext fields from which the excerpt should be created.
   * @param array $keys
   *   The search keys to use for highlighting.
   */
  protected function addExcerpts(array $results, array $fulltext_fields, array $keys) {
    $items = $this->getFulltextFields($results, $fulltext_fields, FALSE);
    foreach ($items as $item_id => $item) {
      if (!$item) {
        continue;
      }
      // We call array_merge() using call_user_func_array() to prevent having to
      // use it in a loop because it is a resource greedy construction.
      // @see https://github.com/kalessil/phpinspectionsea/blob/master/docs/performance.md#slow-array-function-used-in-loop
      $text = call_user_func_array('array_merge', array_values($item));
      $item_keys = $keys;

      // If the backend already did highlighting and told us the exact keys it
      // found in the item's text values, we can use those for our own
      // highlighting. This will help us take stemming, transliteration, etc.
      // into account properly.
      $highlighted_keys = $results[$item_id]->getExtraData('highlighted_keys');
      if ($highlighted_keys) {
        $item_keys = array_unique(array_merge($keys, $highlighted_keys));
      }

      // @todo This is pretty poor handling for the borders between different
      //   values/fields. Better would be to pass an array and have proper
      //   handling of this in createExcerpt(), ensuring that no snippet goes
      //   across multiple values/fields.
      $results[$item_id]->setExcerpt($this->createExcerpt(implode($this->getEllipses()[1], $text), $item_keys));
    }
  }

  /**
   * Extracts the positive keywords used in a search query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The query from which to extract the keywords.
   * @param string $parse_mode_id
   *   The parse mode plugin id used by the query
   *
   * @return string[]
   *   An array of all unique positive keywords used in the query.
   */
  protected function getKeywordsParseModeAware(QueryInterface $query, string $parse_mode_id) {
    if ($parse_mode_id == 'direct') {
      $direct_keys = $query->getOriginalKeys();
      $match = [];
      $keys = [];
      preg_match_all('/"([^"]+)"/', $direct_keys,$match);
      if (isset($match[1]) && is_array($match[1])) {
        $keys = array_unique($match[1]);
      }
    }
    else {
      $keys = $query->getOriginalKeys();
    }
    if (!$keys) {
      return [];
    }
    if (is_array($keys)) {
      return $this->flattenKeysArray($keys);
    }

    $keywords_in = preg_split(static::$split, $keys);
    if (!$keywords_in) {
      return [];
    }
    // Assure there are no duplicates. (This is actually faster than
    // array_unique() by a factor of 3 to 4.)
    // Remove quotes from keywords.
    $keywords = [];
    foreach (array_filter($keywords_in) as $keyword) {
      if ($keyword = trim($keyword, "'\"")) {
        $keywords[$keyword] = $keyword;
      }
    }
    return $keywords;
  }
}
