<?php

namespace Drupal\strawberryfield\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Drupal\search_api\ParseMode\ParseModePluginManager;


/**
 * A Wrapper Controller to access Twig processed JSON on a URL.
 */
class StrawberryfieldFlavorDatasourceSearchController extends ControllerBase {

  /**
   * Symfony\Component\HttpFoundation\RequestStack definition.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $request;

  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;

  /**
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager
   */
  protected $parseModeManager;

  /**
   * StrawberryfieldFlavorDatasourceSearchController constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The Symfony Request Stack.
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfield_utility_service
   *   The SBF Utility Service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entitytype_manager
   *   The Entity Type Manager.
   * @param \Drupal\search_api\ParseMode\ParseModePluginManager $parse_mode_manager
   *   The Search API parse Manager
   */
  public function __construct(
    RequestStack $request_stack,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    EntityTypeManagerInterface $entitytype_manager,
    ParseModePluginManager $parse_mode_manager
  ) {
    $this->request = $request_stack;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->entityTypeManager = $entitytype_manager;
    $this->parseModeManager = $parse_mode_manager;

  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('strawberryfield.utility'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.search_api.parse_mode')
    );
  }


  /**
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   * @param string $processor
   * @param string $fileuuid
   * @param string $format
   * @param string $page
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   */
  public function search(Request $request, ContentEntityInterface $node, string $fileuuid = 'all', string $processor = 'ocr', string $format = 'json', string $page = 'all') {

    if (!Uuid::isValid($fileuuid) && $fileuuid !== 'all') {
      // We do not want to expose the user to errors here?
      // So an empty JSON response is better?
      return new JsonResponse([]);
    }

    //search for IAB highlight
    //if format=json, page=all and q not null
    if (($input = $request->query->get('q')) && ($format == 'json') && ($page == 'all')) {

      $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();
      $snippets = $this->flavorfromSolrIndex(
        $input,
        $node->id(),
        $processor,
        $fileuuid,
        $indexes
      );

      $response = new CacheableJsonResponse(
        json_encode($snippets),
        200,
        ['content-type' => 'application/json'],
        TRUE
      );


      if ($response) {
        // Set CORS. IIIF and others will assume this is true.
        $response->headers->set('access-control-allow-origin', '*');
        $response->addCacheableDependency($node);
        if ($callback = $request->query->get('callback')) {
          $response->setCallback($callback);
        }

      }
      return $response;

    }
    elseif ((($format == 'originalocr') || ($format == 'djvuxml')) && (is_numeric($page))) {
      $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();
      //as IAB text selection page number starts from 0
      //and djvuxml is inteded for IAB
      if ($format == 'djvuxml') {
        $page += 1;
      }
      $originalocr = $this->originalocrfromSolrIndex(
        $node->id(),
        $processor,
        $fileuuid,
        $indexes,
        $page
      );

      if ($format == 'originalocr') {
        $output = $originalocr;
      }
      else {
        $output = $this->originalocr2djvuxml($originalocr);
      }

      $response = new CacheableResponse(
        $output,
        200,
        ['content-type' => 'application/xml'],
        TRUE
      );
      if ($response) {
        // Set CORS. IIIF and others will assume this is true.
        $response->headers->set('access-control-allow-origin', '*');
        $response->addCacheableDependency($node);
        if ($callback = $request->query->get('callback')) {
          $response->setCallback($callback);
        }
      }
      return $response;
    }
    else {
      return new JsonResponse([]);
    }
  }

