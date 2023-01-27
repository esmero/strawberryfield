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

    foreach ($items['fulltext'] as $item_id => $item) {
      $text = [];
      $linkable_text = [];
      $item_keys_with_links = [];
      if (!$item) {
        continue;
      }
      if ($this->configuration['highlight_from_solr'] ?? TRUE) {
        $backed_highlight = $results[$item_id]->getExtraData('highlighted_fields',[]);
        foreach( $backed_highlight as $key => $backed_highlight_values) {
          if (in_array($key,$items['linkable_fields'])) {
            $linkable_text = array_merge($linkable_text, $backed_highlight_values ?? []);
          }
          else {
            $text = call_user_func_array('array_merge', array_values($backed_highlight));
          }
        }
      }
      else {
        // We call array_merge() using call_user_func_array() to prevent having to
        // use it in a loop because it is a resource greedy construction.
        // @see https://github.com/kalessil/phpinspectionsea/blob/master/docs/performance.md#slow-array-function-used-in-loop
        $text = call_user_func_array('array_merge', array_values($item));
      }
      $item_keys = $keys;

      // If the backend already did highlighting and told us the exact keys it
      // found in the item's text values, we can use those for our own
      // highlighting. This will help us take stemming, transliteration, etc.
      // into account properly.
      $highlighted_keys = $results[$item_id]->getExtraData('highlighted_keys');
      if ($highlighted_keys) {
        $item_keys = array_unique(array_merge($keys, $highlighted_keys));
      }
      if ($this->configuration['highlight_link'] ?? TRUE) {
        try {
          $uri = $results[$item_id]->getDatasource()->getItemUrl($results[$item_id]->getOriginalObject());
          $uri->setOptions(['fragment' => 'search/'.reset($item_keys)]);
          foreach ($item_keys as $key) {
            $rendered_url = \Drupal\Core\Link::fromTextAndUrl(
              $key, $uri
            );
            $item_keys_with_links[$key] = $rendered_url->toString()->getGeneratedLink();
          }
        }
        catch (\Exception $e) {
          $this->getLogger()->warning('Error happened trying to load the entity for Linked and generate a link highlight', []);
        }
      }
      $excerpt = '';
      $excerpt_return = [];
      $linked_excerpt = '';
      if (is_array($text) && count($text)) {
        $excerpt = $this->createExcerpt(
          implode($this->getEllipses()[1], $text), $item_keys
        );
        $excerpt_return[] = $this->highlightField($excerpt, $item_keys, FALSE);
      }
      if (is_array($linkable_text) && count($linkable_text)) {
        uasort(
          $linkable_text, function ($a, $b) {
          return strlen($b) - strlen($a);
        }
        );
        $linked_excerpt = $this->createExcerpt(
          implode($this->getEllipses()[1], $linkable_text), $item_keys
        );
        $excerpt_return[] = $this->highlightFieldWithLinks(
            $linked_excerpt, $item_keys_with_links, FALSE
          ) ?? '';
      }

      $results[$item_id]->setExcerpt(implode('<br/>', $excerpt_return));
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
   * Returns snippets from a piece of text, with certain keywords highlighted.
   *
   * Largely copied from search_excerpt().
   *
   * @param string $text
   *   The text to extract fragments from.
   * @param array $keys
   *   The search keywords entered by the user.
   *
   * @return string|null
   *   A string containing HTML for the excerpt. Or NULL if no excerpt could be
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
        $this->getLogger()->warning('A PCRE error (#%error_num) occurred during Advanced results highlighting with Links.', $args);
        return $text;
      }
      $textsCount = count($texts);
      for ($i = 0; $i < $textsCount; $i += 2) {
        $texts[$i] = $this->highlightFieldWithLink($texts[$i], $keys, FALSE);
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
      $patterns[] = '/' . preg_quote($this->configuration['prefix'],'/') . $k . preg_quote($this->configuration['suffix'],'/'). '/i';  // use i to ignore case
      $replacements[] = $this->configuration['prefix'].$v.$this->configuration['suffix'];
    }
    $text_with_links = preg_replace($patterns, $replacements, ' ' . $text . ' ', 1);
    // Ensures that we can always trim
    $text = $text_with_links !== '  ' ?  '&ldquo;'.$text_with_links.'&rdquo;' : $text;
    return trim($text);
  }

}
