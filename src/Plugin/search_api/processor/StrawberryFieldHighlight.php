<?php

namespace Drupal\strawberryfield\Plugin\search_api\processor;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api\Plugin\search_api\processor\Highlight;
use Drupal\search_api\Query\Query;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\Utility\Utility;

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
 *     "postprocess_query" = 0,
 *     "preprocess_query" = -20,
 *   }
 * )
 */
class StrawberryFieldHighlight extends Highlight implements PluginFormInterface {

  /**
   * The data lazy loader for the excerpt.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldLazyBuilders|null
   */
  protected $lazyLoader;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'prefix' => '<strong>',
      'suffix' => '</strong>',
      'excerpt' => TRUE,
      'excerpt_clean' => TRUE,
      'excerpt_length' => 256,
      'highlight_link' => TRUE,
      'highlight_processing' => 'backend',
      'highlight_backend_use_keys' => TRUE,
      'highlight_partial' => FALSE,
      'exclude_fields' => [],
      'lazy_excerpt' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $parent_name = 'processors[sbf_highlight][settings]';
    if (!empty($form['#parents'])) {
      $parents = $form['#parents'];
      $parent_name = $root = array_shift($parents);
      if ($parents) {
        $parent_name = $root . '[' . implode('][', $parents) . ']';
      }
    }
    $form['highlight_processing'] = [
      '#type' => 'select',
      '#title' => $this->t('Highlight processing type'),
      '#description' => $this->t('Defines whether highlight and excerpt (if enabled) should be processed from backend highlighter or via Front end post processing. Backend is recommended.'),
      '#options' => [
        'backend' => $this->t('Backend'),
        'frontend' => $this->t('Front End'),
      ],
      '#default_value' => $this->configuration['highlight_processing'],
    ];

    $form['highlight_backend_use_keys'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use backend returned Highlighted keys'),
      '#description' => $this->t('Solr will return the keys highlighted. These might not match 1:1 the terms (stemming/ngram) used by the user. If enabled we will use these but remove any that are already present in the actual user input'),
      '#default_value' => $this->configuration['highlight_backend_use_keys'],
      '#states' => [
        'visible' => [
          ":input[name=\"{$parent_name}[highlight_processing]\"]" => [
            'value' => 'backend',
          ],
        ],
      ],
    ];