  protected function flavorfromSolrIndex($term, $nodeid, $processor, $file_uuid, $indexes) {
    /* @var \Drupal\search_api\IndexInterface[] $indexes */

    $result_snippets = [];
    foreach ($indexes as $search_api_index) {

      // Create the query.
      // How many?
      $query = $search_api_index->query([
        'limit' => 20,
        'offset' => 0,
      ]);

      $parse_mode = $this->parseModeManager->createInstance('terms');
      $query->setParseMode($parse_mode);
      $query->sort('search_api_relevance', 'DESC');
      $query->keys($term);

      $query->setFulltextFields(['ocr_text']);

      $query->addCondition('parent_id', $nodeid)
        ->addCondition('search_api_datasource', 'strawberryfield_flavor_datasource')
        ->addCondition('processor_id', $processor);
      // IN the case of multiple files being used in the same IIIF manifest we do not limit
      // Which file we are loading.
      // @IDEA. Since the Rendered Twig templates (metadata display) that generate a IIIF manifest are cached
      // We could also pass arguments that would allow us to fetch that rendered JSON
      // And preprocess everything here.
      // Its like having the same Twig template on front and back?
      // @giancarlobi. Maybe an idea for the future once this "works".

      if ($file_uuid != 'all') {
        if (Uuid::isValid($file_uuid)) {
          $query->addCondition('file_uuid', $file_uuid);
        }
      }

      $allfields_translated_to_solr = $search_api_index->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($query->getIndex());
      if (isset($allfields_translated_to_solr['ocr_text'])) {
        // Will be used by \strawberryfield_search_api_solr_query_alter
        $query->setOption('ocr_highlight', 'on');
        // We are already checking if the Node can be viewed. Custom Datasources can not depend on Solr node access policies.
        $query->setOption('search_api_bypass_access', TRUE);
      }
      $query->setOption('search_api_retrieved_field_values', ['id']);
      // If we allow Extra processing here Drupal adds Content Access Check
      // That does not match our Data Source \Drupal\search_api\Plugin\search_api\processor\ContentAccess
      // we get this filter (see 2nd)
      /*
       *   array (
        0 => 'ss_search_api_id:"strawberryfield_flavor_datasource/2006:1:en:3dccdb09-f79f-478e-81c5-0bb680c3984e:ocr"',
        1 => 'ss_search_api_datasource:"strawberryfield_flavor_datasource"',
        2 => '{!tag=content_access,content_access_enabled,content_access_grants}(ss_search_api_datasource:"entity:file" (+(bs_status:"true" bs_status_2:"true") +(sm_node_grants:"node_access_all:0" sm_node_grants:"node_access__all")))',
        3 => '+index_id:default_solr_index +hash:1evb7z',
        4 => 'ss_search_api_language:("en" "und" "zxx")',
      ),
       */

      // We have to get page number from different ID (sequence_12, Page12, page_12)
      // so better use $str = preg_replace('/\D/', '', $str); to get page number integer from ID
      //$page_prefix = 'sequence_'; // Optional. XML id=1 is really invalid but if someone wants to use that, OK.
      //$page_prefix_len = strlen($page_prefix);
      $query->setProcessingLevel(QueryInterface::PROCESSING_BASIC);
      $results = $query->execute();
      $extradata = $results->getAllExtraData();
      // Just in case something goes wrong with the returning region text
      $region_text = $term;
      if ($results->getResultCount() >= 1) {
        if (isset($extradata['search_api_solr_response']['ocrHighlighting']) && count(
            $extradata['search_api_solr_response']['ocrHighlighting']
          ) > 0) {
          foreach ($extradata['search_api_solr_response']['ocrHighlighting'] as $sol_doc_id => $field) {
            $result_snippets_base = [];
            if (isset($field[$allfields_translated_to_solr['ocr_text']]['snippets'])) {
              foreach ($field[$allfields_translated_to_solr['ocr_text']]['snippets'] as $snippet) {
                // IABR uses 0 to N-1. We may want to reprocess this for other endpoints.
                //$page_number = strpos($snippet['pages'][0]['id'], $page_prefix) === 0 ? (int) substr(
                //  $snippet['pages'][0]['id'],
                //  $page_prefix_len
                //) : (int) $snippet['pages'][0]['id'];
                $page_number = preg_replace('/\D/', '', $snippet['pages'][0]['id']);
                // Just some check in case something goes wrong and page number is 0 or negative?
                // and rebase page number starting with 0
                $page_number = ($page_number > 0) ? (int) ($page_number - 1) : 0;

                //We assume that if coords <1 (i.e. .123) => MINIOCR else ALTO
                //As ALTO are absolute to be compatible with current logic we have to transform to relative
                //To convert we need page width/height
                $page_width = (float) $snippet['pages'][0]['width'];
                $page_height = (float) $snippet['pages'][0]['height'];

                $result_snippets_base = [
                  'par' => [
                    [
                      'page' => $page_number,
                      'boxes' => [],
                    ],
                  ],
                ];

                foreach ($snippet['highlights'] as $highlight) {
                  $region_text = str_replace(
                    ['<em>', '</em>'],
                    ['{{{', '}}}'],
                    $snippet['regions'][$highlight[0]['parentRegionIdx']]['text']
                  );

                  //check if coord >=1 (ALTO)
                  // else between 0 and <1 (MINIOCR)
                  if ( ((int) $highlight[0]['lrx']) > 0  ){
                    //ALTO so coords need to be relative
                    $left = sprintf('%.3f',((float) $highlight[0]['ulx'] / $page_width));
                    $top = sprintf('%.3f',((float) $highlight[0]['uly'] / $page_height));
                    $right = sprintf('%.3f',((float) $highlight[0]['lrx'] / $page_width));
                    $bottom = sprintf('%.3f',((float) $highlight[0]['lry'] / $page_height));
                    $result_snippets_base['par'][0]['boxes'][] = [
                      'l' => $left,
                      't' => $top,
                      'r' => $right,
                      'b' => $bottom,
                      'page' => $page_number,
                    ];
                  }
                  else {
                    //MINIOCR coords already relative
                    $result_snippets_base['par'][0]['boxes'][] = [
                      'l' => $highlight[0]['ulx'],
                      't' => $highlight[0]['uly'],
                      'r' => $highlight[0]['lrx'],
                      'b' => $highlight[0]['lry'],
                      'page' => $page_number,
                    ];
                  }
                }
              }
              $result_snippets_base['text'] = $region_text;
            }

            $result_snippets[] = $result_snippets_base;
          }
        }
      }
    }
    return ['matches' => $result_snippets];
  }





