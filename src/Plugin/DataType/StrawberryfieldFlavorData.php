<?php

namespace Drupal\strawberryfield\Plugin\DataType;

use Drupal\Core\TypedData\Plugin\DataType\Map;


/**
 * Defines the "StrawberryfieldFlavorData" data type.
 *
 * @DataType(
 *  id = "strawberryfield_flavor_data",
 *  label = @Translation("Strawberryfield Flavor Data"),
 *  definition_class = "\Drupal\strawberryfield\TypedData\StrawberryfieldFlavorDataDefinition",
 * )
 */
class StrawberryfieldFlavorData extends Map {}
