<?php
namespace Drupal\strawberryfield\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\strawberryfield\Entity\keyNameProviderEntity;
use Drupal\Component\Utility\Random;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;

/**
 * Provides a field type of strawberryfield.
 *
 * @FieldType(
 *   id = "strawberryfield_field",
 *   label = @Translation("Strawberry Field"),
 *   module = "strawberryfield",
 *   default_formatter = "strawberry_default_formatter",
 *   default_widget = "strawberry_textarea",
 *   constraints = {"valid_strawberry_json" = {}},
 *   list_class = "\Drupal\strawberryfield\Field\StrawberryFieldItemList",
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
    * A flattened JSON array.
    *
    * This is computed once so other properties can use it.
    *
    * @var array|null
    */
   protected $flattenjson = NULL;

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


     $reserverd_keys = [
       'value',
       'str_flatten_keys',
     ];

     // @TODO mapDataDefinition() is the next step.
     $properties['value'] = DataDefinition::create('string')
       ->setLabel(t('JSON String'))
       ->setRequired(TRUE);

     // @See also https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21TypedData%21OptionsProviderInterface.php/interface/OptionsProviderInterface/8.5.x

     // All properties as Property keys.
     // Handy when dealing with Field formatters
     $properties['str_flatten_keys'] = ListDataDefinition::create('string')
       ->setLabel('JSON keys defined in this field')
       ->setComputed(TRUE)
       ->setClass(
         '\Drupal\strawberryfield\Plugin\DataType\StrawberryKeysFromJson'
       )
       ->setInternal(FALSE)
       ->setReadOnly(TRUE);


     $keynamelist = [];

     $plugin_config_entities = \Drupal::EntityTypeManager()->getListBuilder('strawberry_keynameprovider')->load();
     if (count($plugin_config_entities))  {
       /* @var keyNameProviderEntity[] $plugin_config_entities */
       foreach($plugin_config_entities as $plugin_config_entity) {
         if ($plugin_config_entity->isActive()) {
           $entity_id = $plugin_config_entity->id();
           $configuration_options = $plugin_config_entity->getPluginconfig();
           // This argument is used when buildin the cid for the plugin internal cache.
           $configuration_options['configEntity'] = $entity_id ;
           /* @var \Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderInterface $plugin_instance */
           $plugin_instance = \Drupal::service('strawberryfield.keyname_manager')->createInstance($plugin_config_entity->getPluginid(),$plugin_config_entity->getPluginconfig());
           $plugin_definition = $plugin_instance->getPluginDefinition();
           // Allows plugins to define its own processing class for the JSON values.
           $processing_class = isset($plugin_definition['processor_class'])? $plugin_definition['processor_class'] : '\Drupal\strawberryfield\Plugin\DataType\StrawberryValuesFromJson';
           if (!isset($keynamelist[$processing_class])) {
             // make sure we have a processing class key even if we still have no keys
             $keynamelist[$processing_class] = [];
           }
           //@TODO HOW MANY KEYS? we should be able to set this per instance.
           $keynamelist[$processing_class] = array_merge($plugin_instance->provideKeyNames(), $keynamelist[$processing_class]);
         }
       }
     }

     foreach ($keynamelist as $processor_class => $keynames) {
       if (is_array($keynames)) {
         foreach ($keynames as $keyname) {
           if (isset($reserverd_keys[$keyname])) {
             // Avoid internal reserved keys
             continue;
           }
           $properties[$keyname] = ListDataDefinition::create('string')
             ->setLabel($keyname)
             ->setComputed(TRUE)
             ->setClass(
               $processor_class
             )
             ->setInternal(FALSE)
             ->setSetting('jsonkey', $keyname)
             ->setReadOnly(TRUE);
         }
       }
     }
     return $properties;

   }

   /**
    * {@inheritdoc}
    */
   public function isEmpty() {
     // Lets optimize.
     // All our properties are computed
     // So if main value is empty rest will be too
     $mainproperty = $this->mainPropertyName();

     if (isset($this->{$mainproperty})) {
       $mainvalue = $this->{$mainproperty};
       if (empty($mainvalue) || $mainvalue == '') {
         return TRUE;
       }
     }
     else {
       return TRUE;
     }
     return FALSE;
   }


   /**
    * Calculates / keeps around a flatten common keys array for the main value.
    *
    * @param bool $force
    * Forces regeneration even if already computed.
    *
    * @return array
    */
   public function provideFlatten($force = TRUE) {

     if ($this->isEmpty()) {
       $this->flattenjson = [];
     }
     else {
       if ($this->flattenjson == NULL || $force) {
         $mainproperty = $this->mainPropertyName();
         $jsonArray = json_decode($this->{$mainproperty}, TRUE, 10);
         $flattened = [];
         StrawberryfieldJsonHelper::arrayToFlatCommonkeys(
           $jsonArray,
           $flattened,
           TRUE
         );
         $this->flattenjson = $flattened;
       }
     }
     return $this->flattenjson;
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