  protected function originalocrfromSolrIndex($nodeid, $processor, $file_uuid, $indexes, $page) {
    /* @var \Drupal\search_api\IndexInterface[] $indexes */

    $result_snippets = [];
    foreach ($indexes as $search_api_index) {

      // Create the query.
      // How many?
      $query = $search_api_index->query([
        'limit' => 1,
        'offset' => 0,
      ]);

      $parse_mode = $this->parseModeManager->createInstance('direct');
      $query->setParseMode($parse_mode);
      //$query->sort('search_api_relevance', 'DESC');
      //$query->keys($term);

      //$query->setFulltextFields(['ocr_text']);

      $query->addCondition('parent_id', $nodeid)
        ->addCondition('search_api_datasource', 'strawberryfield_flavor_datasource')
        ->addCondition('processor_id', $processor)
        ->addCondition('sequence_id', ($page));
      // IN the case of multiple files being used in the same IIIF manifest we do not limit
      // Which file we are loading.
      // @IDEA. Since the Rendered Twig templates (metadata display) that generate a IIIF manifest are cached
      // We could also pass arguments that would allow us to fetch that rendered JSON
      // And preprocess everything here.
      // Its like having the same Twig template on front and back?
      // @giancarlobi. Maybe an idea for the future once this "works".

      if ($file_uuid != 'all') {
        if (Uuid::isValid($file_uuid)) {
          $query->addCondition('file_uuid', $file_uuid);
        }
      }

      $allfields_translated_to_solr = $search_api_index->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($query->getIndex());
      //if (isset($allfields_translated_to_solr['ocr_text'])) {
        // Will be used by \strawberryfield_search_api_solr_query_alter
        //$query->setOption('ocr_highlight', 'on');
        // We are already checking if the Node can be viewed. Custom Datasources can not depend on Solr node access policies.
        //$query->setOption('search_api_bypass_access', TRUE);
      //}
      //$query->setOption('search_api_retrieved_field_values', ['id']);
      $query->setOption('search_api_retrieved_field_values', ['ocr_text']);
      // If we allow Extra processing here Drupal adds Content Access Check
      // That does not match our Data Source \Drupal\search_api\Plugin\search_api\processor\ContentAccess
      // we get this filter (see 2nd)
      /*
       *   array (
        0 => 'ss_search_api_id:"strawberryfield_flavor_datasource/2006:1:en:3dccdb09-f79f-478e-81c5-0bb680c3984e:ocr"',
        1 => 'ss_search_api_datasource:"strawberryfield_flavor_datasource"',
        2 => '{!tag=content_access,content_access_enabled,content_access_grants}(ss_search_api_datasource:"entity:file" (+(bs_status:"true" bs_status_2:"true") +(sm_node_grants:"node_access_all:0" sm_node_grants:"node_access__all")))',
        3 => '+index_id:default_solr_index +hash:1evb7z',
        4 => 'ss_search_api_language:("en" "und" "zxx")',
      ),
       */

      //$page_prefix = 'sequence_'; // Optional. XML id=1 is really invalid but if someone wants to use that, OK.
      //$page_prefix_len = strlen($page_prefix);
      $query->setProcessingLevel(QueryInterface::PROCESSING_BASIC);
      //$query->setOption('ocr_highlight','off');
      //$query->setOption('highlight','off');
      $results = $query->execute();
      $extradata = $results->getAllExtraData();
      // Just in case something goes wrong with the returning region text
      //$region_text = $term;

      $output = '';
      foreach ($results as $result) {
        $value = $result->getField('ocr_text')->getValues();
        $output = $value[0];
      }
      if ($results->getResultCount() >= 1) {
        return $output;
      }
    }
    return [];
  }


