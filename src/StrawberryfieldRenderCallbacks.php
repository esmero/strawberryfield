<?php
declare(strict_types=1);

namespace Drupal\strawberryfield;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\views\Exception\ViewRenderElementException;
use Drupal\views\Views;

/**
 * Defines a class for render callbacks.
 *
 * @internal
 */
final class StrawberryfieldRenderCallbacks implements TrustedCallbackInterface
{

  /**
   * #pre_render callback to alter views session starting crazyness during an index operation.
   */
  public static function preRender($build) {
    if (\Drupal::service('strawberryfield.search_api_state_helper')->isIndexing()) {
      // Initialize the exposed filters so Views won't start the session.
      // @see \Drupal\views\ViewExecutable::getExposedInput()
      /** @var \Drupal\views\ViewExecutable|null $view */

      if (!isset($build['#view'])) {
        $view = Views::getView($build['#name']);
        if (!$view) {
          throw new ViewRenderElementException("Invalid View name ({$build['#name']}) given.");
        }
        $build['#view'] = $view;
      }
      $view = $build['#view'] ?? NULL;
      if ($view) {
        $view->initDisplay();
        $view->setExposedInput(['' => '']);
        // Disable render caching.
        // $build['#cache']['max-age'] = 0;
      }
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender'];
  }


}
