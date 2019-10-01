<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 3:59 PM
 */

namespace Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProvider;

use Drupal\Core\Annotation\Translation;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\UrlHelper;
use GuzzleHttp\Exception\ClientException;
use Drupal\Core\Cache\CacheBackendInterface;


/**
 *
 * Flavor Strawberry Field Key Name Provider
 *
 * @StrawberryfieldKeyNameProvider(
 *    id = "flavor",
 *    label = @Translation("Flavor/Embeded JSON Service Strawberry Field Key Name Provider"),
 *    processor_class = "\Drupal\strawberryfield\Plugin\DataType\StrawberryValuesFromFlavorJson",
 *    item_type = "map"
 * )
 */
class FlavorKeyNameProvider extends StrawberryfieldKeyNameProviderBase {

  public function calculateDependencies() {
    // TODO: Implement calculateDependencies() method.
  }

  public function getFormClass($operation) {
    // TODO: Implement getFormClass() method.
  }

  public function hasFormClass($operation) {
    // TODO: Implement hasFormClass() method.
  }

  public function defaultConfiguration() {
    return [
        // Example ap:hocr
        'source_key' => '',
        // Example hocr
        'exposed_key' => '',
        // The id of the config entity from where these values came from.'
        'configEntity' => ''
      ] + parent::defaultConfiguration();
  }


  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $parents, FormStateInterface $form_state) {

    $element['source_key'] = [
      '#id' => 'source_key',
      '#type' => 'textfield',
      '#title' => $this->t('Source JSON Key used to read the Service/Flavour'),
      '#size' => 40,
      '#maxlength' => 255,
      '#default_value' => $this->getConfiguration()['source_key'],
      '#description' => $this->t('A Source JSON Key where this flavour can be found inside a <em>Strawberry field</em>.<br> e.g. ap:hocr'),
      '#required' => true,
    ];
    // We need the parent form structure, if any, to make machine name work.
    $exposed_key_parents = $parents;
    $exposed_key_parents[] = 'source_key';

    $element['exposed_key'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Exposed <em>Strawberry field</em> property name'),
      '#machine_name' => [
        'label' => '<br/>' . $this->t('Exposed Strawberry Field Property'),
        'exists' => [$this, 'exists'],
        'source' => $exposed_key_parents,
      ],
      '#default_value' => $this->getConfiguration()['exposed_key'],
      '#description' => $this->t('Enter a value for the exposed property name. This is how the property will be available for Drupal, e.g the Search API.'),
      '#required' => true,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function provideKeyNames(string $config_entity_id = NULL) {
    // We always return an array  property => jsonkey
    // Both values are required
    $key = $this->getConfiguration()['exposed_key'] && $this->getConfiguration()['source_key'] ?
      [$this->getConfiguration()['exposed_key'] => $this->getConfiguration()['source_key']]:
      [];
    return $key;
  }

  /**
   * @param string $key
   *   The field property key.
   * @return bool
   *   TRUE if the field property key, FALSE otherwise.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function exists($key) {
    // Selects all config entities of strawberry_keynameprovider that are of this plugin type!
    $plugin_config_entities = $this->entityTypeManager->getStorage('strawberry_keynameprovider')->loadByProperties(['pluginid' => $this->getPluginId()]);
    if (count($plugin_config_entities))  {
      /* @var keyNameProviderEntity[] $plugin_config_entities */
      foreach($plugin_config_entities as $plugin_config_entity) {
          $configuration_options = $plugin_config_entity->getPluginconfig();
          if ($configuration_options['exposed_key'] == $key) {
            return TRUE;
          }
      }
    }
    return FALSE;
  }



}