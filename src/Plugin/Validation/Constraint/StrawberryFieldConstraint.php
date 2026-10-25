<?php

namespace Drupal\strawberryfield\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Valid JSON constraint.
 *
 * Verifies that input values are valid JSON.
 */
#[Constraint(
  id: 'valid_strawberry_json',
  label: new TranslatableMarkup('Valid deserializable JSON text', options: ['context' => 'Validation']
  )
)]
class StrawberryFieldConstraint extends SymfonyConstraint {

    /**
     * The default violation message.
     *
     * @var string
     */
    //@TODO: Add a link so users can see how a valid JSON should look like.
    public $message = 'The supplied value is not valid JSON representation (@error).';

}