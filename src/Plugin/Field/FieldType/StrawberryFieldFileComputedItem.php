<?php

namespace Drupal\strawberryfield\Plugin\Field\FieldType;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of a virtual 'file' field type.
 *
 * @FieldType(
 *   id = "strawberryfieldfile_field",
 *   label = @Translation("Virtual Strawberry Field"),
 *   description = @Translation("This field accepts the ID of a file as an integer value and writes it into a Strawberry Field."),
 *   category = @Translation("Reference"),
 *   default_widget = "file_generic",
 *   default_formatter = "file_default",
 *   list_class = "\Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldFileComputedItemList",
 *   constraints = {"ReferenceAccess" = {}, "FileValidation" = {}}
 * )
 */
class StrawberryFieldFileComputedItem extends EntityReferenceItem {
  // @see \Drupal\file\Plugin\Field\FieldType\FileItem

  protected $isCalculated = FALSE;

  /**
   * {@inheritdoc}
   */
  public function __get($name) {
    $this->ensureCalculated();
    return parent::__get($name);
  }
  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $this->ensureCalculated();
    return parent::isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    $this->ensureCalculated();
    return parent::getValue();
  }


  /**
   * Calculates the value of the field and sets it.
   */
  protected function ensureCalculated() {
    if (!$this->isCalculated) {
      $entity = $this->getEntity();
      if (!$entity->isNew()) {
        $sbf_parent_field =  $this->getSetting('sbf_field');
        $random = new Random();

        $values = [
          'target_id' => 1,
          'display' => FALSE,
          'description' => $random->sentences(10),
        ];
        error_log('setting value!');
        $this->setValue($values);
      }
      $this->isCalculated = TRUE;
    }
  }
  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    return [
        'target_type' => 'file',
        'display_field' => FALSE,
        'display_default' => FALSE,
        'uri_scheme' => file_default_scheme(),
      ] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
        'file_extensions' => '',
        'file_directory' => 'sbf_temp',
        'max_filesize' => '',
        'description_field' => 0,
      ] + parent::defaultFieldSettings();
  }


  /**
   * Determines the URI for a file field.
   *
   * @param array $data
   *   An array of token objects to pass to Token::replace().
   *
   * @return string
   *   An unsanitized file directory URI with tokens replaced. The result of
   *   the token replacement is then converted to plain text and returned.
   *
   * @see \Drupal\Core\Utility\Token::replace()
   */
  public function getUploadLocation($data = []) {
    return static::doGetUploadLocation($this->getSettings(), $data);
  }

  /**
   * Determines the URI for a file field.
   *
   * @param array $settings
   *   The array of field settings.
   * @param array $data
   *   An array of token objects to pass to Token::replace().
   *
   * @return string
   *   An unsanitized file directory URI with tokens replaced. The result of
   *   the token replacement is then converted to plain text and returned.
   *
   * @see \Drupal\Core\Utility\Token::replace()
   */
  protected static function doGetUploadLocation(array $settings, $data = []) {
    $destination = trim($settings['file_directory'], '/');

    // Replace tokens. As the tokens might contain HTML we convert it to plain
    // text.
    $destination = PlainTextOutput::renderFromHtml(\Drupal::token()->replace($destination, $data));
    return $settings['uri_scheme'] . '://' . $destination;
  }

  /**
   * Retrieves the upload validators for a file field.
   *
   * @return array
   *   An array suitable for passing to file_save_upload() or the file field
   *   element's '#upload_validators' property.
   */
  public function getUploadValidators() {
    $validators = [];
    $settings = $this->getSettings();

    // Cap the upload size according to the PHP limit.
    $max_filesize = Bytes::toInt(file_upload_max_size());
    if (!empty($settings['max_filesize'])) {
      $max_filesize = min($max_filesize, Bytes::toInt($settings['max_filesize']));
    }

    // There is always a file size limit due to the PHP server limit.
    $validators['file_validate_size'] = [$max_filesize];

    // Add the extension check if necessary.
    if (!empty($settings['file_extensions'])) {
      $validators['file_validate_extensions'] = [$settings['file_extensions']];
    }

    return $validators;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $random = new Random();
    $settings = $field_definition->getSettings();

    // Prepare destination.
    $dirname = static::doGetUploadLocation($settings);
    file_prepare_directory($dirname, FILE_CREATE_DIRECTORY);

    // Generate a file entity.
    $destination = $dirname . '/' . $random->name(10, TRUE) . '.txt';
    $data = $random->paragraphs(3);
    $file = file_save_data($data, $destination, FILE_EXISTS_ERROR);
    $values = [
      'target_id' => $file->id(),
      'display' => (int) $settings['display_default'],
      'description' => $random->sentences(10),
    ];
    return $values;
  }

  /**
   * Determines whether an item should be displayed when rendering the field.
   *
   * @return bool
   *   FALSE always since this field is a drop box.
   */
  public function isDisplayed() {

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function getPreconfiguredOptions() {
    return [];
  }

}