  protected function originalocr2djvuxml($response) {

    $miniocr = simplexml_load_string($response);
    $internalErrors = libxml_use_internal_errors(TRUE);
    libxml_clear_errors();
    libxml_use_internal_errors($internalErrors);
    if (!$miniocr) {
      $this->logger->warning('Sorry for this page we could not decode/extract MINIOCR');
      return NULL;
    }

    $wh = explode(" ", $miniocr->p[0]['wh']);
    $pagewidth = (float) $wh[0];
    $pageheight = (float) $wh[1];

    $djvuxml = new \XMLWriter();
    $djvuxml->openMemory();
    $djvuxml->startDocument('1.0', 'UTF-8');
    $djvuxml->startElement("OBJECT");
    $djvuxml->writeAttribute("height", $wh[1]);
    $djvuxml->writeAttribute("width", $wh[0]);
    foreach ($miniocr->children() as $p) {
      $djvuxml->startElement("PARAGRAPH");
      foreach ($p->children() as $b) {
        foreach ($b->children() as $l) {

          $djvuxml->startElement("LINE");
          foreach ($l->children() as $word) {
            $djvuxml->startElement("WORD");
            //left top width height (miniocr)
            //left bottom right top (djvuxml)
            $wcoos = explode(" ", $word['x']);
            $left = (float) $wcoos[0] * $pagewidth;
            $top = (float) $wcoos[1] * $pageheight;
            $width = (float) $wcoos[2] * $pagewidth;
            $height = (float) $wcoos[3] * $pageheight;
            $x0 = sprintf('%.0f',$left);
            $y1 = sprintf('%.0f',$top);
            $x1 = sprintf('%.0f',($left + $width));
            $y0 = sprintf('%.0f',($top + $height));
            $djvuxml->writeAttribute("coords", $x0 . ', ' . $y0 . ', ' . $x1 . ', ' . $y1);
            $text = (string) $word;
            $djvuxml->text($text);
            $djvuxml->endElement();
            $lastchar = substr($text, -1);
          }
          $djvuxml->endElement();
          //if ($lastchar == '.') {
            //$djvuxml->endElement();
            //$djvuxml->startElement("PARAGRAPH");
          //}
        }
      }
      $djvuxml->endElement();
    }



    $djvuxml->endElement();
    $djvuxml->endDocument();
    unset($miniocr);

    return $djvuxml->outputMemory(TRUE);

  }

  /**
   * Returns number of Solr Flavour Documents for a given processor and Node.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   * @param string $processor
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   * @throws \Drupal\search_api\SearchApiException
   */
  public function count(Request $request, ContentEntityInterface $node, string $processor = 'ocr') {
    $count = 0;
    try {
      $count = $this->strawberryfieldUtility->getCountByProcessorInSolr(
        $node, $processor
      );
      return new JsonResponse(['count' => $count]);
    }
    catch (\Exception $exception) {
      // We do not want to throw nor record exceptions for public facing Endpoints
      return new JsonResponse(['count' => $count]);
    }
  }

}
