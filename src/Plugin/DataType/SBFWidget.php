<?php

namespace Drupal\strawberryfield\Plugin\DataType;

use Drupal\Core\TypedData\Plugin\DataType\Map;

/**
 * Defines the "SBFWidget" data type.
 *
 * @DataType(
 *  id = "strawberryfield_test_widget",
 *  label = @Translation("SBFWidget"),
 *  definition_class = "\Drupal\strawberryfield\TypedData\SBFWidgetDefinition"
 * )
 */
class SBFWidget extends Map {}
