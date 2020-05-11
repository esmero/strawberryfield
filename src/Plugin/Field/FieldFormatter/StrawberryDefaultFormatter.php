<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Simplistic Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_default_formatter",
 *   label = @Translation("Strawberry Default Formatter"),
 *   class = "\Drupal\strawberryfield\Plugin\Field\FieldFormatter\StrawberryDefaultFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "plain_text"
 *   }
 * )
 */
class StrawberryDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Displays JSON');
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'limit_access' => 'edit',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = [];
    $entity = $items->getEntity();
    $access = $entity
      ->access('edit', NULL, TRUE)->isAllowed();

    if ($access) {
      foreach ($items as $delta => $item) {
        // Render each element as markup.
        $element[$delta] = [
          '#type' => 'details',
          '#title' => t('Raw Metadata (JSON)'),
          '#open' => FALSE,
          'json' => [
            '#markup' => json_encode(
              json_decode($item->value, TRUE),
              JSON_PRETTY_PRINT
            ),
            '#prefix' => '<pre>',
            '#suffix' => '</pre>',
          ]
        ];
      }
    }
    return $element;
  }



}