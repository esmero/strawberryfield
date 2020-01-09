<?php

namespace Drupal\strawberryfield\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\search_api\Entity\Index;

/**
 * ConfigurationForm for which Solr field is used in ADO Type Mapping.
 */
class ImportantSolrSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;
  

  /**
   * Constructs an ImportantSolrSettingsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * 
   * * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->setConfigFactory($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {

    return [
      'strawberryfield.archipelago_solr_settings.ado_type',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    
    return 'strawberryfield_important_solr_settings_form';
  }

  /**
   * Formats array into correct format for Form API #options
   * 
   * @param array
   * 
   * @return array
   */
  public function formatOptions($array) {
    $options = array();
    foreach ($array as $key => $item) {
      $options[$key] = $item->label();
    }
    
    return $options;
  }
  
  /**
   * Returns the ID of the Solr Server of a given index
   * 
   * @param Drupal\search_api\Entity\Index $index 
   *   A Solr Index Entity
   * 
   * @return string
   *   The index's server ID
   */
  public function getServerId(Index $index) {

    return $index->getServerId();
  }
  

  public function getIndex($index_id) {
    
    return $this->entityTypeManager
      ->getStorage('search_api_index')
      ->load($index_id);
  }

  /**
   * Takes a given Solr server and returns all its indexes
   * 
   * @param $server
   *   The solr server we use to then get its indexes
   * 
   * @return array
   *   array of indexes formatted for #options
   */
  public function getIndexes($server) {
      // Get all indexes 
      $indexes = $this->entityTypeManager
        ->getStorage('search_api_index')
        ->loadMultiple();

      // Add the indexes with matching server to $indexes_by_server
      $indexes_by_server = array();
      foreach ($indexes as $key => $index) {
        $s = $index->getServerId();
        if ($s === $server) {
          $indexes_by_server[$key] = $index->label();
        }
      }
      
      return $indexes_by_server;
  }

  /**
   * Takes a Solr Index id and return its fields
   *
   * @param Drupal\search_api\Entity\Index $selected_index_entity
   *   id of the solr index
   *
   * @return array
   *   array of Solr fields formatted for #options
   */
  public function getSolrFields(Index $selected_index_entity) {
    // filter only for SBF related Solr fields
    $sbf_solr_fields = \Drupal::service('strawberryfield.utility')->getStrawberryfieldSolrFields($selected_index_entity);
    
    // format for #options
    $formatted_fields = array();
    foreach ($sbf_solr_fields as $key => $field) {
      $formatted_fields[$key] = $field['label'];
    }
    
    return $formatted_fields;
  }
  
  
  
  /**
   * AJAX callback function when user selects Solr Server
   * 
   * Updates both Index and Solr Field dropdowns in the returned AjaxResponse
   */
  public function onServerSelect(array &$form, FormStateInterface $form_state) {
    // Pass the selected server to get its indexes
    $indexes_by_server = $this->getIndexes($form_state->getValue('server_select'));
    // As a default for this new selection, use the first index the array and fetch its fields
    $fields = $this->getSolrFields(array_key_first($indexes_by_server));
    
    // Set the new #options for Index
    $form['ado_type']['index_select']['#options'] = $indexes_by_server;
    // Set the new #options for Solr Field
    $form['ado_type']['solr_field_select']['#options'] = $fields;

    // Construct our own response to return/change multiple form fields
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand("#index-select", ($form['ado_type']['index_select'])));
    $response->addCommand(new ReplaceCommand("#solr-field-select", ($form['ado_type']['solr_field_select'])));
    
    return $response;
   }

  /**
   * AJAX callback function when user selects Index
   *
   * Updates Solr Field dropdown
   */
  public function onIndexSelect(array &$form, FormStateInterface $form_state) {
    // Get solr fields for selected Index
    $selected_index_id = $form_state->getValue('index_select');
    $fields = $this->getSolrFields($this->getIndex($selected_index_id));
    $form['ado_type']['solr_field_select']['#options'] = $fields ? $fields : null;
    
    return $form['ado_type']['solr_field_select'];  
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $ado_type_config = $this->config('strawberryfield.archipelago_solr_settings.ado_type');
    
    // Get all Solr servers
    $servers = $this->entityTypeManager
      ->getStorage('search_api_server')
      ->loadMultiple();
    
    // Assume these aren't set yet (no config)
    $index_id = '';
    $index_entity = null;
    $server = '';
    
    $has_index_config = !empty($ado_type_config->get('index_id'));
    // If there is config, use the values
    if ($has_index_config) {
      $index_id = $ado_type_config->get('index_id');
      // Get full index entity based on the index_id stored in config
      $index_entity = $this->getIndex($index_id);
      // use the built-in index entity method to get its server_id
      $server = $index_entity->getServerId();
    }

    // I couldn't find a better way to get this info into the form #states, because #states api for selects can only depend on the value of another element and don't work well with boolean value.
    $form['servers-exist'] = [
      '#type' => 'hidden',
      '#prefix' => '<div id="servers-exist">',
      '#suffix' => '</div>',
      '#value' => empty($servers) ? null : 'servers'
    ];
    
    // Create replacement fieldset in the case of no existing Solr Servers
    $form['no-servers-message'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Source for Archipelago Digital Object Type'),
      '#markup' => '<p>No existing Solr Servers. Please visit <a href=" /admin/config/search/search-api/">Search API Config</a> to set one up, and then return to this form.</p>',
      '#states' => [
        'invisible' => [
          ':input[name="servers-exist"]' => ['value' => 'servers'],
        ],
      ],
    ];
    
    // Create a fieldset for the ADO Type settings
    $form['ado_type'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Source for Archipelago Digital Object Type'),
      '#markup' => '<p>Choose the Solr field that determines an ADO\'s Type. Type is then used across Archipelago. <br><br> Fields are specific to their Solr Server and Index. <br> To edit servers, indexes, and fields, visit <a href=" /admin/config/search/search-api/">Search API Config</a><br> For info and settings about the View Mode mapper, visit the <a href="/admin/config/archipelago/viewmode_mapping">ADO to View Mode Config</a></p>',
      '#states' => [
        'visible' => [
          ':input[name="servers-exist"]' => ['value' => 'servers'],
        ],
      ],
    ];

    $form['ado_type']['server_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Step 1. Select Server'),
      '#options' => $this->formatOptions($servers),
      '#default_value' => $server,
      "#empty_value" => '',
      '#empty_option' => '- Select Server -',
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::onServerSelect',
        'disable-refocus' => FALSE,
        'event' => 'change',
        'wrapper' => 'index-select', 
      ],
    ];
    
    $form['ado_type']['index_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Step 2. Select Solr Index'),
      '#prefix' => '<div id="index-select">',
      '#suffix' => '</div>',
      '#options' => $has_index_config ? $this->getIndexes($server) : null,
      "#default_value" => $index_id,
      "#empty_value" => '',
      '#empty_option' => '- Select Solr Index -',
      // If not #validated, dynamically populated dropdowns don't work.
      '#validated' => TRUE,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::onIndexSelect',
        'disable-refocus' => FALSE, 
        'event' => 'change',
        'wrapper' => 'solr-field-select', 
      ],
      '#states' => [
        'visible' => [
          ':input[name="server_select"]' => ['!value' => ''],
        ],
      ]
    ];
    
    $form['ado_type']['solr_field_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Step 3. Select Solr Field'),
      '#prefix' => '<div id="solr-field-select">',
      '#suffix' => '</div>',
      '#options' => $has_index_config ? $this->getSolrFields($index_entity) : null,
      "#default_value" => $ado_type_config->get('field'),
      "#empty_value" => '',
      '#empty_option' => '- Select Solr Field -',
      // If not #validated, dynamically populated dropdowns don't work.
      '#validated' => TRUE,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="server_select"]' => ['!value' => ''],
        ],
      ],
    ];
    
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('strawberryfield.archipelago_solr_settings.ado_type')
      ->set('index_id', $form_state->getValue('index_select'))
      ->set('field', $form_state->getValue('solr_field_select'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
