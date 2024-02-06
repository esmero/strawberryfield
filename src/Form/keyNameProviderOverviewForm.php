<?php


namespace Drupal\strawberryfield\Form;


use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\SettingsCommand;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\search_api\Plugin\search_api\datasource\ContentEntity;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\search_api\Utility\Utility;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\ContentEntityInterface;

class keyNameProviderOverviewForm extends FormBase {
  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Search API field helper.
   *
   * @var \Drupal\search_api\Utility\FieldsHelperInterface
   */
  protected $fieldHelper;

  /**
   * Constructs a StrawberryRunnersToolsForm object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\search_api\Utility\FieldsHelperInterface $field_helper
   */
  public function __construct(RendererInterface $renderer, EntityRepositoryInterface $entity_repository, EntityTypeManagerInterface $entity_type_manager, FieldsHelperInterface $field_helper) {
    $this->entityRepository = $entity_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->fieldHelper = $field_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('entity.repository'),
      $container->get('entity_type.manager'),
      $container->get('search_api.fields_helper')
    );
  }
  /**
   * @inheritDoc
   */
  public function getFormId() {
    return 'strawberryfield_keynameprovider_overview_form';
  }

  /**
   * @inheritDoc
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Display a Preview feature.
    $form['preview'] = [
      '#attributes' => ['id' => 'metadata-preview-container'],
      '#type' => 'details',
      '#title' => $this->t('Preview'),
      '#open' => FALSE,
    ];
    $form['preview']['ado_context_preview'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('ADO to preview'),
      '#description' => $this->t('The ADO used to Simulate the JSON Key Name provider data flow.'),
      '#target_type' => 'node',
      '#maxlength' => 1024,
      '#selection_handler' => 'default:nodewithstrawberry',
    ];
    $form['preview']['button_preview'] = [
      '#type' => 'button',
      '#op' => 'preview',
      '#value' => $this->t('Show preview'),
      '#ajax' => [
        'callback' => [$this, 'ajaxSimulate'],
      ],
      '#states' => [
        'visible' => [':input[name="ado_context_preview"]' => ['filled' => true]],
      ],
    ];


    $form['visualize'] = [
      '#type' => 'container',
      '#title' => 'JSON Key Name Providers Data flow',
      '#attributes' => [
        'id' => 'visualized',
      ],
    ];

    // Enable autosaving in code mirror.
    $form['#attached']['library'][] = 'strawberryfield/strawberryfield.d3viz';

    $form['#attached']['drupalSettings']['strawberry_keyname_provider'] = json_encode($this->generateEmptyTree());

    return $form;
  }

  /**
   * @inheritDoc
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // TODO: Implement submitForm() method.
  }


  public function ajaxSimulate(array &$form, FormStateInterface $form_state) {

    $response = new AjaxResponse();
    if (!empty($form_state->getValue('ado_context_preview'))) {
      /** @var \Drupal\node\NodeInterface $preview_node */
      $preview_node = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->load($form_state->getValue('ado_context_preview'));
      if (empty($preview_node)) {
        return $response;
      }
      $button = $form_state->getTriggeringElement();
      $element = NestedArray::getValue(
        $form,
        array_slice($button['#array_parents'], 0, -2)
      );
      $element['visualize']['#title'] = $preview_node->label();

      $response = new AjaxResponse();

      $settings['strawberry_keyname_provider'] = json_encode($this->generatePopulatedTree($preview_node));
      $response->addCommand(new SettingsCommand(['strawberry_keyname_provider' => []], TRUE));
      $response->addCommand(new SettingsCommand($settings, TRUE));
      return $response;
    }
  }


  /**
   * @return array
   */
  protected function generateEmptyTree(): array {

    $indexes = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->loadByProperties([
        'status' => TRUE,
      ]);

    $property_base = 'field_descriptive_metadata';
    $fields_for_keyprovided = [];
    // Keeps track of just the Search API Field IDs that matched
    // our property path splitting so we can then match the facets.
    $sbf_search_api_fields = [];
    // We only want 'entity' datasources here.
    foreach ($indexes as $index) {
      /** @var \Drupal\search_api\Item\FieldInterface[] $fields */
      $fields = $index->getFieldsByDatasource('entity:node');
      foreach ($fields as $field) {
        $property_names = Utility::splitPropertyPath($field->getPropertyPath(), FALSE);
        if ($property_base == $property_names[0]) {
          if (!empty($property_names[1])) {
            $property_keys = Utility::splitPropertyPath($property_names[1],
              FALSE)[0];
            $fields_for_keyprovided[$property_keys][] = $field;
            $sbf_search_api_fields[] = $field->getFieldIdentifier();
          }
        }
      }
    }

    /** @var \Drupal\facets\FacetManager\DefaultFacetManager $facet_manager */
    $facet_manager = \Drupal::service('facets.manager');

    foreach($facet_manager->getEnabledFacets() as $enabledFacet) {
      if (in_array($enabledFacet->getFieldIdentifier(), $sbf_search_api_fields)) {
        $facets_for_fields[$enabledFacet->getFieldIdentifier()][] = $enabledFacet;
      }
    }

    $tree = [];
    $i = 0;
    $tree['name'] = 'Strawberry Field';
    $tree['header'] = 'Strawberry Fields';
    $tree['url'] = NULL;
    $tree['children'] = [];
    /** @var \Drupal\strawberryfield\Entity\keyNameProviderEntityInterface[] $keyNames */
    $keyNames = $this->entityTypeManager->getListBuilder('strawberry_keynameprovider')->load();
    foreach ($keyNames as $plugin_config_entity) {
      $top = [
        "name" => $plugin_config_entity->label(),
        "active" => $plugin_config_entity->isActive(),
        "header" => "JSON Key Name Provider",
        "url" => $plugin_config_entity->toUrl('edit-form')->toString()
      ];
      $keynamelist = [];
      $entity_id = $plugin_config_entity->id();
      $configuration_options = $plugin_config_entity->getPluginconfig();
      $configuration_options['configEntity'] = $entity_id;
      /* @var \Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderInterface $plugin_instance */
      $plugin_instance = \Drupal::service('strawberryfield.keyname_manager')
        ->createInstance($plugin_config_entity->getPluginid(),
          $configuration_options);
      $plugin_definition = $plugin_instance->getPluginDefinition();
      // Allows plugins to define its own processing class for the JSON values.
      $processor_class = isset($plugin_definition['processor_class']) ? $plugin_definition['processor_class'] : '\Drupal\strawberryfield\Plugin\DataType\StrawberryValuesFromJson';
      // Allows plugins to define its own item type for each item in the ListDataDefinition for the JSON values.
      $item_type = isset($plugin_definition['item_type']) ? $plugin_definition['item_type'] : 'string';
      if (!isset($keynamelist[$processor_class])) {
        // make sure we have a processing class key even if we still have no keys
        $keynamelist[$processor_class] = [];
        // All processing classes share the same $item_type
        $item_types[$processor_class] = $item_type;
      }
      //@TODO HOW MANY KEYS? we should be able to set this per instance.
      $keynamelist[$processor_class] = array_merge($plugin_instance->provideKeyNames($entity_id),
        $keynamelist[$processor_class]);

      if (!empty($configuration_options['exposed_key'])) {
        $plugin_config_entity_configs[$processor_class][$configuration_options['exposed_key']] = $configuration_options;
      }
      foreach ($keynamelist as $processor_class => $keys) {
        foreach ($keys as $exposed_key => $how_to_expose) {
          $key = [
            "name" => $exposed_key,
            "header" => "Field Properties"
          ];
          if (isset($fields_for_keyprovided[$exposed_key])) {
            /** @var \Drupal\search_api\Item\FieldInterface $search_api_field */
            foreach($fields_for_keyprovided[$exposed_key] as $search_api_field) {
              // Get fits the Facets
              $facets = [];
              if (isset($facets_for_fields[$search_api_field->getFieldIdentifier()])) {
                /** @var \Drupal\facets\FacetInterface $facet_for_field */
                foreach ($facets_for_fields[$search_api_field->getFieldIdentifier()] as $facet_for_field) {
                  $facets[] = [
                    "name" => $facet_for_field->label(),
                    "machine_name" => $facet_for_field->getOriginalId(),
                    "url" => $facet_for_field->toUrl('edit-form')->toString()
                  ];
                }
              }

        $key['children'][] = [
                "name" => $search_api_field->getLabel(),
                "machine_name" => $search_api_field->getFieldIdentifier(),
                "header" => "Search API Fields",
                "url" => NULL,
                "children" => $facets,
              ];
            }
          }
          $top['children'][] = $key;
        }
      }
      $i++;
      $tree['children'][] = $top;
    }
    return $tree;
  }


  /**
   * @return array
   */
  protected function generatePopulatedTree(ContentEntityInterface $entity): array {
    $node = [];

    if ($sbf_fields = \Drupal::service('strawberryfield.utility')->bearsStrawberryfield($entity)) {
      $node['name'] = $entity->label();
      $node['header'] = 'ADO';
      $node['url'] = $entity->toUrl('edit-form')->toString();
      $node['children'] = [];
      $indexes = ContentEntity::getIndexesForEntity($entity);
      $entity_type = $entity->getEntityTypeId();
      foreach ($sbf_fields as $property_base) {
        $treeforfield = [];
        /* @var $field \Drupal\Core\Field\FieldItemInterface */
        $sbf_field = $entity->get($property_base);
        /* @var \Drupal\strawberryfield\Field\StrawberryFieldItemList $field */
        foreach ($sbf_field->getIterator() as $delta => $itemfield) {
          $fields_for_keyprovided = [];
          // Keeps track of just the Search API Field IDs that matched
          // our property path splitting so we can then match the facets.
          $sbf_search_api_fields = [];
          // We only want 'entity' datasources here.
          foreach ($indexes as $index) {
            /** @var \Drupal\search_api\Item\FieldInterface[] $fields */
            $fields = $index->getFieldsByDatasource('entity:' . $entity_type);
            foreach ($fields as $field) {
              $property_names = Utility::splitPropertyPath($field->getPropertyPath(),
                FALSE);
              if ($property_base == $property_names[0]) {
                if (!empty($property_names[1])) {
                  $property_keys = Utility::splitPropertyPath($property_names[1],
                    FALSE)[0];
                  $fields_for_keyprovided[$property_keys][] = $field;
                  $sbf_search_api_fields[] = $field->getFieldIdentifier();
                }
              }
            }
          }

          /** @var \Drupal\facets\FacetManager\DefaultFacetManager $facet_manager */
          $facet_manager = \Drupal::service('facets.manager');

          foreach ($facet_manager->getEnabledFacets() as $enabledFacet) {
            if (in_array($enabledFacet->getFieldIdentifier(),
              $sbf_search_api_fields)) {
              $facets_for_fields[$enabledFacet->getFieldIdentifier()][] = $enabledFacet;
            }
          }

          $i = 0;
          $treeforfield['name'] = $sbf_field->getFieldDefinition()->getLabel();
          $treeforfield['header'] = 'Strawberry Fields';
          $treeforfield['url'] = NULL;
          $treeforfield['children'] = [];
          /** @var \Drupal\strawberryfield\Entity\keyNameProviderEntityInterface[] $keyNames */
          $keyNames = $this->entityTypeManager->getListBuilder('strawberry_keynameprovider')
            ->load();
          foreach ($keyNames as $plugin_config_entity) {
            $top = [
              "name" => $plugin_config_entity->label(),
              "active" => $plugin_config_entity->isActive(),
              "header" => "JSON Key Name Provider",
              "url" => $plugin_config_entity->toUrl('edit-form')->toString()
            ];
            $keynamelist = [];
            $entity_id = $plugin_config_entity->id();
            $configuration_options = $plugin_config_entity->getPluginconfig();
            $configuration_options['configEntity'] = $entity_id;
            /* @var \Drupal\strawberryfield\Plugin\StrawberryfieldKeyNameProviderInterface $plugin_instance */
            $plugin_instance = \Drupal::service('strawberryfield.keyname_manager')
              ->createInstance($plugin_config_entity->getPluginid(),
                $configuration_options);
            $plugin_definition = $plugin_instance->getPluginDefinition();
            // Allows plugins to define its own processing class for the JSON values.
            $processor_class = isset($plugin_definition['processor_class']) ? $plugin_definition['processor_class'] : '\Drupal\strawberryfield\Plugin\DataType\StrawberryValuesFromJson';
            // Allows plugins to define its own item type for each item in the ListDataDefinition for the JSON values.
            $item_type = isset($plugin_definition['item_type']) ? $plugin_definition['item_type'] : 'string';
            if (!isset($keynamelist[$processor_class])) {
              // make sure we have a processing class key even if we still have no keys
              $keynamelist[$processor_class] = [];
              // All processing classes share the same $item_type
              $item_types[$processor_class] = $item_type;
            }
            //@TODO HOW MANY KEYS? we should be able to set this per instance.
            $keynamelist[$processor_class] = array_merge($plugin_instance->provideKeyNames($entity_id),
              $keynamelist[$processor_class]);

            if (!empty($configuration_options['exposed_key'])) {
              $plugin_config_entity_configs[$processor_class][$configuration_options['exposed_key']] = $configuration_options;
            }
            foreach ($keynamelist as $processor_class => $keys) {
              foreach ($keys as $exposed_key => $how_to_expose) {
                $value_to_show = $itemfield->{$exposed_key};
                $printed = print_r($value_to_show, true);
                $printed = "<pre>" . htmlspecialchars($printed, ENT_QUOTES, 'UTF-8', true) . "</pre>";
                $key = [
                  "name" => $exposed_key,
                  "header" => "Field Properties",
                  "values" => $printed,
                ];
              if (isset($fields_for_keyprovided[$exposed_key])) {
                /** @var \Drupal\search_api\Item\FieldInterface $search_api_field */
                foreach ($fields_for_keyprovided[$exposed_key] as $search_api_field) {
                  // Get fits the Facets
                  $facets = [];
                  if (isset($facets_for_fields[$search_api_field->getFieldIdentifier()])) {
                    /** @var \Drupal\facets\FacetInterface $facet_for_field */
                    foreach ($facets_for_fields[$search_api_field->getFieldIdentifier()] as $facet_for_field) {
                      $facets[] = [
                        "name" => $facet_for_field->label(),
                        "machine_name" => $facet_for_field->getOriginalId(),
                        "url" => $facet_for_field->toUrl('edit-form')
                          ->toString()
                      ];
                    }
                  }

                  $key['children'][] = [
                    "name" => $search_api_field->getLabel(),
                    "machine_name" => $search_api_field->getFieldIdentifier(),
                    "header" => "Search API Fields",
                    "url" => NULL,
                    "children" => $facets,
                  ];
                }
              }
              $top['children'][] = $key;
            }
            }
            $i++;
            $treeforfield['children'][] = $top;
          }
        }
        $tree[] = $treeforfield;
      }
      $node['children'] = $tree;
    }
    return $node;
  }
}
