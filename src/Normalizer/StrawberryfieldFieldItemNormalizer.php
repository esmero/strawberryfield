<?php

namespace Drupal\strawberryfield\Normalizer;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\serialization\Normalizer\FieldItemNormalizer;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Drupal\serialization\Normalizer\EntityReferenceFieldItemNormalizerTrait;
use Drupal\Core\TypedData\TypedDataInternalPropertiesHelper;

/**
 * Normalizes StrawberryfieldFieldItem values and expands to full JSON
 */
class StrawberryfieldFieldItemNormalizer extends FieldItemNormalizer {

  use EntityReferenceFieldItemNormalizerTrait;

  /**
   * The interface or class that this Normalizer supports.
   *
   * @var string
   */
  protected $supportedInterfaceOrClass = StrawberryFieldItem::class;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Constructs a EntityReferenceFieldItemNormalizer object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(EntityRepositoryInterface $entity_repository) {
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($field_item, $format = NULL, array $context = []) {
    //@TODO check what options we can get from $context

    //@TODO allow per Field instance to limit which prop is internal or external
    //@TODO do the inverse, a denormalizer for 'value' to allow API ingests
    // Only do this because parent implementation can change.
    $values = parent::normalize($field_item, $format , $context);
    $mainproperty = $field_item->mainPropertyName();
    // Now get the mainPropertyName and decode
    if ((isset($values[$mainproperty])) && (!empty($values[$mainproperty])) || $values[$mainproperty]!='') {
      $values[$mainproperty] = $this->serializer->decode($values[$mainproperty], 'json');
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function constructValue($data, $context) {

    return parent::constructValue($data, $context);
  }

}
