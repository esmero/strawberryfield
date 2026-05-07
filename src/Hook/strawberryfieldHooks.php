<?php

namespace Drupal\strawberryfield\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Render\BubbleableMetadata;


class strawberryfieldHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo(): array {
    $types['ado'] = [
      'name' => t('Archipelago Digital Object'),
      'description' => t('Defines custom tokens for Strawberryfield Bearing Nodes (ADOs).'),
      'needs-data' => 'node'
    ];
    $tokens['type'] = [
      'name' => t('ADO Type'),
      'description' => t('Token to get the main JSON type of an ADO.'),
    ];
    $tokens['types'] = [
      'name' => t('ADO Types'),
      'description' => t('Token to get all JSON types (flattened type key at any hierarchy) of an ADO.'),
      'type' => 'array',
    ];

    $tokens['breadcrumb_by_ado_type'] = [
      'name' => t('ADO Parentship breadcrumb filtered by ADO type'),
      'description' => t('Token to get the complete parent (ADO to ADO) breadcrump, but filtering out any ADO type that is not provided. If multiple trails, the longest is returned'),
      'dynamic' => TRUE,
      'type' => 'array',
    ];
    $tokens['breadcrumb_by_relation'] = [
      'name' => t('ADO Parentship breadcrumb filtered by connecting relationship'),
      'description' => t('Token to get the complete parent (ADO to ADO) breadcrump, but for specific relationships (e.g ismemberof). If multiple trails, the longest is returned'),
      'dynamic' => TRUE,
      'type' => 'array',
    ];
    // Also create an ADO wrapper inside node
    $token_ado['ado'] = [
      'name' => t('ADO'),
      'description' => t('Wrapper on the Node Token so we can call the other tokens when modules only call Core ones'),
      'dynamic' => TRUE,
    ] ;
    return [
      'types' => $types,
      'tokens' => ['ado' => $tokens, 'node' => $token_ado],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    $replacements = [];
    $token_service = \Drupal::token();
    // First check for a Top node:ado... then chain
    if ($type === 'node' && !empty($data['node'])) {
      if ($ado_from_node = $token_service->findWithPrefix($tokens, 'ado')) {
        foreach ($ado_from_node as $passed_from_node => $original) {
          $token_to_call = [$passed_from_node => $original];
          $replacements += $token_service->generate('ado', $token_to_call, $data, $options, $bubbleable_metadata);
        }
      }
    }

    if ($type === 'ado' && !empty($data['node'])) {
      /** @var \Drupal\webform\WebformSubmissionInterface $webform_submission */
      $node = $data['node'];
      $sbf_fields = \Drupal::service('strawberryfield.utility')->bearsStrawberryfield($node);
      if (empty($sbf_fields)) {
        return $replacements;
      }

      foreach ($tokens as $name => $original) {
        switch ($name) {
          case 'type':
            if ($node->hasField('field_sbf_semantictype')) {
              $types = $node->get('field_sbf_semantictype')->getValue();
              if (is_array($types) && count($types)) {
                $type =  reset($types);
                $sbf_type = $type['value'] ?? '';
                $replacements[$original] = $sbf_type;
              }
            }
            break;
          case 'types':
            if ($node->hasField('field_sbf_semantictype')) {
              $types = $node->get('field_sbf_semantictype')->getValue();
              if (is_array($types) && count($types)) {
                $sbf_type = [];
                foreach($types as $type) {
                  $sbf_type[] = $type['value'] ?? NULL;
                }
                $replacements[$original] = $sbf_type;
              }
            }
            break;
        }
      }

      // For chaining "types" into an array.
      if ($types_tokens = $token_service->findWithPrefix($tokens, 'types')) {
        if ($node->hasField('field_sbf_semantictype')) {
          $types = $node->get('field_sbf_semantictype')->getValue();
          if (is_array($types) && count($types)) {
            $sbf_type = [];
            foreach ($types as $type) {
              $sbf_type[] = $type['value'] ?? NULL;
            }
            $replacements += $token_service->generate('array', $types_tokens, ['array' => $sbf_type], $options, $bubbleable_metadata);
          }
        }
      }

      if ($breadcrump_by_ado_type_tokens = $token_service->findWithPrefix($tokens, 'breadcrumb_by_ado_type')) {
        foreach($breadcrump_by_ado_type_tokens as $passed_type_with_suffix => $original) {
          $trail = [];
          // @TODO. this logic works when the token is called with an array operator
          // but will fail if the user forgets.
          $ado_type = explode(":",$passed_type_with_suffix, 2);

          $start_path = bin2hex(random_bytes(16));
          try {
            \Drupal::service('strawberryfield.semantic_breadcrumb')
              ->recursiveParentPathsByTypeAndPredicate($node, $trail, 1, $start_path, [], [$ado_type[0] ?? NULL], $bubbleable_metadata);
            // Now we can have multiple paths here.
            // And because we are filtering each path might have FALSE entries (to keep the length/dept)
            $array_for_token = [];
            $longest = 0;
            foreach ($trail as $apath) {
              $apath = is_array($apath) ? array_filter($apath) : [];
              if (!empty($apath)) {
                if (count($apath) > $longest) {
                  $array_for_token = array_reverse($apath);
                  $longest =  count($apath);
                }
              }
            }
            if (isset($ado_type[1]) && strpos($ado_type[1], ":") !== FALSE) {
              $breadcrump_by_ado_type_token = [$ado_type[1] => $original];
              $replacements += $token_service->generate('array', $breadcrump_by_ado_type_token, ['array' => $array_for_token], $options, $bubbleable_metadata);
            }
            else {
              // if just calling the actual token without any array processing, we return a string representation, the last one
              $ado_label = end($array_for_token);
              if ($ado_label) {
                $replacements[$original] = $ado_label;
              }
            }
          }
          catch (\Throwable $e) {
            \Drupal::logger('strawberryfield')->error(
              'ADO with UUID @uuid failed parsing Token @token <br><ul>@events</ul>',
              [
                '@uuid' => $node->uuid(),
                '@token' => $original
              ]
            );
          }
        }
      }

      if ($breadcrump_by_relationship_tokens = $token_service->findWithPrefix($tokens, 'breadcrumb_by_relation')) {
        foreach($breadcrump_by_relationship_tokens as $passed_rel_with_suffix => $original) {
          $trail = [];
          // @TODO. this logic works when the token is called with an array operator
          // but will fail if the user forgets.
          $rel = explode(":", $passed_rel_with_suffix, 2);
          $start_path = bin2hex(random_bytes(16));
          try {
            \Drupal::service('strawberryfield.semantic_breadcrumb')
              ->recursiveParentPathsByTypeAndPredicate($node, $trail, 1, $start_path, [$rel[0] ?? NULL], [], $bubbleable_metadata);
            // Now we can have multiple paths here.
            // And because we are filtering each path might have FALSE entries (to keep the length/dept)
            $array_for_token = [];
            $longest = 0;
            foreach ($trail as $apath) {
              $apath = is_array($apath) ? array_filter($apath) : [];
              if (!empty($apath)) {
                if (count($apath) > $longest) {
                  $array_for_token = array_reverse($apath);
                  $longest =  count($apath);
                }
              }
            }
            if (isset($rel[1]) && strpos($rel[1], ":") !== FALSE) {
              $breadcrump_by_rel_token = [$rel[1] => $original];
              $replacements += $token_service->generate('array', $breadcrump_by_rel_token, ['array' => $array_for_token], $options, $bubbleable_metadata);
            }
            else {
              // if just calling the actual token without any array processing, we return a string representation, the last one
              $ado_label = end($array_for_token);
              if ($ado_label) {
                $replacements[$original] = $ado_label;
              }
            }
          }
          catch (\Throwable $e) {
            \Drupal::logger('strawberryfield')->error(
              'ADO with UUID @uuid failed parsing Token @token <br><ul>@events</ul>',
              [
                '@uuid' => $node->uuid(),
                '@token' => $original
              ]
            );
          }
        }
      }
    }
    return $replacements;
  }

}