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
use Drupal\Core\Session\AccountInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Entity\ContentEntityBase;

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
    $account = $this->prepareUser();

    // Only enforce owner permissions here if the entity has an owner
    $entity = $items->getEntity();
    $access = FALSE;

    //@TODO make this its own Method and reuse in every Formatter.
    if ($entity instanceof EntityOwnerInterface) {
      /* @var ContentEntityBase $entity */
      // Check if the entity can have an owner?

      if (($account->id() == $entity->getOwner()->id()) &&
        $account->hasPermission('view own Raw Strawberryfield')) {
        $access = TRUE;
      }
      elseif ($account->hasPermission('view any Raw Strawberryfield')) {
        $access = TRUE;
      }
    }
    else {
      // Users with Edit access can always see this?
      $access = $entity
        ->access('edit', NULL, TRUE)->isAllowed();
    }

    if ($access || $account->hasPermission('bypass node access')) {
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
          ],
        ];
      }
    }
    return $element;
  }

  /**
  * Loads the current account object, if it does not exist yet.
  *
  * @param \Drupal\Core\Session\AccountInterface $account
  *   The account interface instance.
  *
  * @return \Drupal\Core\Session\AccountInterface
  *   Returns the current account object.
  */
  protected function prepareUser(AccountInterface $account = NULL) {
    if (!$account) {
      $account = \Drupal::currentUser();
    }
    return $account;
  }



}