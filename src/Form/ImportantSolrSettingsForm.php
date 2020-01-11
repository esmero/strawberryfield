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
use Drupal\strawberryfield\StrawberryfieldUtilityService;

/**
 * ConfigurationForm for Solr settings in Archipelago
 */
class ImportantSolrSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Strawberryfield Utility service
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $sbfUtiliy;

  protected $solrServers;

  /**
   * Constructs an ImportantSolrSettingsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   *
   * * @param \Drupal\strawberryfield\StrawberryfieldUtilityService $sbf_utility
   *   SBF Utility Service
   *
   * * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    StrawberryfieldUtilityService $sbf_utility,
    ConfigFactoryInterface $config_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->sbfUtiliy = $sbf_utility;
    $this->setConfigFactory($config_factory);
    $this->solrServers = $entity_type_manager->getStorage('search_api_server')
      ->loadMultiple();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {

    return new static(
      $container->get('entity_type.manager'),
      $container->get('strawberryfield.utility'),
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
    $options = [];
    foreach ($array as $key => $item) {
      $options[$key] = $item->label();
    }

    return $options;
  }

  /**
   * Returns the ID of the Solr Server of a given index
   *
   * @param \Drupal\search_api\Entity\Index $index
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
   * @param string|null $server
   *   The solr server we use to then get its indexes
   *
   * @return array
   *   array of indexes formatted for #options
   */
  public function getIndexes($server) {
    // Get all indexes
    /* @var $indexes \Drupal\search_api\IndexInterface[] */
    $indexes = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->loadMultiple();

    // Add the indexes with matching server to $indexes_by_server
    $indexes_by_server = [];
    foreach ($indexes as $key => $index) {
      if ($index->isServerEnabled()) {
        $s = $index->getServerId();
        if ($s === $server) {
          $indexes_by_server[$key] = $index->label();
        }
      }
    }

    return $indexes_by_server;
  }

  /**
   * Takes a Solr Index id and return its fields
   *
   * @param $selected_index_entity
   *   id of the solr index
   *
   * @return array
   *   array of Solr fields formatted for #options
   */
  public function getSolrFields($selected_index_entity) {
    // filter only for SBF related Solr fields
    $sbf_solr_fields = $this->sbfUtiliy->getStrawberryfieldSolrFields(
      $selected_index_entity
    );

    // format for #options
    $formatted_fields = [];
    foreach ($sbf_solr_fields as $id => $field) {
      $formatted_fields[$id] = $field['label'];
    }

    return $formatted_fields;
  }

  /**
   * AJAX callback function when user selects Solr Server
   *
   * Updates both Index and Solr Field dropdowns in the returned AjaxResponse
   */
  public function onServerSelect(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    if ($form_state->getValue('server_select')) {
      $response->addCommand(
        new ReplaceCommand("#index-select", ($form['ado_type']['index_select']))
      );
      $response->addCommand(
        new ReplaceCommand(
          "#solr-field-select",
          ($form['ado_type']['solr_field_select'])
        )
      );
    }

    return $response;
  }

  /**
   * AJAX callback function when user selects Index
   *
   * Updates Solr Field dropdown
   */
  public function onIndexSelect(array &$form, FormStateInterface $form_state) {

    $response = new AjaxResponse();
    if ($form_state->getValue('index_select')) {
      $response->addCommand(
        new ReplaceCommand(
          "#solr-field-select",
          ($form['ado_type']['solr_field_select'])
        )
      );
      $response->addCommand(
        new ReplaceCommand(
          "#no-fields", ($form['ado_type']['no-fields'])
        )
      );
    }

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    if (empty($this->solrServers)) {
      // Create replacement fieldset in the case of no existing Solr Servers.
      $form['no-servers-message'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Source for Archipelago Digital Object Type'),
        '#markup' => '<p>No existing Search API Servers. Please visit <a href=" /admin/config/search/search-api/">Search API Config</a> to set one up, and then return to this form.</p>',
      ];
      return $form;

    }

    $ado_type_config = $this->config(
      'strawberryfield.archipelago_solr_settings.ado_type'
    );

    // Note: this all could go into the constructor. No need to load things each
    // time the Form rebuilds.
    $index_id = $ado_type_config->get('index_id');
    $field = $ado_type_config->get('field');

    // Defaults
    $index_entity = NULL;
    $server = NULL;
    $solr_fields = [];
    $indexes_by_server = [];

    if ($form_state->isRebuilding()) {
      // Idea here is: rebuilding means user submitted values,
      // So our original Config is not longer valid
      // We use what is passed around.
      $server = !empty(
      $form_state->getValue(
        'server_select'
      )
      ) ? $form_state->getValue('server_select') : NULL;
      $indexes_by_server = $this->getIndexes($server);
      $index_id = !empty(
      $form_state->getValue(
        'index_select'
      )
      ) ? $form_state->getValue('index_select') : NULL;
      // NO need to get 'solr_field_select' from form state
      // since its the last step and would only
      // trigger a rebuild if index or server changes.
    }


    if ($index_id) {
      // Get full index entity based on the index_id stored in config
      $index_entity = $this->getIndex($index_id);
      // use the built-in index entity method to get its server_id
      $server = $index_entity->getServerId();
      // Todo: Check if we need to call  this again?
      $indexes_by_server = $this->getIndexes($server);
      $solr_fields = $this->getSolrFields($index_entity);
    }

    // Create a fieldset for the ADO Type settings, visible when servers exist
    $form['ado_type'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Source for Archipelago Digital Object Type'),
      '#markup' => '<p>Choose the Solr field that determines an ADO\'s Type. Type is then used across Archipelago. <br><br> Fields are specific to their Solr Server and Index. <br> To edit servers, indexes, and fields, visit <a href=" /admin/config/search/search-api/">Search API Config</a><br> For info and settings about the View Mode mapper, visit the <a href="/admin/config/archipelago/viewmode_mapping">ADO to View Mode Config</a></p>',
    ];

    $form['ado_type']['server_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Step 1. Select Server'),
      '#options' => $this->formatOptions($this->solrServers),
      '#default_value' => $server,
      "#empty_value" => NULL,
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
      '#options' => $indexes_by_server,
      "#default_value" => $index_id,
      "#empty_value" => NULL,
      '#empty_option' => '- Select Solr Index -',
      // If not #validated, dynamically populated dropdowns don't work.
      '#validated' => TRUE,
      '#required' => TRUE,
      '#submit' => [[$this, 'field_submit']],
      '#executes_submit_callback' => TRUE,
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
        'optional' => [
          ':input[name="server_select"]' => ['value' => ''],
        ],
      ],
    ];


    // Check if there are Solr fields for this index.
    $form['ado_type']['solr_field_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Step 3. Select Solr Field'),
      '#prefix' => '<div id="solr-field-select">',
      '#suffix' => '</div>',
      '#options' => $solr_fields,
      "#default_value" => $field,
      "#empty_value" => NULL,
      '#empty_option' => '- Select Solr Field -',
      // If not #validated, dynamically populated dropdowns don't work.
      '#validated' => TRUE,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="server_select"]' => ['!value' => ''],
          ':input[name="index_select"]' => ['!value' => ''],
        ],
        'optional' => [
          ':input[name="index_select"]' => ['value' => ''],
        ],
      ],
    ];

    // This avoids Browser error when an JS conditionally hidden field is required.
    if (!$server) {
      $form['ado_type']['index_select']['#required'] = FALSE;
    }
    if (!$index_id) {
      $form['ado_type']['solr_field_select']['#required'] = FALSE;
    }


    if (empty($solr_fields) && $index_entity) {
      $url = '/admin/config/search/search-api/index/' . $index_id . '/fields';
      // Means there is an $index but its empty.
      $form['ado_type']['no-fields'] = [
        '#markup' => '<p class=\'color-error\'><br> There are currently no Solr Fields for this index that can be used with ADOs. <br> Please select another Index or set up a Solr Field based on Strawberryfield content <a href=' . $url . '><b>here</b></a> and return.</p>',
        '#prefix' => '<div id="no-fields">',
        '#suffix' => '</div>',
      ];
    }
    else {
      // Why? Well, we want to be sure the Form builder has the chance to keep
      // Track of this element when it initialize. Could be an overkill.
      $form['ado_type']['no-fields'] = [
        '#prefix' => '<div id="no-fields">',
        '#suffix' => '</div>',
        '#markup' => '',
      ];
    }

    return parent::buildForm($form, $form_state);
  }


  /**
   * Submission handler for condition changes in
   */
  function field_submit(array &$form, FormStateInterface $form_state) {
    // We want to unset values here.
    // That way, e.g in case people select an index, then a field
    // And then a new index, solr_field_select gets always a chance to be
    // reselected.
    if (empty($form_state->getValue('server_select'))) {
      $form_state->unsetValue('solr_field_select');
      $form_state->unsetValue('index_select');
    }
    if (empty($form_state->getValue('index_select'))) {
      $form_state->unsetValue('solr_field_select');
    }

    $form_state->setRebuild(TRUE);
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
