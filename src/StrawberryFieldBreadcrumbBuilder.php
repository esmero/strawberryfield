<?php


namespace Drupal\strawberryfield;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\UseCacheBackendTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Link;
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
   * Constructs the StrawberryFieldBreadcrumbBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   * @param Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfield_utility_service
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $account, StrawberryfieldUtilityService $strawberryfield_utility_service) {
    $this->nodeStorage = $entity_type_manager->getStorage('node');
    $this->account = $account;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    $entity = $route_match->getParameter('node');
    return $route_match->getRouteName() == "entity.node.canonical" && $entity instanceof NodeInterface && $this->strawberryfieldUtility->bearsStrawberryfield($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();

    $links = [Link::createFromRoute($this->t('Home'), '<front>')];
    /* @var $parent_node_ids \Drupal\strawberryfield\Field\StrawberryFieldEntityComputedItemList */
    $field_sbf_nodetonode = $route_match->getParameter('node')->field_sbf_nodetonode;
    $parent_nodes = $field_sbf_nodetonode->referencedEntities();
    /* $depth = 1;
    // We skip the current node.
    while (!empty($book['p' . ($depth + 1)])) {
      $book_nids[] = $book['p' . $depth];
      $depth++;
    }*/
    $depth = 1;
    $trail = [];
    if (count($parent_nodes) > 0) {
      foreach ($parent_nodes as $parent_node) {
        $trail[$parent_node->id()] = [];
        $this->recursiveParents($parent_node, $trail[$parent_node->id()]);
        $access = $parent_node->access('view', $this->account, TRUE);
        $breadcrumb->addCacheableDependency($access);
        if ($access->isAllowed()) {
          $breadcrumb->addCacheableDependency($parent_node);
          $links[] = Link::createFromRoute($parent_node->label(),
            'entity.node.canonical', ['node' => $parent_node->id()]);
        }
      }
    }
      $breadcrumb->setLinks($links);
      $breadcrumb->addCacheContexts(['route']);
      return $breadcrumb;

  }


    protected function recursiveParents($node, &$trail = []) {
      if ($node->field_sbf_nodetonode instanceof EntityReferenceFieldItemListInterface) {
        foreach($node->field_sbf_nodetonode->referencedEntities() as $referencedEntity) {
          if (!isset($trail["{$node->id()}"]["{$referencedEntity->id()}"])) {
            $this->recursiveParents($referencedEntity, $trail);
            $trail["{$node->id()}"]["{$referencedEntity->id()}"] = Link::createFromRoute($referencedEntity->label(),
              'entity.node.canonical', ['node' => $referencedEntity->id()]);
          }
        }
      }
}

}
