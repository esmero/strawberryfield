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
 *    label = @Translation("Flavor/Embeded JSON Service Strawberry Field Key Name Provider")
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
      '#type' => 'string',
      '#title' => $this->t('Source JSON Key used to read the Service/Flavour'),
      '#size' => 40,
      '#maxlength' => 255,
      '#default_value' => $this->getConfiguration()['source_key'],
      '#description' => $this->t('A Source JSON Key where this flavour can be found inside a <em>Strawberry field/em>.<br> e.g. ap:hocr'),
      '#required' => true,
      ];

    $element['exposed_key'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Where the processed values of this services will be available as <em>Strawberry field/em> property'),
      '#size' => 40,
      '#maxlength' => 255,
      '#default_value' => $this->getConfiguration()['exposed_key'],
      '#description' => $this->t('Enter a value for the exposed property name. This is how the property will be available for the Drupal, e.g the Search API.'),
      '#required' => true,
    ];

    return $element;
  }

  /**
   * @return array
   */
  public function provideKeyNames() {
    return $this->getConfiguration()['source_key']?: NULL;
  }


}