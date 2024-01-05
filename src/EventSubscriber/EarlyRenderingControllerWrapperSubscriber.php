<?php
/**
 * @file
 * @author https://www.drupal.org/u/garphy, Copied under GPL license from
 *         https://www.drupal.org/project/jsonapi_earlyrendering_workaround
 *         for D10 Compatibility reasons.
 */

namespace Drupal\strawberryfield\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\EventSubscriber\EarlyRenderingControllerWrapperSubscriber as OriginalEarlyRenderingControllerWrapperSubscriber;
use Drupal\Core\Render\RenderContext;

class EarlyRenderingControllerWrapperSubscriber extends OriginalEarlyRenderingControllerWrapperSubscriber {

  protected $originalService;

  public function __construct($originalService) {
    $this->originalService = $originalService;
    parent::__construct($this->originalService->argumentResolver, $this->originalService->renderer);
  }

  protected function wrapControllerExecutionInRenderContext($controller, array $arguments) {
    $class = NULL;
    if (is_array($controller) && !empty($controller[0]) && is_object($controller[0])) {
      $class = get_class($controller[0]);
    }
    elseif (is_object($controller)) {
      $class = get_class($controller);
    }

    if ($class === "Drupal\jsonapi\Controller\EntityResource"){
      $context = new RenderContext();
      $response = $this->renderer->executeInRenderContext($context, function () use ($controller, $arguments) {
        return call_user_func_array($controller, $arguments);
      });
      if (!$context->isEmpty() && $response instanceof CacheableResponseInterface) {
        $response->addCacheableDependency($context->pop());
      }
      return $response;
    }
    return $this->originalService->wrapControllerExecutionInRenderContext($controller, $arguments);
  }
}


