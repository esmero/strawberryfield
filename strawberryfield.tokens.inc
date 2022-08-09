<?php

/**
 * @file
 * Builds placeholder replacement tokens for Strawberryfield Computed Properties.
 */

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Language\LanguageInterface;

/**
 * Implements hook_token_info().
 */
function strawberryfield_token_info() {
  $type_info = \Drupal::service('plugin.manager.field.field_type')
    ->getDefinitions();
  $info = [];
  // Attach field tokens to their respective entity tokens.
  foreach (\Drupal::entityTypeManager()->getDefinitions() as $entity_type_id => $entity_type) {
    if (!$entity_type->entityClassImplements(ContentEntityInterface::class)) {
      continue;
    }
    // Make sure a token type exists for this entity.
    $token_type = \Drupal::service('token.entity_mapper')
      ->getTokenTypeForEntityType($entity_type_id);
    if (empty($token_type)) {
      continue;
    }

    $fields = [];

    $fields = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions($entity_type_id);
    foreach (\Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id) as $bundle_name => $bundle) {
     $fields += \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type_id, $bundle_name);
    }

    foreach ($fields as $field_name => $field) {
      if ($field->getType() !== "strawberryfield_field") {
        continue;
      }

      // Do not define the token type if the field has no properties.
      if (!$field->getPropertyDefinitions()) {
        continue;
      }

      // Generate a description for the token.
      $labels = _token_field_label($entity_type_id, $field_name);
      $label = array_shift($labels);
      $params['@type'] = $type_info[$field->getType()]['label'];
      /*if (!empty($labels)) {
        $params['%labels'] = implode(', ', $labels);
        $description = t('@type field. Also known by friends and family as %labels.', $params);
      }
      else {
        $description = t('@type field for SBF', $params);
      }*/

      //$cardinality = $field->getCardinality();
      //$cardinality = ($cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED || $cardinality > 3) ? 3 : $cardinality;
      $field_token_name = $token_type . '-' . $field_name;
      /* $info['tokens'][$token_type][$field_name] = [
        'name' => Html::escape($label),
        'description' => $description,
        'module' => 'strawberryfield',
        // For multivalue fields the field token is a list type.
        'type' => $cardinality > 1 ? "list<$field_token_name>" : $field_token_name,
      ];

      // Field token type.
      $info['types'][$field_token_name] = [
        'name' => Html::escape($label),
        'description' => t('@label tokens.', ['@label' => Html::escape($label)]),
        'needs-data' => $field_token_name,
        'nested' => TRUE,
      ];
      // Field list token type.
      if ($cardinality > 1) {
        $info['types']["list<$field_token_name>"] = [
          'name' => t('List of @type values', ['@type' => Html::escape($label)]),
          'description' => t('Tokens for lists of @type values.', ['@type' => Html::escape($label)]),
          'needs-data' => "list<$field_token_name>",
          'nested' => TRUE,
        ];
      }

      // Show a different token for each field delta.
      if ($cardinality > 1) {
        for ($delta = 0; $delta < $cardinality; $delta++) {
          $info['tokens']["list<$field_token_name>"][$delta] = [
            'name' => t('@type type with delta @delta', ['@type' => Html::escape($label), '@delta' => $delta]),
            'module' => 'strawberryfield',
            'type' => $field_token_name,
          ];
        }
      }
      */

      // Property tokens.
      foreach ($field->getPropertyDefinitions() as $property => $property_definition) {
        $transliterated = \Drupal::transliteration()->transliterate($property, LanguageInterface::LANGCODE_DEFAULT, '_');
        $transliterated = mb_strtolower($transliterated);
        $transliterated = preg_replace('@[^a-z0-9_.]+@', '_', $transliterated);

        if (is_subclass_of($property_definition->getClass(), '\Drupal\Core\TypedData\Plugin\DataType\ItemList')) {
          $info['tokens'][$field_token_name][$transliterated] = [
            'name' => $property_definition->getLabel(),
            'description' => $property_definition->getDescription(),
            'module' => 'strawberryfield',
            'type' => 'array'
          ];
        }
        elseif (($property_definition instanceof DataReferenceDefinitionInterface) && ($property_definition->getTargetDefinition() instanceof EntityDataDefinitionInterface)) {
          $transliterated = \Drupal::transliteration()->transliterate($property, LanguageInterface::LANGCODE_DEFAULT, '_');
          $transliterated = mb_strtolower($transliterated);
          $transliterated = preg_replace('@[^a-z0-9_.]+@', '_', $transliterated);
          $referenced_entity_type = $property_definition->getTargetDefinition()->getEntityTypeId();
          $referenced_token_type = \Drupal::service('token.entity_mapper')->getTokenTypeForEntityType($referenced_entity_type);
          $info['tokens'][$field_token_name][$transliterated] = [
            'name' => $property_definition->getLabel(),
            'description' => $property_definition->getDescription(),
            'module' => 'strawberryfield',
            'type' => $referenced_token_type,
          ];
        }
      }
    }
  }
  return $info;
}