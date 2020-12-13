<?php

namespace Drupal\strawberryfield\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
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
  protected $requestStack;

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
    $this->requestStack = $request_stack;
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
   * @param string $term
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   */
  public function search(
    ContentEntityInterface $node,
    string $fileuuid = '',
    string $processor = 'ocr',
    string $term = ''
  ) {

    $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();
    $this->flavorfromSolrIndex($term, $node->id(), $processor, $fileuuid, $indexes);
           $cacheabledata = [];
           $response = new CacheableJsonResponse(
              'hi',
              200,
              ['content-type' => 'application/json'],
              TRUE
            );


        if ($response) {
          // Set CORS. IIIF and others will assume this is true.
          $response->headers->set('access-control-allow-origin','*');
          $response->addCacheableDependency($node);

        }
        return $response;

      }

      protected function flavorfromSolrIndex($term, $nodeid, $processor, $file_uuid, $indexes) {
        /* @var \Drupal\search_api\IndexInterface[] $indexes */

        $count = 0;
        foreach ($indexes as $search_api_index) {

          // Create the query.
          $query = $search_api_index->query([
            'limit' => 1000,
            'offset' => 0,
          ]);

          /*$query->setFulltextFields([
            'title',
            'body',
            'filename',
            'saa_field_file_document',
            'saa_field_file_news',
            'saa_field_file_page'
          ]);*/
          //$parse_mode = $this->parseModeManager->createInstance('direct');
          $parse_mode = $this->parseModeManager->createInstance('terms');
          $query->setParseMode($parse_mode);
          // $parse_mode->setConjunction('OR');
          // $query->keys($search);
          $query->sort('search_api_relevance', 'DESC');

          $query->addCondition('parent_id', $nodeid)
            ->addCondition('search_api_datasource', 'strawberryfield_flavor_datasource')
            ->addCondition('file_uuid', $file_uuid)
            ->addCondition('processor_id', $processor);

          $query->addCondition('ocr_text', $term, '=');
          $allfields_translated_to_solr = $search_api_index->getServerInstance()->getBackend()->getSolrFieldNames($query->getIndex());
          if (isset($allfields_translated_to_solr['ocr_text'])) {
            dpm('allfields_translated_to_solr');
            $query->setOption('ocr_highlight','on');
          }
          //$query->setOption('hl.ocr.fl', );
          //$query = $query->addCondition('ss_checksum', $checksum);
          // If we allow processing here Drupal adds Content Access Check
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
          // Another solution would be to make our conditions all together an OR
          // But no post processing here is also good, faster and we just want
          // to know if its there or not.

          $query->setProcessingLevel(QueryInterface::PROCESSING_BASIC);
          $results = $query->execute();
          dpm($results->getAllExtraData());

          // $solr_response = $results->getExtraData('search_api_solr_response');
          // In case of more than one Index with the same Data Source we accumulate
          $count = $count + (int) $results->getResultCount();

        }
      }

}

