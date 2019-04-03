<?php

namespace Drupal\strawberryfield\Normalizer;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\serialization\Normalizer\FieldItemNormalizer;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Drupal\serialization\Normalizer\EntityReferenceFieldItemNormalizerTrait;

/**
 * Adds the file URI to embedded file entities.
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
    //@TODO json decode and encode into array our strawberryfield
    //@TODO check what options we can get from $context
    $values = parent::normalize($field_item, $format, $context);


    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function constructValue($data, $context) {

    return parent::constructValue($data, $context);
  }

}
