<?php


namespace Drupal\strawberryfield;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\UseCacheBackendTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Link;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\strawberryfield\StrawberryfieldUtilityService;

/**
 * Provides a breadcrumb builder for ADOs.
 */
class StrawberryFieldBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;
  use UseCacheBackendTrait;

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;


  /**
   * Constructs the StrawberryFieldBreadcrumbBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   * @param Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfield_utility_service
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $account, StrawberryfieldUtilityService $strawberryfield_utility_service, ConfigFactoryInterface $config_factory) {
    $this->nodeStorage = $entity_type_manager->getStorage('node');
    $this->account = $account;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    $entity = $route_match->getParameter('node');
    $qualifies = $route_match->getRouteName() == "entity.node.canonical" && $entity instanceof NodeInterface && $this->strawberryfieldUtility->bearsStrawberryfield($entity);
    if ($qualifies) {
      return $this->configFactory->get('strawberryfield.breadcrumbs')->get('enabled');
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $links = [Link::createFromRoute($this->t('Home'), '<front>')];
    $node =  $route_match->getParameter('node');
    /* @var $parent_node_ids \Drupal\strawberryfield\Field\StrawberryFieldEntityComputedItemList */
    $field_sbf_nodetonode = $node->field_sbf_nodetonode;
    $parent_nodes = $field_sbf_nodetonode->referencedEntities();
    $breadcrumb->addCacheableDependency($node);
    $trail_flat = [];
    $depth = 1;
    $visited = [];
    $longest_trail = [];
    $start_path = bin2hex(random_bytes(16));
    $this->recursiveParentPaths($node,$trail_flat, $visited, $depth, $start_path, $breadcrumb);

    if ($this->configFactory->get('strawberryfield.breadcrumbs')->get('type') === 'longest') {
      $longest_trail = !empty($trail_flat) ? max($trail_flat) : [];
    }
    elseif ($this->configFactory->get('strawberryfield.breadcrumbs')->get('type') === 'shortest') {
      $longest_trail = !empty($trail_flat) ? min($trail_flat) : [];
    }
    else {
      $longest_trail = $this->smartTrail($trail_flat);
    }

    if (is_array($longest_trail) && !empty($longest_trail)) {
      $longest_trail = array_reverse($longest_trail, TRUE);
    }

    foreach($longest_trail as $link) {
      $links[] = $link;
    }
    $breadcrumb->setLinks($links);
    $breadcrumb->addCacheTags(['config:strawberryfield.breadcrumbs']);
    $breadcrumb->addCacheContexts(['route', 'url.path', 'languages']);
    return $breadcrumb;
  }

  /**
   * Constructs the Multiple Flat trails recursively.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The current Node
   * @param array $trail
   *   All Trails keyed by a randon hash.
   * @param array $visited
   *   Node Ids that were already visited
   * @param int $depth
   *  Current Depth of the recursive function
   * @param string $current_path
   *  The current Forked/flat Path (Key)
   * @param \Drupal\Core\Breadcrumb\Breadcrumb $breadcrumb
   */
  protected function recursiveParentPaths(EntityInterface $node, array &$trail, array &$visited, int $depth, string $current_path, Breadcrumb $breadcrumb) {
    if ($depth >= 5) { return; }
    // Everytime we diverge/multiple parents (like in git) we need to create a
    // new Path ID/array that includes the previous one
    // The idea is that this way we create multiple/overlapping paths/breacrumbs.
    if ($node->field_sbf_nodetonode instanceof EntityReferenceFieldItemListInterface) {
      $old_depth = $depth;
      $depth++;
      foreach($node->field_sbf_nodetonode->referencedEntities() as $key => $referencedEntity) {
        $access = $referencedEntity->access('view', $this->account, TRUE);
        $breadcrumb->addCacheableDependency($access);
        if ($access->isAllowed()) {
          $breadcrumb->addCacheableDependency($referencedEntity);
        }
        if ($key !== 0) {
          $newpath = bin2hex(random_bytes(16));
          $oldpath = $trail[$current_path];
          $trail[$newpath] = array_slice($oldpath, 0, ($old_depth-1), TRUE) ;
          $trail[$newpath]["{$referencedEntity->id()}"] = Link::createFromRoute($referencedEntity->label(),
            'entity.node.canonical', ['node' => $referencedEntity->id()]);

          $this->recursiveParentPaths($referencedEntity, $trail, $visited, $depth, $newpath, $breadcrumb);
        }
        else {
          $trail[$current_path]["{$referencedEntity->id()}"] = Link::createFromRoute($referencedEntity->label(),
            'entity.node.canonical', ['node' => $referencedEntity->id()]);
          $this->recursiveParentPaths($referencedEntity, $trail, $visited, $depth, $current_path, $breadcrumb);
        }
      }
    }
  }

  /**
   * Best Longest Trail by comparing common Node IDs with the shorter ones.
   *
   *  @param array $trail_flat
   *   A list of Trails
   */
  protected function smartTrail(array $trail_flat) {

    if (count($trail_flat) == 0) {
      return [];
    }
    // Start by getting the max. That way we have a count to
    // Use for selecting candidates.
    $longest_trail = !empty($trail_flat) ? max($trail_flat) : [];
    $max_count = count($longest_trail);
    $unqualified = [];
    $qualified = [];
    $hits = [];
    // Now let's sort the array from low to high
    $count_array_flat = array_map('count', $trail_flat);
    array_multisort($count_array_flat, SORT_ASC, $trail_flat);
    foreach ($trail_flat as $key => $trail) {
      if (count($trail) != $max_count) {
        $unqualified = array_merge($unqualified, array_keys($trail));
      }
      else {
        $qualified[$key] = $trail;
      }
    }

    foreach($qualified as $key => $trail) {
      $hits[$key] = count(array_intersect(array_keys($trail), $unqualified));
    }
    // Now get me the key with the highest count of commonalities with the rest
    $max = array_keys($hits, max($hits));
    $key = $max[0] ?? NULL;
    return !empty($key) && isset($trail_flat[$key]) ? $trail_flat[$key] :  $longest_trail;
  }

  /**
   * Public method/builds Multiple Flat trails recursively filtered by predicate/ado type.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   A  Node
   * @param array $trail
   *   All Trails keyed by a randon hash.
   *   Ommitted ADOs (bc of filters) in a TRAIL have a value of FALSE instead
   *   of the Label.
   * @param int $depth
   *  Current Depth of the recursive function
   * @param string $current_path
   *  The current Forked/flat Path (Key)
   * @param array $predicates
   *  Indexed list of JSON keys holding ADO to ADO relationships.["ismemberof"]
   *  Leave empty if all properties should be accumulated
   * @param array $ado_types
   *  Indexed list of valid ADO semantic Types. e.g ["Collection"]
   *  Leave empty if all types of ADOs should be accumulated
   * @param \Drupal\Core\Render\BubbleableMetadata $bubbleableMetadata
   *
   * @throws \Exception
   */
  public function recursiveParentPathsByTypeAndPredicate(EntityInterface $node, array &$trail, int $depth, string $current_path, array $predicates, array $ado_types, BubbleableMetadata $bubbleableMetadata) {
    if ($depth >= 5) { return; }
    // Everytime we diverge/multiple parents (like in git) we need to create a
    // new Path ID/array that includes the previous one
    // The idea is that this way we create multiple/overlapping paths/breacrumbs.
    $old_depth = $depth;
    $depth++;
    $i = 0;
    $seen = [];
    if (!empty($ado_types)) {
      $ado_types = array_map(function ($el) {
        $value = is_string($el) ? strtolower($el) : NULL;
        return $value;
      }, $ado_types);
      $ado_types = array_filter($ado_types);
    }

    if (!empty($predicates)) {
      $predicates = array_map(function ($el) {
        $value = is_string($el) ? strtolower($el) : NULL;
        return $value;
      }, $predicates);
      $predicates = array_filter($predicates);
    }
    // Note: Filtering by Type && Property does not mean
    // not traversing the paths. Just means not accumulating Labels
    // The goal here is to allow someone to get e.g Just parent Collections
    // even if the object is nested by a CWS and an isParent.
    foreach($this->strawberryfieldUtility->getStrawberryfieldParentADOs($node) as $predicate => $referencedEntitys) {
      foreach ($referencedEntitys as $nid => $referencedEntity) {
        $access = $referencedEntity->access('view', $this->account, TRUE);
        $bubbleableMetadata->addCacheableDependency($access);
        if ($access->isAllowed()) {
          $bubbleableMetadata->addCacheableDependency($referencedEntity);
        }
        if ($i == 1) {
          // Any other loop
          if (!in_array($referencedEntity->id(), $seen)){
            $newpath = bin2hex(random_bytes(16));
            $oldpath = $trail[$current_path];
            $trail[$newpath] = array_slice($oldpath, 0, ($old_depth - 1), TRUE);
            // Fill skippped paths with FALSE; Only way the "dept" can be kept and used for slicing.
            $trail[$newpath]["{$referencedEntity->id()}"] =  $this->shouldTrackCrumb($referencedEntity, $predicate, $predicates, $ado_types) ? $referencedEntity->label() : FALSE;
            $seen[] = $referencedEntity->id();
            $this->recursiveParentPathsByTypeAndPredicate($referencedEntity, $trail, $depth, $newpath, $predicates, $ado_types, $bubbleableMetadata);
          }
        }
        else {
            $seen[] = $referencedEntity->id();
            // Fill skippped paths with FALSE;
            $trail[$current_path]["{$referencedEntity->id()}"] = $this->shouldTrackCrumb($referencedEntity, $predicate, $predicates, $ado_types) ? $referencedEntity->label() : FALSE;
            $i = 1;
          $this->recursiveParentPathsByTypeAndPredicate($referencedEntity, $trail, $depth, $current_path, $predicates, $ado_types, $bubbleableMetadata);
        }
      }
    }
  }


  private function shouldTrackCrumb(EntityInterface $node, $holding_predicate, $predicates, $ado_types) {
    $should_track = FALSE;
    $in_type = TRUE;
    if (empty($predicates) || in_array($holding_predicate, $predicates)) {
      $should_track = TRUE;
      // Only accumulate and mark as seen if we have no restrictions
      if (!empty($ado_types) && $node->hasField('field_sbf_semantictype')) {
        $types = $node->get('field_sbf_semantictype')->getValue();
        if (is_array($types) && count($types)) {
          $sbf_type = [];
          foreach ($types as $type) {
            $sbf_type[] = is_string($type['value'] ?? NULL) ? strtolower($type['value']) : NULL;
          }
          $in_type = array_intersect($sbf_type, $ado_types);
          $in_type = count($in_type) ? TRUE : FALSE;
        }
      }
      if (!$in_type) {
        $should_track = FALSE;
      }
    }
    return $should_track;
  }
}
