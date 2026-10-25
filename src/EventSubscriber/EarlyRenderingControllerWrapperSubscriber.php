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

  public function __construct(protected $originalService) {
    parent::__construct($this->originalService->argumentResolver, $this->originalService->renderer);
  }

  protected function wrapControllerExecutionInRenderContext($controller, array $arguments) {
    $class = NULL;
    if (is_array($controller) && !empty($controller[0]) && is_object($controller[0])) {
      $class = $controller[0]::class;
    }
    elseif (is_object($controller)) {
      $class = $controller::class;
    }

    if ($class === \Drupal\jsonapi\Controller\EntityResource::class){
      $context = new RenderContext();
      $response = $this->renderer->executeInRenderContext($context, fn() => call_user_func_array($controller, $arguments));
      if (!$context->isEmpty() && $response instanceof CacheableResponseInterface) {
        $response->addCacheableDependency($context->pop());
      }
      return $response;
    }
    return $this->originalService->wrapControllerExecutionInRenderContext($controller, $arguments);
  }
}


