<?php

namespace Drupal\strawberryfield\Plugin\Menu;

use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Routing\RouteMatchInterface;

class CustomEditTab extends LocalTaskDefault {

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters(RouteMatchInterface $route_match) {
    $node_id = $route_match->getParameter('node')->id();
    
    return [
       'node' => $node_id
    ];
  }
}