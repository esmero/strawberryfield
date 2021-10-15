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
    $breadcrumb->addCacheContexts(['route']);
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
  protected function recursiveParentPaths(EntityInterface $node, array &$trail = [], array &$visited = [], int $depth, string $current_path, Breadcrumb $breadcrumb) {
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
    array_multisort(array_map('count', $trail_flat), SORT_ASC, $trail_flat);
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
}
