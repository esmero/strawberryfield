<?php

namespace Drupal\strawberryfield\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Valid JSON constraint.
 *
 * Verifies that input values are valid JSON.
 *
 * @Constraint(
 *   id = "valid_strawberry_json",
 *   label = @Translation("Valid deserializable JSON text", context = "Validation")
 * )
 */
class StrawberryFieldConstraint extends Constraint {

    /**
     * The default violation message.
     *
     * @var string
     */
    //@TODO: Add a link so users can see how a valid JSON should look like.
    public $message = 'The supplied value is not valid JSON representation (@error).';

}