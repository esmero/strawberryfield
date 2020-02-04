<?php

namespace Drupal\strawberryfield\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CustomNodeEditController extends ControllerBase {

  /**
   * The EntityDisplayRepository service.
   *
   * @var \Drupal\core\Entity\EntityDisplayRepository $entityDisplayRepository
   *
   */
  protected $entityDisplayRepository;

  /**
   * Node form modes active for this current user
   *
   * @var array
   *
   */
  protected $activeNodeFormModes;
  
  /**
   * Constructs a new CustomNodeEditLocalTasks
   *
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *
   */
  public function __construct(EntityDisplayRepositoryInterface $entity_display_repository) {
    $this->entityDisplayRepository = $entity_display_repository;
    $this->activeNodeFormModes = $this->getActiveNodeFormModes();
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
   * Returns form modes active for current user
   *
   * @return array 
   *
   */
  public function getActiveNodeFormModes() {
    $activeFormModes = [];
    // "default" form mode is not returned from getFormModes(). Only named form modes.
    $formModes = $this->entityDisplayRepository->getFormModes('node');
    // Check which form modes user has access to
    foreach ($formModes as $key => $value) {
      if ($this->currentUser()->hasPermission("use {$value['id']} form mode")) {
        $activeFormModes[$key] = $value;
      }
    }
    
    return $activeFormModes;
  }


  /**
  * Grants access if user has access to a named form mode, but not default form mode.
  *
  * @param \Drupal\Core\Session\AccountInterface $account
  *   Run access checks for this account.
  *
  * @return \Drupal\Core\Access\AccessResultInterface
  *   The access result.
  */
  public function access(AccountInterface $account) {
    $accessDefaultFormMode = $account->hasPermission('use default form mode');
    $accessAnyFormMode = count($this->activeNodeFormModes) > 0;
    
    return AccessResult::allowedIf(!$accessDefaultFormMode && $accessAnyFormMode);
  }

  /**
   * Sends redirect response to first active form mode
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node parameter from route
   *
   * @return RedirectResponse
   */
  public function redirectToForm(NodeInterface $node) {
    // select one active form mode
    $firstActiveFormMode = key($this->activeNodeFormModes);
    if ($firstActiveFormMode) {
      return $this->redirect("entity.node.edit_form.{$firstActiveFormMode}", ["node" => $node->id()]);
    } else {
      // This shouldn't happen, because access check has already concluded there are active form modes.
      // But just in case.
      throw new NotFoundHttpException();
    }
  }
  
}
