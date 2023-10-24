<?php

namespace Drupal\strawberryfield\Normalizer;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\serialization\Normalizer\FieldItemNormalizer;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Drupal\serialization\Normalizer\EntityReferenceFieldItemNormalizerTrait;
use Drupal\Core\TypedData\TypedDataInternalPropertiesHelper;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;


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
  public function normalize($object, $format = NULL, array $context = []) {
    //@TODO check what options we can get from $context
    //@TODO allow per Field instance to limit which prop is internal or external
    // Only do this because parent implementation can change.
    $values = parent::normalize($object, $format , $context);

    //  Get the main property and decode
    $mainproperty = $object->mainPropertyName();
    if ((isset($values[$mainproperty])) && (!empty($values[$mainproperty])) || $values[$mainproperty]!='') {
      $values[$mainproperty] = $this->serializer->decode($values[$mainproperty], 'json');
    }

    return $values;
  }


  public function denormalize($data, $class, $format = NULL, array $context = []):mixed {
    if (!isset($context['target_instance'])) {
      throw new InvalidArgumentException('$context[\'target_instance\'] must be set to denormalize with the FieldItemNormalizer');
    }

    if ($context['target_instance']->getParent() == NULL) {
      throw new InvalidArgumentException('The field item passed in via $context[\'target_instance\'] must have a parent set.');
    }

    /** @var \Drupal\Core\Field\FieldItemInterface $field_item */
    $field_item = $context['target_instance'];
    $this->checkForSerializedStrings($data, $class, $field_item);

    // Set each key in the field_item with its constructed value
    // At this point all values will be encoded JSON strings
    $constructedValue = $this->constructValue($data, $context);
    foreach ($constructedValue as $key => $value) {
      $field_item->set($key, $value);
    }

    return $field_item;
  }

  /**
   * Encodes the $data items that aren't already strings or computed/readOnly properties
   *
   * @param mixed $data
   * @param array $context
   *
   * @return array|mixed
   */
  protected function constructValue($data, $context) {
    // Encode individually, otherwise 'value' gets nested and breaks the SBF
    $individualEncodedValues = [];

    $data_definition = $context['target_instance']->getDataDefinition();

    foreach ($data as $key => $value ) {
      // Don't bother with properties that were defined computed or readOnly in StrawberryFieldItem data definition
      // These values won't be returned to denormalize() and won't be set
      if ($data_definition->getPropertyDefinition($key)->isComputed() || $data_definition->getPropertyDefinition($key)->isReadOnly()) {
        continue;
      }

      $isJsonString = StrawberryfieldJsonHelper::isJsonString($value);
      if ($isJsonString) {
        $encoded = $value;
      } else {
        $encoded = $this->serializer->encode($value, 'json');
      }
      $individualEncodedValues[$key] = $encoded;
    }

    return $individualEncodedValues;
  }

}
