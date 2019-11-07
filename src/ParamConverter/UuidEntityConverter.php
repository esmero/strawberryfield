<?php
/**
 * @file
 * Contains UuidEntityConverter.php
 *
 * @author Diego Pino Navarro <dpino@metro.org> https://github.com/diegopino
 */

namespace Drupal\strawberryfield\ParamConverter;


use Drupal\Core\ParamConverter\EntityConverter;
use Symfony\Component\Routing\Route;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Entity\EntityRepositoryInterface;

/**
 * Converts an UUID into a valid Content Entity.
 *
 * @ingroup strawberryfield
 */
class UuidEntityConverter extends EntityConverter {


  /**
   * @inheritDoc
   */
  public function convert($value, $definition, $name, array $defaults) {

    $entity_type_id = $this->getEntityTypeFromDefaults(
      $definition,
      $name,
      $defaults
    );
    $uuid_key = $this->entityTypeManager->getDefinition($entity_type_id)
      ->getKey('uuid');

    if ($storage = $this->entityTypeManager->getStorage($entity_type_id)) {
      if (!$entities = $storage->loadByProperties([$uuid_key => $value])) {
        return NULL;
      }
      // get the actualentity and deal with ID thing afterwards so we can deal with revisions which have
      // no UUID. See
      /* @var \Drupal\Core\Entity\EntityInterface $entity */
      $entity = reset($entities);

      // If the entity type is revisionable and the parameter has the
      // "load_latest_revision" flag, load the active variant.
      if (!empty($definition['load_latest_revision'])) {
        return $this->entityRepository->getActive(
          $entity_type_id,
          $entity->id()
        );
      }

      // Do not inject the context repository as it is not an actual dependency:
      // it will be removed once both the TODOs below are fixed.
      /** @var \Drupal\Core\Plugin\Context\ContextRepositoryInterface $contexts_repository */
      $contexts_repository = \Drupal::service('context.repository');
      // @todo Consider deprecating the legacy context operation altogether in
      //   https://www.drupal.org/node/3031124.
      $contexts = $contexts_repository->getAvailableContexts();
      $contexts[EntityRepositoryInterface::CONTEXT_ID_LEGACY_CONTEXT_OPERATION] =
        new Context(new ContextDefinition('string'), 'entity_upcast');
      // @todo At the moment we do not need the current user context, which is
      //   triggering some test failures. We can remove these lines once
      //   https://www.drupal.org/node/2934192 is fixed.
      $context_id = '@user.current_user_context:current_user';
      if (isset($contexts[$context_id])) {
        $account = $contexts[$context_id]->getContextValue();
        unset($account->_skipProtectedUserFieldConstraint);
        unset($contexts[$context_id]);
      }
      $entity = $this->entityRepository->getCanonical(
        $entity_type_id,
        $entity->id(),
        $contexts
      );

      return $entity;

    }

  }


  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {

    $route_parameters = $route->getOption('parameters');
    $is_ado = (isset($route_parameters['resource_type']['type']) && $route_parameters['resource_type']['type'] == 'ado') ? TRUE : FALSE;

    return (
      !empty($definition['type']) && strpos(
        $definition['type'],
        'entity:node'
      ) === 0 && $is_ado
    );
  }

}