<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 3:59 PM
 */

namespace Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProvider;

use Drupal\Core\Annotation\Translation;
use Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventPresaveSubscriberAsFileStructureGenerator;
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
 *    id = "entityjmespath",
 *    label = @Translation("Entity Reference JmesPath Strawberry Field Key Name Provider"),
 *    processor_class = "\Drupal\strawberryfield\Plugin\DataType\StrawberryEntitiesViaJmesPathFromJson",
 *    item_type = "entity_reference"
 *
 * )
 */
class EntityJmesPathNameProvider extends JmesPathNameProvider {

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $parents, FormStateInterface $form_state) {

    $element['source_key'] = [
      '#id' => 'source_key',
      '#type' => 'textfield',
      '#title' => $this->t('One or more comma separated valid JMESPaths.'),
      '#size' => 40,
      '#maxlength' => 255,
      '#default_value' => $this->getConfiguration()['source_key'],
      '#description' => $this->t('JMespath(s) will be evaluated against your <em>Strawberry field</em> JSON to extract referenced Drupal entities.<br> e.g. ismemberof. Only Integer values are valid.'),
      '#required' => true,
    ];
    $supported_entities = str_replace('entity:', '', StrawberryfieldEventPresaveSubscriberAsFileStructureGenerator::SUPPORTED_CORE_ENTITIES);
    $element['entity_type'] = [
      '#id' => 'entity_type',
      '#type' => 'select',
      '#title' => $this->t('Entity type.'),
      '#options' => array_combine($supported_entities, $supported_entities),
      '#default_value' => !empty($this->getConfiguration()['entity_type']) ? $this->getConfiguration()['entity_type'] : 'node',
      '#description' => $this->t('The type of Drupal entity'),
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

}
