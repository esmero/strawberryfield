<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/28/18
 * Time: 2:04 PM
 */

namespace Drupal\strawberryfield\Plugin\EntityReferenceSelection;
use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\Plugin\EntityReferenceSelection\NodeSelection;

/**
 * Node with SBF plugin implementation of the Entity Reference Selection plugin.
 */
#[EntityReferenceSelection(
  id: 'default:nodewithstrawberry',
  label: new TranslatableMarkup('Node with StrawberryField selection'),
  entity_types: ['node'],
  group: 'default',
  weight: 3)
]
class NodeBearingStrawberryfieldSelection extends NodeSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {

    $query = parent::buildEntityQuery($match, $match_operator);
    $handler_settings = $this->configuration['handler_settings'] ?? [];
    if (!isset($handler_settings['filter'])) {
      return $query;
    }
    $strawberryfields = \Drupal::service('entity_field.manager')->getFieldMapByFieldType('strawberryfield_field');

    $filter_settings = $strawberryfields['node'] ?? [];
    foreach ($filter_settings as $field_name => $value) {
      $query->condition($field_name, 'NULL', 'IS NOT NULL');
    }
    return $query;
  }
}
