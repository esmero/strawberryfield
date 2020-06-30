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
    * A JMESPATH processed results array.
    *
    * This is computed once per expression so other properties can use it.
    *
    * @var array|null
    */
   protected $jsonjmesresults = NULL;

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
     $item_types = [];

     // Fixes Search paging. Properties get lost because something in D8 fails
     // to invoke correctly (null results)
     // \Drupal::EntityTypeManager()->getListBuilder('strawberry_keynameprovider')
     $plugin_config_entities = \Drupal::EntityTypeManager()->getStorage('strawberry_keynameprovider')->loadMultiple();

     if (count($plugin_config_entities))  {
       /* @var keyNameProviderEntity[] $plugin_config_entities */
       foreach($plugin_config_entities as $plugin_config_entity) {
         if ($plugin_config_entity->isActive()) {
           $entity_id = $plugin_config_entity->id();
           $configuration_options = $plugin_config_entity->getPluginconfig();
           // This argument is used when buildin the cid for the plugin internal cache.
           $configuration_options['configEntity'] = $entity_id ;
           /* @var \Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderInterface $plugin_instance */
           $plugin_instance = \Drupal::service('strawberryfield.keyname_manager')->createInstance($plugin_config_entity->getPluginid(),$configuration_options);
           $plugin_definition = $plugin_instance->getPluginDefinition();
           // Allows plugins to define its own processing class for the JSON values.
           $processor_class = isset($plugin_definition['processor_class'])? $plugin_definition['processor_class'] : '\Drupal\strawberryfield\Plugin\DataType\StrawberryValuesFromJson';
           // Allows plugins to define its own item type for each item in the ListDataDefinition for the JSON values.
           $item_type = isset($plugin_definition['item_type'])? $plugin_definition['item_type'] : 'string';
           if (!isset($keynamelist[$processor_class])) {
             // make sure we have a processing class key even if we still have no keys
             $keynamelist[$processor_class] = [];
             // All processing classes share the same $item_type
             $item_types[$processor_class] = $item_type;
           }
           //@TODO HOW MANY KEYS? we should be able to set this per instance.
           $keynamelist[$processor_class] = array_merge($plugin_instance->provideKeyNames($entity_id), $keynamelist[$processor_class]);
         }
       }
     }

     foreach ($keynamelist as $processor_class => $plugin_info) {
       if (is_array($plugin_info)) {
         foreach ($plugin_info as $property => $keyname) {
           if (isset($reserverd_keys[$property])) {
             // Avoid internal reserved keys
             continue;
           }
           $properties[$property] = ListDataDefinition::create($item_types[$processor_class])
             ->setLabel($property)
             ->setComputed(TRUE)
             ->setClass(
               $processor_class
             )
             ->setInternal(TRUE)
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
    * Decodes main value JSON string into an array
    *
    * We don't keep this around because this will be mainly used to be
    * modified and re encoded afterwards.
    *
    * @param bool $assoc
    *   If return is array or a stdclass Object.
    *
    * @return array|\stdClass
    */
   public function provideDecoded($assoc = TRUE) {
     if ($this->isEmpty()) {
       $this->flattenjson = [];
       $jsonArray = [];
     }
     elseif ($this->validate()->count() == 0) {
       $mainproperty = $this->mainPropertyName();
       $jsonArray = json_decode($this->{$mainproperty}, $assoc, 10);

     }
     return $jsonArray;
   }


   /**
    * Encodes and sets main value from array
    *
    * This method also clears flattenjson and jsonjmesresult caches.
    *
    * @param array $jsonarray
    *   Array of data we want to save in the main property
    *
    * @return string|boolean
    *   Returns either the correctly encoded string or boolean FALSE
    *   Make sure you compare using === FALSE!.
    *
    * @throws  \InvalidArgumentException
    *    If what is passed to ::setValue() is not an array.
    */
   public function setMainValueFromArray(array $jsonarray) {

     $jsonstring = json_encode($jsonarray, JSON_PRETTY_PRINT, 10);

     if ($jsonstring) {
       $this->setValue([$this->mainPropertyName() => $jsonstring], TRUE);
       // Clear this caches just in case
       $this->flattenjson = [];
       $this->jsonjmesresults = [];
     }

     return $jsonstring;
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
     elseif ($this->validate()->count() == 0) {
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
    * Calculates / keeps around JMES Path search results for the main value.
    *
    * @param bool $force
    * Forces regeneration even if already computed.
    *
    * @param $expression
    * @param bool $force
    *
    * @return array|mixed
    */
   public function searchPath($expression, $force = TRUE) {

     if ($this->isEmpty()) {
       $this->jsonjmesresults = [];
     }
     else {
       if ($this->jsonjmesresults == NULL || !isset($this->jsonjmesresults[$expression]) || $force) {
         $mainproperty = $this->mainPropertyName();
         $jsonArray = json_decode($this->{$mainproperty}, TRUE, 10);
         $searchresult = StrawberryfieldJsonHelper::searchJson($expression, $jsonArray);
         $this->jsonjmesresults[$expression] = $searchresult;
         return $searchresult;
       }
     }
     return $this->jsonjmesresults[$expression];
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