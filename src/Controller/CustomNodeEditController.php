<?php

namespace Drupal\strawberryfield\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class CustomNodeEditController.
 *
 * @package strawberryfield
 */
class CustomNodeEditController extends ControllerBase {

  /**
   * The EntityDisplayRepository service.
   *
   * @var \Drupal\core\Entity\EntityDisplayRepository
   */
  protected $entityDisplayRepository;

  /**
   * Constructs a new CustomNodeEditLocalTasks.
   *
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The EntityDisplayRepository service.
   */
  public function __construct(EntityDisplayRepositoryInterface $entity_display_repository) {
    $this->entityDisplayRepository = $entity_display_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_display.repository')
    );
  }

  /**
   * Returns form modes active for current user.
   *
   * @return array
   *   The list of active modes for the current user.
   */
  public function getActiveNodeFormModes(NodeInterface $node) {
    $formModes = $this->entityDisplayRepository->getFormModeOptionsByBundle('node', $node->bundle());
    return array_filter($formModes, function ($mode) {
      return $this->currentUser()->hasPermission("use node.{$mode} form mode");
    }, ARRAY_FILTER_USE_KEY);
  }

  /**
   * Allows access if user has access to a form mode but not the default one.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(NodeInterface $node) {
    $accessDefaultFormMode = $this->currentUser()
      ->hasPermission('use node.default form mode');
    $accessAnyFormMode = count($this->getActiveNodeFormModes($node)) > 0;
    return AccessResult::allowedIf(!$accessDefaultFormMode && $accessAnyFormMode);
  }

  /**
   * Sends redirect response to first active form mode.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node parameter from route.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The form mode, 404 if not found.
   */
  public function redirectToForm(NodeInterface $node) {
    $firstActiveFormMode = key($this->getActiveNodeFormModes($node));
    if ($firstActiveFormMode) {
      return $this->redirect("entity.node.edit_form.{$firstActiveFormMode}", ['node' => $node->id()]);
    }
    else {
      // This shouldn't happen, because access check has already concluded there
      // are active form modes. But just in case.
      throw new NotFoundHttpException();
    }
  }

}