    $form['highlight_partial'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Highlight partial matches'),
      '#description' => $this->t('When enabled, matches in parts of words will be highlighted as well.'),
      '#default_value' => $this->configuration['highlight_partial'],
    ];
    $form['excerpt'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create excerpt'),
      '#description' => $this->t('When enabled, an excerpt will be created for searches with keywords, containing all occurrences of keywords in a fulltext field.'),
      '#default_value' => $this->configuration['excerpt'],
    ];
    $form['excerpt_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Excerpt length'),
      '#description' => $this->t('The requested length of the excerpt, in characters'),
      '#default_value' => $this->configuration['excerpt_length'],
      '#min' => 50,
      '#states' => [
        'visible' => [
          ":input[name=\"{$parent_name}[excerpt]\"]" => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];
    $form['excerpt_clean'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sort and dedupe returned fields for the excerpt'),
      '#description' => $this->t('When enabled and excerpt creation is selected, the returned fields (or backend highlight if highlight_processing is set to "backend" will be sorted by longest text first and deduplicated giving a more representative snippet but not taking the order of the results in account'),
      '#default_value' => $this->configuration['excerpt_clean'],
      '#states' => [
        'visible' => [
          ":input[name=\"{$parent_name}[excerpt]\"]" => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];
    $form['highlight_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add Content Entity Links on a separate Strawberry flavor backed excerpt'),
      '#description' => $this->t('When enabled,  Strawberry Flavor Data Source type and Strawberry Flavor Aggregator types will have their own excerpt. The first occurrences of a keywords in a fulltext field of these types will get a link with the keyword as a URL fragment to the Original Content Entity.'),
      '#default_value' => $this->configuration['highlight_link'],
      '#states' => [
        'visible' => [
          ":input[name=\"{$parent_name}[excerpt]\"]" => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];

    $form['lazy_excerpt'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use lazy loader to deliver excerpts on GET http calls'),
      '#description' => $this->t('When enabled, we will try to deliver on GET requests Excerpts via a Lazy Loading mechanism to bypass global Entity Cache. This is experimental and might deliver stale caches.'),
      '#default_value' => $this->configuration['lazy_excerpt'],
      '#states' => [
        'visible' => [
          ":input[name=\"{$parent_name}[excerpt]\"]" => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];

    // Exclude certain fulltext fields.
    $fields = $this->index->getFields();
    $fulltext_fields = [];
    foreach ($this->index->getFulltextFields() as $field_id) {
      $fulltext_fields[$field_id] = $fields[$field_id]->getLabel() . ' (' . $field_id . ')';
    }
    $form['exclude_fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Exclude fields from excerpt'),
      '#description' => $this->t('Exclude certain fulltext fields from being included in the excerpt.'),
      '#options' => $fulltext_fields,
      '#default_value' => $this->configuration['exclude_fields'],
      '#attributes' => ['class' => ['search-api-checkboxes-list']],
      '#states' => [
        'visible' => [
          ":input[name=\"{$parent_name}[excerpt]\"]" => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];
    $form['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Highlighting prefix'),
      '#description' => $this->t('Text/HTML that will be prepended to all occurrences of search keywords in highlighted text'),
      '#default_value' => $this->configuration['prefix'],
    ];
    $form['suffix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Highlighting suffix'),
      '#description' => $this->t('Text/HTML that will be appended to all occurrences of search keywords in highlighted text'),
      '#default_value' => $this->configuration['suffix'],
    ];


    return $form;
  }

  /**
   * Retrieves the data type helper.
   *
   * @return \Drupal\search_api\Utility\DataTypeHelperInterface
   *   The data type helper.
   */
  public function getLazyLoader() {
    return $this->lazyLoader ?: \Drupal::service('strawberryfield.lazy_builders');
  }

  /**
   * {@inheritdoc}
   */
  public function postprocessSearchResults(ResultSetInterface $results) {

    $query = $results->getQuery();
    $tags = $query->getTags();
    // Don't run this on autocompletes!
    if (isset($tags['search_api_autocomplete'])) {
      return;
    }
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

    // If a join query is present we will use its current config to trigger a quick
    // strawberry flavor solr query to highlight the current returned ADOs/NODE IDs
    // based on the existing $results->getQuery()->getOption('sbf_join_flavor') array
    if (($results->getQuery()->getOption('sbf_join_flavor') ||
        $results->getQuery()->getOption('sbf_advanced_search_filter_flavor_hl')) &&
      $this->configuration['highlight_processing'] == 'backend') {
      $fetched_ids = [];
      $from_solr_highlight_fields = [];
      foreach ($result_items as $item) {
        // The tracker methods above prepend the datasource id, so we need to
        // workaround it by removing it beforehand.
        [$datasource, $raw_id] = Utility::splitCombinedId($item->getId());
        $raw_id = explode(':', $raw_id);
        if ($raw_id) {
          $fetched_ids[] = reset($raw_id);
        }
      }
      if (count($fetched_ids)) {
        // Calls direct Solr Highlight from Source.
        $from_solr_highlight_fields = $this->highlightFlavorsFromIndex($query, $fetched_ids);
        foreach ($from_solr_highlight_fields as $item_id => $highlights) {
          // Check if there are highlights for our matches
          if (isset($result_items[$item_id]) && is_array($highlights)) {
            $item_highlight = [];
            $item_highlight_keys = [];
            $item_highlight = $result_items[$item_id]->getExtraData('highlighted_fields', []);
            $item_highlight_keys = $result_items[$item_id]->getExtraData('highlighted_keys', []);
            $item_highlight = array_merge($item_highlight, $highlights['highlighted_fields']);
            $item_highlight_keys = array_unique(array_merge($item_highlight_keys, $highlights['highlighted_keys']));
            $result_items[$item_id]->setExtraData('highlighted_fields', $item_highlight);
            $result_items[$item_id]->setExtraData('highlighted_keys', $item_highlight_keys);
          }
        }
      }
    }

    if ($this->configuration['excerpt']) {
      $this->addExcerpts($result_items, $excerpt_fulltext_fields, $keys);
    }
    if ($this->configuration['highlight_processing'] != 'backend') {
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

    foreach ($items['fulltext'] as $item_id => $item) {
      $text = [];
      $linkable_text = [];
      $item_keys_with_links = [];
      if (!$item) {
        continue;
      }
      if ($this->configuration['highlight_processing'] == 'backend') {
        $backed_highlight = $results[$item_id]->getExtraData('highlighted_fields',[]);
        foreach($backed_highlight as $key => $backed_highlight_values) {
          if ($this->configuration['highlight_link'] && in_array($key, $items['linkable_fields'])) {
            $linkable_text = array_merge($linkable_text, $backed_highlight_values ?? []);
          }
          else {
            $text = array_merge($text, $backed_highlight_values ?? []);
          }
        }
        if ($this->configuration['excerpt_clean']) {
          $linkable_text = $this->sortAndDedupe($linkable_text);
          $text = $this->sortAndDedupe($text);
        }
      }
      else {
        // We call array_merge() using call_user_func_array() to prevent having to
        // use it in a loop because it is a resource greedy construction.
        // @see https://github.com/kalessil/phpinspectionsea/blob/master/docs/performance.md#slow-array-function-used-in-loop
        $text = call_user_func_array('array_merge', array_values($item));
        if ($this->configuration['excerpt_clean']) {
          $text = $this->sortAndDedupe($text);
        }
      }

      $item_keys = $keys;

      // If the backend already did highlighting and told us the exact keys it
      // found in the item's text values, we can use those for our own
      // highlighting. This will help us take stemming, transliteration, etc.
      // into account properly.
      // These keys might not BE the EXACT term passed by the user so we will
      // check if we are allowed to do this or not
      // then we will dedup removing from the highlighted keys parts/terms
      // already present in a phrase.
      if ($this->configuration['highlight_processing'] == 'backend' && $this->configuration['highlight_backend_use_keys']) {
        $highlighted_keys = $results[$item_id]->getExtraData(
          'highlighted_keys'
        );
        if ($highlighted_keys && is_array($highlighted_keys)) {
          // first implode all existing keys, makes comparing easier.
          $joined_keys = strtolower(implode(" ", $keys));
          foreach($highlighted_keys as $index => $highlighted_key) {
            if (strpos($joined_keys, strtolower($highlighted_key)) !== FALSE) {
              unset($highlighted_keys[$index]);
            }
          }
          $item_keys = array_unique(array_merge($keys, $highlighted_keys));
        }
      }

      $excerpt_return = [];

      if (is_array($text) && count($text)) {
        $excerpt = $this->createExcerpt(
          implode($this->getEllipses()[1], $text), $item_keys
        );
        $excerpt_return[] = $this->highlightField($excerpt, $item_keys, FALSE);
      }

      if (($this->configuration['highlight_link']  ?? FALSE ) && is_array($linkable_text) && count($linkable_text)) {
        try {
          $uri = $results[$item_id]->getDatasource()->getItemUrl($results[$item_id]->getOriginalObject());
          foreach ($item_keys as $key) {
            if (count(explode(" ", $key)) > 1) {
              $uri->setOptions(['fragment' => 'search/"'.$key.'"']);
            }
            else {
              $uri->setOptions(['fragment' => 'search/'.$key]);
            }
            $rendered_url = \Drupal\Core\Link::fromTextAndUrl(
              $key, $uri
            );
            $item_keys_with_links[$key] = $rendered_url->toString()->getGeneratedLink();
          }
        }
        catch (\Exception $e) {
          $this->getLogger('strawberryfield')->warning('Error happened trying to load an entity to generate a link highlight', []);
        }

        $linked_excerpt = $this->createExcerptFromBackend(
          implode($this->getEllipses()[1], $linkable_text), $item_keys
        );
        $excerpt_return[] = $this->highlightFieldWithLinks(
            $linked_excerpt, $item_keys_with_links, FALSE
          ) ?? '';
      }

      if (\Drupal::request()->getMethod() == 'GET' && $this->configuration['lazy_excerpt']) {
        $cid = $results[$item_id]->getId();
        $this->getLazyLoader()->setExcerpt(
          $cid, implode('<br/>', $excerpt_return)
        );
      }
      $results[$item_id]->setExcerpt(implode('<br/>', $excerpt_return));
    }
  }

  /**
   * Deduplicates and sorts array by value length
   *
   * @param array $text
   *
   * @return array
   */
  protected function sortAndDedupe(array $text) {
    $text = array_unique($text);
    uasort(
      $text, function ($a, $b) {
      return strlen($b) - strlen($a);
    }
    );
    return $text;
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
      // This assumes we are indeed using phrase escaping (see \Drupal\search_api_solr\Utility\Utility::flattenKeys)
      // And not term escaping (which would be desired for single keys
      // but then i will not re-write code from \Drupal\search_api_solr!
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

    $keywords_and_phrases = [];
    if (preg_match_all('/"(?:\\\\.|[^\\\\"])*"|\S+/', $keys, $keywords_and_phrases)) {
      $keywords_in = $keywords_and_phrases[0] ?? NULL;
      if (!$keywords_in) {
        return [];
      }
      // Ensure there are no duplicates. (This is actually faster than
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
    else {
      return [];
    }
  }

  /**
   * Retrieves the fulltext fields of the given result items.
   *
   * @param \Drupal\search_api\Item\ItemInterface[] $result_items
   *   The results for which fulltext data should be extracted, keyed by item
   *   ID.
   * @param string[]|null $fulltext_fields
   *   (optional) The fulltext fields to highlight, or NULL to highlight all
   *   fulltext fields.
   * @param bool $load
   *   (optional) If FALSE, only field values already present will be returned.
   *   Otherwise, fields will be loaded if necessary.
   *
   * @return mixed[][][]
   *   Associative array with two base keys, fulltext and linkable_fields
   *   first containing Field values extracted from the result items' fulltext fields, keyed by
   *   item ID, field ID and then numeric indices. The later field names using
   *   'sbf_aggregated_items' path.
   */
  protected function getFulltextFields(array $result_items, array $fulltext_fields = NULL, $load = TRUE) {
    // All the index's fulltext fields, grouped by datasource.
    // @TODO also treat anything from strawberryFlavorDataSource as the aggregated?
    $fields_by_datasource = [];
    $fulltext_sbf_might_link = [];
    foreach ($this->index->getFields() as $field_id => $field) {
      if (isset($fulltext_fields) && !in_array($field_id, $fulltext_fields)) {
        continue;
      }
      if ($this->getDataTypeHelper()->isTextType($field->getType())) {
        if ($field->getPropertyPath() == 'sbf_aggregated_items' ||
          ($field->getPropertyPath() == 'plaintext' && $field->getDatasourceId(
            ) == 'strawberryfield_flavor_datasource')) {
          $fulltext_sbf_might_link[] = $field_id;
        }
        $fields_by_datasource[$field->getDatasourceId(
        )][$field->getPropertyPath()]
          = $field_id;
      }
    }

    return [
      'fulltext' => $this->getFieldsHelper()
        ->extractItemValues($result_items, $fields_by_datasource, $load),
      'linkable_fields' => $fulltext_sbf_might_link
    ];
  }

  /**
   * Returns snippets from a piece of text, but does not highlight.
   *
   * Modified from the parent class to not run highlight.
   *
   * @param string $text
   *   The text to extract fragments from.
   * @param array $keys
   *   The search keywords entered by the user.
   *
   * @return string|null
   *   A string containing text for the excerpt. Or NULL if no excerpt could be
   *   created.
   */
  protected function createExcerpt($text, array $keys) {
    // Remove HTML tags <script> and <style> with all of their contents.
    $text = preg_replace('#<(style|script).*?>.*?</\1>#is', ' ', $text);

    // Prepare text by stripping HTML tags and decoding HTML entities.
    $text = strip_tags(str_replace(['<', '>'], [' <', '> '], $text));
    $text = Html::decodeEntities($text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text, ' ');
    $text_length = mb_strlen($text);

    // Try to reach the requested excerpt length with about two fragments (each
    // with a keyword and some context).
    $ranges = [];
    $length = 0;
    $look_start = [];
    $remaining_keys = $keys;

    // Get the set excerpt length from the configuration. If the length is too
    // small, only use one fragment.
    $excerpt_length = $this->configuration['excerpt_length'];
    $context_length = round($excerpt_length / 4) - 3;
    if ($context_length < 32) {
      $context_length = round($excerpt_length / 2) - 1;
    }

    while ($length < $excerpt_length && !empty($remaining_keys)) {
      $found_keys = [];
      foreach ($remaining_keys as $key) {
        if ($length >= $excerpt_length) {
          break;
        }

        // Remember where we last found $key, in case we are coming through a
        // second time.
        if (!isset($look_start[$key])) {
          $look_start[$key] = 0;
        }

        // See if we can find $key after where we found it the last time. Since
        // we are requiring a match on a word boundary, make sure $text starts
        // and ends with a space.
        $matches = [];

        if (!$this->configuration['highlight_partial']) {
          $found_position = FALSE;
          $regex = '/' . static::$boundary . preg_quote($key, '/') . static::$boundary . '/iu';
          // $look_start contains the position as character offset, while
          // preg_match() takes a byte offset.
          $offset = $look_start[$key];
          if ($offset > 0) {
            $offset = strlen(mb_substr(' ' . $text, 0, $offset));
          }
          if (preg_match($regex, ' ' . $text . ' ', $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $found_position = $matches[0][1];
            // Convert the byte position into a multi-byte character position.
            $found_position = mb_strlen(substr(" $text", 0, $found_position));
          }
        }
        else {
          $found_position = mb_stripos($text, $key, $look_start[$key], 'UTF-8');
        }
        if ($found_position !== FALSE) {
          $look_start[$key] = $found_position + 1;
          // Keep track of which keys we found this time, in case we need to
          // pass through again to find more text.
          $found_keys[] = $key;

          // Locate a space before and after this match, leaving some context on
          // each end.
          if ($found_position > $context_length) {
            $before = mb_strpos($text, ' ', $found_position - $context_length);
            if ($before !== FALSE) {
              ++$before;
            }
            // If we can’t find a space anywhere within the context length, just
            // settle for a non-space.
            if ($before === FALSE || $before > $found_position) {
              $before = $found_position - $context_length;
            }
          }
          else {
            $before = 0;
          }
          if ($before !== FALSE && $before <= $found_position) {
            if ($text_length > $found_position + $context_length) {
              $after = mb_strrpos(mb_substr($text, 0, $found_position + $context_length), ' ', $found_position);
            }
            else {
              $after = $text_length;
            }
            if ($after !== FALSE && $after > $found_position) {
              if ($before < $after) {
                // Save this range.
                $ranges[$before] = $after;
                $length += $after - $before;
              }
            }
          }
        }
      }
      // Next time through this loop, only look for keys we found this time,
      // if any.
      $remaining_keys = $found_keys;
    }

    if (!$ranges) {
      // We didn't find any keyword matches, return NULL.
      return NULL;
    }

    // Sort the text ranges by starting position.
    ksort($ranges);

    // Collapse overlapping text ranges into one. The sorting makes it O(n).
    $new_ranges = [];
    $working_from = $working_to = NULL;
    foreach ($ranges as $this_from => $this_to) {
      if ($working_from === NULL) {
        // This is the first time through this loop: initialize.
        $working_from = $this_from;
        $working_to = $this_to;
        continue;
      }
      if ($this_from <= $working_to) {
        // The ranges overlap: combine them.
        $working_to = max($working_to, $this_to);
      }
      else {
        // The ranges do not overlap: save the working range and start a new
        // one.
        $new_ranges[$working_from] = $working_to;
        $working_from = $this_from;
        $working_to = $this_to;
      }
    }
    // Save the remaining working range.
    $new_ranges[$working_from] = $working_to;

    // Fetch text within the combined ranges we found.
    $out = [];
    foreach ($new_ranges as $from => $to) {
      $out[] = Html::escape(mb_substr($text, $from, $to - $from));
    }
    if (!$out) {
      return NULL;
    }

    $ellipses = $this->getEllipses();
    $excerpt = $ellipses[0] . implode($ellipses[1], $out) . $ellipses[2];

    // Since we stripped the tags at the beginning, highlighting doesn't need to
    // handle HTML anymore.
    return $excerpt;
  }

  /**
   * Returns snippets from a piece of text, but does not highlight.
   *
   * Modified from the parent class to not run highlight.
   *
   * @param string $text
   *   The text to extract fragments from.
   * @param array $keys
   *   The search keywords entered by the user.
   *
   * @return string|null
   *   A string containing text for the excerpt. Or NULL if no excerpt could be
   *   created.
   */
  protected function createExcerptFromBackend($text, array $keys) {
    // Remove HTML tags <script> and <style> with all of their contents.
    $text = preg_replace('#<(style|script).*?>.*?</\1>#is', ' ', $text);

    // Prepare text by stripping HTML tags and decoding HTML entities.
    $text = strip_tags(str_replace(['<', '>'], [' <', '> '], $text),['HIGHLIGHT']);
    $text = Html::decodeEntities($text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text, ' ');
    $text_length = mb_strlen($text);

    // Try to reach the requested excerpt length with about two fragments (each
    // with a keyword and some context).
    $ranges = [];
    $length = 0;
    $look_start = [];
    $remaining_keys = $keys;

    // Get the set excerpt length from the configuration. If the length is too
    // small, only use one fragment.
    $excerpt_length = $this->configuration['excerpt_length'];
    $context_length = round($excerpt_length / 4) - 3;
    if ($context_length < 32) {
      $context_length = round($excerpt_length / 2) - 1;
    }

    while ($length < $excerpt_length && !empty($remaining_keys)) {
      $found_keys = [];
      foreach ($remaining_keys as $key) {
        if ($length >= $excerpt_length) {
          break;
        }

        // Remember where we last found $key, in case we are coming through a
        // second time.
        if (!isset($look_start[$key])) {
          $look_start[$key] = 0;
        }

        // See if we can find $key after where we found it the last time. Since
        // we are requiring a match on a word boundary, make sure $text starts
        // and ends with a space.
        $matches = [];

        if (!$this->configuration['highlight_partial']) {
          $found_position = FALSE;
          $regex = '/' . static::$boundary . preg_quote($key, '/') . static::$boundary . '/iu';
          // $look_start contains the position as character offset, while
          // preg_match() takes a byte offset.
          $offset = $look_start[$key];
          if ($offset > 0) {
            $offset = strlen(mb_substr(' ' . $text, 0, $offset));
          }
          if (preg_match($regex, ' ' . $text . ' ', $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $found_position = $matches[0][1];
            // Convert the byte position into a multi-byte character position.
            $found_position = mb_strlen(substr(" $text", 0, $found_position));
          }
        }
        else {
          $found_position = mb_stripos($text, $key, $look_start[$key], 'UTF-8');
        }
        if ($found_position !== FALSE) {
          $look_start[$key] = $found_position + 1;
          // Keep track of which keys we found this time, in case we need to
          // pass through again to find more text.
          $found_keys[] = $key;

          // Locate a space before and after this match, leaving some context on
          // each end.
          if ($found_position > $context_length) {
            $before = mb_strpos($text, ' ', $found_position - $context_length);
            if ($before !== FALSE) {
              ++$before;
            }
            // If we can’t find a space anywhere within the context length, just
            // settle for a non-space.
            if ($before === FALSE || $before > $found_position) {
              $before = $found_position - $context_length;
            }
          }
          else {
            $before = 0;
          }
          if ($before !== FALSE && $before <= $found_position) {
            if ($text_length > $found_position + $context_length) {
              $after = mb_strrpos(mb_substr($text, 0, $found_position + $context_length), ' ', $found_position);
            }
            else {
              $after = $text_length;
            }
            if ($after !== FALSE && $after > $found_position) {
              if ($before < $after) {
                // Save this range.
                $ranges[$before] = $after;
                $length += $after - $before;
              }
            }
          }
        }
      }
      // Next time through this loop, only look for keys we found this time,
      // if any.
      $remaining_keys = $found_keys;
    }

    if (!$ranges) {
      // We didn't find any keyword matches, return NULL.
      return NULL;
    }

    // Sort the text ranges by starting position.
    ksort($ranges);

    // Collapse overlapping text ranges into one. The sorting makes it O(n).
    $new_ranges = [];
    $working_from = $working_to = NULL;
    foreach ($ranges as $this_from => $this_to) {
      if ($working_from === NULL) {
        // This is the first time through this loop: initialize.
        $working_from = $this_from;
        $working_to = $this_to;
        continue;
      }
      if ($this_from <= $working_to) {
        // The ranges overlap: combine them.
        $working_to = max($working_to, $this_to);
      }
      else {
        // The ranges do not overlap: save the working range and start a new
        // one.
        $new_ranges[$working_from] = $working_to;
        $working_from = $this_from;
        $working_to = $this_to;
      }
    }
    // Save the remaining working range.
    $new_ranges[$working_from] = $working_to;

    // Fetch text within the combined ranges we found.
    $out = [];
    foreach ($new_ranges as $from => $to) {
      $out[] = Html::escape(mb_substr($text, $from, $to - $from));
    }
    if (!$out) {
      return NULL;
    }
    //^.+?(?=<HIGLIGHT>)+|(?<=<\/HIGHLIGHT>).*

    $ellipses = $this->getEllipses();
    $excerpt = $ellipses[0] . implode($ellipses[1], $out) . $ellipses[2];

    // Since we stripped the tags at the beginning, highlighting doesn't need to
    // handle HTML anymore.
    return $excerpt;
  }


  /**
   * Marks occurrences of the search keywords in a text field.
   *
   * @param string $text
   *   The text of the field.
   * @param array $keys
   *   Associative array of The search keywords entered by the user and the
   *   Entity links.
   * @param bool $html
   *   (optional) Whether the text can contain HTML tags or not. In the former
   *   case, text inside tags (that is, tag names and attributes) won't be
   *   highlighted.
   *
   * @return string
   *   The given text with all occurrences of search keywords highlighted.
   */
  protected function highlightFieldWithLinks($text, array $keys, $html = TRUE) {
    if ($html) {
      $texts = preg_split('#((?:</?[[:alpha:]](?:[^>"\']*|"[^"]*"|\'[^\']\')*>)+)#i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
      if ($texts === FALSE) {
        $args = [
          '%error_num' => preg_last_error(),
        ];
        $this->getLogger('strawberryfield')->warning('A PCRE error (#%error_num) occurred during Advanced results highlighting with Links.', $args);
        return $text;
      }
      $textsCount = count($texts);
      for ($i = 0; $i < $textsCount; $i += 2) {
        $texts[$i] = $this->highlightFieldWithLinks($texts[$i], $keys, FALSE);
      }
      return implode('', $texts);
    }
    $key_strings = array_keys($keys);
    $key_strings = implode('|', array_map('preg_quote', $key_strings, array_fill(0, count($key_strings), '/')));
    // If "Highlight partial matches" is disabled, we only want to highlight
    // matches that are complete words. Otherwise, we want all of them.
    $boundary = !$this->configuration['highlight_partial'] ? static::$boundary : '';
    $regex = '/' . $boundary . '(?:' . $key_strings . ')' . $boundary . '/iu';
    $replace = $this->configuration['prefix'] . '\0' . $this->configuration['suffix'];
    $text = preg_replace($regex, $replace, ' ' . $text . ' ');
    // we want a single link per word...
    // so
    $patterns = [];
    $replacements = [];

    foreach ($keys as $k => $v) {
      $patterns[] = '/' . preg_quote($this->configuration['prefix'],'/') . preg_quote($k,'/') . preg_quote($this->configuration['suffix'],'/'). '/i';  // use i to ignore case
      $replacements[] = $this->configuration['prefix'].$v.$this->configuration['suffix'];
    }
    $text_with_links = preg_replace($patterns, $replacements, ' ' . $text . ' ', 1);
    // Ensures that we can always trim and we add double quotes.
    $text = $text_with_links !== '  ' ?  '&ldquo;'.$text_with_links.'&rdquo;' : $text;
    return trim($text);
  }

  /**
   * Retrieves highlighted field values for the given result items.
   *
   * @param \Drupal\search_api\Item\ItemInterface[] $results
   *   The result items whose fields should be highlighted.
   * @param array $keys
   *   The search keys to use for highlighting.
   *
   * @return string[][][]
   *   An array keyed by item IDs, containing arrays that map field IDs to the
   *   highlighted versions of the values for that field.
   */
  protected function highlightFields(array $results, array $keys) {
    $highlighted_fields = [];
    foreach ($results as $item_id => $item) {
      // Maybe the backend or some other processor has already set highlighted
      // field values.
      $highlighted_fields[$item_id] = $item->getExtraData('highlighted_fields', []);
    }
    // This is our override of ::getFulltextFields that returns a upper level set
    // of keys. Be careful dear coder/extender.
    $item_fields = $this->getFulltextFields($results, NULL, FALSE);
    foreach ($item_fields['fulltext'] as $item_id => $fields) {
      foreach ($fields as $field_id => $values) {
        if (empty($highlighted_fields[$item_id][$field_id])) {
          $change = FALSE;
          foreach ($values as $i => $value) {
            if (is_string($value)) {
              $values[$i] = $this->highlightField($value, $keys);
            }
            elseif (is_array($value)) {
              foreach ($value as $j => $value_item) {
                if (is_string($value_item)) {
                  $values[$i][$j] = $this->highlightField($value_item, $keys);
                }
              }
            }
            if ($values[$i] !== $value) {
              $change = TRUE;
            }
          }
          if ($change) {
            $highlighted_fields[$item_id][$field_id] = $values;
          }
        }
      }
    }
    return $highlighted_fields;
  }

  /**
   * Fetches highlighted matches for SB Flavours from backend.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   * @param  array $entities
   *   A list of NODE IDs to limit the query against.
   *
   * @return array
   * @throws \Drupal\search_api\SearchApiException
   */
  public function highlightFlavorsFromIndex(QueryInterface $query, array $entities) {

    $parse_mode_manager = $query->getParseModeManager();
    $highlighted_fields = [];
    $results = [];
    //@TODO make which Flavors (OCR, web, etc) are fetched either a config or part of
    // $query->getOption('sbf_join_flavor') structure.
    if ($parse_mode_manager && count($entities)) {
      /** @var \Drupal\search_api\ParseMode\ParseModeInterface $parse_mode_direct */
      $parse_mode_direct = $parse_mode_manager->createInstance('direct');
      $flavor_query = new Query(
        $this->index, [
          'limit' => 200, //count($entities)?,
          'offset' => 0,
        ]
      );

      $flavor_query->setParseMode($parse_mode_direct);
      // Important NOTE: e.g if you are using a global AND, means for a search
      // ALL WORDS need to match, this in NO CASE means all the words need to match
      // THE OCR flavor or any flavor. The combined result (JOIN time + main query) need to match
      // SO here we can not use the 'v' from $query->getOption('sbf_join_flavor')['v']; that was used
      // to generate the JOIN because only on rare cases (e.g ONLY OCR matched all) will give us
      // any highlights. This is a complexity of our process
      // and OK if we handle this differently.
      // Just in case someone tried to copy the code without understanding, let's be safe
      $combined_keys = $query->getOption('sbf_join_flavor')['hl'] ??
        ($query->getOption('sbf_join_flavor')['v'] ?? NULL);
      // No join. Try with the Advanced Search Flavor Filter
      // Sweet and made for a hit summer of advanced Searching!
      // @See \Drupal\format_strawberryfield_views\Plugin\views\filter\AdvancedSearchApiFulltext::query
      if (!$combined_keys) {
        $combined_keys = $query->getOption('sbf_advanced_search_filter_flavor_hl') ?? NULL;
      }
      if ($combined_keys && is_string($combined_keys) && strlen(trim($combined_keys)) > 0
      ) {
        $group_options = [
          'use_grouping' => TRUE,
          'fields'       => ['parent_id'],
          'truncate'     => TRUE,
          'group_limit'  => 3,
          'group_sort'   => [],
        ];
        $flavor_query->setOption('search_api_grouping', $group_options);
        $flavor_query->keys("{$combined_keys}");
        $flavor_query->addCondition('parent_id', $entities, 'IN');

        // This is just to avoid Search API rewriting the query
        $flavor_query->setOption('solr_param_defType', 'edismax');
        // This will allow us to remove the edismax processor on a hook/event subscriber.
        $flavor_query->setOption('sbf_advanced_highlight_flavor', TRUE);
        $flavor_query->addCondition(
          'search_api_datasource', 'strawberryfield_flavor_datasource'
        );
        // Flavors inherit permissions of a NODE. If not accesible this will never be called
        $flavor_query->setOption('search_api_bypass_access', TRUE);
        $flavor_query->setFulltextFields([]);
        $flavor_query->setProcessingLevel(QueryInterface::PROCESSING_BASIC);
        $flavor_query->setOption(
          'search_api_retrieved_field_values',
          ['parent_id' => 'parent_id', 'processor_id' => 'processor_id']
        );
        $results = $flavor_query->execute();
        foreach ($results as $item_id => $item) {
          [$unused, $item_id] = Utility::splitCombinedId($item->getId());
          $item_id = explode(':', $item_id);
          if ($item_id && count($item_id) == 5) {
            // For now this is fixed SBF can not be generated by other Entity Types
            $node_id = 'entity:node/' . $item_id[0] . ':' . $item_id[2];
            // ocr is 4, File id is 1,
            // Gosh we need to merge this... if not we end with the last one only.
            // @TODO. we could check here if the keys for a certain highlight
            // were already highlighted but then we are already generating a snippet
            // that is limited in the caller. So not sure it is worth
            $highlighted_fields[$node_id]['highlighted_fields']
              = array_merge_recursive( $highlighted_fields[$node_id]['highlighted_fields'] ?? [], $item->getExtraData('highlighted_fields', []));
            $highlighted_fields[$node_id]['highlighted_keys']
              = array_unique(array_merge($highlighted_fields[$node_id]['highlighted_keys'] ?? [], $item->getExtraData('highlighted_keys', [])));
          }
        }
      }
    }
    return $highlighted_fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessSearchQuery(QueryInterface $query) {
    // OK we know this only works for Solr. C'mon
    $tags = $query->getTags();
    // Don't run this on autocompletes!
    if (isset($tags['search_api_autocomplete'])) {
      return;
    }
    $backend = $query->getIndex()->getServerInstance()->getBackend();
    $index_fields = $query->getIndex()->getFields();
    $backend_highlights = [];
    if ($backend instanceof \Drupal\search_api_solr\SolrBackendInterface && $this->configuration['highlight_processing'] == 'backend') {
        $excerpt_fulltext_fields = $this->index->getFulltextFields();
        if (!empty($this->configuration['exclude_fields'])) {
          $excerpt_fulltext_fields = array_diff(
            $excerpt_fulltext_fields, $this->configuration['exclude_fields']
          );
        }

      $field_names = $backend->getSolrFieldNamesKeyedByLanguage(
        $this->getLanguages($query), $query->getIndex()
      );

      // If Search API provides information about the fields to retrieve, limit
      // the fields accordingly. ...
      foreach ($excerpt_fulltext_fields as $field_name) {
        if (isset($field_names[$field_name])) {
          $backend_highlights[] = array_values($field_names[$field_name]);
        }
      }
      $backend_highlights = array_unique(array_merge(...$backend_highlights));
      $backend_highlights = !empty($backend_highlights) ? $backend_highlights : ['*'];
      $backend_highlights = array_filter($backend_highlights, function($v) {
        return preg_match('/^t.?[sm]_/', $v) || preg_match('/^s[sm]_/', $v);
      });
      $query->setOption(
        'sbf_highlight_fields', $backend_highlights
      );
    }
  }

  public function getLanguages(QueryInterface $query) {
    $language_ids = [];
    $settings = \Drupal\search_api_solr\Utility\Utility::getIndexSolrSettings($query->getIndex());
    $language_ids = $query->getLanguages();
    // If there are no languages set, we need to set them. As an example, a
    // language might be set by a filter in a search view.
    if (empty($language_ids)) {
      if (!$query->hasTag('views') && $settings['multilingual']['limit_to_content_language']) {
        // Limit the language to the current language being used.
        $language_ids[] = \Drupal::languageManager()
          ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
          ->getId();
      }
      else {
        // If the query is generated by views and/or the query isn't limited
        // by any languages we have to search for all languages using their
        // specific fields.
        $language_ids = array_keys(\Drupal::languageManager()->getLanguages());
      }
    }

    if ($settings['multilingual']['include_language_independent']) {
      $language_ids[] = LanguageInterface::LANGCODE_NOT_SPECIFIED;
    }
    return $language_ids;

  }

}
