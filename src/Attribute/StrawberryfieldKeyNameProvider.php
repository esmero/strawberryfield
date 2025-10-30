<?php

declare(strict_types=1);

namespace Drupal\strawberryfield\Attribute;
use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a StrawberryfieldKeyNameProvider item Attribute object.
 *
 * Class StrawberryfieldKeyNameProvider
 *
 * @ingroup strawberry_field
 *
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class StrawberryfieldKeyNameProvider extends Plugin {

  /**
   * Constructs a StrawberryfieldKeyNameProvider attribute.
   *
   * @param string $id
   *   The StrawberryfieldKeyNameProvider Plugin  ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The human-readable name of the Key Name Provider Plugin.
   * @param class-string $processor_class
   *   Use to define which class will process the data from the JSON.
   *   Example: \Drupal\strawberryfield\Plugin\DataType\StrawberryValuesFromJson
   * @param string $item_type
   *   Use to define of which data type each value will be.
   *   Example: \Drupal\strawberryfield\Plugin\DataType\StrawberryValuesFromJson
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *    A human-readable description of the Key Name Provider Plugin.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly string $processor_class,
    public readonly string $item_type,
    public readonly ?TranslatableMarkup $description = NULL,
  ) {}
}