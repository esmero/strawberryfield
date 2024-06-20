<?php


namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\search_api\Event\IndexingItemsEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api_solr\Event\PostCreateIndexDocumentEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\search_api_solr\Event\SearchApiSolrEvents;
use Drupal\search_api_solr\Event\PreQueryEvent;
use Drupal\search_api_solr\Event\PostConvertedQueryEvent;
use Drupal\search_api_solr\Event\PostConfigFilesGenerationEvent;
use Solarium\Component\ComponentAwareQueryInterface;
use Drupal\strawberryfield\StrawberryfieldSearchAPIUtilityServiceInterface;

class SearchApiSolrEventSubscriber implements EventSubscriberInterface {
  private StrawberryfieldSearchAPIUtilityServiceInterface $search_api_state;

  /**
   * @inheritDoc
   */
  public static function getSubscribedEvents() {
    return [
      SearchApiSolrEvents::PRE_QUERY => 'preQuery',
      SearchApiSolrEvents::POST_CONVERT_QUERY => 'convertedQuery',
      SearchApiEvents::INDEXING_ITEMS => 'indexingItems',
      SearchApiSolrEvents::POST_CONFIG_FILES_GENERATION => 'overrideExtraFields',
      SearchApiSolrEvents::POST_CREATE_INDEX_DOCUMENT => 'RestoreLostZerosVector',
    ];
  }

  /**
   * StrawberryEventDeleteFlavorSubscriber constructor.
   *
   * @param StrawberryfieldSearchAPIUtilityServiceInterface $search_api_state
   */
  public function __construct(StrawberryfieldSearchAPIUtilityServiceInterface $search_api_state) {
    $this->search_api_state = $search_api_state;
  }

  /**
   * @param \Drupal\search_api_solr\Event\PreQueryEvent $event
   */
  public function preQuery(PreQueryEvent $event): void {
    $query = $event->getSearchApiQuery();
    $solarium_query = $event->getSolariumQuery();

    // To get a list of solarium events:
    // @see http://solarium.readthedocs.io/en/stable/customizing-solarium/#plugin-system
    if ($query->getOption('no_highlight')) {
      $solarium_query->addParam('hl', 'false');
      /* @var \Solarium\Component\Highlighting\Highlighting $hl */
      $hl = $solarium_query->getHighlighting();
      $hl->clearFields();
    }

    if ($query->getOption('ocr_highlight')) {
      // $solr_field_names maps search_api field names to real field names in
      // the Solr index.
      $solr_field_names = $query->getIndex()->getServerInstance()->getBackend()->getSolrFieldNames($query->getIndex());
      if (isset($solr_field_names['ocr_text'])) {
        /* @var \Solarium\Component\Highlighting\Highlighting $hl */
        $hl = $solarium_query->getHighlighting();
        // hl.fl has issues if ocr_text is in that list (Token Offset big error,
        // bad, bad)
        // By removing any highlight returns in this case we can focus on what we
        // need.
        $hl->clearFields();

        $solarium_query->addParam('hl.ocr.fl', $solr_field_names['ocr_text']);
        $solarium_query->addParam('hl.ocr.absoluteHighlights', 'on');
        // Only place where unified is justified
        $hl->setMethod('unified');
      }
    }
    elseif ($query->getOption('sbf_highlight_fields', FALSE)) {
      //advanced_highlight_return
      // ELSEIF bc OCR and these ones are incompatible
      /* @var \Solarium\Component\Highlighting\Highlighting $hl */
      $hl = $solarium_query->getHighlighting();
      $highlight_fields = $query->getOption('sbf_highlight_fields',[]);
      foreach ($highlight_fields as $highlighted_field) {
        // We must not set the fields at once using setFields() to not break
        // the altered queries.
        $hl->addField($highlighted_field);
      }

      // Force HL to original for now. We can make this an option
      // but given the Drupal nature of treating all Full Text fields as the same
      // If a given Full text does not contain the vector index data required this will
      // fail. Unified does not play with JOINs on Solr 9.1 throwing
      // a class mismatch even if we are not asking for Highlights from the flavor.
      // @TODO revisit for Solr 9.2.x
      $hl->setUsePhraseHighlighter(TRUE);
      $hl->setMethod('original');
      $hl->setFragSize(128);
      $hl->setRequireFieldMatch(TRUE);
    }
  }


