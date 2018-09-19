<?php
namespace Drupal\strawberryfield\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\strawberryfield\Tools\StrawberryKeyNameProvider;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Provides a field type of strawberryfield.
 *
 * @FieldType(
 *   id = "strawberryfield_field",
 *   label = @Translation("Strawberry Field"),
 *   module = "strawberryfield",
 *   default_formatter = "strawberry_default_formatter",
 *   default_widget = "string_textarea",
 *   constraints = {"valid_strawberry_json" = {}}
 *   category = "GLAM Metadata",
 * )
 */
 class StrawberryFieldItem extends FieldItemBase  {

   /**
    * An array of values for the contained properties.
    *
    * @var array
    */
   protected $values = [];

   /**
    * The array of properties.
    *
    * @var \Drupal\Core\TypedData\TypedDataInterface[]
    */
   protected $properties = [];

   /**
    * @param FieldStorageDefinitionInterface $field_definition
    * @return array
    */
   public static function schema(FieldStorageDefinitionInterface $field_definition)
   {
     return array(
       'columns' => array(
         'value' => array(
           'type' => 'json',
           'pgsql_type' => 'json',
           'mysql_type' => 'json',
           'not null' => FALSE,
         ),
       ),
     );
   }

   /**
    * @param FieldStorageDefinitionInterface $field_definition
    * @return \Drupal\Core\TypedData\DataDefinitionInterface[]|mixed
    */
   public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {

     // @TODO mapDataDefinition() is the next step.
     $properties['value'] = DataDefinition::create('string')
       ->setLabel(t('JSON String'))
       ->setRequired(TRUE);

     // @See also https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21TypedData%21OptionsProviderInterface.php/interface/OptionsProviderInterface/8.5.x
     $keynamelist = StrawberryKeyNameProvider::fetchKeyNames();
     foreach ($keynamelist as $keyname) {
       $properties[$keyname] = DataDefinition::create('string')
         ->setLabel($keyname)
         ->setComputed(TRUE)
         ->setClass(
           '\Drupal\strawberryfield\Plugin\DataType\StrawberryDataByKeyProvider'
         )
         ->setInternal(FALSE)
         ->setSetting('jsonkey',$keyname)
         ->setReadOnly(TRUE);
     }
     return $properties;
   }

   /**
    * {@inheritdoc}
    */
   public function isEmpty() {
     $value = parent::isEmpty();
     //@TODO: assume a json with only keys and no values is empty
     return $value === NULL || $value === '';
   }

   /**
    * {@inheritdoc}
    */
   public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
     $random = new Random();
     $values['value'] = '{"label": "' . $random->word(mt_rand(1, 2000)) . '""}';
     return $values;
   }


 }
 
