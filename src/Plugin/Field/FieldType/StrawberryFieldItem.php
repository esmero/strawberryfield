<?php
namespace Drupal\strawberryfield\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\strawberryfield\Tools\StrawberryKeyNameProvider;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderManager;
use Drupal\strawberryfield\Entity\keyNameProviderEntity;
use Drupal\Component\Utility\Random;

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
           //@TODO HOW MANY KEYS? we should be able to set this per instance.
           $keynamelist = array_merge(\Drupal::service('strawberryfield.keyname_manager')->createInstance($plugin_config_entity->getPluginid(),$plugin_config_entity->getPluginconfig())->provideKeyNames(), $keynamelist);
         }
       }
     } else {
       // @TODO not sure if i need this. This is the default in case we have no plugins yet.
       //
       $keyprovider_plugin = \Drupal::service('strawberryfield.keyname_manager')
         ->getDefinitions();
       // Collect the key Providers Plugins

       foreach ($keyprovider_plugin as $plugin_definition) {
         /* @var \Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderInterface $plugin_definition */
         $keynamelist = array_merge(
           \Drupal::service('strawberryfield.keyname_manager')->createInstance(
             $plugin_definition['id'],
             []
           )->provideKeyNames(),
           $keynamelist
         );
       }
     }
    // @TODO add also the flat representation as a property. Simply reuse our internal property helper
    // Handy when dealing with Field formatters


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