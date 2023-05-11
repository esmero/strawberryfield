<?php

namespace Drupal\strawberryfield;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Render\RendererInterface;

/**
 * Defines a service for #lazy_builder callbacks for strawberryfield module.
 */
class StrawberryfieldLazyBuilders implements TrustedCallbackInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  protected $_excerpts = NULL;

  /**
   * Constructs a new CommentLazyBuilders object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer) {
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }


  public function setExcerpt($cid, $excerpt) {
    $this->_excerpts[$cid] = $excerpt;
  }

  public function getExcerpt($cid) {
    return $this->_excerpts[$cid] ?? NULL;
  }


  /**
   * #lazy_builder callback; builds the comment form.
   *
   * @param string $cid
   *   The Item result ID from Search API results..
   * @param string $extra_tag
   *   Additional cache tag
   *
   * @return array
   *   A renderable array containing excerpt.
   */
  public function renderExcerpt(string $cid, string $extra_tag) {

    if ($excerpt = ($this->_excerpts[$cid] ?? NULL)) {
      //@TODO add node:node_id as tag? Could be passed as an argument
      return [
        '#type' => 'markup',
        '#markup' => $this->_excerpts[$cid],
        '#cache' => [
          'contexts' => ['url.query_args','user.node_grants:view'],
          'tags' => ['search_api_index_list',$extra_tag],
        ]
      ];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['renderExcerpt'];
  }

}
