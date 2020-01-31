<?php

namespace Drupal\strawberryfield\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\form_mode_manager\FormModeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines dynamic local tasks.
 */
class StrawberryLocalTasks extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The Form Mode Manager service.
   *
   * @var \Drupal\form_mode_manager\FormModeManagerInterface
   */
  protected $formModeManager;
  
  /**
   * Constructs a new Form Mode ManagerLocalTasks.
   *
   * @param \Drupal\form_mode_manager\FormModeManagerInterface $form_mode_manager
   *   The form mode manager.
   */
  public function __construct(FormModeManagerInterface $form_mode_manager) {
    $this->formModeManager = $form_mode_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('form_mode.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $this->derivatives = [];
    foreach ($this->formModeManager->getFormModesIdByEntity('node') as $id) {
      $this->derivatives["strawberryfield.node.{$id}}.task_tab"] = [
        'route_name' => "entity.node.edit_form.{$id}",
        'title' => $id,
        'parent_id' => "strawberryfield.custom_node_edit"
      ];
    }
    foreach ($this->derivatives as &$entry) {
      $entry += $base_plugin_definition;
    }

    return $this->derivatives;
  }

}