  /**
   * @param \Drupal\search_api_solr\Event\PostConvertedQueryEvent $event
   */
  public function convertedQuery(PostConvertedQueryEvent $event): void {
    $query = $event->getSearchApiQuery();
    $solarium_query = $event->getSolariumQuery();
    if ($query->getOption('sbf_advanced_highlight_flavor',FALSE)) {
      $components = $solarium_query->getComponents();
      if (isset($components['edismax'])) {
        $solarium_query->removeComponent(
          ComponentAwareQueryInterface::COMPONENT_EDISMAX
        );
        $solarium_query->addParam('defType', 'lucene');
        /* @var \Solarium\Component\Highlighting\Highlighting $hl */
        $hl = $solarium_query->getHighlighting();

        $hl->setUsePhraseHighlighter(TRUE);
        $hl->setDefaultSummary(TRUE);
        $hl->setMethod('original');
        $hl->setRequireFieldMatch(TRUE);
        $hl->setFragsizeIsMinimum(FALSE);
        $hl->setMergeContiguous(TRUE);
        $hl->setFragSize(128);
        if ($combined_keys = $query->getOption('sbf_join_flavor')['hl'] ?? NULL) {
          $hl->setQuery($combined_keys);
        }
        // Because the Query Sets a few Fields to retrieve (to make it faster)
        // But Search API is silly and decides that when that happens
        // I want only those fields highlighted
        // By setting the to all but limiting it to setRequireFieldMatch we only the matched ones.
        // This fails with JOINS and unified so we set method original.
        $hl->setFields(['*']);
      }
    }
    if ($query->getOption('sbf_knn')) {
        //@TODO this is overly nested. I should also allow multiple ones like this
        // then move any non KNN queries to "before" or to "filters"
      $solarium_query->setQuery($query->getOption('sbf_knn')[0][0]);
      $hl = $solarium_query->getHighlighting();
      // We can't highlight when doing Vector Queries or we will get
      //  [ x:drupal] o.a.s.h.RequestHandlerBase java.lang.UnsupportedOperationException => java.lang.UnsupportedOperationException
      //	at org.apache.lucene.search.highlight.WeightedSpanTermExtractor$DelegatingLeafReader.getFieldInfos(WeightedSpanTermExtractor.java:460)
      //java.lang.UnsupportedOperationException: null

      $hl->clearFields();
      $hl->setUsePhraseHighlighter(FALSE);
      // Just in case?
      $solarium_query->removeComponent(
        ComponentAwareQueryInterface::COMPONENT_EDISMAX
      );
    }
  }
  /**
   * Reacts to the indexing items event.
   *
   * @param \Drupal\search_api\Event\IndexingItemsEvent $event
   *   The indexing items event.
   */
  public function indexingItems(IndexingItemsEvent $event) {
    $items = $event->getItems();
    if (count($items)) {
      $this->search_api_state->setIsIndexing(TRUE);
    }
  }


  /**
   * Reacts to the finished indexing items event.
   *
   * @param \Drupal\search_api\Event\IndexingItemsEvent $event
   *   The indexing items event.
   */
  public function finishedIndexingItems(IndexingItemsEvent $event) {
    $this->search_api_state->setIsIndexing(FALSE);
  }

  public function RestoreLostZerosVector(PostCreateIndexDocumentEvent $event) {
    $item = $event->getSearchApiItem();
    $names = [];
    foreach($item->getFields(FALSE) as $field) {
      if (str_starts_with($field->getType(), 'densevector_')) {
          $names[] = $field->getFieldIdentifier();
      }
    }

    if (count($names) > 0) {
      $document = $event->getSolariumDocument();
      $index = $item->getIndex();
      $solr_names = $index->getServerInstance()->getBackend()->getSolrFieldNames($index);
      foreach ($names as $name) {
        if (isset($solr_names[$name])) {
          // Why this check? I can't sent an empty. But i can send a NULL if not present
          if (!empty($values = $item->getField($name)->getValues())) {
            $document->setField($solr_names[$name], $item->getField($name)->getValues() ?? NULL);
          }
        }
      }
    }
  }


  public function overrideExtraFields(PostConfigFilesGenerationEvent $event): void {
    /// TODO override extra fields so we can get around silly search api defining all as multivalued
  }
}
