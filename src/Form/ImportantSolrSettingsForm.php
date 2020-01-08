<?php

namespace Drupal\strawberryfield\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;

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
      'strawberryfield.archipelago_solr_settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'strawberryfield_type_solr_field_form';
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
   * @param $selected_index_id
   *   id of the solr index
   *
   * @return array
   *   array of Solr fields formatted for #options
   */
  public function getSolrFields($selected_index_id) {
    // get specific Index
    $index = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->load($selected_index_id);

    // get the fields of this Index
    $fields = $index->get('field_settings');

    // format correctly for #options
    $fields_by_index = array();
    foreach ($fields as $key => $field) {
      $fields_by_index[$key] = $field['label'];
    }

    return $fields_by_index;
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
    $form['type']['index_select']['#options'] = $indexes_by_server;
    // Set the new #options for Solr Field
    $form['type']['solr_field_select']['#options'] = $fields;

    // Construct our own response to return/change multiple form fields
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand("#index-select", ($form['type']['index_select'])));
    $response->addCommand(new ReplaceCommand("#solr-field-select", ($form['type']['solr_field_select'])));
    
    return $response;
   }

  /**
   * AJAX callback function when user selects Index
   *
   * Updates Solr Field dropdown
   */
  public function onIndexSelect(array &$form, FormStateInterface $form_state) {
    // Get solr fields for selected Index
    $fields = $this->getSolrFields($form_state->getValue('index_select'));
    $form['type']['solr_field_select']['#options'] = $fields;
    
    return $form['type']['solr_field_select'];  
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('strawberryfield.archipelago_solr_settings');
    
    // Get all Solr servers
    $servers = $this->entityTypeManager
      ->getStorage('search_api_server')
      ->loadMultiple();

    // Create a fieldset for the Type setting
    $form['type'] = array(
      '#type' => 'fieldset',
      '#title' => $this->t('Solr Field for Type in View Mode mapping'),
      '#markup' => '<p>Choose the Solr field that will be used in our ADO to View Mode mapper. <br><br> Fields are specific to their Solr Server and Index. <br> To edit servers, indexes, and fields, visit <a href=" /admin/config/search/search-api/">Search API Config</a><br> For info and settings about the View Mode mapper, visit the <a href="/admin/config/archipelago/viewmode_mapping">ADO to View Mode Config</a></p>'
    );

    $form['type']['server_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Step 1. Select Server'),
      '#options' => $this->formatOptions($servers),
      '#default_value' => $config->get('type_server'),
      '#ajax' => [
        'callback' => '::onServerSelect',
        'disable-refocus' => FALSE,
        'event' => 'change',
        'wrapper' => 'index-select', 
      ]
    ];
    
    $form['type']['index_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Step 2. Select Solr Index'),
      '#prefix' => '<div id="index-select">',
      '#suffix' => '</div>',
      '#options' => $config->get('type_server') ? $this->getIndexes($config->get('type_server')) : null,
      "#empty_value" => "",
      "#default_value" => $config->get('type_index'),
      // If not #validated, dynamically populated dropdowns don't work.
      '#validated' => TRUE,
      '#ajax' => [
        'callback' => '::onIndexSelect',
        'disable-refocus' => FALSE, 
        'event' => 'change',
        'wrapper' => 'solr-field-select', 
      ]
    ];
    
    $form['type']['solr_field_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Step 3. Select Solr Field'),
      '#prefix' => '<div id="solr-field-select">',
      '#suffix' => '</div>',
      '#options' => $config->get('type_server') && $config->get('type_index') ? $this->getSolrFields($config->get('type_index')) : null,
      "#empty_value" => "",
      "#default_value" => $config->get('type_field'),
      // If not #validated, dynamically populated dropdowns don't work.
      '#validated' => TRUE,
    ];
    
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('strawberryfield.archipelago_solr_settings')
      ->set('type_server', $form_state->getValue('server_select'))
      ->set('type_index', $form_state->getValue('index_select'))
      ->set('type_field', $form_state->getValue('solr_field_select'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
