<?php

namespace Drupal\strawberryfield;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a listing of Strawberry Key Name Provider entities.
 */
class keyNameProviderEntityListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Strawberry Key Name Providers');
    $header['id'] = $this->t('Machine name');
    $header['active'] = $this->t('Is active ?');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['active'] = $entity->isActive() ? $this->t('Yes') : $this->t('No');
    return $row + parent::buildRow($entity);
  }

}
