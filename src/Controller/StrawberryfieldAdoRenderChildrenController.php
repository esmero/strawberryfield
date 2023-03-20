<?php

namespace Drupal\strawberryfield\Controller;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Render\Renderer;
use Drupal\views\Views;

/**
 * A Controller for rendering a view of an ADO's children.
 */
class StrawberryfieldAdoRenderChildrenController extends ControllerBase {
  
    /**
   * Renders the contestants page.
   *
   * @return array
   *   The renderable array.
   */
  public function renderAdoChildrenViews() {
    $view_names = ['ado_tools_children', 'ado_tools_children_creative_work_series'];
    $return = [];
    foreach ($view_names as $view_name) {
      $view = Views::getView($view_name);
      if(isset($view)) {
        $view->execute();
        $rendered = $view->render();
        if(!empty($rendered['#rows'])) {
          $output = \Drupal::service('renderer')->render($rendered);
          $markup = ['#markup' => $output];
          array_push($return, $markup);
        }
      }
    }
    return $return;
  }
}